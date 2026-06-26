<?php

require_once ROOT_DIR . '/services/Admin/Admin.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsSetting.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsIndexingLogEntry.php';
require_once ROOT_DIR . '/sys/Enrichment/SyndeticsIndexingExtractor.php';

class SyndeticsUnbound_ReloadRecord extends Admin_Admin {
	function launch(): void {
		global $interface;
		$message = '';
		$isError = false;

		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['identifier'])) {
			$settingsId = (int)($_POST['settingsId'] ?? 0);
			$identifierType = $_POST['identifierType'] ?? '';
			// Normalize to the cache identifier contract: ISBN-13 / UPC-A (12 digits), digits only, before it
			// reaches the classic Syndetics URL in fetchClassicXml().
			$identifier = preg_replace('/[^0-9]/', '', (string)$_POST['identifier']);
			$validIdentifier = ($identifierType === 'isbn' && strlen($identifier) === 13)
				|| ($identifierType === 'upc' && strlen($identifier) === 12);
			if ($settingsId <= 0 || !$validIdentifier) {
				$message = 'Choose a settings row and enter a 13-digit ISBN or 12-digit UPC.';
				$isError = true;
			} else {
				$settings = new SyndeticsSetting();
				$settings->id = $settingsId;
				if (!$settings->find(true)) {
					$message = 'Settings row not found.';
					$isError = true;
				} elseif (empty($settings->syndeticsUnbound) || empty($settings->indexingEnabled)) {
					$message = 'Syndetics Unbound indexing is not enabled on this settings row; refusing to reload.';
					$isError = true;
				} else {
					$logEntry = new SyndeticsIndexingLogEntry();
					$logEntry->syndeticsSettingId = $settingsId;
					$logEntry->feedSource = 'syndetics_classic';
					$logEntry->startTime = time();
					$logEntry->insert();
					try {
						$extractor = new SyndeticsIndexingExtractor($settings, $logEntry);
						$result = $extractor->reloadSingleIdentifier($identifierType, $identifier);
						$message = "Reloaded $identifierType $identifier; touched_classic={$result['touched_classic']}, touched_su_tags={$result['touched_su_tags']}.";
					} catch (Exception $e) {
						$message = 'Reload failed: ' . $e->getMessage();
						$isError = true;
					} finally {
						$logEntry->endTime = time();
						$logEntry->update();
					}
				}
			}
		}

		$interface->assign('message', $message);
		$interface->assign('isError', $isError);
		$interface->assign('settingsList', $this->listSettings());
		$this->display('reloadRecord.tpl', 'Reload Syndetics Unbound Record');
	}

	private function listSettings(): array {
		$rows = [];
		$obj = new SyndeticsSetting();
		$obj->syndeticsUnbound = 1;
		$obj->indexingEnabled = 1;
		$obj->orderBy('id');
		$obj->find();
		while ($obj->fetch()) {
			$rows[$obj->id] = !empty($obj->name) ? $obj->name : ('Settings #' . $obj->id);
		}
		return $rows;
	}

	function getBreadcrumbs(): array {
		$breadcrumbs = [];
		$breadcrumbs[] = new Breadcrumb('/Admin/Home', 'Administration Home');
		$breadcrumbs[] = new Breadcrumb('/Admin/Home#third_party_enrichment', 'Syndetics Unbound');
		$breadcrumbs[] = new Breadcrumb('', 'Reload Single Record');
		return $breadcrumbs;
	}

	function canView(): bool {
		return UserAccount::userHasPermission('Administer Third Party Enrichment API Keys');
	}

	function getActiveAdminSection(): string {
		return 'third_party_enrichment';
	}
}
