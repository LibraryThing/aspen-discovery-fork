<?php /** @noinspection PhpMissingFieldTypeInspection */

require_once ROOT_DIR . '/sys/Enrichment/SyndeticsSetting.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsIndexingLogEntry.php';

/**
 * Shared fetch/parse/cache-write library for the two SU enrichment crons
 * (syndeticsUnboundTagsBackground.php and syndeticsClassicEnrichmentBackground.php).
 */
class SyndeticsIndexingExtractor {
	private const SU_TAGS_SEED_URL = 'https://www.librarything.com/api_su_feed_seed.php';
	private const SU_TAGS_LIBRARY_URL = 'https://www.librarything.com/api_su_feed_library.php';
	private const SYNDETICS_BASE = 'https://syndetics.com/index.aspx';
	private const CLASSIC_CHUNK_SIZE = 200;
	private const SU_TAGS_LIBRARY_PROBE_MIN_INTERVAL_SECS = 86400;    // 24h — library feed regenerates ~biweekly
	private const SU_TAGS_SEED_PROBE_MIN_INTERVAL_SECS = 7776000;     // 90 days (~3 months) — seed regenerates ~6-monthly
	private const CLASSIC_REQUEST_TIMEOUT_SECS = 10;
	private const FULL_REFRESH_INTERVAL_SECS = 15552000;   // 6 months — classic full-refresh cadence
	private const CLASSIC_REVIEW_SOURCES = [
		'CHREVIEW'        => ['title' => 'Choice Review', 'file' => 'CHREVIEW.XML'],
		'BLREVIEW'        => ['title' => 'Booklist Review', 'file' => 'BLREVIEW.XML'],
		'PWREVIEW'        => ['title' => "Publisher's Weekly Review", 'file' => 'PWREVIEW.XML'],
		'SLJREVIEW'       => ['title' => 'School Library Journal Review', 'file' => 'SLJREVIEW.XML'],
		'LJREVIEW'        => ['title' => 'Library Journal Review', 'file' => 'LJREVIEW.XML'],
		'HBREVIEW'        => ['title' => 'Horn Book Review', 'file' => 'HBREVIEW.XML'],
		'KIREVIEW'        => ['title' => 'Kirkus Book Review', 'file' => 'KIREVIEW.XML'],
		'CRITICASEREVIEW' => ['title' => 'Criti Case Review', 'file' => 'CRITICASEREVIEW.XML'],
	];

	private SyndeticsSetting $settings;
	private SyndeticsIndexingLogEntry $logEntry;

	/** (feedSource|identifierType|identifier) => rawChecksum, loaded at pass start. */
	private array $checksumMap = [];

	private $touchLastFetchedStmt = null;

	public function __construct(SyndeticsSetting $settings, SyndeticsIndexingLogEntry $logEntry) {
		$this->settings = $settings;
		$this->logEntry = $logEntry;
	}

	// === Classic Syndetics XML fetch (replicated from GoDeeperData.php / Reviews.php) ===

	/**
	 * @noinspection PhpUnused
	 * Returns the parsed XML, or null on no data. $transientFailure (out) distinguishes a transport-level
	 * failure (no response / non-2xx — caller should retry) from a definitive "no data" response (a 2xx HTML
	 * "no data" page or non-XML body — the identifier has been checked, write the empty marker so backfill drains).
	 */
	private function fetchClassicXml(string $file, string $isbn, string $upc, string $clientKey, string $viewType = 'xw10', &$transientFailure = null): ?SimpleXMLElement {
		$transientFailure = false;
		$requestUrl = self::SYNDETICS_BASE . "?isbn=$isbn/$file&client=$clientKey&type=$viewType&upc=$upc";
		$ctx = stream_context_create([
			'http' => [
				'timeout' => self::CLASSIC_REQUEST_TIMEOUT_SECS,
				'ignore_errors' => true,   // capture the body on non-2xx so we can classify it
			],
		]);
		$response = @file_get_contents($requestUrl, false, $ctx);
		$status = 0;
		if (isset($http_response_header[0]) && preg_match('{HTTP/\S+\s+(\d+)}', $http_response_header[0], $m)) {
			$status = (int)$m[1];
		}
		require_once ROOT_DIR . '/sys/SystemLogging/ExternalRequestLogEntry.php';
		ExternalRequestLogEntry::logRequest('syndetics.indexing.' . strtolower($file), 'GET', $requestUrl, [], '', $status, $response === false ? '' : $response, []);
		// Transport failure: no response, or a definitive non-2xx status. Caller should retry, not mark.
		if ($response === false || $response === '' || ($status !== 0 && ($status < 200 || $status >= 300))) {
			$transientFailure = true;
			return null;
		}
		// 2xx response. ProQuest serves an HTML "no data" page (still HTTP 200) when an ISBN has no content
		// for a view — that's a definitive "no data" answer, NOT transient.
		if (preg_match('/Error in Query Selection|The page you are looking for could not be found/', $response)) {
			return null;
		}
		try {
			return new SimpleXMLElement($response);
		} catch (Exception $e) {
			global $logger;
			$logger->log("Syndetics indexing: could not parse XML from $requestUrl: $e", Logger::LOG_WARNING);
			return null;   // 2xx non-XML body => definitive no data (not transient).
		}
	}

