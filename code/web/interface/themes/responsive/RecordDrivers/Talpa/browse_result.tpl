{strip}
	{if $browseMode == '1'}
		<div class="{*browse-title *}browse-list grid-item {$coverStyle} {if $browseStyle == 'grid'}browse-grid-style col-tn-6 col-xs-6 col-sm-6 col-md-4 col-lg-3{/if}">
		<div class="{*browse-title *}browse-list grid-item {$coverStyle} {if $browseStyle == 'grid'}browse-grid-style col-tn-6 col-xs-6 col-sm-6 col-md-4 col-lg-3{/if}">
			<a href="{$talpaUrl}" target="_blank" aria-label="{$talpaTitle} ({translate text='opens in new window' isPublicFacing=true})">
				<img class="img-responsive" src="{$bookCoverUrl}" alt="{$talpaTitle}" title="{$talpaTitle}">
				<div><strong>{$talpaTitle}</strong></div>
			</a>
		</div>

	{else}{*Default Browse Mode (covers) *}
		<div class="browse-thumbnail grid-item {$coverStyle} {if $browseStyle == 'grid'}col-tn-6 col-xs-4 col-sm-4 col-md-3 col-lg-2{/if}">
			<a href="{$talpaUrl}" target="_blank" aria-label="{$talpaTitle} ({translate text='opens in new window' isPublicFacing=true})">
				<div>
					<img src="{$bookCoverUrlMedium}" alt="{$talpaTitle}" title="{$talpaTitle}">
				</div>
			</a>
		</div>
	{/if}
{/strip}