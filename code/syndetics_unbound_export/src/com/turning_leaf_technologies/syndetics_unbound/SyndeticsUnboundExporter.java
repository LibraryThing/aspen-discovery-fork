package com.turning_leaf_technologies.syndetics_unbound;

import com.turning_leaf_technologies.net.NetworkUtils;
import com.turning_leaf_technologies.net.WebServiceResponse;
import org.apache.logging.log4j.Logger;
import org.aspen_discovery.reindexer.GroupedWorkIndexer;
import org.ini4j.Ini;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.ArrayList;
import java.util.Date;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.zip.CRC32;

public class SyndeticsUnboundExporter {
	private static final String SU_API_BASE = "https://www.librarything.com/api_unbound.php";

	private static final int BATCH_SIZE = 500;
	private static final int FULL_PASS_DRAIN_INTERVAL = 1000;
	private static final long STALE_CUTOFF_BUFFER_SECONDS = 60 * 60;

	private static final int MAX_RETRIES_RATE_LIMIT = 5;
	private static final int MAX_RETRIES_TRANSIENT = 3;
	private static final long BACKOFF_TRANSIENT_MS = 120_000L;
	private static final long BACKOFF_RATE_LIMIT_INITIAL_MS = 1000L;
	private static final long BACKOFF_RATE_LIMIT_MAX_MS = 60_000L;

	private final String serverName;
	private final Connection aspenConn;
	private final Ini configIni;
	private final SyndeticsUnboundSettings settings;
	private final SyndeticsUnboundExportLogEntry logEntry;
	private final Logger logger;
	private final long passStartTime;
	private GroupedWorkIndexer groupedWorkIndexer;

	public SyndeticsUnboundExporter(String serverName, Connection aspenConn, Ini configIni, SyndeticsUnboundSettings settings, SyndeticsUnboundExportLogEntry logEntry, Logger logger) {
		this.serverName = serverName;
		this.aspenConn = aspenConn;
		this.configIni = configIni;
		this.settings = settings;
		this.logEntry = logEntry;
		this.logger = logger;
		this.passStartTime = new Date().getTime() / 1000;
	}

	public boolean exportSyndeticsUnboundData() {
		if (!settings.isIndexingEnabled()) {
			return runCleanupIfNeeded();
		}

		if (!validateCredentials()) {
			return false;
		}

		boolean fullSnapshot = settings.isRunFullUpdate();
		logEntry.addNote(fullSnapshot ? "Starting full snapshot pass" : "Starting incremental pass since " + settings.getLastUpdateOfChangedRecords());
		logEntry.saveResults();

		HashMap<String, Long> existingChecksums;
		try {
			existingChecksums = loadExistingChecksums();
		} catch (SQLException e) {
			logEntry.incErrors("Could not load existing checksums", e);
			return false;
		}
		logger.info("Loaded " + existingChecksums.size() + " existing checksums");

		HashSet<String> changedGroupedWorks = new HashSet<>();
		int numProcessed = 0;
		String cursor = null;
		boolean firstBatch = true;

		do {
			JSONObject response = fetchBatch(fullSnapshot, cursor);
			if (response == null) {
				// fetchBatch already logged the error. Abort the pass — cursor preserved.
				return numProcessed > 0;
			}
			if (firstBatch) {
				if (response.has("totalEstimated")) {
					logEntry.incNumProducts(response.getInt("totalEstimated"));
					logEntry.saveResults();
				}
				firstBatch = false;
			}
			JSONArray records = response.optJSONArray("records");
			if (records != null) {
				for (int i = 0; i < records.length(); i++) {
					JSONObject record = records.getJSONObject(i);
					String changedKey = processRecord(record, existingChecksums);
					numProcessed++;
					if (changedKey != null) {
						int colonIdx = changedKey.indexOf(':');
						String idType = changedKey.substring(0, colonIdx);
						String idValue = changedKey.substring(colonIdx + 1);
						for (String groupedWorkId : findGroupedWorksForIdentifier(idType, idValue)) {
							changedGroupedWorks.add(groupedWorkId);
						}
					}
					if (fullSnapshot && changedGroupedWorks.size() >= FULL_PASS_DRAIN_INTERVAL) {
						drainReindexQueue(changedGroupedWorks);
					}
					if (numProcessed % 100 == 0) {
						logEntry.saveResults();
					}
				}
			}
			cursor = response.optString("nextCursor", null);
		} while (cursor != null && !cursor.isEmpty());

		drainReindexQueue(changedGroupedWorks);

		logEntry.addNote("Processed " + numProcessed + " records");

		if (!logEntry.hasErrors()) {
			if (fullSnapshot) {
				runStalePurge();
			}
			if (!logEntry.hasErrors()) {
				updateCursorOnSuccess(fullSnapshot);
			}
		}

		return numProcessed > 0;
	}

