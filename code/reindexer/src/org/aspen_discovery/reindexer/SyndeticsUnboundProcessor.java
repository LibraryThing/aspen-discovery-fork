package org.aspen_discovery.reindexer;

import com.turning_leaf_technologies.indexing.Scope;
import org.apache.logging.log4j.Logger;
import org.json.JSONArray;
import org.json.JSONObject;

import java.nio.charset.StandardCharsets;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Objects;
import java.util.Set;
import java.util.regex.Pattern;

/**
 * Metadata-only enrichment processor for Syndetics Unbound. Reads cached enrichment from
 * syndetics_indexing_data and decorates grouped work Solr docs with per-scope dynamic fields
 * (su_summary_&lt;scopeId&gt;, su_tags_&lt;scopeId&gt;, su_review_text_&lt;scopeId&gt;, ...).
 *
 * Unlike the ILS / eContent processors this does NOT register a constituent record, build a
 * RecordInfo, or contribute holdings, format, or audience. It only adds extra searchable fields
 * to the existing grouped work.
 *
 * See docs/superpowers/specs/2026-05-11-syndetics-unbound-indexing-design.md
 */
class SyndeticsUnboundProcessor {
	private final Connection dbConn;
	private final Logger logger;
	private final GroupedWorkIndexer indexer;
	private PreparedStatement getEnrichmentStmt;
	private PreparedStatement getSettingsForLibraryStmt;
	private HashMap<String, Scope> scopesByName;                                    // built lazily from indexer.getScopes()
	private final HashMap<Long, SettingsInfo> settingsByLibrary = new HashMap<>();  // libraryId -> active SU settings

	SyndeticsUnboundProcessor(GroupedWorkIndexer indexer, Connection dbConn, Logger logger) {
		this.dbConn = dbConn;
		this.logger = logger;
		this.indexer = indexer;
		try {
			getEnrichmentStmt = dbConn.prepareStatement(
					"SELECT feedSource, suTagsFeedKind, UNCOMPRESS(rawResponse) AS rawResponse FROM syndetics_indexing_data " +
					"WHERE syndeticsSettingId = ? AND identifierType = ? AND identifier = ?",
					ResultSet.TYPE_FORWARD_ONLY, ResultSet.CONCUR_READ_ONLY);
		} catch (SQLException e) {
			logger.error("Could not prepare SyndeticsUnboundProcessor statements", e);
		}
	}

	/**
	 * Top-level entry point. Decorates the grouped work with SU enrichment fields, scoped to each
	 * library/location that includes the work and has an active Syndetics Unbound subscription.
	 */
	void decorateGroupedWork(AbstractGroupedWorkSolr groupedWork) {
		try {
			HashMap<String, SettingsInfo> scopes = findScopesWithSu(groupedWork);
			if (scopes.isEmpty()) {
				return;
			}
			List<String[]> identifiers = collectIdentifiers(groupedWork);
			if (identifiers.isEmpty()) {
				return;
			}
			// Many scopes (a library + its branches) share one settingsId, so merge once per settingsId and
			// write to every scope suffix.
			HashMap<Integer, MergedEnrichment> mergedBySettings = new HashMap<>();
			for (Map.Entry<String, SettingsInfo> entry : scopes.entrySet()) {
				String scopeName = entry.getKey();
				int settingsId = entry.getValue().settingsId;
				MergedEnrichment merged;
				if (mergedBySettings.containsKey(settingsId)) {
					merged = mergedBySettings.get(settingsId);   // may be null (no enrichment for this settingsId)
				} else {
					List<EnrichmentRow> rows = loadEnrichmentForScope(settingsId, identifiers);
					merged = rows.isEmpty() ? null : mergeForScope(rows);
					mergedBySettings.put(settingsId, merged);
				}
				if (merged != null) {
					writeToSolr(groupedWork, scopeName, merged);
				}
			}
		} catch (Exception e) {
			logger.error("Error decorating grouped work " + groupedWork.getId() + " with Syndetics Unbound enrichment", e);
		}
	}

