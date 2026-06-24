<?php /** @noinspection PhpMissingFieldTypeInspection */


class SyndeticsSetting extends DataObject {
	public $__table = 'syndetics_settings';    // table name
	public $id;
	public $name;
	public $syndeticsUnbound;
	public $syndeticsKey;
	public $unboundAccountNumber;
	public $unboundInstanceNumber;
	public $indexingEnabled;
	public $lastSeenLtSeedVersion;
	public $lastSeenLtSeedFetchedAt;
	public $lastSeenLtLibraryVersion;
	public $lastSeenLtLibraryFetchedAt;
	public $classicEnrichmentCursor;
	public $classicEnrichmentLastFullPassAt;
	public $hasSummary;
	public $hasAvSummary;
	public $hasAvProfile;
	public $hasToc;
	public $hasExcerpt;
	public $hasFictionProfile;
	public $hasAuthorNotes;
	public $hasVideoClip;

	private $_libraries;

	public function getNumericColumnNames(): array {
		return [
			'hasSummary',
			'hasAvSummary',
			'hasAvProfile',
			'hasToc',
			'hasExcerpt',
			'hasFictionProfile',
			'hasAuthorNotes',
			'hasVideoClip',
			'indexingEnabled',
		];
	}

	static $_objectStructure = [];
	static function getObjectStructure(string $context = ''): array {
		if (isset(self::$_objectStructure[$context]) && self::$_objectStructure[$context] !== null) {
			return self::$_objectStructure[$context];
		}

		$libraryList = Library::getLibraryList(!UserAccount::userHasPermission('Administer All Libraries'));
		$structure = [
			'id' => [
				'property' => 'id',
				'type' => 'label',
				'label' => 'Id',
				'description' => 'The unique id',
			],
			'name' => [
				'property' => 'name',
				'type' => 'text',
				'label' => 'Name',
				'description' => 'A Name for the Syndetics Subscription for internal use',
				'maxlength' => 255,
				'required' => true,
			],
			'syndeticsKey' => [
				'property' => 'syndeticsKey',
				'type' => 'text',
				'label' => 'Syndetics Key (Client Code)',
				'description' => 'The key/client code for the subscription and required for providing cover images.',
			],
			'syndeticsUnbound' => [
				'property' => 'syndeticsUnbound',
				'type' => 'checkbox',
				'label' => 'Syndetics Unbound',
				'description' => 'Check this option if this is a Syndetics Unbound Subscription',
				'default' => 0,
				'onchange' => "return AspenDiscovery.Admin.updateSyndeticsFields();"
			],
			'unboundAccountNumber' => [
				'property' => 'unboundAccountNumber',
				'type' => 'integer',
				'label' => 'Unbound Account Number',
				'description' => 'Enter the account number for syndetics unbound',
				'default' => 0,
			],
			'unboundInstanceNumber' => [
				'property' => 'unboundInstanceNumber',
				'type' => 'integer',
				'label' => 'Unbound Instance Number',
				'description' => 'Enter the instance number for syndetics unbound (may be left at 0 to ignore)',
				'default' => 0,
			],
			'hasSummary' => [
				'property' => 'hasSummary',
				'type' => 'checkbox',
				'label' => 'Has Summary',
				'description' => 'Whether or not the summary is available in the subscription',
				'default' => 1,
			],
			'hasAvSummary' => [
				'property' => 'hasAvSummary',
				'type' => 'checkbox',
				'label' => 'Has Audio Visual Summary',
				'description' => 'Whether or not the summary is available in the subscription',
			],
			'hasAvProfile' => [
				'property' => 'hasAvProfile',
				'type' => 'checkbox',
				'label' => 'Has Audio Visual Profile',
				'description' => 'Whether or not the summary is available in the subscription',
			],
			'hasToc' => [
				'property' => 'hasToc',
				'type' => 'checkbox',
				'label' => 'Has Table of Contents',
				'description' => 'Whether or not the table of contents is available in the subscription',
				'default' => 1,
			],
			'hasExcerpt' => [
				'property' => 'hasExcerpt',
				'type' => 'checkbox',
				'label' => 'Has Excerpt',
				'description' => 'Whether or not the excerpt is available in the subscription',
				'default' => 1,
			],
			'hasFictionProfile' => [
				'property' => 'hasFictionProfile',
				'type' => 'checkbox',
				'label' => 'Has Fiction Profile',
				'description' => 'Whether or not the excerpt is available in the subscription',
			],
			'hasAuthorNotes' => [
				'property' => 'hasAuthorNotes',
				'type' => 'checkbox',
				'label' => 'Has Author Notes',
				'description' => 'Whether or not author notes are available in the subscription',
			],
			'hasVideoClip' => [
				'property' => 'hasVideoClip',
				'type' => 'checkbox',
				'label' => 'Has Video Clip',
				'description' => 'Whether or not the excerpt is available in the subscription',
			],
			'indexingHeader' => [
				'property' => 'indexingHeader',
				'type' => 'section',
				'label' => 'Solr Indexing',
				'hideInLists' => true,
				'properties' => [
					'indexingEnabled' => [
						'property' => 'indexingEnabled',
						'type' => 'checkbox',
						'label' => 'Index SU enrichment into Solr',
						'description' => 'When enabled, the SU enrichment crons pull tag data from the LibraryThing feed and summary/TOC/review data from the classic Syndetics service for this account, and index both into the grouped works Solr core so SU enrichment becomes searchable for libraries on this subscription.',
						'default' => 0,
						'forcesReindex' => true,
					],
				],
			],
			'libraries' => [
				'property' => 'libraries',
				'type' => 'multiSelect',
				'listStyle' => 'checkboxSimple',
				'label' => 'Libraries',
				'description' => 'Define libraries that can use these settings',
				'values' => $libraryList,
				'hideInLists' => false,
				'forcesReindex' => true,
			],
		];

		self::$_objectStructure[$context] = $structure;
		return self::$_objectStructure[$context];
	}