	public void exporterCleanUp() {
		if (groupedWorkIndexer != null) {
			groupedWorkIndexer.finishIndexingFromExtract(logEntry);
			groupedWorkIndexer.close();
			groupedWorkIndexer = null;
		}
	}

	private GroupedWorkIndexer getGroupedWorkIndexer() {
		if (groupedWorkIndexer == null) {
			groupedWorkIndexer = new GroupedWorkIndexer(serverName, aspenConn, configIni, false, false, logEntry, logger);
		}
		return groupedWorkIndexer;
	}

	/**
	 * Validates that the settings row has usable credentials before any HTTP call.
	 * Returns true if credentials are present; false (with logged error + log-entry note)
	 * if syndeticsKey is blank or unboundAccountNumber is non-positive.
	 */
	private boolean validateCredentials() {
		String key = settings.getSyndeticsKey();
		if (key == null || key.trim().isEmpty()) {
			logEntry.incErrors("SU exporter aborting: syndeticsKey is blank for settings row " + settings.getSettingsId());
			return false;
		}
		if (settings.getUnboundAccountNumber() <= 0) {
			logEntry.incErrors("SU exporter aborting: unboundAccountNumber is <= 0 for settings row " + settings.getSettingsId());
			return false;
		}
		return true;
	}

	/**
	 * Loads (identifierType, identifier) -> rawChecksum from syndetics_indexing_data
	 * for this setting at pass start. Subsequent per-record lookups are O(1) against
	 * this map.
	 */
	private HashMap<String, Long> loadExistingChecksums() throws SQLException {
		HashMap<String, Long> checksumMap = new HashMap<>();
		PreparedStatement stmt = aspenConn.prepareStatement(
				"SELECT identifierType, identifier, rawChecksum FROM syndetics_indexing_data WHERE syndeticsSettingId = ?");
		stmt.setLong(1, settings.getSettingsId());
		ResultSet rs = stmt.executeQuery();
		while (rs.next()) {
			long rawChecksum = rs.getLong("rawChecksum");
			if (rs.wasNull()) {
				continue;
			}
			String key = rs.getString("identifierType") + ":" + rs.getString("identifier");
			checksumMap.put(key, rawChecksum);
		}
		rs.close();
		stmt.close();
		return checksumMap;
	}

	private static long computeChecksum(String payload) {
		CRC32 crc = new CRC32();
		crc.update(payload.getBytes(StandardCharsets.UTF_8));
		return crc.getValue();
	}

	/**
	 * Fetches a batch with retry on transient errors. Retry policy differs by HTTP code:
	 * 429 → exponential backoff (1s, 2s, 4s, 8s, 16s; capped at 60s), up to 5 retries.
	 * 503/504 → fixed 2-minute backoff, up to 3 retries.
	 * Persistent failures return null. Pagination: pass nextCursor from the previous response; null for first call.
	 */
	private JSONObject fetchBatch(boolean fullSnapshot, String cursor) {
		int retries = 0;
		while (true) {
			JSONObject result = fetchBatchOnce(fullSnapshot, cursor);
			if (result != null) {
				return result;
			}
			if (!shouldRetry()) {
				return null;
			}
			int maxRetries = (lastResponseCode == 429) ? MAX_RETRIES_RATE_LIMIT : MAX_RETRIES_TRANSIENT;
			if (retries >= maxRetries) {
				logEntry.incErrors("Retry exhausted on transient error (HTTP " + lastResponseCode + ") after " + maxRetries + " retries");
				return null;
			}
			long backoffMs = (lastResponseCode == 429)
					? Math.min(BACKOFF_RATE_LIMIT_INITIAL_MS * (1L << retries), BACKOFF_RATE_LIMIT_MAX_MS)
					: BACKOFF_TRANSIENT_MS;
			retries++;
			logEntry.addNote("Retrying fetch after HTTP " + lastResponseCode + " (retry " + retries + "/" + maxRetries + ", waiting " + backoffMs + "ms)");
			try {
				Thread.sleep(backoffMs);
			} catch (InterruptedException e) {
				logEntry.addNote("Fetch retry interrupted; pass aborting");
				Thread.currentThread().interrupt();
				return null;
			}
		}
	}

