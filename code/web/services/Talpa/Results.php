<?php
require_once ROOT_DIR . '/ResultsAction.php';
class Talpa_Results extends ResultsAction {
	function launch() {
		global $interface;
		global $timer;
		global $library;

//		global $aspenUsage;
//		$aspenUsage->groupedWorkSearches++;
//		$aspenUsage->talpaSearches++;

		global $solrScope;
		if(!$solrScope)
					{
						//TODO LAUREN get library settings and use primary instance
						$solrScope='main';
					}

		//Retrieve the Grouped Work Display settings to use in result.tpl
		foreach ($library->getGroupedWorkDisplaySettings()->showInSearchResultsMainDetails as $detailOption) {
			$interface->assign($detailOption, true);
		}

		if (!isset($_REQUEST['lookfor']) || empty($_REQUEST['lookfor'])) {
			$_REQUEST['lookfor'] = 'The Man with the Yellow Hat'; //TODO LAUREN pick a default query
		}

		//Include Search Engine
		/** @var SearchObject_TalpaSearcher $searchObject */
		$searchObject = SearchObjectFactory::initSearchObject("Talpa");
		$timer->logTime('Include search engine');

		// Hide Covers when the user has set that setting on the Search Results Page
		$this->setShowCovers();

		$searchObject->init();

		//If queryID matches the session data queryID (from Talpa's top facets), use those saved results to save load time.
		if (isset($_REQUEST['queryId']) && $_SESSION['last_recordData'] && ($_SESSION['last_query_id']== $_REQUEST['queryId']) ) {
			$result = unserialize($_SESSION['last_recordData']);
			$searchObject->processRepeatedSearch($result);
		}
		elseif( isset($_REQUEST['queryId']) && ($_SESSION['last_query_id']!= $_REQUEST['queryId'])){ //two concurrent sessions, request new results
			$result = $searchObject->sendRequest($_REQUEST['queryId']);
		}
		else //performing a new search
		{
			$result = $searchObject->sendRequest();
		}


		if ($result instanceof AspenError) { //TODO LAUREN error reporting
			global $serverName;
			$logSearchError = true;
			if ($logSearchError) {
				try {
					require_once ROOT_DIR . '/sys/SystemVariables.php';
					$systemVariables = new SystemVariables();
					if ($systemVariables->find(true) && !empty($systemVariables->searchErrorEmail)) {
						require_once ROOT_DIR . '/sys/Email/Mailer.php';
						$mailer = new Mailer();
						$emailErrorDetails = $_SERVER['REQUEST_URI'] . "\n" . $result->getMessage();
						$mailer->send($systemVariables->searchErrorEmail, "$serverName Error processing Talpa search", $emailErrorDetails);
					}
				} catch (Exception $e) {

				}
			}

			$interface->assign('searchError', $result);
			$this->display('searchError.tpl', 'Error in Search');
			return;
		}

	//DISPLAY SEARCH TO USER
		$displayQuery = $searchObject->displayQuery();
		$pageTitle = $displayQuery;
		if (strlen($pageTitle) > 20) {
			$pageTitle = substr($pageTitle, 0, 20) . '...';
		}

		$interface->assign('lookfor', $displayQuery);
		$interface->assign('topRecommendations', $searchObject->getRecommendationsTemplates('top'));


		//SET INTERFACE VARS/SETTINGS
		$interface->assign('showLanguages', true);

		$summary = $searchObject->getResultSummary();
		$interface->assign('recordCount', $summary['resultTotal']);
		$interface->assign('recordStart', $summary['startRecord']);
		$interface->assign('recordEnd', $summary['endRecord']);



		$appliedFacets = $searchObject->getFilterList();
		$interface->assign('filterList', $appliedFacets);
		var_dump($appliedFacets);
		//TODO LAUREN I think this logic is buggy
		$filterListApplied = $appliedFacets['Search Within'][0]['value'];
		$interface->assign('filterListApplied', $filterListApplied);

		$limitList = $searchObject->getLimitList();
		$interface->assign('limitList', $limitList);
		$facetSet = $searchObject->getFacetSet();
		$interface->assign('sideFacetSet', $facetSet);

		//Figure out which counts to show.
		$facetCountsToShow = $library->getGroupedWorkDisplaySettings()->facetCountsToShow;
		$interface->assign('facetCountsToShow', $facetCountsToShow);



		//Talpa Results //
		$recordSet = $searchObject->getResultRecordHTML();

		$interface->assign('recordSet', $recordSet);
		$timer->logTime('load result records');

		//TODO LAUREN - What do these do?
		$interface->assign('sortList', $searchObject->getSortList());
		$interface->assign('searchIndex', $searchObject->getSearchIndex());


//			$oldSearchUrl = $_SERVER['REQUEST_URI'];
//			$oldSearchUrl = preg_replace('/searchIndex=Keyword/', 'searchIndex=' . $replacedIndex, $oldSearchUrl);
//			$_REQUEST['searchIndex'] = 'Keyword';
//			$oldSearchUrl = preg_replace('/sort=.+?(&|$)/', '', $oldSearchUrl);
//			unset($_REQUEST['sort']);
//			$oldSearchUrl = preg_replace("/[?&]replacedIndex=$replacedIndex/", '', $oldSearchUrl);
//			$interface->assign('oldSearchUrl', $oldSearchUrl);
//		}

		$interface->assign('showNotInterested', false);


		if ($summary['resultTotal'] > 0) {
			$link = $searchObject->renderLinkPageTemplate();
			$options = [
				'totalItems' => $summary['resultTotal'],
				'fileName' => $link,
				'perPage' => $summary['perPage'],
			];
			$pager = new Pager($options);
			$interface->assign('pageLinks', $pager->getLinks());
		}

		$searchObject->close();
	//TODO LAUREN remove saved search?
		$interface->assign('savedSearch', $searchObject->isSavedSearch());
		$interface->assign('searchId', $searchObject->getSearchId());

		//TODO LAUREN - UPDATE THESE?
		// Save the ID of this search to the session so we can return to it easily:
		$_SESSION['lastSearchId'] = $searchObject->getSearchId();

		// Save the URL of this search to the session so we can return to it easily:
		$_SESSION['lastSearchURL'] = $searchObject->renderSearchUrl();

//TODO LAUREN
		$displayTemplate = 'Talpa/list-list.tpl'; // structure for regular results
		$interface->assign('subpage', $displayTemplate);
		$interface->assign('sectionLabel', 'Talpa');

		$interface->assign('hasSearchableFacets', $searchObject->hasSearchableFacets());

		$sidebar = $searchObject->getResultTotal() > 0 ? 'Talpa/results-sidebar.tpl' : '';
		$this->display($summary['resultTotal'] > 0 ? 'list.tpl' : 'list-none.tpl', $pageTitle, $sidebar, false);
	}

	function getBreadcrumbs(): array {
		return parent::getResultsBreadcrumbs($_SESSION['talpaBreadcrumb']);
	}
}
