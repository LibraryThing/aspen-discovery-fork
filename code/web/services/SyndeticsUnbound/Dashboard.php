<?php

require_once ROOT_DIR . '/services/Admin/Admin.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsSetting.php';

class SyndeticsUnbound_Dashboard extends Admin_Admin {
	// Reload feed key => the settings-row columns to NULL so the next cron pass restarts that feed. Both the
	// version/cursor AND the eligibility timestamp must clear, since each cron gates on the timestamp first.
	const RELOAD_COLUMNS = [
		'seed' => ['lastSeenSuTagsSeedVersion', 'lastSeenSuTagsSeedFetchedAt'],
		'library' => ['lastSeenSuTagsLibraryVersion', 'lastSeenSuTagsLibraryFetchedAt'],
		'classic' => ['classicEnrichmentCursor', 'classicEnrichmentLastFullPassAt'],
	];

	function launch(): void {
		global $interface;

		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['reloadFeed']) && !empty($_POST['settingsId'])) {
			$this->handleReload((int)$_POST['settingsId'], (string)$_POST['reloadFeed']);
		}

		$settingsRows = [];
		$settings = new SyndeticsSetting();
		$settings->syndeticsUnbound = 1;
		$settings->indexingEnabled = 1;
		$settings->orderBy('id');
		$settings->find();
		while ($settings->fetch()) {
			$settingsRows[] = [
				'id' => $settings->id,
				'unboundAccountNumber' => $settings->unboundAccountNumber,
				'seedVersion' => $settings->lastSeenSuTagsSeedVersion,
				'seedFetchedAt' => $settings->lastSeenSuTagsSeedFetchedAt,
				'libraryVersion' => $settings->lastSeenSuTagsLibraryVersion,
				'libraryFetchedAt' => $settings->lastSeenSuTagsLibraryFetchedAt,
				'classicCursor' => $settings->classicEnrichmentCursor,
				'classicLastFullPassAt' => $settings->classicEnrichmentLastFullPassAt,
				'tagsLog' => $this->latestLog($settings->id, 'su_tags'),
				'classicLog' => $this->latestLog($settings->id, 'syndetics_classic'),
			];
		}
		$interface->assign('settingsRows', $settingsRows);

		$this->display('dashboard.tpl', 'Syndetics Unbound Dashboard');
	}

	private function latestLog(int $settingsId, string $feedSource): ?array {
		global $aspen_db;
		$stmt = $aspen_db->prepare(
			"SELECT startTime, endTime, numErrors, numProducts FROM syndetics_indexing_log
			 WHERE syndeticsSettingId = ? AND feedSource = ? ORDER BY startTime DESC LIMIT 1"
		);
		$stmt->execute([$settingsId, $feedSource]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	private function handleReload(int $settingsId, string $feed): void {
		global $interface, $aspen_db;
		if (!isset(self::RELOAD_COLUMNS[$feed]) || $settingsId <= 0) {
			$interface->assign('reloadError', 'Invalid reload request.');
			return;
		}
		// Per-session rate limit: at most one reload per minute per (settings row, feed).
		$key = $settingsId . ':' . $feed;
		$now = time();
		if (!isset($_SESSION['suReloadTimes'])) {
			$_SESSION['suReloadTimes'] = [];
		}
		if (isset($_SESSION['suReloadTimes'][$key]) && ($now - $_SESSION['suReloadTimes'][$key]) < 60) {
			$interface->assign('reloadError', 'Please wait at least a minute between reloads of the same feed.');
			return;
		}
		// Only act on a row the dashboard actually manages: an active SU indexing settings row.
		$check = $aspen_db->prepare("SELECT id FROM syndetics_settings WHERE id = ? AND syndeticsUnbound = 1 AND indexingEnabled = 1");
		$check->execute([$settingsId]);
		if ($check->fetchColumn() === false) {
			$interface->assign('reloadError', 'No active Syndetics Unbound settings row matched that reload request.');
			return;
		}
		$setClause = implode(' = NULL, ', self::RELOAD_COLUMNS[$feed]) . ' = NULL';
		$stmt = $aspen_db->prepare("UPDATE syndetics_settings SET $setClause WHERE id = ?");
		$stmt->execute([$settingsId]);
		$_SESSION['suReloadTimes'][$key] = $now;
		$interface->assign('reloadMessage', "Queued a reload of the $feed feed for settings $settingsId; it re-fetches on the next cron pass.");
	}

	function getBreadcrumbs(): array {
		$breadcrumbs = [];
		$breadcrumbs[] = new Breadcrumb('/Admin/Home', 'Administration Home');
		$breadcrumbs[] = new Breadcrumb('/Admin/Home#third_party_enrichment', 'Syndetics Unbound');
		$breadcrumbs[] = new Breadcrumb('', 'Dashboard');
		return $breadcrumbs;
	}

	function canView(): bool {
		return UserAccount::userHasPermission('Administer Third Party Enrichment API Keys');
	}

	function getActiveAdminSection(): string {
		return 'third_party_enrichment';
	}
}
