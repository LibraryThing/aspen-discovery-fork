<?php

function getTalpaUpdates() {
	return [

//		'hoopla_add_settings' => [
//			'title' => 'Add Hoopla Settings',
//			'description' => 'Add Settings for Hoopla to move configuration out of ini',
//			'sql' => [
//				"CREATE TABLE IF NOT EXISTS hoopla_settings(
//						id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
//						apiUrl VARCHAR(255),
//						libraryId INT(11) DEFAULT 0,
//						apiUsername VARCHAR(50),
//						apiPassword VARCHAR(50),
//						runFullUpdate TINYINT(1) DEFAULT 0,
//						lastUpdateOfChangedRecords INT(11) DEFAULT 0,
//						lastUpdateOfAllRecords INT(11) DEFAULT 0
//					)",
//			],
//		],

		'create_talpa_module' => [
			'title' => 'Create Talpa Module',
			'description' => 'Setup Talpa module',
			'sql' => [
				"INSERT INTO modules (name, indexName, backgroundProcess) VALUES ('Talpa Search', '', '')",
			],
		],
		'createSettingsForTalpa' => [
			'title' => 'Create Talpa settings',
			'description' => 'Create settings to store information for Talpa Search Integrations',
			'continueOnError' => true,
			'sql' => [
				"CREATE TABLE talpa_settings (
					id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(50) NOT NULL,
					talpaApiToken VARCHAR(32) DEFAULT ''
				) ENGINE=INNODB",
				'ALTER TABLE library ADD COLUMN talpaSettingsId INT(11) DEFAULT -1',
			],
		],
//		'disable_hoopla_module_auto_restart' => [
//			'title' => 'Disable Hoopla Auto Restart',
//			'description' => 'Disable Hoopla Auto Restart',
//			'sql' => [
//				"UPDATE modules SET backgroundProcess = '' WHERE name = 'Hoopla'",
//			],
//		],
//
//		're_enable_hoopla_module_auto_restart' => [
//			'title' => 'Re-enable Hoopla Auto Restart',
//			'description' => 'Re-enable Hoopla Auto Restart',
//			'sql' => [
//				"UPDATE modules SET backgroundProcess = 'hoopla_export' WHERE name = 'Hoopla'",
//			],
//		],
//
//		'hoopla_module_add_log' => [
//			'title' => 'Hoopla add log info to module',
//			'description' => 'Add logging information to Hoopla module',
//			'sql' => [
//				"UPDATE modules set logClassPath='/sys/Hoopla/HooplaExportLogEntry.php', logClassName='HooplaExportLogEntry' WHERE name='Hoopla'",
//			],
//		],
//
//		'hoopla_add_settings_2' => [
//			'title' => 'Add Settings to Hoopla module',
//			'description' => 'Add Settings to Hoopla module',
//			'sql' => [
//				"UPDATE modules set settingsClassPath = '/sys/Hoopla/HooplaSetting.php', settingsClassName = 'HooplaSetting' WHERE name = 'Hoopla'",
//			],
//		],
//
//		'hoopla_add_setting_to_scope' => [
//			'title' => 'Add settingId to Hoopla scope',
//			'description' => 'Allow multiple settings to be defined for Hoopla within a consortium',
//			'continueOnError' => true,
//			'sql' => [
//				'ALTER TABLE hoopla_scopes ADD column settingId INT(11)',
//				'updateHooplaScopes',
//			],
//		],
//
//		'hoopla_usage_add_instance' => [
//			'title' => 'Hoopla Usage - Instance Information',
//			'description' => 'Add Instance Information to Hoopla Usage stats',
//			'continueOnError' => true,
//			'sql' => [
//				'ALTER TABLE hoopla_record_usage ADD COLUMN instance VARCHAR(100)',
//				'ALTER TABLE hoopla_record_usage DROP INDEX hooplaId',
//				'ALTER TABLE hoopla_record_usage ADD UNIQUE INDEX (instance, hooplaId, year, month)',
//				'ALTER TABLE user_hoopla_usage ADD COLUMN instance VARCHAR(100)',
//				'ALTER TABLE user_hoopla_usage DROP INDEX userId',
//				'ALTER TABLE user_hoopla_usage ADD UNIQUE INDEX (instance, userId, year, month)',
//			],
//		],
	];
}
