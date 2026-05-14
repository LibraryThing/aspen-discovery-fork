package com.turning_leaf_technologies.syndetics_unbound;

import java.sql.ResultSet;
import java.sql.SQLException;

class SyndeticsUnboundSettings {
	private final long settingsId;
	private final String syndeticsKey;
	private final long unboundAccountNumber;
	private final long unboundInstanceNumber;
	private final long lastUpdateOfChangedRecords;
	private final long lastUpdateOfAllRecords;
	private final boolean runFullUpdate;
	private final boolean indexingEnabled;

	public SyndeticsUnboundSettings(ResultSet settingsRS) throws SQLException {
		settingsId = settingsRS.getLong("id");
		syndeticsKey = settingsRS.getString("syndeticsKey");
		unboundAccountNumber = settingsRS.getLong("unboundAccountNumber");
		unboundInstanceNumber = settingsRS.getLong("unboundInstanceNumber");

		lastUpdateOfChangedRecords = settingsRS.getLong("lastUpdateOfChangedRecords");
		lastUpdateOfAllRecords = settingsRS.getLong("lastUpdateOfAllRecords");
		runFullUpdate = settingsRS.getBoolean("runFullUpdate");
		indexingEnabled = settingsRS.getBoolean("indexingEnabled");
	}

	public long getSettingsId() {
		return settingsId;
	}

	public String getSyndeticsKey() {
		return syndeticsKey;
	}

	public long getUnboundAccountNumber() {
		return unboundAccountNumber;
	}

	public long getUnboundInstanceNumber() {
		return unboundInstanceNumber;
	}

	public long getLastUpdateOfChangedRecords() {
		return lastUpdateOfChangedRecords;
	}

	public long getLastUpdateOfAllRecords() {
		return lastUpdateOfAllRecords;
	}

	public boolean isRunFullUpdate() {
		return runFullUpdate;
	}

	public boolean isIndexingEnabled() {
		return indexingEnabled;
	}
}