	public function __get($name) {
		if ($name == "libraries") {
			if (!isset($this->_libraries) && $this->id) {
				$this->_libraries = [];
				$obj = new Library();
				$obj->syndeticsSettingId = $this->id;
				$obj->find();
				while ($obj->fetch()) {
					$this->_libraries[$obj->libraryId] = $obj->libraryId;
				}
			}
			return $this->_libraries;
		} else {
			return parent::__get($name);
		}
	}

	public function __set($name, $value) {
		if ($name == "libraries") {
			$this->_libraries = $value;
		} else {
			parent::__set($name, $value);
		}
	}

	/**
	 * When indexingEnabled = 1 is being saved, require a positive unboundAccountNumber
	 * and reject saves whose account number is already in use by another indexed row.
	 * a_id is the canonical per-library SU identifier; two settings rows must not both
	 * index the same account or they will race over the same Solr enrichment data.
	 */
	private function validateUnboundAccountForIndexing(): bool {
		if (empty($this->indexingEnabled)) {
			return true;
		}
		if (empty($this->syndeticsUnbound)) {
			$this->setLastError("'Index SU enrichment into Solr' can only be enabled on Syndetics Unbound subscriptions. Check 'Syndetics Unbound' first.");
			return false;
		}
		if (empty($this->unboundAccountNumber) || $this->unboundAccountNumber <= 0) {
			$this->setLastError("Unbound Account Number is required when 'Index SU enrichment into Solr' is enabled.");
			return false;
		}
		$duplicate = new SyndeticsSetting();
		$duplicate->indexingEnabled = 1;
		$duplicate->unboundAccountNumber = $this->unboundAccountNumber;
		if (!empty($this->id)) {
			$duplicate->whereAdd('id != ' . (int)$this->id);
		}
		if ($duplicate->find(true)) {
			$this->setLastError("Another Syndetics Unbound subscription (\"" . $duplicate->name . "\") is already indexing account number " . $this->unboundAccountNumber . " into Solr. Each account can only be indexed once.");
			return false;
		}
		return true;
	}

	/**
	 * Returns true if the row already exists with a previous unboundAccountNumber
	 * that differs from the value currently on $this. Used by update() to trigger
	 * cache invalidation when an admin changes the SU account on a settings row.
	 */
	private function detectAccountNumberChange(): bool {
		if (empty($this->id)) {
			return false;
		}
		$previous = new SyndeticsSetting();
		$previous->id = $this->id;
		if (!$previous->find(true)) {
			return false;
		}
		return ((int)$previous->unboundAccountNumber !== (int)$this->unboundAccountNumber)
			&& (int)$previous->unboundAccountNumber > 0;
	}

