{strip}
	<div id="main-content" class="col-md-12">
		<h1>{translate text="Syndetics Unbound Indexing Log" isAdminFacing=true}</h1>

		<form class="form-inline" method="get" action="/SyndeticsUnbound/IndexingLog">
			<div class="form-group" style="margin-right: 15px;">
				<label for="feedSource" style="margin-right: 5px;">{translate text="Feed" isAdminFacing=true}</label>
				<select id="feedSource" name="feedSource" class="form-control input-sm">
					<option value=""{if $selectedFeedSource == ''} selected="selected"{/if}>{translate text="All feeds" isAdminFacing=true}</option>
					<option value="su_tags"{if $selectedFeedSource == 'su_tags'} selected="selected"{/if}>{translate text="Tags" isAdminFacing=true}</option>
					<option value="syndetics_classic"{if $selectedFeedSource == 'syndetics_classic'} selected="selected"{/if}>{translate text="Enrichment" isAdminFacing=true}</option>
					<option value="cleanup"{if $selectedFeedSource == 'cleanup'} selected="selected"{/if}>{translate text="Cleanup" isAdminFacing=true}</option>
				</select>
			</div>
			<div class="form-group" style="margin-right: 15px;">
				<label for="showErrorsOnly" style="margin-right: 5px;">{translate text="Show Errors Only" isAdminFacing=true}</label>
				<input type="checkbox" name="showErrorsOnly" id="showErrorsOnly" {if !empty($showErrorsOnly)}checked{/if}/>
			</div>
			<button class="btn btn-primary btn-sm" type="submit">{translate text="Apply" isAdminFacing=true}</button>
		</form>

		<div class="adminTableRegion fixed-height-table">
			<table class="adminTable table table-condensed table-hover smallText table-sticky">
				<thead>
					<tr>
						<th>{translate text="Id" isAdminFacing=true}</th>
						<th>{translate text="Feed" isAdminFacing=true}</th>
						<th>{translate text="Started" isAdminFacing=true}</th>
						<th>{translate text="Last Update" isAdminFacing=true}</th>
						<th>{translate text="Finished" isAdminFacing=true}</th>
						<th>{translate text="Elapsed" isAdminFacing=true}</th>
						<th>{translate text="Total Products" isAdminFacing=true}</th>
						<th>{translate text="Added" isAdminFacing=true}</th>
						<th>{translate text="Updated" isAdminFacing=true}</th>
						<th>{translate text="Deleted" isAdminFacing=true}</th>
						<th>{translate text="Skipped" isAdminFacing=true}</th>
						<th>{translate text="Invalid Records" isAdminFacing=true}</th>
						<th>{translate text="Num Errors" isAdminFacing=true}</th>
						<th>{translate text="Notes" isAdminFacing=true}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$logEntries item=logEntry}
						<tr>
							<td>{$logEntry->id}</td>
							<td>{$logEntry->feedSource}</td>
							<td>{$logEntry->startTime|date_format:"%D %T"}</td>
							<td>{$logEntry->lastUpdate|date_format:"%D %T"}</td>
							<td>{$logEntry->endTime|date_format:"%D %T"}</td>
							<td>{$logEntry->getElapsedTime()}</td>
							<td>{$logEntry->numProducts}</td>
							<td>{$logEntry->numAdded}</td>
							<td>{$logEntry->numUpdated}</td>
							<td>{$logEntry->numDeleted}</td>
							<td>{$logEntry->numSkipped}</td>
							<td>{$logEntry->numInvalidRecords}</td>
							<td>{$logEntry->numErrors}</td>
							<td class="preserveWhitespace">{$logEntry->notes|escape}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		</div>

		{if !empty($pageLinks.all)}<div class="text-center">{$pageLinks.all}</div>{/if}
	</div>
{/strip}
