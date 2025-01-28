<div id="searchError">
	<h1>{translate text="There was an error processing your search" isPublicFacing=true}</h1>
	<div>{translate text="We were unable to process your search. Please try to rephrase your query, or try again later." isPublicFacing=true}</div>


	{if !empty($searchError) && !empty($searchError.error.msg)}
		<h2>{translate text="Error description" isPublicFacing=true}</h2>
		<div>
			{$searchError.error.msg}
		</div>
	{/if}
</div>