	// === Syndetics Unbound tags feed (Bearer + gzip, NDJSON body) ===

	/** Entry point for the SU-tags cron: probe + (if changed) download both feeds. */
	public function runSuTagsPass(): void {
		foreach (['seed', 'library'] as $feedKind) {
			$this->processSuTagsFeed($feedKind);
			if (empty($this->settings->indexingEnabled)) {
				// A feed signaled subscription_revoked (runSubscriptionRevocationPause set indexingEnabled = 0).
				// Stop before another doomed authenticated request burns quota on a dead token.
				break;
			}
		}
	}

	private function processSuTagsFeed(string $feedKind): void {
		$now = time();
		$fetchedAtCol = $feedKind === 'seed' ? 'lastSeenSuTagsSeedFetchedAt' : 'lastSeenSuTagsLibraryFetchedAt';
		$versionCol   = $feedKind === 'seed' ? 'lastSeenSuTagsSeedVersion'    : 'lastSeenSuTagsLibraryVersion';
		// Seed regenerates ~6-monthly so we probe it every ~3 months; the per-library feed daily.
		$minInterval  = $feedKind === 'seed' ? self::SU_TAGS_SEED_PROBE_MIN_INTERVAL_SECS : self::SU_TAGS_LIBRARY_PROBE_MIN_INTERVAL_SECS;
		if ($this->settings->$fetchedAtCol && ($now - (int)$this->settings->$fetchedAtCol) < $minInterval) {
			$this->logEntry->notes .= "\n[$feedKind] not yet eligible (last fetched " . date('c', $this->settings->$fetchedAtCol) . ')';
			return;
		}
		$url = $feedKind === 'seed' ? self::SU_TAGS_SEED_URL : self::SU_TAGS_LIBRARY_URL;
		$meta = $this->fetchSuTagsMeta($url);
		if ($meta === null) {
			return;   // error already logged via handleSuTagsError()
		}
		// ?meta=1 returns {version, generated, count} — no a_id. Change-detect on version.
		if ((int)$meta['version'] === (int)$this->settings->$versionCol) {
			$this->settings->$fetchedAtCol = $now;
			$this->settings->update();
			return;   // unchanged — record the probe time and skip the full download.
		}
		$this->downloadAndApplySuTagsSnapshot($feedKind, $url, $meta);
	}

