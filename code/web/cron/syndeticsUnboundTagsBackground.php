<?php
/**
 * Syndetics Unbound tags cron — fetches the SU tag feeds (shared seed + per-library) and writes
 * them to the syndetics_indexing_data cache for the reindexer processor to read. One-shot Aspen
 * BackgroundProcess (modeled on talpaRecalculationBackground.php).
 *
 * First parameter  - server name
 * Second parameter - background process id (optional; present on the admin "Run now" path)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../bootstrap_aspen.php';

set_time_limit(0);

require_once ROOT_DIR . '/sys/Administration/BackgroundProcess.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsSetting.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsIndexingLogEntry.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsIndexingExtractor.php';

global $logger;

$backgroundProcess = null;
if ($argc > 2) {
	$backgroundProcess = new BackgroundProcess();
	$backgroundProcess->id = $argv[2];
	if (!$backgroundProcess->find(true)) {
		echo "Could not find the specified background process\n";
		die();
	}
	if (!$backgroundProcess->isRunning) {
		$backgroundProcess->endProcess('Error, attempted to restart a completed background process');
		die();
	}
}

// Cross-invocation lock, independent of admin BackgroundProcess rows so direct crontab runs (which pass no
// tracking id) are covered too: a seed download/parse can run long and must never overlap the next run. flock
// auto-releases when this process exits. Scoped per server + per cron so other sites and the classic cron are unaffected.
$lockName = 'aspen_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($argv[1] ?? 'server')) . '_syndeticsUnboundTagsBackground.lock';
$lockHandle = fopen(sys_get_temp_dir() . '/' . $lockName, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
	echo "Another SU-tags pass is already running; exiting.\n";
	if ($backgroundProcess !== null) {
		$backgroundProcess->endProcess('Another SU-tags pass is already running; exiting.');
	}
	return;
}

$settings = new SyndeticsSetting();
$settings->indexingEnabled = 1;
$settings->syndeticsUnbound = 1;
$settings->find();
while ($settings->fetch()) {
	if (empty($settings->syndeticsUnboundFeedToken) || $settings->unboundAccountNumber <= 0) {
		continue;
	}
	$logEntry = new SyndeticsIndexingLogEntry();
	$logEntry->syndeticsSettingId = $settings->id;
	$logEntry->feedSource = 'su_tags';
	$logEntry->startTime = time();
	$logEntry->insert();

	// Clone so the extractor's settings->update() (cursor persistence / revocation pause) doesn't
	// mutate the object the fetch() loop is iterating.
	$extractor = new SyndeticsIndexingExtractor(clone $settings, $logEntry);
	$extractor->runSuTagsPass();

	$logEntry->endTime = time();
	$logEntry->update();

	if ($backgroundProcess !== null) {
		$backgroundProcess->addNote("SU-tags pass complete for settings {$settings->id}");
	}
}

if ($backgroundProcess !== null) {
	$backgroundProcess->endProcess('Completed SU-tags pass');
}
