<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../bootstrap_aspen.php';
require_once ROOT_DIR . '/sys/Talpa/TalpaSettings.php';
require_once ROOT_DIR . '/sys/SearchObject/TalpaSearcher.php';
require_once ROOT_DIR . '/sys/Grouping/GroupedWork.php';
require_once ROOT_DIR . '/RecordDrivers/GroupedWorkDriver.php';
require_once ROOT_DIR . '/sys/Talpa/TalpaData.php';
require_once ROOT_DIR . '/sys/ISBN.php';

$talpaWorkAPI ='https://www.librarything.com/api_aspen_works.php';

//Set up Globals
global $configArray;
global $serverName;
global $interface;
global $aspen_db;
global $logger;
global $library;

global $library;
if ($library->talpaSettingsId != -1) {
	$talpaSettings = new TalpaSettings();
	$talpaSettings->id = $library->talpaSettingsId;
	if (!$talpaSettings->find(true)) {
		$talpaSettings = null;
	}
}
$token = $talpaSettings -> talpaApiToken;


$logger->log('Running Talpa ISBNs cron ', Logger::LOG_NOTICE);

$noIsbns = 0;
$noIsbnA = array();
//Get all Grouped works:
$permanent_ids = array();
//$results = $aspen_db -> query('select * from grouped_work');
$results = $aspen_db -> query('SELECT *
										FROM grouped_work gw
										LEFT JOIN talpa_ltwork_to_groupedwork ltg
											ON gw.permanent_id = ltg.groupedRecordPermanentId
										WHERE ltg.groupedRecordPermanentId IS NULL;
								');

if ($results) {
	while ($result = $results->fetch()) {
		$permanent_ids[] = $result['permanent_id'];
	}
}
$results->closeCursor();

$logger->log('FOUND '.count($permanent_ids).' permanent ID(s)', Logger::LOG_NOTICE);

$retA = array();
//Now, grab the correlating ISBNS for each grouped work ID
foreach ($permanent_ids as $permanent_id) {


	$groupedWork = new GroupedWork();
	$groupedWork->permanent_id = $permanent_id;
	if ($groupedWork->find(true)) {
		$groupedWorkDriver = new GroupedWorkDriver($groupedWork->permanent_id);

		//All Fields
		$fields = $groupedWorkDriver -> getFields();


		$isbnA = array();
		//ISBN Data
		$primaryISBN = $groupedWorkDriver->getPrimaryISBN();

		if(!$primaryISBN  && isset($fields['primary_isbn'] )) {
			$primaryISBN = $fields['primary_isbn'];
		}

		$primaryIsbnObj = new ISBN($primaryISBN);
		if($primaryIsbnObj->isValid()) {
			$isbnA[]= $primaryISBN;
		}

		$allIsbns = $groupedWorkDriver->getISBNs();
		if(!$allIsbns  && isset($fields['isbn'])) {
			$allIsbns = $fields['isbn'];
		}
		if ($allIsbns) {
			foreach ($allIsbns as $rawIsbn) {
				$isbn = '';
				$isbnObj = new ISBN($rawIsbn);
				if ($isbnObj->isValid() && !in_array($rawIsbn, $isbnA)) {
					$isbnA[] = $rawIsbn;
				} elseif (strlen($rawIsbn) == 9) {//When items are indexed into SOLR, the checksum X is removed.
					$_isbn = $rawIsbn . $isbnObj->getISBN10CheckDigit($rawIsbn);
					$convertedIsbn = new ISBN($_isbn);
					if ($convertedIsbn->isValid() && !in_array($rawIsbn, $isbnA)) {
						$isbnA[] = $_isbn;
					}
				} elseif (strlen($rawIsbn) == 11) { //Addressing a bug where an 11th digit is added to valid ISBNs
					$_isbn = substr($rawIsbn, 0, 10);
					$convertedIsbn = new ISBN($_isbn);
					if ($convertedIsbn->isValid() && !in_array($rawIsbn, $isbnA)) {
						$isbnA[] = $_isbn;
					}
				}
			}
		}
		if($isbnA) {
			$retA[$permanent_id]['isbnA'] = $isbnA;
		}
		else{ //We can't use it.
			$noIsbnA[]= $permanent_id;
			$noIsbns++;
			continue;
		}

			//Title and Author
			$primaryAuthor = $groupedWorkDriver  -> getPrimaryAuthor();
		if(!$primaryAuthor) {
				$primaryAuthor = isset($fields['author2Str'])? $fields['author2Str']:'';
			}
		$retA[$permanent_id]['primary_author'] = $primaryAuthor;

		//secondary author
		$auth2A = array();
		if(isset($fields['auth_author2'])) {
			$auth2A = $fields['auth_author2'];
		}
		$retA[$permanent_id]['secondary_author_or_contributorA'] = $auth2A;
			$title = $groupedWorkDriver -> getTitle();
			if(!$title){
				$title =isset($fields['title_display']) ? $fields['title_display'] : '';
			}
			if(!$title){
				$title =isset($fields['base_title']) ? $fields['base_title'] : '';
			}
			if(!$title){
				$title = isset($fields['title_short']) ? $fields['title_short'] : '';
			}
			if($title){
				$retA[$permanent_id]['base_title'] = $title;
			}

			$retA[$permanent_id]['full_titleA'] = isset($fields['title_full']) ? $fields['title_full'] : array();

			//Contributors
			$retA[$permanent_id]['contributorsA'][] = $groupedWorkDriver->getContributors();

			//UPCs
			$retA[$permanent_id]['upcA'][] = $groupedWorkDriver -> getUpcs();

			//ISSNS
			$retA[$permanent_id]['issnA'][] = $groupedWorkDriver -> getISSNs();

		}

	else {
		$logger->log('failed to fetch info for grouped work '.$permanent_id, Logger::LOG_NOTICE);
	}
}
$logger->log("\n".'NO ISBNS found for '.$noIsbns.' records', Logger::LOG_NOTICE);

$logger->log('SENDING '.count($retA).' groupedWorkIDs to API for processing', Logger::LOG_NOTICE);

//batch up the requests
$chunks = array_chunk($retA, 50, true);
foreach ($chunks as $chunk) {
	$data = array(
		'works' => $chunk,
		'token' => $token,
	);

	$logger->log('Sent '.count($chunk).' records', Logger::LOG_NOTICE);

	$curlConnection = curl_init($talpaWorkAPI);
	curl_setopt($curlConnection, CURLOPT_CONNECTTIMEOUT, 15);
	curl_setopt($curlConnection, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
	curl_setopt($curlConnection, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curlConnection, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($curlConnection, CURLOPT_TIMEOUT, 60);
	curl_setopt($curlConnection, CURLOPT_RETURNTRANSFER, true);

// Set cURL options to use POST and send data in the request body
	curl_setopt($curlConnection, CURLOPT_POST, true);
	curl_setopt($curlConnection, CURLOPT_POSTFIELDS, http_build_query($data));

	$result = curl_exec($curlConnection);
	if ($result === false) {
		throw new Exception("Error in HTTP Request: " . curl_error($curlConnection));
	}
	curl_close($curlConnection);

	$resA = json_decode($result, true);

	if(!empty($resA['msg']) && !empty($resA['mappedWorkIDs'])) {
		$logger->log($resA['msg']."\n", Logger::LOG_NOTICE);
		$mappedWorkIDs = $resA['mappedWorkIDs'];
		$logger->log('Work API returned  '.count($mappedWorkIDs).' mapped workids. ', Logger::LOG_NOTICE);

//save to the talpa_lt_to_groupedwork table
		if($mappedWorkIDs)
		{
			foreach ($mappedWorkIDs as $permanent_id => $lt_workcode) {
				$talpaData = new TalpaData();
				$talpaData->whereAdd();
				$talpaData->whereAdd('groupedRecordPermanentId="'.$permanent_id.'"');
				if ($talpaData->find(true)) {
					$talpaData->lt_workcode=$lt_workcode;
					$talpaData->update();
				} else {
					$talpaData -> lt_workcode = $lt_workcode;
					$talpaData -> groupedRecordPermanentId = $permanent_id;
					$talpaData->insert();
				}
			}
		}
	}
	else {
		$logger->log($result, Logger::LOG_NOTICE);
	}
}



?>