	/**
	 * Bearer GET. If $tmpFile is given, stream RAW bytes to it (no auto-inflate) so the gzip NDJSON
	 * can be read back through compress.zlib://; otherwise accept + auto-inflate gzip and return the
	 * (small) body — used for the ?meta=1 probe. Returns ['code','headers','body','error'].
	 */
	private function suTagsHttpGet(string $url, ?string $tmpFile): array {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->settings->syndeticsUnboundFeedToken]);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 1800);
		$headers = [];
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$headers) {
			$parts = explode(':', $line, 2);
			if (count($parts) === 2) {
				$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
			}
			return strlen($line);
		});
		if ($tmpFile !== null) {
			// Raw gzip bytes to disk. Do NOT set CURLOPT_ENCODING — auto-inflate would write plaintext and
			// compress.zlib:// would then choke.
			$fp = fopen($tmpFile, 'w');
			curl_setopt($ch, CURLOPT_FILE, $fp);
			$ok = curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$err = $ok === false ? curl_error($ch) : null;
			curl_close($ch);
			fclose($fp);
			return ['code' => $code, 'headers' => $headers, 'body' => null, 'error' => $err];
		}
		curl_setopt($ch, CURLOPT_ENCODING, '');   // accept + auto-inflate gzip for the small probe JSON
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = $body === false ? curl_error($ch) : null;
		curl_close($ch);
		return ['code' => $code, 'headers' => $headers, 'body' => $body === false ? null : $body, 'error' => $err];
	}

	private function fetchSuTagsMeta(string $url) {
		$resp = $this->suTagsHttpGet($url . '?meta=1', null);
		if ($resp['code'] < 200 || $resp['code'] >= 300) {
			$this->handleSuTagsError($resp['code'], (string)($resp['body'] ?? ''), $resp['headers']);
			return null;
		}
		$meta = json_decode((string)($resp['body'] ?? ''), true);
		if (!is_array($meta) || !isset($meta['version'])) {
			$this->logEntry->numErrors++;
			$this->logEntry->notes .= "\nProbe from $url was not parseable as {version,generated,count}.";
			return null;
		}
		$this->logEntry->notes .= "\n[probe] $url version=" . $meta['version'] . ' count=' . ($meta['count'] ?? '?');
		return $meta;
	}

	/**
	 * Download the full NDJSON snapshot (one JSON record per line, meta on the LAST line) to a tmp gzip
	 * file, stream-parse it natively (fgets + json_decode per line — constant memory, no library), upsert
	 * each identifier, then mark-and-sweep + queue reindex + advance cursors. The trailing meta line is a
	 * required completeness sentinel: if it's missing the download was truncated and we abort without
	 * sweeping (so a partial download can't delete cache rows).
	 */
	private function downloadAndApplySuTagsSnapshot(string $feedKind, string $url, array $probeMeta): void {
		$passStart = time();
		$tmpGz = tempnam(sys_get_temp_dir(), 'su_tags_');
		if ($tmpGz === false) {
			$this->logEntry->numErrors++;
			$this->logEntry->notes .= "\n[$feedKind] could not create a temp file for the download.";
			return;
		}
		try {
			$resp = $this->suTagsHttpGet($url, $tmpGz);
			if ($resp['code'] < 200 || $resp['code'] >= 300) {
				$this->handleSuTagsError($resp['code'], (string)@file_get_contents($tmpGz), $resp['headers']);
				return;
			}
			// gzip magic-byte sanity check (raw single-member gzip).
			$fh = @fopen($tmpGz, 'rb');
			$magic = $fh ? fread($fh, 2) : '';
			if ($fh) {
				fclose($fh);
			}
			if ($magic !== "\x1f\x8b") {
				$this->logEntry->numErrors++;
				$this->logEntry->notes .= "\n[$feedKind] download did not start with gzip magic bytes; aborting.";
				return;
			}

			// Pre-pass: read the trailing meta sentinel and validate BEFORE writing anything. Because meta is
			// the LAST line, this both confirms the download is complete (not truncated) and that a_id matches
			// the account — so neither failure mutates the cache.
			$meta = $this->readSuTagsMetaSentinel($tmpGz);
			if ($meta === null) {
				$this->logEntry->numErrors++;
				$this->logEntry->notes .= "\n[$feedKind] no trailing meta line — download appears truncated; aborting before any cache write.";
				return;
			}
			$aId = isset($meta['a_id']) ? (int)$meta['a_id'] : null;
			if ($feedKind === 'library' && $aId !== (int)$this->settings->unboundAccountNumber) {
				$this->logEntry->numErrors++;
				$this->logEntry->notes .= "\n[library] a_id_mismatch: feed a_id=$aId vs unboundAccountNumber={$this->settings->unboundAccountNumber}; aborting before any cache write.";
				return;
			}
			if ($feedKind === 'seed' && $aId !== 0) {
				$this->logEntry->numErrors++;
				$this->logEntry->notes .= "\n[seed] seed_a_id_nonzero: expected 0, got $aId; aborting before any cache write.";
				return;
			}

			// Preload checksums for this feed kind (drives checksum-skip + numAdded/numUpdated), and for the
			// seed pass the set of library-owned identifiers (library-wins-on-collision; defensive no-op
			// post-canonicalization).
			$this->loadSuTagsChecksumMap($feedKind);
			$libraryIds = $feedKind === 'seed' ? $this->loadSuTagsLibraryIdentifierSet() : [];

			// Main pass: stream the identifier records and upsert (the meta line is already validated above).
			$stream = @fopen('compress.zlib://' . $tmpGz, 'r');
			if ($stream === false) {
				$this->logEntry->numErrors++;
				$this->logEntry->notes .= "\n[$feedKind] could not open the gzip stream for parsing.";
				return;
			}
			$reindexIds = [];
			while (($line = fgets($stream)) !== false) {
				$line = trim($line);
				if ($line === '') {
					continue;
				}
				$rec = json_decode($line, true);
				if (!is_array($rec)) {
					$this->logEntry->numInvalidRecords++;
					continue;
				}
				if (isset($rec['meta'])) {
					continue;   // the sentinel — already validated in the pre-pass.
				}
				if (!isset($rec['t'], $rec['id'])) {
					$this->logEntry->numInvalidRecords++;
					continue;
				}
				$type = $rec['t'];
				$id = (string)$rec['id'];
				// Validate the identifier shape so malformed ids never enter the cache (they'd silently fail
				// the reindex Solr lookup later). The feed emits ISBN-13 (13 digits) and UPC-A (12 digits).
				$validId = ($type === 'isbn' && preg_match('/^[0-9]{13}$/', $id))
					|| ($type === 'upc' && preg_match('/^[0-9]{12}$/', $id));
				if (!$validId) {
					$this->logEntry->numInvalidRecords++;
					continue;
				}
				$this->logEntry->numProducts++;
				if ($feedKind === 'seed' && isset($libraryIds[$id])) {
					continue;   // a library row already owns this identifier — library wins, seed write is a no-op.
				}
				$payload = json_encode(['index' => $rec['index'] ?? [], 'facet' => $rec['facet'] ?? []]);
				if ($this->upsertCacheRow('su_tags', $feedKind, $type, $id, $payload)) {
					$reindexIds[] = $id;
				}
			}
			fclose($stream);

			// Mark-and-sweep (per feed kind): every row touched this pass had lastFetched set to >= $passStart
			// (upsert or checksum-skip), so rows still < $passStart were dropped from the snapshot.
			$deletedIds = $this->sweepStaleSuTagsRows($feedKind, $passStart);
			$this->logEntry->numDeleted += count($deletedIds);
			$reindexIds = array_merge($reindexIds, $deletedIds);

			if (!empty($reindexIds)) {
				$this->queueReindexForIdentifiers(array_values(array_unique($reindexIds)));
			}

			// Advance cursors for this feed kind.
			$versionCol = $feedKind === 'seed' ? 'lastSeenSuTagsSeedVersion' : 'lastSeenSuTagsLibraryVersion';
			$fetchedCol = $feedKind === 'seed' ? 'lastSeenSuTagsSeedFetchedAt' : 'lastSeenSuTagsLibraryFetchedAt';
			$this->settings->$versionCol = (int)($meta['version'] ?? $probeMeta['version'] ?? 0);
			$this->settings->$fetchedCol = $passStart;
			$this->settings->update();
		} finally {
			if (is_string($tmpGz) && file_exists($tmpGz)) {
				@unlink($tmpGz);
			}
		}
	}

	/**
	 * Stream the NDJSON to find the trailing meta sentinel ({"meta":{...}}). Returns the meta array, or
	 * null if no meta line is present (truncated download). Decodes only candidate lines (those containing
	 * the literal "meta" token) rather than every record, so the scan stays cheap.
	 */
	private function readSuTagsMetaSentinel(string $tmpGz): ?array {
		$stream = @fopen('compress.zlib://' . $tmpGz, 'r');
		if ($stream === false) {
			return null;
		}
		$meta = null;
		while (($line = fgets($stream)) !== false) {
			if (strpos($line, '"meta"') === false) {
				continue;   // identifier records carry "t"/"id"; only the sentinel has a top-level "meta".
			}
			$rec = json_decode(trim($line), true);
			if (is_array($rec) && isset($rec['meta']) && is_array($rec['meta'])) {
				$meta = $rec['meta'];   // keep the last match (the sentinel is the final line).
			}
		}
		fclose($stream);
		return $meta;
	}

	/** Load (feedSource|identifierType|identifier) => rawChecksum for one su_tags feed kind. */
	private function loadSuTagsChecksumMap(string $feedKind): void {
		global $aspen_db;
		$this->checksumMap = [];
		$stmt = $aspen_db->prepare(
			"SELECT identifierType, identifier, rawChecksum FROM syndetics_indexing_data
			 WHERE syndeticsSettingId = ? AND feedSource = 'su_tags' AND suTagsFeedKind = ?"
		);
		$stmt->execute([$this->settings->id, $feedKind]);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$this->checksumMap['su_tags|' . $row['identifierType'] . '|' . $row['identifier']] = $row['rawChecksum'];
		}
	}

	/** Identifier => true for every su_tags row currently owned by the per-library feed (library-wins probe). */
	private function loadSuTagsLibraryIdentifierSet(): array {
		global $aspen_db;
		$set = [];
		$stmt = $aspen_db->prepare(
			"SELECT identifier FROM syndetics_indexing_data
			 WHERE syndeticsSettingId = ? AND feedSource = 'su_tags' AND suTagsFeedKind = 'library'"
		);
		$stmt->execute([$this->settings->id]);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$set[$row['identifier']] = true;
		}
		return $set;
	}

	/** Delete su_tags rows of this feed kind not touched this pass (lastFetched < $passStart); return their identifiers. */
	private function sweepStaleSuTagsRows(string $feedKind, int $passStart): array {
		global $aspen_db;
		$sel = $aspen_db->prepare(
			"SELECT identifier FROM syndetics_indexing_data
			 WHERE syndeticsSettingId = ? AND feedSource = 'su_tags' AND suTagsFeedKind = ? AND lastFetched < ?"
		);
		$sel->execute([$this->settings->id, $feedKind, $passStart]);
		$ids = [];
		while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
			$ids[] = $row['identifier'];
		}
		if (!empty($ids)) {
			$del = $aspen_db->prepare(
				"DELETE FROM syndetics_indexing_data
				 WHERE syndeticsSettingId = ? AND feedSource = 'su_tags' AND suTagsFeedKind = ? AND lastFetched < ?"
			);
			$del->execute([$this->settings->id, $feedKind, $passStart]);
		}
		return $ids;
	}

	// === Error contract (branch on the machine `code`, never the bare HTTP status) ===

	private function handleSuTagsError(int $httpStatus, string $body, ?array $headers): void {
		$decoded = json_decode($body, true);
		$code = is_array($decoded) ? ($decoded['code'] ?? null) : null;
		$human = is_array($decoded) ? ($decoded['error'] ?? '(no human message)') : '(non-JSON body)';
		$this->logEntry->numErrors++;
		$this->logEntry->notes .= "\nSyndetics Unbound HTTP $httpStatus code=$code msg=$human";
		switch ($code) {
			case 'subscription_revoked':
				// 403: confirmed cancellation. Auto-pause only; the destructive purge is the cleanup script's job.
				$this->runSubscriptionRevocationPause();
				return;
			case 'account_not_active':
				// 403: lsa_unbound=0 (paused / not yet provisioned). Pause + preserve cache; do NOT purge or auto-disable.
				$this->logEntry->notes .= "\nAccount paused (account_not_active) — cache preserved; admin should check the Syndetics Unbound account status.";
				return;
			case 'ambiguous_account':
				// 403: token maps to >1 library account. Provisioning error; needs a human.
				$this->logEntry->notes .= "\nambiguous_account — token resolves to multiple library accounts. Contact Syndetics Unbound to reissue a single-account token. Cache preserved.";
				return;
			case 'invalid_token':
				// 401: missing/malformed/unknown token. Pause + preserve cache; admin must refresh the token.
				$this->logEntry->notes .= "\ninvalid_token — refresh the Syndetics Unbound Feed Bearer Token on the settings row. Cache preserved.";
				return;
			case 'account_not_found':
				// 404: token has no SU account, or account has no library mapping. Pause + preserve.
				$this->logEntry->notes .= "\naccount_not_found — token has no SU account or library mapping. Cache preserved.";
				return;
			case 'quota_exceeded':
				// 429: daily quota or rate-limit. Retry-After typically 3600s.
				$this->logEntry->notes .= "\nquota_exceeded — retry after " . $this->parseRetryAfter($headers) . 's (default 3600s = 1h).';
				return;
			case 'feed_not_ready':
				// 503: snapshot not yet generated. Retry-After typically 86400s.
				$this->logEntry->notes .= "\nfeed_not_ready — retry after " . $this->parseRetryAfter($headers) . 's (default 86400s = 24h).';
				return;
			default:
				// Unknown code: preserve cache defensively — never purge on an unrecognized error.
				$this->logEntry->notes .= "\nUnhandled Syndetics Unbound error code '$code'; preserving cache for safety.";
		}
	}

	private function runSubscriptionRevocationPause(): void {
		// Pause indexing so later passes don't burn quota against revoked credentials. Do NOT delete the
		// cache or force a reindex — that is the sysadmin-run cleanup script's job.
		$this->settings->indexingEnabled = 0;
		$this->settings->update();
		$this->logEntry->notes .= "\n*** SUBSCRIPTION REVOKED *** indexingEnabled set to 0; indexing paused. "
			. 'Cache and Solr fields preserved. Run syndeticsUnboundCleanup.php to purge when ready.';
	}

	private function parseRetryAfter(?array $headers): int {
		if ($headers !== null && isset($headers['retry-after']) && is_numeric($headers['retry-after'])) {
			return (int)$headers['retry-after'];
		}
		return 0;
	}

	// === Classic Syndetics enrichment (per-identifier content + reviews from ProQuest) ===

	/**
	 * One classic-enrichment pass. Picks backfill (steady state — su_tags rows that have no syndetics_classic
	 * companion yet) or full-refresh (cursor-walk all su_tags rows; entered when classicEnrichmentLastFullPassAt
	 * is NULL or older than 6 months). Only identifiers Syndetics Unbound has already enriched (su_tags rows)
	 * are ever fetched.
	 */
	public function runClassicEnrichmentPass(): void {
		$now = time();
		$this->loadClassicChecksumMap();
		$lastFullPass = (int)($this->settings->classicEnrichmentLastFullPassAt ?? 0);
		$fullRefresh = ($lastFullPass === 0) || ($lastFullPass < $now - self::FULL_REFRESH_INTERVAL_SECS);

		$rows = $fullRefresh ? $this->selectFullRefreshChunk() : $this->selectBackfillChunk();
		if (empty($rows)) {
			if ($fullRefresh) {
				// Reached the end of the full crawl: stamp it and drop back to backfill next pass.
				$this->settings->classicEnrichmentLastFullPassAt = $now;
				$this->settings->classicEnrichmentCursor = 0;
				$this->settings->update();
			}
			return;
		}

		$reindexIds = [];
		foreach ($rows as $row) {
			if ($this->processOneClassicIdentifier($row['identifierType'], $row['identifier'])) {
				$reindexIds[] = $row['identifier'];
			}
			if ($fullRefresh) {
				$this->settings->classicEnrichmentCursor = (int)$row['id'];
			}
		}
		if ($fullRefresh) {
			$this->settings->update();
		}
		if (!empty($reindexIds)) {
			$this->queueReindexForIdentifiers($reindexIds);
		}
	}

	/** Load (feedSource|identifierType|identifier) => rawChecksum for all syndetics_classic rows (checksum-skip). */
	private function loadClassicChecksumMap(): void {
		global $aspen_db;
		$this->checksumMap = [];
		$stmt = $aspen_db->prepare(
			"SELECT identifierType, identifier, rawChecksum FROM syndetics_indexing_data
			 WHERE syndeticsSettingId = ? AND feedSource = 'syndetics_classic'"
		);
		$stmt->execute([$this->settings->id]);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$this->checksumMap['syndetics_classic|' . $row['identifierType'] . '|' . $row['identifier']] = $row['rawChecksum'];
		}
	}

	/** Backfill: su_tags rows with no syndetics_classic companion yet. No cursor — written rows stop matching. */
	private function selectBackfillChunk(): array {
		global $aspen_db;
		$stmt = $aspen_db->prepare(
			"SELECT su.id, su.identifierType, su.identifier
			   FROM syndetics_indexing_data su
			   LEFT JOIN syndetics_indexing_data sc
			     ON  sc.syndeticsSettingId = su.syndeticsSettingId
			     AND sc.feedSource     = 'syndetics_classic'
			     AND sc.identifierType = su.identifierType
			     AND sc.identifier     = su.identifier
			  WHERE su.syndeticsSettingId = ?
			    AND su.feedSource = 'su_tags'
			    AND sc.id IS NULL
			  ORDER BY su.id ASC
			  LIMIT " . self::CLASSIC_CHUNK_SIZE
		);
		$stmt->execute([$this->settings->id]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/** Full refresh: walk ALL su_tags rows in id order, refreshing existing classic rows. */
	private function selectFullRefreshChunk(): array {
		global $aspen_db;
		$cursor = (int)($this->settings->classicEnrichmentCursor ?? 0);
		$stmt = $aspen_db->prepare(
			"SELECT id, identifierType, identifier
			   FROM syndetics_indexing_data
			  WHERE syndeticsSettingId = ? AND feedSource = 'su_tags' AND id > ?
			  ORDER BY id ASC
			  LIMIT " . self::CLASSIC_CHUNK_SIZE
		);
		$stmt->execute([$this->settings->id, $cursor]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Two probes (type= is a ProQuest account-entitlement code): xw10 INDEX.XML enumerates content types
	 * (SUMMARY/TOC/FICTION); rw12,hw7 index.xml enumerates the available *REVIEW sources. Returns availability
	 * flags + the available review-source list, or null if both probes failed.
	 */
	private function probeClassicAvailability(string $isbn, string $upc): ?array {
		$clientKey = $this->settings->syndeticsKey;
		$contentTransient = false;
		$contentXml = $this->fetchClassicXml('INDEX.XML', $isbn, $upc, $clientKey, 'xw10', $contentTransient);
		// Only retry (return null, no marker) when the content probe FAILED at the transport level. A definitive
		// 2xx "no data" response ($contentTransient === false, $contentXml === null) must fall through so the
		// caller writes the {} marker — otherwise a genuinely no-data ISBN is re-selected by backfill forever.
		if ($contentTransient) {
			return null;
		}
		// Reviews probe is best-effort: a transient reviews failure just yields no reviews this pass; the
		// 6-month full refresh re-fetches. It never blocks the marker (so it can't stall backfill).
		$reviewsXml = $this->fetchClassicXml('index.xml', $isbn, $upc, $clientKey, 'rw12,hw7');
		$availability = [
			'summary' => $contentXml !== null && isset($contentXml->SUMMARY),
			'toc'     => $contentXml !== null && isset($contentXml->TOC),
			'profile' => $contentXml !== null && isset($contentXml->FICTION),
			'reviews' => [],
		];
		if ($reviewsXml !== null) {
			foreach (self::CLASSIC_REVIEW_SOURCES as $tag => $info) {
				if (!empty($reviewsXml->xpath('//' . $tag))) {
					$availability['reviews'][] = $info;
				}
			}
		}
		return $availability;
	}

	/**
	 * Probe + fetch + consolidate all available classic content for one identifier, then upsert. ALWAYS
	 * writes a row (an empty {} marker if no data) so backfill marks the identifier "checked" and won't
	 * re-probe it every pass. Returns true if the cache row changed (caller queues a reindex).
	 */
	private function processOneClassicIdentifier(string $identifierType, string $identifier): bool {
		$isbn = $identifierType === 'isbn' ? $identifier : '';
		$upc  = $identifierType === 'upc' ? $identifier : '';
		$clientKey = $this->settings->syndeticsKey;

		$availability = $this->probeClassicAvailability($isbn, $upc);
		if ($availability === null) {
			// Content probe failed at the transport level (transient): skip without a marker so backfill
			// retries. A definitive no-data response is NOT null here — it returns all-false availability,
			// so we still write the {} marker below and backfill drains.
			$this->logEntry->numInvalidRecords++;
			return false;
		}

		$consolidated = [];
		if ($availability['summary']) {
			$summary = $this->fetchSummary($isbn, $upc, $clientKey);
			if ($summary !== null && $summary !== '') {
				$consolidated['summary'] = $summary;
			}
		}
		if ($availability['toc']) {
			$toc = $this->fetchTableOfContents($isbn, $upc, $clientKey);
			if (!empty($toc)) {
				$consolidated['toc'] = $toc;
			}
		}
		if ($availability['profile']) {
			$profile = $this->fetchBookProfile($isbn, $upc, $clientKey);
			if (!empty($profile)) {
				$consolidated['profile'] = $profile;
			}
		}
		if (!empty($availability['reviews'])) {
			$reviews = $this->fetchReviews($availability['reviews'], $isbn, $upc, $clientKey);
			if (!empty($reviews)) {
				$consolidated['reviews'] = $reviews;
			}
		}

		$payload = empty($consolidated) ? '{}' : json_encode($consolidated);
		return $this->upsertCacheRow('syndetics_classic', null, $identifierType, $identifier, $payload);
	}

	// The four fetchers replicate the URL + XML traversal from GoDeeperData / Reviews via fetchClassicXml().

	private function fetchSummary(string $isbn, string $upc, string $clientKey): ?string {
		// TODO(optimization): read-through SyndeticsData (the display cache) within its TTL to skip this HTTP
		// call, and write back after a fresh fetch to warm the display path. Deferred — fetching fresh is correct.
		$xml = $this->fetchClassicXml('SUMMARY.XML', $isbn, $upc, $clientKey, 'xw10');
		if ($xml !== null && isset($xml->VarFlds->VarDFlds->Notes->Fld520->a)) {
			return trim((string)$xml->VarFlds->VarDFlds->Notes->Fld520->a);
		}
		return null;
	}

	private function fetchTableOfContents(string $isbn, string $upc, string $clientKey): array {
		$xml = $this->fetchClassicXml('TOC.XML', $isbn, $upc, $clientKey, 'xw10');
		$toc = [];
		if ($xml !== null && isset($xml->VarFlds->VarDFlds->SSIFlds->Fld970)) {
			foreach ($xml->VarFlds->VarDFlds->SSIFlds->Fld970 as $field) {
				$toc[] = [
					'label' => (string)$field->l,
					'title' => (string)$field->t,
					'page'  => (string)$field->p,
				];
			}
		}
		return $toc;
	}

	private function fetchBookProfile(string $isbn, string $upc, string $clientKey): array {
		$xml = $this->fetchClassicXml('FICTION.XML', $isbn, $upc, $clientKey, 'xw10');
		$profile = [];
		if ($xml === null || !isset($xml->VarFlds->VarDFlds->SSIFlds)) {
			return $profile;
		}
		$ssi = $xml->VarFlds->VarDFlds->SSIFlds;
		if (isset($ssi->Fld920)) {
			foreach ($ssi->Fld920 as $f) {
				$profile['characters'][] = [
					'name'        => (string)$f->b,
					'gender'      => (string)$f->c,
					'age'         => (string)$f->d,
					'description' => (string)$f->f,
					'occupation'  => (string)$f->g,
				];
			}
		}
		if (isset($ssi->Fld950)) {
			foreach ($ssi->Fld950 as $f) {
				$profile['topics'][] = (string)$f->a;
			}
		}
		foreach (['Fld951', 'Fld952'] as $settingField) {
			if (isset($ssi->$settingField)) {
				foreach ($ssi->$settingField as $f) {
					$profile['settings'][] = isset($f->c) ? ((string)$f->a . ' -- ' . (string)$f->c) : (string)$f->a;
				}
			}
		}
		if (isset($ssi->Fld955)) {
			foreach ($ssi->Fld955 as $f) {
				$subGenres = [];
				if (isset($f->b)) {
					foreach ($f->b as $sub) {
						$subGenres[] = (string)$sub;
					}
				}
				$profile['genres'][] = ['name' => (string)$f->a, 'subGenres' => $subGenres];
			}
		}
		if (isset($ssi->Fld985)) {
			foreach ($ssi->Fld985 as $f) {
				$profile['awards'][] = ['name' => (string)$f->a, 'year' => (string)$f->y];
			}
		}
		return $profile;
	}

	private function fetchReviews(array $availableSources, string $isbn, string $upc, string $clientKey): array {
		$reviews = [];
		foreach ($availableSources as $info) {
			$xml = $this->fetchClassicXml($info['file'], $isbn, $upc, $clientKey, 'rw12,hw7');
			if ($xml === null) {
				continue;
			}
			$fld520 = $xml->xpath('//Fld520');
			if (empty($fld520)) {
				continue;
			}
			$text = trim(strip_tags($fld520[0]->asXML()));
			if ($text === '') {
				continue;
			}
			$reviews[] = [
				'source' => $info['title'],
				'text'   => $text,
				'date'   => null,   // the existing classic review parse (Reviews.php) does not expose a clean date.
			];
		}
		return $reviews;
	}

	/**
	 * Synchronous single-identifier reload for the admin "Reload single record" form. Re-runs the classic
	 * fetch path for one identifier and queues its reindex. The SU-tags side can't be re-fetched per
	 * identifier (the feed is a full snapshot), so only the classic side reloads here.
	 */
	public function reloadSingleIdentifier(string $identifierType, string $identifier): array {
		$this->loadClassicChecksumMap();
		$changed = $this->processOneClassicIdentifier($identifierType, $identifier);
		$this->queueReindexForIdentifiers([$identifier]);   // manual reload: refresh the work regardless.
		$this->logEntry->numProducts = 1;
		return ['touched_classic' => $changed ? 1 : 0, 'touched_su_tags' => 0];
	}

	// === Grouped-work reindex queueing ===

	public function findGroupedWorksForIdentifiers(array $identifiers): array {
		// SU identifiers are ISBN-13 / UPC-12 digits (optionally an ISBN-10 'X' check digit). Reject
		// anything else so we never interpolate Lucene-special characters into the query below.
		$identifiers = array_values(array_filter($identifiers, fn($id) => preg_match('/^[0-9Xx]+$/', (string)$id)));
		if (empty($identifiers)) {
			return [];
		}

		global $configArray, $solrScope, $searchSource, $activeLanguage;
		$savedSolrScope = $solrScope;
		$savedSearchSource = $searchSource;
		$savedActiveLanguage = $activeLanguage;
		$solrScope = null;
		$searchSource = null;
		$activeLanguage = null;
		require_once ROOT_DIR . '/sys/SystemVariables.php';
		try {
			$url = $configArray['Index']['url'];
			$systemVariables = SystemVariables::getSystemVariables();
			if ($systemVariables !== false && (int)$systemVariables->searchVersion === 2) {
				require_once ROOT_DIR . '/sys/SolrConnector/GroupedWorksSolrConnector2.php';
				$solr = new GroupedWorksSolrConnector2($url);
			} else {
				require_once ROOT_DIR . '/sys/SolrConnector/GroupedWorksSolrConnector.php';
				$solr = new GroupedWorksSolrConnector($url);
			}

			$solr->disableBoosting();
			$all = [];
			foreach (array_chunk($identifiers, 500) as $chunk) {
				$clauses = array_map(fn($id) => '(isbn:"' . $id . '" OR upc:"' . $id . '")', $chunk);
				$query = implode(' OR ', $clauses);
				// Positional args per Solr::search(): $query, $handler, $filter, $start, $limit, $facet,
				// $spell, $dictionary, $sort, $fields. $handler=null keeps it a raw Lucene query (no dismax
				// qf/field-boost); $fields='id' -> fl=id so we only pull the grouped-work permanent id.
				$result = $solr->search($query, null, null, 0, 100000, null, '', null, null, 'id');
				if (!empty($result['response']['docs'])) {
					foreach ($result['response']['docs'] as $doc) {
						$all[$doc['id']] = true;
					}
				}
			}
			return array_keys($all);
		} finally {
			$solrScope = $savedSolrScope;
			$searchSource = $savedSearchSource;
			$activeLanguage = $savedActiveLanguage;
		}
	}

	public function queueReindexForIdentifiers(array $identifiers): void {
		$permanentIds = $this->findGroupedWorksForIdentifiers($identifiers);
		if (empty($permanentIds)) {
			return;
		}
		require_once ROOT_DIR . '/sys/Grouping/GroupedWork.php';
		foreach ($permanentIds as $permanentId) {
			$gw = new GroupedWork();
			$gw->permanent_id = $permanentId;
			$gw->forceReindex();
		}
	}

	// === Cache upsert ===

	/**
	 * Upsert one cache row. rawResponse is stored MySQL-COMPRESS()'d; rawChecksum is the CRC32 of the
	 * UNCOMPRESSED payload so re-fetches compare like-for-like. Returns true if the row changed (caller
	 * queues a reindex), false if checksum-skipped. $suTagsFeedKind is 'seed'|'library' for su_tags rows,
	 * NULL for syndetics_classic. Increments the log entry's numAdded / numUpdated / numSkipped counters.
	 */
	public function upsertCacheRow(string $feedSource, ?string $suTagsFeedKind, string $identifierType, string $identifier, string $rawPayload, ?int $workcode = null): bool {
		global $aspen_db;
		// Unsigned decimal string so it round-trips through BIGINT rawChecksum and compares equal to the
		// values the cron loads back from the DB (which arrive as strings). A signed int, or a strict
		// int/string compare, would make checksum-skip silently never match.
		$checksum = sprintf('%u', crc32($rawPayload));
		$key = $feedSource . '|' . $identifierType . '|' . $identifier;
		$existed = array_key_exists($key, $this->checksumMap);
		if ($existed && (string)$this->checksumMap[$key] === $checksum) {
			// Unchanged content: bump lastFetched (the row stays "seen" this pass) but skip the expensive
			// rewrite + reindex. At seed scale the cron may batch these touches instead of per-row.
			$this->touchLastFetched($feedSource, $identifierType, $identifier);
			$this->logEntry->numSkipped++;
			return false;
		}
		$now = time();
		$stmt = $aspen_db->prepare(
			"INSERT INTO syndetics_indexing_data
				(syndeticsSettingId, feedSource, suTagsFeedKind, identifierType, identifier, workcode, rawChecksum, rawResponse, lastFetched, dateFirstDetected)
			 VALUES (?, ?, ?, ?, ?, ?, ?, COMPRESS(?), ?, ?)
			 ON DUPLICATE KEY UPDATE
				suTagsFeedKind = VALUES(suTagsFeedKind),
				workcode = VALUES(workcode),
				rawChecksum = VALUES(rawChecksum),
				rawResponse = VALUES(rawResponse),
				lastFetched = VALUES(lastFetched)"
		);
		$stmt->execute([
			$this->settings->id, $feedSource, $suTagsFeedKind, $identifierType, $identifier,
			$workcode, $checksum, $rawPayload, $now, $now,
		]);
		$this->checksumMap[$key] = $checksum;
		if ($existed) {
			$this->logEntry->numUpdated++;
		} else {
			$this->logEntry->numAdded++;
		}
		return true;
	}

	/** Bump lastFetched on a checksum-skipped row without rewriting its compressed payload. */
	private function touchLastFetched(string $feedSource, string $identifierType, string $identifier): void {
		global $aspen_db;
		if ($this->touchLastFetchedStmt === null) {
			$this->touchLastFetchedStmt = $aspen_db->prepare(
				"UPDATE syndetics_indexing_data SET lastFetched = ?
				 WHERE syndeticsSettingId = ? AND feedSource = ? AND identifierType = ? AND identifier = ?"
			);
		}
		$this->touchLastFetchedStmt->execute([time(), $this->settings->id, $feedSource, $identifierType, $identifier]);
	}
}
