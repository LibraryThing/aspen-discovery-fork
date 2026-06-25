<?php
/**
 * Classic Syndetics enrichment cron — fetches summary / TOC / book profile / professional reviews from the
 * classic Syndetics (ProQuest) XML endpoints for identifiers Syndetics Unbound has already tagged, and writes
 * them to the syndetics_indexing_data cache (feedSource='syndetics_classic'). One-shot Aspen BackgroundProcess
 * (mirrors syndeticsUnboundTagsBackground.php).
 *
 * First parameter  - server name
 * Second parameter - background process id (optional)
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
// tracking id) are covered too: a long pass must never overlap the next scheduled */5 run. flock auto-releases
// when this process exits. Scoped per server + per cron so other sites and the SU-tags cron are unaffected.
$lockName = 'aspen_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($argv[1] ?? 'server')) . '_syndeticsClassicEnrichmentBackground.lock';
$lockHandle = fopen(sys_get_temp_dir() . '/' . $lockName, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
	echo "Another classic enrichment pass is already running; exiting.\n";
	if ($backgroundProcess !== null) {
		$backgroundProcess->endProcess('Another classic enrichment pass is already running; exiting.');
	}
	return;
}

$settings = new SyndeticsSetting();
$settings->indexingEnabled = 1;
$settings->syndeticsUnbound = 1;
$settings->find();
while ($settings->fetch()) {
	if (empty($settings->syndeticsKey) || $settings->unboundAccountNumber <= 0) {
		continue;
	}
	$logEntry = new SyndeticsIndexingLogEntry();
	$logEntry->syndeticsSettingId = $settings->id;
	$logEntry->feedSource = 'syndetics_classic';
	$logEntry->startTime = time();
	$logEntry->insert();

	// Clone so the extractor's settings->update() (full-refresh cursor) doesn't mutate the fetch() iterator.
	$extractor = new SyndeticsIndexingExtractor(clone $settings, $logEntry);
	$extractor->runClassicEnrichmentPass();

	$logEntry->endTime = time();
	$logEntry->update();

	if ($backgroundProcess !== null) {
		$backgroundProcess->addNote("Classic enrichment pass complete for settings {$settings->id}");
	}
}

if ($backgroundProcess !== null) {
	$backgroundProcess->endProcess('Completed classic enrichment pass');
}
