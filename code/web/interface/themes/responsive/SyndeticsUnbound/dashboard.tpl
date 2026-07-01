{strip}
	<div id="main-content" class="col-md-12">
		<h1>{translate text="Syndetics Unbound Indexing Dashboard" isAdminFacing=true}</h1>

		{if !empty($reloadMessage)}<div class="alert alert-success">{$reloadMessage}</div>{/if}
		{if !empty($reloadError)}<div class="alert alert-danger">{$reloadError}</div>{/if}

		<p><a href="/SyndeticsUnbound/ReloadRecord" class="btn btn-default">{translate text="Reload Single Record" isAdminFacing=true}</a></p>

		{if empty($settingsRows)}
			<div class="alert alert-info">{translate text="No Syndetics Unbound settings have indexing enabled." isAdminFacing=true}</div>
		{else}
			{foreach from=$settingsRows item=row}
				<div class="panel panel-default">
					<div class="panel-heading">
						<strong>{translate text="Settings" isAdminFacing=true} #{$row.id}</strong> &mdash; {translate text="Account" isAdminFacing=true} {$row.unboundAccountNumber}
					</div>
					<div class="panel-body">
						<table class="table table-condensed table-bordered">
							<thead>
								<tr>
									<th>{translate text="Feed" isAdminFacing=true}</th>
									<th>{translate text="Version / Cursor" isAdminFacing=true}</th>
									<th>{translate text="Last Fetched" isAdminFacing=true}</th>
									<th>{translate text="Last Pass" isAdminFacing=true}</th>
									<th>{translate text="Errors" isAdminFacing=true}</th>
									<th>{translate text="Reload" isAdminFacing=true}</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{translate text="Tags (popular titles)" isAdminFacing=true}</td>
									<td>{if $row.seedVersion === null}&mdash;{else}{$row.seedVersion}{/if}</td>
									<td>{if $row.seedFetchedAt}{$row.seedFetchedAt|date_format:"%D %T"}{else}&mdash;{/if}</td>
									<td>{if $row.tagsLog.endTime}{$row.tagsLog.endTime|date_format:"%D %T"}{else}&mdash;{/if}</td>
									<td>{$row.tagsLog.numErrors|default:0}</td>
									<td>
										<form method="post" action="/SyndeticsUnbound/Dashboard" onsubmit="return confirm('{translate text="Reload this feed on the next cron pass?" isAdminFacing=true inAttribute=true}');">
											<input type="hidden" name="settingsId" value="{$row.id}">
											<input type="hidden" name="reloadFeed" value="seed">
											<button type="submit" class="btn btn-xs btn-warning">{translate text="Reload" isAdminFacing=true}</button>
										</form>
									</td>
								</tr>
								<tr>
									<td>{translate text="Tags (holdings)" isAdminFacing=true}</td>
									<td>{if $row.libraryVersion === null}&mdash;{else}{$row.libraryVersion}{/if}</td>
									<td>{if $row.libraryFetchedAt}{$row.libraryFetchedAt|date_format:"%D %T"}{else}&mdash;{/if}</td>
									<td>{if $row.tagsLog.endTime}{$row.tagsLog.endTime|date_format:"%D %T"}{else}&mdash;{/if}</td>
									<td>{$row.tagsLog.numErrors|default:0}</td>
									<td>
										<form method="post" action="/SyndeticsUnbound/Dashboard" onsubmit="return confirm('{translate text="Reload this feed on the next cron pass?" isAdminFacing=true inAttribute=true}');">
											<input type="hidden" name="settingsId" value="{$row.id}">
											<input type="hidden" name="reloadFeed" value="library">
											<button type="submit" class="btn btn-xs btn-warning">{translate text="Reload" isAdminFacing=true}</button>
										</form>
									</td>
								</tr>
								<tr>
									<td>{translate text="Summaries, Reviews, TOC and Book Profile" isAdminFacing=true}</td>
									<td>{if $row.classicCursor === null}&mdash;{else}{$row.classicCursor}{/if}</td>
									<td>{if $row.classicLastFullPassAt}{$row.classicLastFullPassAt|date_format:"%D %T"}{else}&mdash;{/if}</td>
									<td>{if $row.classicLog.endTime}{$row.classicLog.endTime|date_format:"%D %T"}{else}&mdash;{/if}</td>
									<td>{$row.classicLog.numErrors|default:0}</td>
									<td>
										<form method="post" action="/SyndeticsUnbound/Dashboard" onsubmit="return confirm('{translate text="Reload this feed on the next cron pass?" isAdminFacing=true inAttribute=true}');">
											<input type="hidden" name="settingsId" value="{$row.id}">
											<input type="hidden" name="reloadFeed" value="classic">
											<button type="submit" class="btn btn-xs btn-warning">{translate text="Reload" isAdminFacing=true}</button>
										</form>
									</td>
								</tr>
							</tbody>
						</table>
						<a href="/SyndeticsUnbound/IndexingLog?feedSource=su_tags" class="btn btn-sm btn-default">{translate text="Tags Log" isAdminFacing=true}</a>
						<a href="/SyndeticsUnbound/IndexingLog?feedSource=syndetics_classic" class="btn btn-sm btn-default">{translate text="Enrichment Log" isAdminFacing=true}</a>
					</div>
				</div>
			{/foreach}
		{/if}
	</div>
{/strip}
