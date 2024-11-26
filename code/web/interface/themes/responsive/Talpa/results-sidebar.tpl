{strip}
	<p>Talpa Search is a .... <a target="_blank" href="https://www.talpasearch.com/about">Click here for more information</a></p>
	{if $filterListApplied == 'global'}
	{if $recordCount || $limitList}
		<div id="refineSearch">
			{* Narrow Results *}
			<div class="row">
				{include file="Search/Recommend/limits.tpl"}
			</div>
		</div>
	{/if}

	{if $recordCount || $sideRecommendations}
		<div id="refineSearch">
			{* Narrow Results *}
			<div class="row">
				{include file="Search/Recommend/SideFacets.tpl"}
			</div>
		</div>
	{/if}
	{/if}
{/strip}
