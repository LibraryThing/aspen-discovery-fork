package com.turning_leaf_technologies.syndetics_unbound;

import com.turning_leaf_technologies.net.NetworkUtils;
import com.turning_leaf_technologies.net.WebServiceResponse;
import org.apache.logging.log4j.Logger;
import org.aspen_discovery.reindexer.GroupedWorkIndexer;
import org.ini4j.Ini;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.ArrayList;
import java.util.Date;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
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
		if (!settings.isIndexingEnabled()) {
			return runCleanupIfNeeded();
		}

		if (!validateCredentials()) {
			return false;
		}

		boolean fullSnapshot = settings.isRunFullUpdate();
		logEntry.addNote(fullSnapshot ? "Starting full snapshot pass" : "Starting incremental pass since " + settings.getLastUpdateOfChangedRecords());
		logEntry.saveResults();

		HashMap<String, Long> existingChecksums;
		try {
			existingChecksums = loadExistingChecksums();
		} catch (SQLException e) {
			logEntry.incErrors("Could not load existing checksums", e);
			return false;
		}
		logger.info("Loaded " + existingChecksums.size() + " existing checksums");

		HashSet<String> changedGroupedWorks = new HashSet<>();
		int numProcessed = 0;
		String cursor = null;
		boolean firstBatch = true;

		do {
			JSONObject response = fetchBatch(fullSnapshot, cursor);
			if (response == null) {
				// fetchBatch already logged the error. Abort the pass — cursor preserved.
				return numProcessed > 0;
			}
			if (firstBatch) {
				if (response.has("totalEstimated")) {
					logEntry.incNumProducts(response.getInt("totalEstimated"));
					logEntry.saveResults();
				}
				firstBatch = false;
			}
			JSONArray records = response.optJSONArray("records");
			if (records != null) {
				for (int i = 0; i < records.length(); i++) {
					JSONObject record = records.getJSONObject(i);
					String changedKey = processRecord(record, existingChecksums);
					numProcessed++;
					if (changedKey != null) {
						int colonIdx = changedKey.indexOf(':');
						String idType = changedKey.substring(0, colonIdx);
						String idValue = changedKey.substring(colonIdx + 1);
						for (String groupedWorkId : findGroupedWorksForIdentifier(idType, idValue)) {
							changedGroupedWorks.add(groupedWorkId);
						}
					}
					if (fullSnapshot && changedGroupedWorks.size() >= FULL_PASS_DRAIN_INTERVAL) {
						drainReindexQueue(changedGroupedWorks);
					}
					if (numProcessed % 100 == 0) {
						logEntry.saveResults();
					}
				}
			}
			cursor = response.optString("nextCursor", null);
		} while (cursor != null && !cursor.isEmpty());

		drainReindexQueue(changedGroupedWorks);

		logEntry.addNote("Processed " + numProcessed + " records");

		if (!logEntry.hasErrors()) {
			updateCursorOnSuccess(fullSnapshot);
			if (fullSnapshot) {
				runStalePurge(existingChecksums);
			}
		}

		return numProcessed > 0;
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

	/**
	 * Processes a single record from the API response. Returns the identifier
	 * pair "type:value" if the record was changed (and a reindex should be queued),
	 * or null if the record was unchanged (checksum match) or skipped.
	 */
	private String processRecord(JSONObject record, HashMap<String, Long> existingChecksums) {
		try {
			String identifierType = record.getString("identifierType");
			identifierType = identifierType.toLowerCase();
			String rawIdentifier = record.getString("identifier");
			String identifier = normalizeIdentifier(identifierType, rawIdentifier);
			if (identifier == null) {
				logEntry.incInvalidRecords(identifierType + ":" + rawIdentifier);
				return null;
			}

			String rawJson = record.toString();
			long checksum = computeChecksum(rawJson);
			String key = identifierType + ":" + identifier;
			Long existing = existingChecksums.get(key);

			if (existing != null && existing == checksum) {
				PreparedStatement bumpStmt = aspenConn.prepareStatement(
						"UPDATE syndetics_indexing_data SET lastFetched = ? WHERE syndeticsSettingId = ? AND identifierType = ? AND identifier = ?");
				bumpStmt.setLong(1, passStartTime);
				bumpStmt.setLong(2, settings.getSettingsId());
				bumpStmt.setString(3, identifierType);
				bumpStmt.setString(4, identifier);
				bumpStmt.executeUpdate();
				bumpStmt.close();
				logEntry.incSkipped();
				return null;
			}

			long workcode = record.optLong("workcode", 0);
			boolean isNew = (existing == null);

			PreparedStatement upsertStmt = aspenConn.prepareStatement(
					"INSERT INTO syndetics_indexing_data " +
					"(syndeticsSettingId, identifierType, identifier, workcode, rawChecksum, rawResponse, lastFetched, dateFirstDetected) " +
					"VALUES (?, ?, ?, ?, ?, COMPRESS(?), ?, ?) " +
					"ON DUPLICATE KEY UPDATE workcode = VALUES(workcode), rawChecksum = VALUES(rawChecksum), " +
					"rawResponse = VALUES(rawResponse), lastFetched = VALUES(lastFetched)");
			upsertStmt.setLong(1, settings.getSettingsId());
			upsertStmt.setString(2, identifierType);
			upsertStmt.setString(3, identifier);
			if (workcode > 0) {
				upsertStmt.setLong(4, workcode);
			} else {
				upsertStmt.setNull(4, java.sql.Types.BIGINT);
			}
			upsertStmt.setLong(5, checksum);
			upsertStmt.setString(6, rawJson);
			upsertStmt.setLong(7, passStartTime);
			upsertStmt.setLong(8, passStartTime);
			upsertStmt.executeUpdate();
			upsertStmt.close();

			existingChecksums.put(key, checksum);

			if (isNew) {
				logEntry.incAdded();
			} else {
				logEntry.incUpdated();
			}
			return key;
		} catch (JSONException e) {
			logEntry.incInvalidRecords("(malformed SU record)");
			logger.warn("Malformed SU record", e);
			return null;
		} catch (SQLException e) {
			logEntry.incErrors("Database error processing SU record", e);
			return null;
		}
	}

	/**
	 * Normalizes ISBN/UPC per spec: ISBN-10 is converted to ISBN-13; UPC strips non-digits.
	 * Returns null if the identifier is unrecognized or invalid.
	 */
	private static String normalizeIdentifier(String type, String raw) {
		if (raw == null || raw.isEmpty()) {
			return null;
		}
		if ("isbn".equalsIgnoreCase(type)) {
			String trimmed = raw.toUpperCase().replaceAll("[^0-9X]", "");
			if (trimmed.length() == 13) {
				return trimmed;
			}
			if (trimmed.length() == 10) {
				return convertISBN10to13(trimmed);
			}
			return null;
		} else if ("upc".equalsIgnoreCase(type)) {
			String normalized = raw.replaceAll("[^0-9]", "");
			return normalized.isEmpty() ? null : normalized;
		}
		return null;
	}

	private static String convertISBN10to13(String isbn10) {
		if (isbn10.length() != 10) {
			return null;
		}
		String isbnWithoutCheckDigit = isbn10.substring(0, 9);
		if (!isbnWithoutCheckDigit.matches("\\d+")) {
			return null;
		}
		String isbn = "978" + isbnWithoutCheckDigit;
		int sumOfDigits = 0;
		for (int i = 0; i < 12; i++) {
			int multiplier = 1;
			if (i % 2 == 1) {
				multiplier = 3;
			}
			int curDigit = Integer.parseInt(Character.toString(isbn.charAt(i)));
			sumOfDigits += multiplier * curDigit;
		}
		int modValue = sumOfDigits % 10;
		int checksumDigit;
		if (modValue == 0) {
			checksumDigit = 0;
		} else {
			checksumDigit = 10 - modValue;
		}
		return isbn + checksumDigit;
	}

	/**
	 * Finds grouped works that contain the given identifier as a primary identifier.
	 * Used to queue reindex on the affected grouped works.
	 */
	private List<String> findGroupedWorksForIdentifier(String identifierType, String identifier) {
		List<String> result = new ArrayList<>();
		if (identifier == null) {
			return result;
		}
		try {
			PreparedStatement stmt = aspenConn.prepareStatement(
					"SELECT DISTINCT gw.permanent_id FROM grouped_work gw " +
					"JOIN grouped_work_primary_identifiers gwpi ON gw.id = gwpi.grouped_work_id " +
					"WHERE gwpi.type = ? AND gwpi.identifier = ?");
			stmt.setString(1, identifierType);
			stmt.setString(2, identifier);
			ResultSet rs = stmt.executeQuery();
			while (rs.next()) {
				result.add(rs.getString("permanent_id"));
			}
			rs.close();
			stmt.close();
		} catch (SQLException e) {
			logEntry.incErrors("Could not resolve grouped works for " + identifierType + ":" + identifier, e);
		}
		return result;
	}

	/**
	 * Drains the reindex queue: processes each unique grouped work ID and commits to Solr.
	 */
	private void drainReindexQueue(HashSet<String> queue) {
		if (queue.isEmpty()) {
			return;
		}
		GroupedWorkIndexer indexer = getGroupedWorkIndexer();
		for (String groupedWorkId : queue) {
			indexer.processGroupedWork(groupedWorkId);
		}
		indexer.commitChanges();
		queue.clear();
	}

	private void updateCursorOnSuccess(boolean fullSnapshot) {
		try {
			String sql = fullSnapshot
					? "UPDATE syndetics_settings SET lastUpdateOfAllRecords = ?, lastUpdateOfChangedRecords = ?, runFullUpdate = 0 WHERE id = ?"
					: "UPDATE syndetics_settings SET lastUpdateOfChangedRecords = ? WHERE id = ?";
			PreparedStatement stmt = aspenConn.prepareStatement(sql);
			stmt.setLong(1, passStartTime);
			if (fullSnapshot) {
				stmt.setLong(2, passStartTime);
				stmt.setLong(3, settings.getSettingsId());
			} else {
				stmt.setLong(2, settings.getSettingsId());
			}
			stmt.executeUpdate();
			stmt.close();
		} catch (SQLException e) {
			logEntry.incErrors("Could not update cursor on success", e);
		}
	}

	private void runStalePurge(HashMap<String, Long> existingChecksums) {
	}

	private boolean runCleanupIfNeeded() {
		return false;
	}

	private void handleAuthFailure(WebServiceResponse response) {
	}
}
