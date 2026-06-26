<?php

require_once ROOT_DIR . '/services/Admin/IndexingLog.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsIndexingLogEntry.php';

class SyndeticsUnbound_IndexingLog extends Admin_IndexingLog {
	const FEED_SOURCES = ['su_tags', 'syndetics_classic', 'cleanup'];

	function getIndexLogEntryObject(): BaseLogEntry {
		return new SyndeticsIndexingLogEntry();
	}

	function getTemplateName(): string {
		return 'syndeticsIndexingLog.tpl';
	}

	function getTitle(): string {
		return 'Syndetics Unbound Indexing Log';
	}

	function getModule(): string {
		return 'SyndeticsUnbound';
	}

	function applyMinProcessedFilter(DataObject $indexingObject, $minProcessed) {
		if ($indexingObject instanceof SyndeticsIndexingLogEntry) {
			$indexingObject->whereAdd('numProducts >= ' . (int)$minProcessed);
		}
	}

	function applyAdditionalFilters(DataObject $logEntry) {
		if (!empty($_REQUEST['feedSource']) && in_array($_REQUEST['feedSource'], self::FEED_SOURCES, true)) {
			$logEntry->whereAdd("feedSource = '" . $_REQUEST['feedSource'] . "'");
		}
	}

	function getBreadcrumbs(): array {
		$breadcrumbs = [];
		$breadcrumbs[] = new Breadcrumb('/Admin/Home', 'Administration Home');
		$breadcrumbs[] = new Breadcrumb('/Admin/Home#system_reports', 'System Reports');
		$breadcrumbs[] = new Breadcrumb('', 'Syndetics Unbound Indexing Log');
		return $breadcrumbs;
	}

	function getActiveAdminSection(): string {
		return 'system_reports';
	}

	function launch(): void {
		global $interface;
		$selectedFeedSource = !empty($_REQUEST['feedSource']) && in_array($_REQUEST['feedSource'], self::FEED_SOURCES, true) ? $_REQUEST['feedSource'] : '';
		$interface->assign('selectedFeedSource', $selectedFeedSource);
		parent::launch();
	}
}
