<?php

if (count($_SERVER['argv']) > 1) {
	$serverName = $_SERVER['argv'][1];
	// Check to see if the update already exists properly.
	$fhnd = fopen('/usr/local/aspen-discovery/sites/' . $serverName . '/conf/crontab_settings.txt', 'r');
	if ($fhnd) {
		$lines = [];
		$insertSyndeticsUnboundTags = true;
		$syndeticsUnboundTagsInserted = false;
		$insertSyndeticsClassicEnrichment = true;
		$syndeticsClassicEnrichmentInserted = false;
		while (($line = fgets($fhnd)) !== false) {
			// Detect if the cron job is already present.
			if (str_contains($line, 'syndeticsUnboundTagsBackground.php')) {
				$insertSyndeticsUnboundTags = false;
			}
			if (str_contains($line, 'syndeticsClassicEnrichmentBackground.php')) {
				$insertSyndeticsClassicEnrichment = false;
			}
			// Insert before Debian end-of-file marker.
			if ($insertSyndeticsUnboundTags && str_contains($line, 'Debian needs a blank line at the end of cron')) {
				if (!empty($lines) && trim(end($lines)) !== '') {
					$lines[] = "\n";
				}
				$lines[] = "###############################################\n";
				$lines[] = "# Syndetics Unbound tags indexing feed         #\n";
				$lines[] = "###############################################\n";
				$lines[] = "0 * * * * root php /usr/local/aspen-discovery/code/web/cron/syndeticsUnboundTagsBackground.php $serverName\n";
				$lines[] = "\n";
				$syndeticsUnboundTagsInserted = true;
			}
			if ($insertSyndeticsClassicEnrichment && str_contains($line, 'Debian needs a blank line at the end of cron')) {
				if (!empty($lines) && trim(end($lines)) !== '') {
					$lines[] = "\n";
				}
				$lines[] = "###############################################\n";
				$lines[] = "# Syndetics classic enrichment indexing        #\n";
				$lines[] = "###############################################\n";
				$lines[] = "*/5 * * * * root php /usr/local/aspen-discovery/code/web/cron/syndeticsClassicEnrichmentBackground.php $serverName\n";
				$lines[] = "\n";
				$syndeticsClassicEnrichmentInserted = true;
			}
			$lines[] = $line;
		}
		fclose($fhnd);

		// Fallback: If marker was not found, add at the end.
		if ($insertSyndeticsUnboundTags && !$syndeticsUnboundTagsInserted) {
			if (!empty($lines) && trim(end($lines)) !== '') {
				$lines[] = "\n";
			}
			$lines[] = "###############################################\n";
			$lines[] = "# Syndetics Unbound tags indexing feed         #\n";
			$lines[] = "###############################################\n";
			$lines[] = "0 * * * * root php /usr/local/aspen-discovery/code/web/cron/syndeticsUnboundTagsBackground.php $serverName\n";
			$lines[] = "\n";
			$syndeticsUnboundTagsInserted = true;
		}
		if ($insertSyndeticsClassicEnrichment && !$syndeticsClassicEnrichmentInserted) {
			if (!empty($lines) && trim(end($lines)) !== '') {
				$lines[] = "\n";
			}
			$lines[] = "###############################################\n";
			$lines[] = "# Syndetics classic enrichment indexing        #\n";
			$lines[] = "###############################################\n";
			$lines[] = "*/5 * * * * root php /usr/local/aspen-discovery/code/web/cron/syndeticsClassicEnrichmentBackground.php $serverName\n";
			$lines[] = "\n";
			$syndeticsClassicEnrichmentInserted = true;
		}

		// Write the file only if a new cron job was inserted.
		if ($syndeticsUnboundTagsInserted || $syndeticsClassicEnrichmentInserted) {
			$newContent = implode('', $lines);
			file_put_contents('/usr/local/aspen-discovery/sites/' . $serverName . '/conf/crontab_settings.txt', $newContent);
		}
	} else {
		echo '- Could not find cron settings file.' . PHP_EOL;
	}
} else {
	echo 'Must provide server name as first argument.' . PHP_EOL;
	exit();
}