	/**
	 * Scope name -> SU settings for every scope (library or location) the work is in whose library has SU
	 * active. Location scopes are included because a search can be branch-scoped; subscription is
	 * library-level, so a library and its locations resolve to the same settings via Scope.getLibraryId().
	 */
	private HashMap<String, SettingsInfo> findScopesWithSu(AbstractGroupedWorkSolr groupedWork) {
		ensureScopesByName();
		HashMap<String, SettingsInfo> result = new HashMap<>();
		for (String scopeName : groupedWork.getScopeNames()) {
			Scope scope = scopesByName.get(scopeName);
			if (scope == null) {
				continue;
			}
			SettingsInfo si = settingsForLibrary(scope.getLibraryId());
			if (si != null) {
				result.put(scopeName, si);
			}
		}
		return result;
	}

	private void ensureScopesByName() {
		if (scopesByName == null) {
			scopesByName = new HashMap<>();
			for (Scope scope : indexer.getScopes()) {
				scopesByName.put(scope.getScopeName(), scope);
			}
		}
	}

	/** Resolves a libraryId to its active SU settings (or null). Cached across works, including negatives. */
	private SettingsInfo settingsForLibrary(Long libraryId) {
		if (libraryId == null) {
			return null;
		}
		if (settingsByLibrary.containsKey(libraryId)) {
			return settingsByLibrary.get(libraryId);
		}
		SettingsInfo si = null;
		try {
			if (getSettingsForLibraryStmt == null) {
				getSettingsForLibraryStmt = dbConn.prepareStatement(
						"SELECT s.id FROM library l JOIN syndetics_settings s ON l.syndeticsSettingId = s.id " +
						"WHERE l.libraryId = ? AND s.syndeticsUnbound = 1 AND s.indexingEnabled = 1");
			}
			getSettingsForLibraryStmt.setLong(1, libraryId);
			try (ResultSet rs = getSettingsForLibraryStmt.executeQuery()) {
				if (rs.next()) {
					si = new SettingsInfo(rs.getInt("id"));
					si.indexingEnabled = true;   // the query already filters indexingEnabled = 1
				}
			}
		} catch (SQLException e) {
			logger.warn("Could not resolve SU settings for library " + libraryId, e);
		}
		settingsByLibrary.put(libraryId, si);
		return si;
	}

	/**
	 * Edition identifiers off the assembled grouped work as (type, identifier) pairs. ISBNs are already
	 * ISBN-13 (addIsbn normalizes); UPCs are raw 024$a, so strip to digits and keep only 12-digit UPC-A to
	 * match the cache.
	 */
	private List<String[]> collectIdentifiers(AbstractGroupedWorkSolr groupedWork) {
		List<String[]> result = new ArrayList<>();
		for (String isbn : groupedWork.getIsbns()) {
			result.add(new String[]{"isbn", isbn});
		}
		for (String upc : groupedWork.getUpcs()) {
			String normalizedUpc = upc.replaceAll("[^0-9]", "");
			if (normalizedUpc.length() == 12) {
				result.add(new String[]{"upc", normalizedUpc});
			}
		}
		return result;
	}

	/** Loads (feedSource, parsed JSON) rows for the identifiers in one settings scope, across both feedSources. */
	private List<EnrichmentRow> loadEnrichmentForScope(int syndeticsSettingId, List<String[]> identifiers) {
		List<EnrichmentRow> result = new ArrayList<>();
		for (String[] idPair : identifiers) {
			try {
				getEnrichmentStmt.setInt(1, syndeticsSettingId);
				getEnrichmentStmt.setString(2, idPair[0]);
				getEnrichmentStmt.setString(3, idPair[1]);
				try (ResultSet rs = getEnrichmentStmt.executeQuery()) {
					while (rs.next()) {
						String feedSource = rs.getString("feedSource");
						byte[] rawBytes = rs.getBytes("rawResponse");
						if (rawBytes != null) {
							String json = new String(rawBytes, StandardCharsets.UTF_8);
							result.add(new EnrichmentRow(feedSource, new JSONObject(json)));
						}
					}
				}
			} catch (Exception e) {
				logger.warn("Could not load enrichment for " + idPair[0] + ":" + idPair[1], e);
			}
		}
		return result;
	}

