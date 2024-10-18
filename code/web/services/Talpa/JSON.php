<?php

require_once ROOT_DIR . '/JSON_Action.php';

class Talpa_JSON extends JSON_Action {
	/**@noinspection PhpUnused */
	
	public function trackTalpaUsage(): array {
		global $library;
		if (!isset($_REQUEST['id'])) {
			return [
				'success' => false,
				'message' => 'ID was not provided',
			];
		}
		$id = $_REQUEST['id'];

		require_once ROOT_DIR . '/sys/Talpa/TalpaRecordUsage.php';
		$talpaRecordUsage = new TalpaRecordUsage();
		global $aspenUsage;
		$talpaRecordUsage->instance = $aspenUsage->getInstance();
		$talpaRecordUsage->talpaId = $id;
		$talpaRecordUsage->year = date('Y');
		$talpaRecordUsage->month = date('n');
		if ($talpaRecordUsage->find(true)) {
			$talpaRecordUsage->timesUsed++;
			$ret = $talpaRecordUsage->update();
			if ($ret == 0) {
				echo ("Unable to update times used");
			}
		} else {
			$talpaRecordUsage->timesViewedInSearch = 0;
			$talpaRecordUsage->timesUsed = 1;
			$talpaRecordUsage->insert();
		}
		$userId = UserAccount::getActiveUserId();
		if ($userId) {
			$userObj = UserAccount::getActiveUserObj();
			$userTalpaTracking = $userObj->userCookiePreferenceLocalAnalytics;
			if ($userTalpaTracking) {
				//Track usage for the user
				require_once ROOT_DIR . '/sys/Talpa/UserTalpaUsage.php';
				$userTalpaUsage = new UserTalpaUsage();
				global $aspenUsage;
				$userTalpaUsage->instance = $aspenUsage->getInstance();
				$userTalpaUsage->userId = $userId;
				$userTalpaUsage->year = date('Y');
				$userTalpaUsage->month = date('n');
	
				if ($userTalpaUsage->find(true)) {
					$userTalpaUsage->usageCount++;
					$userTalpaUsage->update();
				} else {
					$userTalpaUsage->usageCount = 1;
					$userTalpaUsage->insert();
				}
			}
		}
		
		

		return [
			'success' => true,
			'message' => 'Updated usage for Talpa record ' . $id,
		];
	}

	function getTitleAuthor(): array {
		$result = [
			'success' => false,
			'title' => 'Unknown',
			'author' => 'Unknown',
		];
		require_once ROOT_DIR . '/RecordDrivers/TalpaRecordDriver.php';
		$id = $_REQUEST['id'];
		if (!empty($id)) {
			$recordDriver = new TalpaRecordDriver($id);
			if ($recordDriver->isValid()) {
				$result['success'] = true;
				$result['title'] = $recordDriver->getTitle();
				$result['author'] = $recordDriver->getAuthor();
			}
		}
		return $result;
	}
}