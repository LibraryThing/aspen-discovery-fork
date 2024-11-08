<?php

require_once ROOT_DIR . '/RecordDrivers/RecordInterface.php';

class TalpaRecordDriver extends RecordInterface {
	private $record;
	/**
	 * Constructor.  We build the object using all the data retrieved
	 * @param array|File_MARC_Record||string   $recordData     Data to construct the driver from
	 * @access  public
	 */
	public function __construct($record) {
		if (is_string($record)) {
			/** @var SearchObject_TalpaSearcher $TalpaSearcher */
			$TalpaSearcher = SearchObjectFactory::initSearchObject("Talpa");
			$this->record = $TalpaSearcher->retrieveRecord($record);
		}else{
		$this->record= $record;
		}
	}

	public function isInLibrary() {
		$lt_workcode = $this ->record['work_id'];
		if($lt_workcode){ //get the corresponding groupedWorkID, if we have it.
			require_once ROOT_DIR . '/sys/Talpa/TalpaData.php';
			$talpaData = new TalpaData();
			$talpaData->whereAdd();
			$talpaData->whereAdd("lt_workcode=".$lt_workcode);
			if ($talpaData->find(true)) {
				$groupedWorkID = $talpaData->groupedRecordPermanentId;
				return $groupedWorkID;
			}
			else
			{
			return false;
			}
		}
	}

	public function isValid()
	{
		$isbns = $this->record['isbns'];
		if($isbns) {
			return true;
		}
		else{
			return false;
		}
	}

	public function getBookcoverUrl($size='medium', $absolutePath = false) {
		// require_once ROOT_DIR . '/sys/LibraryLocation/Library.php';
		global $library;

		global $configArray;
		if ($size == 'small' || $size == 'medium'){
			$sizeInArray = 'thumbnail_m';
		}else{
			$sizeInArray = 'thumbnail_l';
		}

//		if (1) {
////		if ($library->showAvailableCoversInTalpa) {
//			if(!empty($this->record[$sizeInArray][0])){
//				$imagePath = $this->record[$sizeInArray][0];
//
//				$imageDimensions = getImageSize($imagePath);
//				if($imageDimensions[0] > 10){
//					return $imagePath;
//				}
//			}
//		}
		if ($absolutePath) {
			$bookCoverUrl = $configArray['Site']['url'];
		} else {
			$bookCoverUrl = '';
		}

		$bookCoverUrl .= "/bookcover.php?id={$this->getUniqueID()}&size={$size}&type=talpa";
		return $bookCoverUrl;
	}

	/**
	 * @param bool $unscoped
	 * @return string
	 */
	public function getLinkUrl($unscoped = false) {
		return $this->getRecordUrl();
	}

	/**
	 * @return string
	 */
//	public function getAbsoluteUrl() {
//		return $this->getRecordUrl();
//	}
//
// 	public function getRecordUrl() {
//		if (isset($this->record['link'])) {
//			return $this->record['link'];
//		} else {
//			return null;
//		}
//	}

	public function getUniqueID() {
		if (isset($this->record['ID'])) {
			return (string)$this->record['ID'][0];
		} elseif ($this->isn) {
			return (string)$this->isn;
		}else{
			return null;
		}
	}

	public function getModule(): string {
		return 'Talpa';
	}

	public function getSearchResult($inLibrary = false) {

		//TODO LAUREN?
		//		if ($view == 'covers') {
//			return $this->getBrowseResult();
//		}
//		print_r($this->record);

		global $interface;
		global $configArray;
//		$url = $configArray['Index']['url'];
//		print_r($this->record);
		$lt_workcode = $this ->record['work_id'];
		if($inLibrary)
		{
			if($lt_workcode){ //get the corresponding groupedWorkID, if we have it.
				require_once ROOT_DIR . '/sys/Talpa/TalpaData.php';
				$talpaData = new TalpaData();
				$talpaData->whereAdd();
				$talpaData->whereAdd("lt_workcode=".$lt_workcode);
				if ($talpaData->find(true)) {
					require_once ROOT_DIR.'/RecordDrivers/GroupedWorkDriver.php';
					$groupedWorkDriver = new GroupedWorkDriver($talpaData->groupedRecordPermanentId);
					if ($groupedWorkDriver->isValid()) {
						$interface->assign('summID', $groupedWorkDriver->getId());
//					$interface->assign('module', $this->getModule());
//					$interface->assign('summRank', $this->record['rank']);
						$formats = $groupedWorkDriver->getFormats();
						$fields = $groupedWorkDriver->getFields();
						$formats = $fields['literary_form_full'];
						$formatS = join(',', $formats);

						//get the most recent pub date
						$pubdateA = $groupedWorkDriver->getPublicationDates();
						$pubdate = max($pubdateA);

						$interface->assign('summFormats', $formatS);
						$interface->assign('summUrl', $groupedWorkDriver->getLinkUrl());
						$interface ->assign('summRating', $groupedWorkDriver ->getRatingData());
						$interface->assign('summTitle', $groupedWorkDriver->getTitle());
						$interface->assign('summAuthor', $groupedWorkDriver->getPrimaryAuthor());
						$interface->assign('summPublisher', $groupedWorkDriver->getPublishers());
						$interface->assign('summPubDate', $pubdate);
						$interface ->assign('summLanguage', $groupedWorkDriver->getLanguage());
						$interface->assign('relatedManifestations', $groupedWorkDriver->getRelatedManifestations());
						$interface->assign('summDescription', $groupedWorkDriver->getDescription());
						$interface->assign('bookCoverUrl', $groupedWorkDriver->getBookcoverUrl('small'));
						$interface->assign('bookCoverUrlMedium', $groupedWorkDriver->getBookcoverUrl('medium'));

					}
					else
					{
						//We shouldn't land here- if results are coming in as "in library" and the API is returning a work code.
					}
				}
			}

		}
		else{ //Not a library result
//			print_r($this->record);
			$this->isn = $this ->record['isbns'][0];
//			print_r($this->record['title'].' '.$this->isn."\n");
			$interface->assign('summUrl', 'https://www.librarything.com/work/'.$this->record['work_id']);
			$interface->assign('summTitle', $this->record['title']);
			$interface->assign('bookCoverUrlMedium',$this->getBookcoverUrl());
			$interface->assign('summAuthor', null);
			$interface->assign('summPublisher',null);
			$interface->assign('summPubDate', null);
			$interface->assign('summFormats', null);
		}



		return 'RecordDrivers/Talpa/result.tpl';
	}