	private function wipeCacheForSettingsId(): void {
		global $aspen_db;
		$stmt = $aspen_db->prepare("DELETE FROM syndetics_indexing_data WHERE syndeticsSettingId = ?");
		$stmt->execute([$this->id]);
	}

	private function resetPerFeedCursors(): void {
		$this->lastSeenLtSeedVersion = null;
		$this->lastSeenLtSeedFetchedAt = null;
		$this->lastSeenLtLibraryVersion = null;
		$this->lastSeenLtLibraryFetchedAt = null;
		$this->classicEnrichmentCursor = null;
		$this->classicEnrichmentLastFullPassAt = null;
	}

	/**
	 * Reset library.syndeticsSettingId to the "no binding" sentinel (-1) for any
	 * library currently bound to this settings row, so deletion doesn't leave the
	 * Reviews / BookCoverProcessor / GoDeeperData lookups pointing at a dead id.
	 */
	private function clearLibraryBindings(): void {
		global $aspen_db;
		$stmt = $aspen_db->prepare("UPDATE library SET syndeticsSettingId = -1 WHERE syndeticsSettingId = ?");
		$stmt->execute([$this->id]);
	}

	/**
	 * Aspen has no "by-ISBN" lookup that would let us identify only the grouped
	 * works whose Solr docs hold stale SU enrichment for this settings row, so
	 * we fall back to the catalog-wide reindex flag that the `forcesReindex`
	 * mechanism on form fields already uses. Account changes and deletes are
	 * rare admin events; over-reindexing is cheaper than maintaining an ISBN
	 * to grouped-work side table.
	 */
	private function forceCatalogReindex(string $reason): void {
		require_once ROOT_DIR . '/sys/SystemVariables.php';
		SystemVariables::forceNightlyIndex("SyndeticsSetting $this->id: $reason");
	}

	public function update(string $context = '') : bool|int {
		if (!$this->validateUnboundAccountForIndexing()) {
			return false;
		}
		$accountChanged = $this->detectAccountNumberChange();
		if ($accountChanged) {
			$this->resetPerFeedCursors();
		}
		$ret = parent::update();
		if ($ret !== FALSE) {
			$this->saveLibraries();
			if ($accountChanged) {
				$this->wipeCacheForSettingsId();
				$this->forceCatalogReindex('unboundAccountNumber changed');
			}
		}
		return $ret;
	}

	public function insert(string $context = '') : int|bool {
		if (!$this->validateUnboundAccountForIndexing()) {
			return false;
		}
		$ret = parent::insert();
		if ($ret !== FALSE) {
			$this->saveLibraries();
		}
		return $ret;
	}

	public function delete(bool $useWhere = false, bool $hardDelete = false) : bool|int {
		$hadBindings = !$useWhere && !empty($this->id);
		if ($hadBindings) {
			$this->clearLibraryBindings();
		}
		$ret = parent::delete($useWhere, $hardDelete);
		if ($ret !== FALSE && $hadBindings) {
			$this->forceCatalogReindex('settings row deleted');
		}
		return $ret;
	}

	public function saveLibraries() : void{
		if (isset ($this->_libraries) && is_array($this->_libraries)) {
			$libraryList = Library::getLibraryList(!UserAccount::userHasPermission('Administer All Libraries'));
			foreach ($libraryList as $libraryId => $displayName) {
				$library = new Library();
				$library->libraryId = $libraryId;
				$library->find(true);
				if (in_array($libraryId, $this->_libraries)) {
					//We want to apply the scope to this library
					if ($library->syndeticsSettingId != $this->id) {
						$library->syndeticsSettingId = $this->id;
						$library->update();
					}
				} else {
					//It should not be applied to this scope. Only change if it was applied to the scope
					if ($library->syndeticsSettingId == $this->id) {
						$library->syndeticsSettingId = -1;
						$library->update();
					}
				}
			}
			unset($this->_libraries);
		}
	}
}
