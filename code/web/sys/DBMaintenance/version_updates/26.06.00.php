<?php
/** @noinspection SqlDialectInspection */

/** @noinspection PhpUnused */
function getUpdates26_06_00(): array {
	$now = time();

	return [
		/*'name' => [
			 'title' => '',
			 'description' => '',
			 'continueOnError' => false,
			 'sql' => [
				 ''
			 ]
		 ], //name*/

		//mark n

		//kirstien

		//kodi

		//yanjun

		//imani

		//galen

		//chloe

		//pedro

		//mark j

		//lucas

		//tomas

		// stephen

		//other
		'syndetics_indexing_v1' => [
			'title' => 'Syndetics Unbound indexing schema',
			'description' => 'Add indexing-related columns to syndetics_settings; create syndetics_indexing_data cache and syndetics_indexing_log audit tables.',
			'continueOnError' => false,
			'sql' => [
				"ALTER TABLE syndetics_settings
					ADD COLUMN lastUpdateOfChangedRecords BIGINT(20) DEFAULT NULL,
					ADD COLUMN lastUpdateOfAllRecords BIGINT(20) DEFAULT NULL,
					ADD COLUMN runFullUpdate TINYINT(1) NOT NULL DEFAULT 0,
					ADD COLUMN indexingEnabled TINYINT(1) NOT NULL DEFAULT 0",
				"CREATE TABLE IF NOT EXISTS syndetics_indexing_data (
					id BIGINT(20) NOT NULL AUTO_INCREMENT,
					syndeticsSettingId INT(11) NOT NULL,
					identifierType VARCHAR(10) NOT NULL,
					identifier VARCHAR(20) NOT NULL,
					workcode BIGINT(20) DEFAULT NULL,
					rawChecksum BIGINT(20) DEFAULT NULL,
					rawResponse MEDIUMBLOB DEFAULT NULL,
					lastFetched BIGINT(20) NOT NULL,
					dateFirstDetected BIGINT(20) DEFAULT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY scope_identifier (syndeticsSettingId, identifierType, identifier),
					KEY identifier (identifier),
					KEY lastFetched (lastFetched)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
				"CREATE TABLE IF NOT EXISTS syndetics_indexing_log (
					id INT(11) NOT NULL AUTO_INCREMENT,
					syndeticsSettingId INT(11) NOT NULL,
					startTime INT(11) NOT NULL,
					endTime INT(11) DEFAULT NULL,
					lastUpdate INT(11) DEFAULT NULL,
					notes MEDIUMTEXT,
					numProducts INT(11) DEFAULT 0,
					numErrors INT(11) DEFAULT 0,
					numInvalidRecords INT(11) DEFAULT 0,
					numAdded INT(11) DEFAULT 0,
					numUpdated INT(11) DEFAULT 0,
					numDeleted INT(11) DEFAULT 0,
					numSkipped INT(11) DEFAULT 0,
					PRIMARY KEY (id),
					KEY startTime (startTime),
					KEY syndeticsSettingId (syndeticsSettingId)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
			]
		], //syndetics_indexing_v1

	];
}
