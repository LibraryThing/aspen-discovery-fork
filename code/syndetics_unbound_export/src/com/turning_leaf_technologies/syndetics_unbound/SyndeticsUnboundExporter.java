package com.turning_leaf_technologies.syndetics_unbound;

import com.turning_leaf_technologies.net.NetworkUtils;
import com.turning_leaf_technologies.net.WebServiceResponse;
import org.apache.logging.log4j.Logger;
import org.aspen_discovery.reindexer.GroupedWorkIndexer;
import org.ini4j.Ini;
import org.json.JSONException;
import org.json.JSONObject;

import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.Date;
import java.util.HashMap;
import java.util.zip.CRC32;

public class SyndeticsUnboundExporter {
	private static final String SU_API_BASE = "https://www.librarything.com/api_unbound.php";

	private static final int BATCH_SIZE = 500;
	private static final int FULL_PASS_DRAIN_INTERVAL = 1000;
	private static final long STALE_CUTOFF_BUFFER_SECONDS = 60 * 60;

	private final String serverName;
	private final Connection aspenConn;
	private final Ini configIni;
	private final SyndeticsUnboundSettings settings;
	private final SyndeticsUnboundExportLogEntry logEntry;
	private final Logger logger;
	private final long passStartTime;
	private GroupedWorkIndexer groupedWorkIndexer;

	public SyndeticsUnboundExporter(String serverName, Connection aspenConn, Ini configIni, SyndeticsUnboundSettings settings, SyndeticsUnboundExportLogEntry logEntry, Logger logger) {
		this.serverName = serverName;
		this.aspenConn = aspenConn;
		this.configIni = configIni;
		this.settings = settings;
		this.logEntry = logEntry;
		this.logger = logger;
		this.passStartTime = new Date().getTime() / 1000;
	}

	public boolean exportSyndeticsUnboundData() {
		return false;
	}

	public void exporterCleanUp() {
		if (groupedWorkIndexer != null) {
			groupedWorkIndexer.finishIndexingFromExtract(logEntry);
			groupedWorkIndexer.close();
			groupedWorkIndexer = null;
		}
	}

	private GroupedWorkIndexer getGroupedWorkIndexer() {
		if (groupedWorkIndexer == null) {
			groupedWorkIndexer = new GroupedWorkIndexer(serverName, aspenConn, configIni, false, false, logEntry, logger);
		}
		return groupedWorkIndexer;
	}

	/**
	 * Validates that the settings row has usable credentials before any HTTP call.
	 * Returns true if credentials are present; false (with logged error + log-entry note)
	 * if syndeticsKey is blank or unboundAccountNumber is non-positive.
	 */
	private boolean validateCredentials() {
		String key = settings.getSyndeticsKey();
		if (key == null || key.trim().isEmpty()) {
			logEntry.incErrors("SU exporter aborting: syndeticsKey is blank for settings row " + settings.getSettingsId());
			return false;
		}
		if (settings.getUnboundAccountNumber() <= 0) {
			logEntry.incErrors("SU exporter aborting: unboundAccountNumber is <= 0 for settings row " + settings.getSettingsId());
			return false;
		}
		return true;
	}

	/**
	 * Loads (identifierType, identifier) -> rawChecksum from syndetics_indexing_data
	 * for this setting at pass start. Subsequent per-record lookups are O(1) against
	 * this map.
	 */
	private HashMap<String, Long> loadExistingChecksums() throws SQLException {
		HashMap<String, Long> checksumMap = new HashMap<>();
		PreparedStatement stmt = aspenConn.prepareStatement(
				"SELECT identifierType, identifier, rawChecksum FROM syndetics_indexing_data WHERE syndeticsSettingId = ?");
		stmt.setLong(1, settings.getSettingsId());
		ResultSet rs = stmt.executeQuery();
		while (rs.next()) {
			long rawChecksum = rs.getLong("rawChecksum");
			if (rs.wasNull()) {
				continue;
			}
			String key = rs.getString("identifierType") + ":" + rs.getString("identifier");
			checksumMap.put(key, rawChecksum);
		}
		rs.close();
		stmt.close();
		return checksumMap;
	}

	private static long computeChecksum(String payload) {
		CRC32 crc = new CRC32();
		crc.update(payload.getBytes(StandardCharsets.UTF_8));
		return crc.getValue();
	}

	/**
	 * Calls the SU API for one batch of records. Returns the parsed response or null on
	 * persistent failure. The caller decides whether to abort the pass on null.
	 * Pagination: pass nextCursor from the previous response; pass null for the first call.
	 */
	private JSONObject fetchBatch(boolean fullSnapshot, String cursor) {
		StringBuilder url = new StringBuilder(SU_API_BASE);
		url.append("?syndeticsKey=").append(URLEncoder.encode(settings.getSyndeticsKey(), StandardCharsets.UTF_8));
		url.append("&a_id=").append(settings.getUnboundAccountNumber());
		url.append("&limit=").append(BATCH_SIZE);
		if (!fullSnapshot) {
			url.append("&since=").append(settings.getLastUpdateOfChangedRecords());
		}
		if (cursor != null) {
			url.append("&cursor=").append(URLEncoder.encode(cursor, StandardCharsets.UTF_8));
		}
		HashMap<String, String> headers = new HashMap<>();
		headers.put("Accept", "application/json");

		WebServiceResponse response = NetworkUtils.getURL(url.toString(), logger, headers);
		if (!response.isSuccess()) {
			int code = response.getResponseCode();
			if (code == 401 || code == 403) {
				handleAuthFailure(response);
			} else if (code == 429) {
				logEntry.incErrors("Rate-limited by SU API (HTTP 429)");
			} else if (code == 503 || code == 504) {
				logEntry.incErrors("Transient SU API error " + code);
			} else {
				logEntry.incErrors("Persistent SU API error " + code + ": " + response.getMessage());
			}
			return null;
		}
		try {
			return new JSONObject(response.getMessage());
		} catch (JSONException e) {
			String body = response.getMessage();
			String snippet = body == null ? "(null)" : body.substring(0, Math.min(200, body.length()));
			logEntry.incErrors("Malformed JSON from SU API: " + snippet, e);
			return null;
		}
	}

	private void handleAuthFailure(WebServiceResponse response) {
	}
}