	public function getBrowseResult() {
		global $interface;

		$interface->assign('summId', $this->getUniqueID());
		$interface->assign('summUrl', $this->getLinkUrl());
		$interface->assign('summTitle', $this->getTitle());

		//Get cover image size
		$appliedTheme = $interface->getAppliedTheme();
		$interface->assign('bookCoverUrl', $this->getBookcoverUrl('medium'));

		$accessibleBrowseCategories = 0;
		if ($appliedTheme) {
			if($appliedTheme->browseCategoryImageSize == 1) {
				$interface->assign('bookCoverUrlMedium', $this->getBookcoverUrl('large'));
			}
			$accessibleBrowseCategories = $appliedTheme->accessibleBrowseCategories;
		} else {
			$interface->assign('bookCoverUrlMedium', $this->getBookcoverUrl('medium'));
		}
		$interface->assign('accessibleBrowseCategories', $accessibleBrowseCategories);

		return 'RecordDrivers/Talpa/browse_result.tpl';
	}

	/**
	 * Assign necessary Smarty variables and return a template name to
	 * load in order to display a summary of the item suitable for use in
	 * search results.
	 *
	 * @access  public
	 * @return  string              Name of Smarty template file to display.
	 */
	public function getCombinedResult() {
		global $interface;
		$formats = $this->getFormats();
		$id = $this->getUniqueID();

		$interface->assign('summId', $id);
		$interface->assign('summShortId', $id);
		$interface->assign('module', $this->getModule());
		$interface->assign('summFormats', $formats);
		$interface->assign('summUrl', $this->getLinkUrl());
		$interface->assign('summTitle', $this->getTitle());
		$interface->assign('summAuthor', $this->getAuthor());
		$interface->assign('summSourceDatabase', $this->getSourceDatabase());
		$interface->assign('summHasFullText', $this->hasFullText());
		$interface->assign('summDescription', $this->getDescription());
		$interface->assign('bookCoverUrl', $this->getBookcoverUrl('small'));
		$interface->assign('bookCoverUrlMedium', $this->getBookcoverUrl('medium'));

		return 'RecordDrivers/Talpa/combinedResult.tpl';
	}

	public function getSpotlightResult(CollectionSpotlight $collectionSpotlight, string $index) {
		global $interface;

		$interface->assign('showRatings', $collectionSpotlight->showRatings);
		$interface->assign('key', $index);
		$interface->assign('title', $this->getTitle());
		$interface->assign('author', $this->getAuthor());
		$interface->assign('description', $this->getDescription());
		$interface->assign('shortId', $this->getUniqueID());
		$interface->assign('id', $this->getUniqueID());
		$interface->assign('titleURL', $this->getLinkUrl());

		if ($collectionSpotlight->coverSize == 'small') {
			$imageUrl = $this->getBookcoverUrl('small');
		} else {
			$imageUrl = $this->getBookcoverUrl('medium');
		}
		$interface->assign('imageUrl', $imageUrl);

		if ($collectionSpotlight->showRatings) {
			$interface->assign('ratingData', null);
			$interface->assign('showNotInterested', false);
		}

		$result = [
			'title' => $this->getTitle(),
			'author' => $this->getAuthor(),
		];
		if ($collectionSpotlight->style == 'text-list') {
			$result['formattedTextOnlyTitle'] = $interface->fetch('CollectionSpotlight/formattedTextOnlyTitle.tpl');
		} elseif ($collectionSpotlight->style == 'horizontal-carousel') {
			$result['formattedTitle'] = $interface->fetch('CollectionSpotlight/formattedHorizontalCarouselTitle.tpl');
		} else {
			$result['formattedTitle'] = $interface->fetch('CollectionSpotlight/formattedTitle.tpl');
		}

		return $result;
	}

