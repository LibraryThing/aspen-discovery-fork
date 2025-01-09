<?php


class TalpaSettings extends DataObject {
	public $__table = 'talpa_settings';
	public $id;
	public $name;
	public $talpaApiToken;
	public $talpaSearchSourceString;
	public $tryThisSearchInTalpaText;
	public $tryThisSearchInTalpaSidebarSwitch;
	public $tryThisSearchInTalpaNoResultsSwitch;
	public $talpaExplainerText;

	public $includeTalpaLogoSwitch;

	public $talpaOtherResultsExplainerText;

//	function getEncryptedFieldNames(): array {
//		return ['talpaApiPassword'];
//	}

	public static function getObjectStructure($context = ''): array {
		return [
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
				'maxLength' => 50,
				'description' => 'A name for these settings',
				'required' => true,
			],
			'talpaApiToken' => [
				'property' => 'talpaApiToken',
				'type' => 'text',
				'label' => 'Talpa API Token',
				'description' => 'The API token to use when connecting to Talpa',
				'hideInLists' => true,
			],
			'talpaSearchSourceString' => [
				'property' => 'talpaSearchSourceString',
				'type' => 'text',
				'label' => 'Search Source String for Talpa',
				'description' => 'What to show in search dropdown menu to search in Talpa',
				'hideInLists' => false,
				'default' => 'Talpa Search',
			],
			'tryThisSearchInTalpaText' => [
				'property' => 'tryThisSearchInTalpaText',
				'type' => 'text',
				'label' => 'Custom "Try this search in Talpa" Link Text',
				'description' => 'Use custom text for the &rdquo;Try this Search in Talpa&rdquo; link (if enabled anywhere in the catalog). Leave blank to use the default value.',
				'hideInLists' => false,
				'default' => 'Try this search in Talpa',
			],
			'tryThisSearchInTalpaSidebarSwitch' => [
				'property' => 'tryThisSearchInTalpaSidebarSwitch',
				'type' => 'checkbox',
				'label' => 'Link in Sidebar',
				'description' => 'You can promote the use of Talpa by adding this link in your main library catalog sidebar, which will launch the patron\'s search terms in Talpa.',
				'hideInLists' => false,
				'default' => 1,
			],

			'tryThisSearchInTalpaNoResultsSwitch' => [
				'property' => 'tryThisSearchInTalpaNoResultsSwitch',
				'type' => 'checkbox',
				'label' => 'Link in "No Results"',
				'description' => 'You can promote the use of Talpa by adding this link when no search results are found, which will launch the patron\'s search terms in Talpa. ',
				'hideInLists' => false,
				'default' => 1,
			],
			'talpaExplainerText' => [
				'property' => 'talpaExplainerText',
				'type' => 'html',
				'allowableTags' => '<p><em><i><strong><b><a><ul><ol><li><h1><h2><h3><h4><h5><h6><h7><pre><hr><table><tbody><tr><th><td><caption><img><br><div><span><sub><sup>',
				'label' => 'Custom Talpa Explainer Text',
				'description' => 'This is the text that customers will see in the sidebar when performing a Talpa Search; it explains what Talpa is and how it works.',
				'hideInLists' => true,
				'default' => '<p>Talpa Search is a new way to search for books and other media. Talpa combines cutting-edge technology with data from libraries, publishers and readers to enable entirely new ways of searching&mdash;and find what you\'re looking for.</span> <a href="https://www.talpasearch.com/about" target="_blank">Learn more about Talpa</a>. </p>',
			],
			'includeTalpaLogoSwitch' => [
				'property' => 'includeTalpaLogoSwitch',
				'type' => 'checkbox',
				'label' => 'Include Talpa logo in sidebar explainer text',
				'description' => 'Show Talpa logo on Talpa Search Results page in the explainer text area. ',
				'hideInLists' => true,
				'default' => 1,
			],
			'talpaOtherResultsExplainerText' => [
				'property' => 'talpaOtherResultsExplainerText',
				'type' => 'text',
				'label' => 'Custom "Other Results" explainer text',
				'description' => 'This appears under the "Other Results" filter and explains the results that Talpa found that are not held by your library.',
				'hideInLists' => true,
				'default' => 'Talpa found these other results.',
			],
//			'talpa_a_id' => [
//				'property' => 'talpa_a_id',
//				'type' => 'text',
//				'label' => 'Talpa Account ID (a_id)',
//				'description' => 'Your library\'s unique a_id',
//				'hideInLists' => true,
//			],

		];
	}
}
