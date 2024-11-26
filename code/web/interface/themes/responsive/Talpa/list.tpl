<h1 class="hiddenTitle">{translate text='Talpa Search Results'}</h1>
<div id="searchInfo">
	{* Library/Other Results Facets *}
	{if !empty($topRecommendations)}
		{foreach from=$topRecommendations item="recommendations"}
			{include file=$recommendations}
		{/foreach}
	{/if}
	<div class="result-head">
		{* User's viewing mode toggle switch *}
		{if !empty($replacementTerm)}
			<div id="replacement-search-info-block">
				<div id="replacement-search-info"><span class="replacement-search-info-text">{translate text="Showing Results for" isPublicFacing=true}</span> {$replacementTerm}</div>
				<div id="original-search-info"><span class="replacement-search-info-text">{translate text="Search instead for" isPublicFacing=true} </span><a href="{$oldSearchUrl}">{$oldTerm}</a></div>
			</div>
		{/if}

		{if !empty($solrSearchDebug)}
			<div id="solrSearchOptionsToggle" onclick="$('#solrSearchOptions').toggle()">{translate text="Show Search Options" isAdminFacing=true}</div>
			<div id="solrSearchOptions" style="display:none">
				<pre>{translate text="Search options" isPublicFacing=true} {$solrSearchDebug}</pre>
			</div>
		{/if}

		{if !empty($solrLinkDebug)}
			<div id='solrLinkToggle' onclick='$("#solrLink").toggle()'>{translate text="Show Solr Link" isAdminFacing=true}</div>
			<div id='solrLink' style='display:none'>
				<pre>{$solrLinkDebug}</pre>
			</div>
		{/if}

		{if !empty($debugTiming)}
			<div id='solrTimingToggle' onclick='$("#solrTiming").toggle()'>{translate text="Show Solr Timing" isAdminFacing=true}</div>
			<div id='solrTiming' style='display:none'>
				<pre>{$debugTiming}</pre>
			</div>
		{/if}

		<div class="clearer"></div>
	</div>

	{if !empty($subpage)}
		{include file=$subpage}
	{else}
		{$pageContent}
	{/if}

	{if !empty($pageLinks.all)}<div class="text-center">{$pageLinks.all}</div>{/if}
</div>

{* Embedded Javascript For this Page *}
<script type="text/javascript">
	$(function(){ldelim}
		if ($('#horizontal-menu-bar-container').is(':visible')) {ldelim}
			$('#home-page-search').show();  {*// Always show the searchbox for search results in mobile views.*}
		{rdelim}

		AspenDiscovery.Talpa.getTalpaResults("{$lookfor|escapeCSS}");


		{if empty($onInternalIP)}
			{* Because content is served on the page, have to set the mode that was used, even if the user didn't choose the mode. *}
			AspenDiscovery.Searches.displayMode = '{$displayMode}';
		{else}
			AspenDiscovery.Searches.displayMode = '{$displayMode}';
			Globals.opac = 1; {* set to true to keep opac browsers from storing browse mode *}
		{/if}
		$('#'+AspenDiscovery.Searches.displayMode).parent('label').addClass('active'); {* show user which one is selected *}
		{rdelim});
</script>
