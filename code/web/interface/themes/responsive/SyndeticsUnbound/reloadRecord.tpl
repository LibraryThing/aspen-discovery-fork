{strip}
	<div id="main-content" class="col-md-12">
		<h1>{translate text="Reload Syndetics Unbound Record" isAdminFacing=true}</h1>

		<p>{translate text="Re-runs the classic Syndetics fetch (summary, table of contents, book profile, professional reviews) for a single identifier. The Syndetics Unbound tags side cannot be re-fetched per identifier (the feed is a full snapshot), so this only refreshes the classic Syndetics cache row." isAdminFacing=true}</p>

		{if !empty($message)}
			<div class="alert {if $isError}alert-danger{else}alert-success{/if}">{$message|escape}</div>
		{/if}

		<form method="post" action="/SyndeticsUnbound/ReloadRecord">
			<div class="form-group">
				<label for="settingsId">{translate text="Syndetics Settings" isAdminFacing=true}</label>
				<select name="settingsId" id="settingsId" class="form-control" required>
					{foreach from=$settingsList key=id item=name}
						<option value="{$id}">{$name|escape}</option>
					{/foreach}
				</select>
			</div>
			<div class="form-group">
				<label for="identifierType">{translate text="Identifier Type" isAdminFacing=true}</label>
				<select name="identifierType" id="identifierType" class="form-control">
					<option value="isbn">{translate text="ISBN" isAdminFacing=true}</option>
					<option value="upc">{translate text="UPC" isAdminFacing=true}</option>
				</select>
			</div>
			<div class="form-group">
				<label for="identifier">{translate text="Identifier" isAdminFacing=true}</label>
				<input type="text" name="identifier" id="identifier" class="form-control" required>
			</div>
			<button type="submit" class="btn btn-primary">{translate text="Reload" isAdminFacing=true}</button>
		</form>
	</div>
{/strip}
