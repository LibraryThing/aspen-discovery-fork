<?php


class TalpaSettings extends DataObject {
	public $__table = 'talpa_settings';
	public $id;
	public $name;
	public $talpaApiToken;
	public $talpaSearchSourceString;

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
				'description' => 'The string to show in Index to search in Talpa',
				'hideInLists' => false,
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