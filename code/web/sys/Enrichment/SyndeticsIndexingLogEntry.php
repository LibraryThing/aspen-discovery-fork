<?php /** @noinspection PhpMissingFieldTypeInspection */

require_once ROOT_DIR . '/sys/BaseLogEntry.php';

class SyndeticsIndexingLogEntry extends BaseLogEntry {
	public $__table = 'syndetics_indexing_log';   // table name
	public $id;
	public $syndeticsSettingId;
	public $feedSource;       // 'su_tags' | 'syndetics_classic'
	public $notes;
	public $numProducts;
	public $numErrors;
	public $numInvalidRecords;
	public $numAdded;
	public $numUpdated;
	public $numDeleted;
	public $numSkipped;
}