	private int lastResponseCode = 200;

	private boolean shouldRetry() {
		return lastResponseCode == 429 || lastResponseCode == 503 || lastResponseCode == 504;
	}

	private JSONObject fetchBatchOnce(boolean fullSnapshot, String cursor) {
		StringBuilder url = new StringBuilder(SU_API_BASE);
		url.append("?syndeticsKey=").append(URLEncoder.encode(settings.getSyndeticsKey(), StandardCharsets.UTF_8));
		url.append("&a_id=").append(settings.getUnboundAccountNumber());
		url.append("&limit=").append(BATCH_SIZE);
		if (!fullSnapshot) {
			url.append("&since=").append(settings.getLastUpdateOfChangedRecords());
		}
		if (cursor != null) {
			url.append("&cursor=").append(URLEncoder.encode(cursor, StandardCharsets.UTF_8));
		}
		HashMap<String, String> headers = new HashMap<>();
		headers.put("Accept", "application/json");

		WebServiceResponse response = NetworkUtils.getURL(url.toString(), logger, headers);
		lastResponseCode = response.getResponseCode();
		if (!response.isSuccess()) {
			int code = response.getResponseCode();
			if (code == 401 || code == 403) {
				handleAuthFailure(response);
			} else if (code == 429) {
				logger.warn("Rate-limited by SU API (HTTP 429)");
			} else if (code == 503 || code == 504) {
				logger.warn("Transient SU API error " + code);
			} else {
				logEntry.incErrors("Persistent SU API error " + code + ": " + response.getMessage());
			}
			return null;
		}
		try {
			return new JSONObject(response.getMessage());
		} catch (JSONException e) {
			String body = response.getMessage();
			String snippet = body == null ? "(null)" : body.substring(0, Math.min(200, body.length()));
			logEntry.incErrors("Malformed JSON from SU API: " + snippet, e);
			return null;
		}
	}