	/**
	 * Assign necessary Smarty variables and return a template name to
	 * load in order to display the full record information on the Staff
	 * View tab of the record view page.
	 *
	 * @access  public
	 * @return  string              Name of Smarty template file to display.
	 */
	public function getStaffView() {
		return null;
	}

	/** * Get the full title of the record.
	 *
	 * @return  string
	 */
	public function getTitle() {
		if (isset($this->record['Title'])) {
			$title=$this->record['Title'][0];
			if (isset($this->record['Subtitle'])) {
				$title .= ': ' . $this->record['Subtitle'][0];
			}
		} else {
			$title='Unknown Title';
		}
		return $title;
	}

	/**
	 * The Table of Contents extracted from the record.
	 * Returns null if no Table of Contents is available.
	 *
	 * @access  public
	 * @return  array              Array of elements in the table of contents
	 */
	public function getTableOfContents() {
		return null;
	}

	/**
	 * Return the unique identifier of this record within the Solr index;
	 * useful for retrieving additional information (like tags and user
	 * comments) from the external MySQL database.
	 *
	 * @access  public
	 * @return  string              Unique identifier.
	 */


	public function getId() {
		return $this->getUniqueID();
	}

	/**
	 * Does this record have searchable full text in the index?
	 *
	 * Note: As of this writing, searchable full text is not a VuFind feature,
	 *       but this method will be useful if/when it is eventually added.
	 *
	 * @access  public
	 * @return  bool
	 */
	public function hasFullText() {
		if(isset($this->record['hasFullText'])){
			return $this->record['hasFullText'];
		}
		return false;
	}

	/**
	 * Does this record have reviews available?
	 *
	 * @access  public
	 * @return  bool
	 */
	public function hasReviews() {
		return false;
	}

	public function getDescription() {
		if(isset($this->record['Abstract'][0])) {
			$description = $this->record['Abstract'][0];
		} else {
			$description = '';
		}
		return $description;
	}

	public function getMoreDetailsOptions() {
		// TODO: Implement getMoreDetailsOptions() method.
	}

	public function getFormats() {
		if(isset($this->record['ContentType'][0])){
			$sourceType = (string)$this->record['ContentType'][0];
		} else {
			$sourceType = 'Unknown Source';
		}
		return $sourceType;
	}

	public function getCleanISSN() {
		return '';
	}

	public function getSourceDatabase() {
		if(isset($this->record['DatabaseTitle'][0])) {
			$databaseTitle = $this->record['DatabaseTitle'][0];
		} else {
			$databaseTitle = '';
		}
		return $databaseTitle;
	}

	public function getPrimaryAuthor() {
		return $this->getAuthor();
	}

	public function getAuthor() {
		if(isset($this->record['Author_xml'][0]['fullname'])) {
			$author=$this->record['Author_xml'][0]['fullname'];
		} else {
			$author='Unknown Title';
		}
		return $author;
	}

	public function getExploreMoreInfo() {
		return [];
	}

	public function getPermanentId() {
		return $this->getUniqueID();
	}

	/**
	 * Assign necessary Smarty variables and return a template name to
	 * load in order to display a summary of the item suitable for use in
	 * user's favorites list.
	 *
	 * @access  public
	 * @param int $listId ID of list containing desired tags/notes (or null to show tags/notes from all user's lists).
	 * @param bool $allowEdit Should we display edit controls?
	 * @param bool $allowEdit Should we display edit controls?
	 * @return  string              Name of Smarty template file to display.
	 */
	public function getListEntry($listId = null, $allowEdit = true) {
		$this->getSearchResult('list');
		//Switch template
		return 'RecordDrivers/Talpa/listEntry.tpl';
	}

	/**
	 * Assign necessary Smarty variables and return a template name
	 * to load in order to display the requested citation format.
	 * For legal values, see getCitationFormats().  Returns null if
	 * format is not supported.
	 *
	 * @param string $format Citation format to display.
	 * @access  public
	 * @return  string              Name of Smarty template file to display.
	 */
	public function getCitation($format) {
		require_once ROOT_DIR . '/sys/CitationBuilder.php';

		// Build author list:
		$authors = [];
		$primary = $this->getAuthor();
		if (!empty($primary)) {
			$authors[] = $primary;
		}
		//TODO: - Make get places of publication function
		//$pubPlaces = $this->getPlacesOfPublication();
		$details = [
			'authors' => $authors,
			'title' => $this->getTitle(),
			'subtitle' => '',
			'pubName' => null,
			'pubDate' => null,
			'edition' => null,
			'format' => $this->getFormats(),
		];

		// Build the citation:
		$citation = new CitationBuilder($details);
		switch ($format) {
			case 'APA':
				return $citation->getAPA();
			case 'AMA':
				return $citation->getAMA();
			case 'ChicagoAuthDate':
				return $citation->getChicagoAuthDate();
			case 'ChicagoHumanities':
				return $citation->getChicagoHumanities();
			case 'MLA':
				return $citation->getMLA();
		}
		return '';
	}
}
