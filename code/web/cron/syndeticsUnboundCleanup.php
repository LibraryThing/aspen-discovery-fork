<?php
/**
 * One-time Syndetics Unbound cache purge for a single settings row. Losing SU access (admin disables
 * indexing, or the feed returns subscription_revoked) only pauses indexing; it never auto-deletes cached
 * enrichment. This script is the deliberate, sysadmin-invoked purge: it physically removes the cache rows
 * and forces a nightly reindex so the su_*_<scopeId> Solr fields clear.
 *
 * First parameter  - server name
 * Second parameter - background process id (a placeholder id with no tracking row is fine for a bare CLI run)
 * Third parameter  - target syndetics_settings row id to purge
 * Optional flag    - --dry-run prints the row count and deletes nothing
 *
 * CLI: php cron/syndeticsUnboundCleanup.php <server> <bgId> <settingsId> [--dry-run]
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../bootstrap_aspen.php';

set_time_limit(0);

require_once ROOT_DIR . '/sys/Administration/BackgroundProcess.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsSetting.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsIndexingLogEntry.php';

global $logger, $aspen_db;

$dryRun = in_array('--dry-run', $_SERVER['argv'], true);

$backgroundProcess = null;
if ($argc > 2 && is_numeric($argv[2]) && (int)$argv[2] > 0) {
	$candidate = new BackgroundProcess();
	$candidate->id = (int)$argv[2];
	if ($candidate->find(true)) {
		if (!$candidate->isRunning) {
			$candidate->endProcess('Error, attempted to restart a completed background process');
			die();
		}
		$backgroundProcess = $candidate;
	}
}

// Same-cron guard: never let two purges run at once.
$running = new BackgroundProcess();
$running->name = 'syndeticsUnboundCleanup';
$running->isRunning = 1;
if ($running->count() > ($backgroundProcess !== null ? 1 : 0)) {
	if ($backgroundProcess !== null) {
		$backgroundProcess->endProcess('Another Syndetics Unbound cleanup is already running; exiting.');
	}
	return;
}

$settingsId = isset($argv[3]) && is_numeric($argv[3]) ? (int)$argv[3] : 0;
if ($settingsId <= 0) {
	$msg = 'Must provide a target syndetics_settings row id as the third argument.';
	echo $msg . "\n";
	if ($backgroundProcess !== null) {
		$backgroundProcess->endProcess($msg);
	}
	return;
}

$settings = new SyndeticsSetting();
$settings->id = $settingsId;
if (!$settings->find(true)) {
	$msg = "No SyndeticsSetting found for id $settingsId; nothing to purge.";
	echo $msg . "\n";
	if ($backgroundProcess !== null) {
		$backgroundProcess->endProcess($msg);
	}
	return;
}

$logEntry = new SyndeticsIndexingLogEntry();
$logEntry->syndeticsSettingId = $settingsId;
$logEntry->feedSource = 'cleanup';
$logEntry->startTime = time();
$logEntry->insert();

$count = (int)$aspen_db->query(
	"SELECT COUNT(*) FROM syndetics_indexing_data WHERE syndeticsSettingId = " . (int)$settingsId
)->fetchColumn();
$summary = "SyndeticsSetting #$settingsId (a_id {$settings->unboundAccountNumber}): $count cache rows" . ($dryRun ? ' would be deleted (dry run).' : ' to delete.');
echo $summary . "\n";
$logEntry->notes .= "\n" . $summary;

if ($dryRun) {
	$logEntry->endTime = time();
	$logEntry->update();
	if ($backgroundProcess !== null) {
		$backgroundProcess->addNote($summary);
		$backgroundProcess->endProcess('Dry run complete; no rows deleted.');
	}
	return;
}

$stmt = $aspen_db->prepare("DELETE FROM syndetics_indexing_data WHERE syndeticsSettingId = ?");
if (!$stmt->execute([$settingsId])) {
	$errInfo = implode(' ', $stmt->errorInfo());
	$logEntry->numErrors++;
	$logEntry->notes .= "\nDELETE failed ($errInfo); no rows removed and no reindex flagged.";
	$logEntry->endTime = time();
	$logEntry->update();
	$msg = "Cleanup DELETE failed for settings $settingsId; nothing purged.";
	echo $msg . "\n";
	if ($backgroundProcess !== null) {
		$backgroundProcess->endProcess($msg);
	}
	return;
}
$deleted = $stmt->rowCount();
require_once ROOT_DIR . '/sys/SystemVariables.php';
SystemVariables::forceNightlyIndex("SyndeticsSetting $settingsId: subscription cleanup");

$logEntry->numDeleted = $deleted;
$logEntry->notes .= "\nDeleted $deleted cache rows and flagged a nightly reindex to clear the Solr fields.";
$logEntry->endTime = time();
$logEntry->update();

// TODO(future): email the Syndetics Unbound admin contact when a purge completes (deleted tally + settings
// row) so the destructive cleanup is not silent; pair it with a confirmation email when cleanup is first
// triggered. Today the only surfaces are this log entry and, on revocation, the SU dashboard alert.
if ($backgroundProcess !== null) {
	$backgroundProcess->addNote("Purged $deleted Syndetics Unbound cache rows for settings $settingsId.");
	$backgroundProcess->endProcess('Completed Syndetics Unbound cache cleanup.');
}