	/**
	 * Processes a single record from the API response. Returns the identifier
	 * pair "type:value" if the record was changed (and a reindex should be queued),
	 * or null if the record was unchanged (checksum match) or skipped.
	 */
	private String processRecord(JSONObject record, HashMap<String, Long> existingChecksums) {
		try {
			String identifierType = record.getString("identifierType");
			identifierType = identifierType.toLowerCase();
			String rawIdentifier = record.getString("identifier");
			String identifier = normalizeIdentifier(identifierType, rawIdentifier);
			if (identifier == null) {
				logEntry.incInvalidRecords(identifierType + ":" + rawIdentifier);
				return null;
			}

			String rawJson = record.toString();
			long checksum = computeChecksum(rawJson);
			String key = identifierType + ":" + identifier;
			Long existing = existingChecksums.get(key);

			if (existing != null && existing == checksum) {
				PreparedStatement bumpStmt = aspenConn.prepareStatement(
						"UPDATE syndetics_indexing_data SET lastFetched = ? WHERE syndeticsSettingId = ? AND identifierType = ? AND identifier = ?");
				bumpStmt.setLong(1, passStartTime);
				bumpStmt.setLong(2, settings.getSettingsId());
				bumpStmt.setString(3, identifierType);
				bumpStmt.setString(4, identifier);
				bumpStmt.executeUpdate();
				bumpStmt.close();
				logEntry.incSkipped();
				return null;
			}

			long workcode = record.optLong("workcode", 0);
			boolean isNew = (existing == null);

			PreparedStatement upsertStmt = aspenConn.prepareStatement(
					"INSERT INTO syndetics_indexing_data " +
					"(syndeticsSettingId, identifierType, identifier, workcode, rawChecksum, rawResponse, lastFetched, dateFirstDetected) " +
					"VALUES (?, ?, ?, ?, ?, COMPRESS(?), ?, ?) " +
					"ON DUPLICATE KEY UPDATE workcode = VALUES(workcode), rawChecksum = VALUES(rawChecksum), " +
					"rawResponse = VALUES(rawResponse), lastFetched = VALUES(lastFetched)");
			upsertStmt.setLong(1, settings.getSettingsId());
			upsertStmt.setString(2, identifierType);
			upsertStmt.setString(3, identifier);
			if (workcode > 0) {
				upsertStmt.setLong(4, workcode);
			} else {
				upsertStmt.setNull(4, java.sql.Types.BIGINT);
			}
			upsertStmt.setLong(5, checksum);
			upsertStmt.setString(6, rawJson);
			upsertStmt.setLong(7, passStartTime);
			upsertStmt.setLong(8, passStartTime);
			upsertStmt.executeUpdate();
			upsertStmt.close();

			existingChecksums.put(key, checksum);

			if (isNew) {
				logEntry.incAdded();
			} else {
				logEntry.incUpdated();
			}
			return key;
		} catch (JSONException e) {
			logEntry.incInvalidRecords("(malformed SU record)");
			logger.warn("Malformed SU record", e);
			return null;
		} catch (SQLException e) {
			logEntry.incErrors("Database error processing SU record", e);
			return null;
		}
	}

	/**
	 * Normalizes ISBN/UPC per spec: ISBN-10 is converted to ISBN-13; UPC strips non-digits.
	 * Returns null if the identifier is unrecognized or invalid.
	 */
	private static String normalizeIdentifier(String type, String raw) {
		if (raw == null || raw.isEmpty()) {
			return null;
		}
		if ("isbn".equalsIgnoreCase(type)) {
			String trimmed = raw.toUpperCase().replaceAll("[^0-9X]", "");
			if (trimmed.length() == 13) {
				return trimmed;
			}
			if (trimmed.length() == 10) {
				return convertISBN10to13(trimmed);
			}
			return null;
		} else if ("upc".equalsIgnoreCase(type)) {
			String normalized = raw.replaceAll("[^0-9]", "");
			return normalized.isEmpty() ? null : normalized;
		}
		return null;
	}

	private static String convertISBN10to13(String isbn10) {
		if (isbn10.length() != 10) {
			return null;
		}
		String isbnWithoutCheckDigit = isbn10.substring(0, 9);
		if (!isbnWithoutCheckDigit.matches("\\d+")) {
			return null;
		}
		String isbn = "978" + isbnWithoutCheckDigit;
		int sumOfDigits = 0;
		for (int i = 0; i < 12; i++) {
			int multiplier = 1;
			if (i % 2 == 1) {
				multiplier = 3;
			}
			int curDigit = Integer.parseInt(Character.toString(isbn.charAt(i)));
			sumOfDigits += multiplier * curDigit;
		}
		int modValue = sumOfDigits % 10;
		int checksumDigit;
		if (modValue == 0) {
			checksumDigit = 0;
		} else {
			checksumDigit = 10 - modValue;
		}
		return isbn + checksumDigit;
	}

	/**
	 * Finds grouped works that contain the given identifier as a primary identifier.
	 * Used to queue reindex on the affected grouped works.
	 */
	private List<String> findGroupedWorksForIdentifier(String identifierType, String identifier) {
		List<String> result = new ArrayList<>();
		if (identifier == null) {
			return result;
		}
		try {
			PreparedStatement stmt = aspenConn.prepareStatement(
					"SELECT DISTINCT gw.permanent_id FROM grouped_work gw " +
					"JOIN grouped_work_primary_identifiers gwpi ON gw.id = gwpi.grouped_work_id " +
					"WHERE gwpi.type = ? AND gwpi.identifier = ?");
			stmt.setString(1, identifierType);
			stmt.setString(2, identifier);
			ResultSet rs = stmt.executeQuery();
			while (rs.next()) {
				result.add(rs.getString("permanent_id"));
			}
			rs.close();
			stmt.close();
		} catch (SQLException e) {
			logEntry.incErrors("Could not resolve grouped works for " + identifierType + ":" + identifier, e);
		}
		return result;
	}

