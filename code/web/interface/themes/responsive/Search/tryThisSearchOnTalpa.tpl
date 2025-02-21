<div id="tryThisSearchOnTalpa" class="facetTitle" style="margin-bottom: 10px; border-bottom: none">
	<div style="text-align: center">
	{if $talpaTryItButton == 1 }
			<a class = "btn btn-primary" href="{$talpaSearchLink}">{$tryThisSearchInTalpaText}</a>
	{elseif $talpaTryItButton == 2 }
		<a href="{$talpaSearchLink}"><img src="https://pics.cdn.librarything.com//pics/talpa/5/talpa_b_60h.png" srcset="https://pics.cdn.librarything.com//pics/talpa/5/talpa_b_60h@2x.png 2x, https://pics.cdn.librarything.com//pics/talpa/5/talpa_b_60h@3x.png 3x"></a>
		<p>{$tryThisSearchInTalpaText}</p>
	{elseif $talpaTryItButton == 3 }
		<a href="{$talpaSearchLink}"><img src="https://pics.cdn.librarything.com//pics/talpa/5/talpa_b2_40h.png" srcset="https://pics.cdn.librarything.com//pics/talpa/5/talpa_b2_40h@2x.png 2x, https://pics.cdn.librarything.com//pics/talpa/5/talpa_b2_40h@3x.png 3x"></a>
		<p>{$tryThisSearchInTalpaText}</p>
	{elseif $talpaTryItButton == 4 }
		<a href="{$talpaSearchLink}"><img src="https://pics.cdn.librarything.com//pics/talpa/5/talpa_b2_d_40h.png" srcset="https://pics.cdn.librarything.com//pics/talpa/5/talpa_b2_d_40h@2x.png 2x, https://pics.cdn.librarything.com//pics/talpa/5/talpa_b2_d_40h@3x.png 3x"></a>
		<p>{$tryThisSearchInTalpaText}</p>

	{/if}
	</div>
</div>