	/** Merge container for in-flight processing of one scope's data. */
	private static class MergedEnrichment {
		String summary = "";
		String toc = "";
		Set<String> tags = new HashSet<>();           // su_tags index array
		Set<String> tagsFacet = new HashSet<>();      // su_tags facet array
		List<String> reviewTexts = new ArrayList<>();
		List<String> reviewSources = new ArrayList<>();
		List<String> reviewDates = new ArrayList<>();
		Set<String> reviewKeys = new HashSet<>();
		Set<String> profileTerms = new HashSet<>();   // book profile (syndetics_classic FICTION.XML)

		/** Dedupes by (source, date), keeping the longest text. */
		void addReview(String text, String source, String date) {
			String key = source + "|" + date;
			if (reviewKeys.contains(key)) {
				int idx = -1;
				for (int i = 0; i < reviewSources.size(); i++) {
					if (Objects.equals(reviewSources.get(i), source) && Objects.equals(reviewDates.get(i), date)) {
						idx = i;
						break;
					}
				}
				if (idx >= 0 && text != null && text.length() > reviewTexts.get(idx).length()) {
					reviewTexts.set(idx, text);
				}
				return;
			}
			reviewKeys.add(key);
			reviewTexts.add(text == null ? "" : text);
			reviewSources.add(source == null ? "" : source);
			reviewDates.add(date == null ? "" : date);
		}
	}

	/** Merges all cache rows for one scope (across both feedSources) into a single result. */
	private MergedEnrichment mergeForScope(List<EnrichmentRow> rows) {
		MergedEnrichment merged = new MergedEnrichment();
		for (EnrichmentRow row : rows) {
			if ("su_tags".equals(row.feedSource)) {
				mergeSuTagsRow(merged, row.payload);
			} else if ("syndetics_classic".equals(row.feedSource)) {
				mergeClassicRow(merged, row.payload);
			}
		}
		return merged;
	}

	/** SU tags payload: { "index": ["tag", ...], "facet": ["tag", ...] }. */
	private void mergeSuTagsRow(MergedEnrichment merged, JSONObject payload) {
		JSONArray indexArr = payload.optJSONArray("index");
		if (indexArr != null) {
			for (int i = 0; i < indexArr.length(); i++) {
				String text = indexArr.optString(i, "");
				if (!text.isEmpty()) {
					merged.tags.add(text);
				}
			}
		}
		JSONArray facetArr = payload.optJSONArray("facet");
		if (facetArr != null) {
			for (int i = 0; i < facetArr.length(); i++) {
				String text = facetArr.optString(i, "");
				if (!text.isEmpty()) {
					merged.tagsFacet.add(text);
				}
			}
		}
	}

	/** Classic Syndetics payload: { "summary": "...", "toc": [...], "profile": {...}, "reviews": [{...}] }. */
	private void mergeClassicRow(MergedEnrichment merged, JSONObject payload) {
		if (merged.summary.isEmpty()) {
			merged.summary = payload.optString("summary", "");
		}
		if (merged.toc.isEmpty()) {
			Object tocVal = payload.opt("toc");
			if (tocVal instanceof String) {
				merged.toc = (String) tocVal;
			} else if (tocVal instanceof JSONArray) {
				StringBuilder sb = new StringBuilder();
				JSONArray arr = (JSONArray) tocVal;
				for (int i = 0; i < arr.length(); i++) {
					JSONObject entry = arr.optJSONObject(i);
					if (entry != null) {
						sb.append(entry.optString("title", "")).append("\n");
					}
				}
				merged.toc = sb.toString();
			}
		}
		JSONObject profile = payload.optJSONObject("profile");
		if (profile != null) {
			flattenProfile(profile, merged.profileTerms);
		}
		JSONArray reviewsArr = payload.optJSONArray("reviews");
		if (reviewsArr != null) {
			for (int i = 0; i < reviewsArr.length(); i++) {
				JSONObject review = reviewsArr.optJSONObject(i);
				if (review != null) {
					merged.addReview(
							review.optString("text", ""),
							review.optString("source", ""),
							review.optString("date", ""));
				}
			}
		}
	}