	/**
	 * Drains the reindex queue: processes each unique grouped work ID and commits to Solr.
	 */
	private void drainReindexQueue(HashSet<String> queue) {
		if (queue.isEmpty()) {
			return;
		}
		GroupedWorkIndexer indexer = getGroupedWorkIndexer();
		for (String groupedWorkId : queue) {
			indexer.processGroupedWork(groupedWorkId);
		}
		indexer.commitChanges();
		queue.clear();
	}

	private void updateCursorOnSuccess(boolean fullSnapshot) {
		try {
			String sql = fullSnapshot
					? "UPDATE syndetics_settings SET lastUpdateOfAllRecords = ?, lastUpdateOfChangedRecords = ?, runFullUpdate = 0 WHERE id = ?"
					: "UPDATE syndetics_settings SET lastUpdateOfChangedRecords = ? WHERE id = ?";
			PreparedStatement stmt = aspenConn.prepareStatement(sql);
			stmt.setLong(1, passStartTime);
			if (fullSnapshot) {
				stmt.setLong(2, passStartTime);
				stmt.setLong(3, settings.getSettingsId());
			} else {
				stmt.setLong(2, settings.getSettingsId());
			}
			stmt.executeUpdate();
			stmt.close();
		} catch (SQLException e) {
			logEntry.incErrors("Could not update cursor on success", e);
		}
	}

	/**
	 * Stale row purge: only runs at the end of a CLEAN full-snapshot pass.
	 * Deletes any cache rows whose lastFetched is older than passStartTime - 1 hour buffer.
	 * Each affected grouped work gets reindexed to clear stale enrichment.
	 */
	private void runStalePurge() {
		long staleCutoff = passStartTime - STALE_CUTOFF_BUFFER_SECONDS;
		HashSet<String> reindexQueue = new HashSet<>();
		try {
			PreparedStatement findStmt = aspenConn.prepareStatement(
					"SELECT identifierType, identifier FROM syndetics_indexing_data WHERE syndeticsSettingId = ? AND lastFetched < ?");
			findStmt.setLong(1, settings.getSettingsId());
			findStmt.setLong(2, staleCutoff);
			ResultSet rs = findStmt.executeQuery();
			int staleCount = 0;
			while (rs.next()) {
				String type = rs.getString("identifierType");
				String id = rs.getString("identifier");
				reindexQueue.addAll(findGroupedWorksForIdentifier(type, id));
				staleCount++;
			}
			rs.close();
			findStmt.close();

			if (staleCount == 0) {
				logEntry.addNote("Stale purge: no stale rows found");
				return;
			}

			if (logEntry.hasErrors()) {
				logEntry.addNote("Stale purge: aborting before delete — grouped-work resolution failed for one or more identifiers; next pass will retry");
				return;
			}

			PreparedStatement deleteStmt = aspenConn.prepareStatement(
					"DELETE FROM syndetics_indexing_data WHERE syndeticsSettingId = ? AND lastFetched < ?");
			deleteStmt.setLong(1, settings.getSettingsId());
			deleteStmt.setLong(2, staleCutoff);
			int deleted = deleteStmt.executeUpdate();
			deleteStmt.close();

			for (int i = 0; i < deleted; i++) {
				logEntry.incDeleted();
			}
			logEntry.addNote("Stale purge: deleted " + deleted + " rows, reindexing " + reindexQueue.size() + " grouped works");
			drainReindexQueue(reindexQueue);
		} catch (SQLException e) {
			logEntry.incErrors("Error during stale row purge", e);
		}
	}

	/**
	 * Called at pass start when indexingEnabled = 0. Checks whether there is residual
	 * cache data to clear (admin recently flipped the flag off) and runs cleanup if so.
	 * Returns true if cleanup ran, false if there was nothing to do.
	 */
	private boolean runCleanupIfNeeded() {
		try {
			PreparedStatement countStmt = aspenConn.prepareStatement(
					"SELECT COUNT(*) FROM syndetics_indexing_data WHERE syndeticsSettingId = ?");
			countStmt.setLong(1, settings.getSettingsId());
			ResultSet rs = countStmt.executeQuery();
			int rowCount = 0;
			if (rs.next()) {
				rowCount = rs.getInt(1);
			}
			rs.close();
			countStmt.close();

			if (rowCount == 0) {
				return false;
			}

			logEntry.addNote("indexingEnabled = 0, found " + rowCount + " stale cache rows — running cleanup");
			logEntry.saveResults();
			runCleanup();
			return true;
		} catch (SQLException e) {
			logEntry.incErrors("Error checking for cleanup-needed state", e);
			return false;
		}
	}

	private boolean revocationDetected = false;

	/**
	 * On 401/403, check for the explicit revocation marker in the response body.
	 * If present, trigger immediate cleanup. If absent, treat as generic auth failure
	 * and preserve the cache pending admin investigation. Revocation itself is a
	 * legitimate terminal signal (not an operational error) and is logged via addNote.
	 */
	private void handleAuthFailure(WebServiceResponse response) {
		String body = response.getMessage();
		if (body != null) {
			try {
				JSONObject errBody = new JSONObject(body);
				if (errBody.has("error") && "subscription_revoked".equals(errBody.getString("error"))) {
					logEntry.addNote("SU API returned subscription_revoked — cleaning up cache");
					revocationDetected = true;
					runCleanup();
					return;
				}
			} catch (JSONException ignore) {
			}
		}
		logEntry.incErrors("SU API auth failure (HTTP " + response.getResponseCode() + "). Credentials may be invalid; cache preserved pending admin investigation.");
	}

	/**
	 * Deletes all cache rows for this settings ID and queues affected grouped works
	 * for reindex. When triggered by revocation, also flips indexingEnabled to 0
	 * to stop further fetch attempts against revoked credentials.
	 */
	private void runCleanup() {
		HashSet<String> reindexQueue = new HashSet<>();
		try {
			PreparedStatement findStmt = aspenConn.prepareStatement(
					"SELECT identifierType, identifier FROM syndetics_indexing_data WHERE syndeticsSettingId = ?");
			findStmt.setLong(1, settings.getSettingsId());
			ResultSet rs = findStmt.executeQuery();
			while (rs.next()) {
				reindexQueue.addAll(findGroupedWorksForIdentifier(rs.getString("identifierType"), rs.getString("identifier")));
			}
			rs.close();
			findStmt.close();

			if (logEntry.hasErrors()) {
				logEntry.addNote("Cleanup: aborting before delete — grouped-work resolution failed for one or more identifiers; next pass will retry");
				return;
			}

			PreparedStatement deleteStmt = aspenConn.prepareStatement(
					"DELETE FROM syndetics_indexing_data WHERE syndeticsSettingId = ?");
			deleteStmt.setLong(1, settings.getSettingsId());
			int deleted = deleteStmt.executeUpdate();
			deleteStmt.close();

			for (int i = 0; i < deleted; i++) {
				logEntry.incDeleted();
			}
			logEntry.addNote("Cleanup: deleted " + deleted + " rows, reindexing " + reindexQueue.size() + " grouped works");

			if (revocationDetected) {
				PreparedStatement disableStmt = aspenConn.prepareStatement(
						"UPDATE syndetics_settings SET indexingEnabled = 0 WHERE id = ?");
				disableStmt.setLong(1, settings.getSettingsId());
				disableStmt.executeUpdate();
				disableStmt.close();
				logEntry.addNote("Set indexingEnabled = 0 on settings row " + settings.getSettingsId() + " due to subscription_revoked signal");
			}

			drainReindexQueue(reindexQueue);
		} catch (SQLException e) {
			logEntry.incErrors("Error during cleanup", e);
		}
	}
}