	/** Flattens a fiction-profile payload into a set of searchable term strings. */
	private void flattenProfile(JSONObject profile, Set<String> out) {
		JSONArray characters = profile.optJSONArray("characters");
		if (characters != null) {
			for (int i = 0; i < characters.length(); i++) {
				JSONObject c = characters.optJSONObject(i);
				if (c != null) {
					addIfPresent(out, c.optString("name", ""));
					addIfPresent(out, c.optString("description", ""));
				}
			}
		}
		JSONArray topics = profile.optJSONArray("topics");
		if (topics != null) {
			for (int i = 0; i < topics.length(); i++) {
				addIfPresent(out, topics.optString(i, ""));
			}
		}
		JSONArray settings = profile.optJSONArray("settings");
		if (settings != null) {
			for (int i = 0; i < settings.length(); i++) {
				addIfPresent(out, settings.optString(i, ""));   // already formatted "<place> -- <time>"
			}
		}
		JSONArray genres = profile.optJSONArray("genres");
		if (genres != null) {
			for (int i = 0; i < genres.length(); i++) {
				JSONObject g = genres.optJSONObject(i);
				if (g != null) {
					addIfPresent(out, g.optString("name", ""));
					JSONArray subs = g.optJSONArray("subGenres");
					if (subs != null) {
						for (int j = 0; j < subs.length(); j++) {
							addIfPresent(out, subs.optString(j, ""));
						}
					}
				}
			}
		}
		JSONArray awards = profile.optJSONArray("awards");
		if (awards != null) {
			for (int i = 0; i < awards.length(); i++) {
				JSONObject a = awards.optJSONObject(i);
				if (a != null) {
					addIfPresent(out, a.optString("name", ""));   // year intentionally dropped — not a useful search term
				}
			}
		}
	}

	private static void addIfPresent(Set<String> out, String value) {
		if (value != null && !value.isEmpty()) {
			out.add(value);
		}
	}

	// Solr's date type only accepts a full ISO-8601 instant (e.g. 2021-05-01T00:00:00Z). Anything else
	// rejects the document, so su_review_date_* is only written for values matching this.
	private static final Pattern SOLR_DATE = Pattern.compile("\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(\\.\\d+)?Z");

	private static boolean isSolrDate(String value) {
		return value != null && SOLR_DATE.matcher(value).matches();
	}

	/** Writes the merged enrichment to scope-suffixed dynamic Solr fields (su_*_<scopeName>). */
	private void writeToSolr(AbstractGroupedWorkSolr groupedWork, String scopeName, MergedEnrichment merged) {
		if (!merged.summary.isEmpty()) {
			groupedWork.addSyndeticsUnboundField("su_summary_" + scopeName, merged.summary);
		}
		if (!merged.toc.isEmpty()) {
			groupedWork.addSyndeticsUnboundField("su_toc_" + scopeName, merged.toc);
		}
		for (String tagText : merged.tags) {
			groupedWork.addSyndeticsUnboundField("su_tags_" + scopeName, tagText);
		}
		for (String facetTag : merged.tagsFacet) {
			groupedWork.addSyndeticsUnboundField("su_tags_facet_" + scopeName, facetTag);
		}
		// Independent search-only fields. su_review_date_* is a Solr date type, so only emit valid instants
		// (a blank/non-ISO value would reject the whole document).
		for (int i = 0; i < merged.reviewTexts.size(); i++) {
			String text = merged.reviewTexts.get(i);
			String source = merged.reviewSources.get(i);
			String date = merged.reviewDates.get(i);
			if (!text.isEmpty()) {
				groupedWork.addSyndeticsUnboundField("su_review_text_" + scopeName, text);
			}
			if (!source.isEmpty()) {
				groupedWork.addSyndeticsUnboundField("su_review_source_" + scopeName, source);
			}
			if (isSolrDate(date)) {
				groupedWork.addSyndeticsUnboundField("su_review_date_" + scopeName, date);
			}
		}
		for (String term : merged.profileTerms) {
			groupedWork.addSyndeticsUnboundField("su_profile_" + scopeName, term);
		}
	}

	private static class EnrichmentRow {
		final String feedSource;
		final JSONObject payload;

		EnrichmentRow(String feedSource, JSONObject payload) {
			this.feedSource = feedSource;
			this.payload = payload;
		}
	}

	private static class SettingsInfo {
		int settingsId;
		boolean indexingEnabled;

		SettingsInfo(int id) { this.settingsId = id; }
	}
}
