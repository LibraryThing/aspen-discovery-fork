AspenDiscovery.Talpa = (function(){
	return {
		updateTalpaButtonFields: function(){
			console.info('updating talpa button fields');
			var buttonType = $("#talpaTryItButtonSelect option:selected").val();

			if(buttonType==0)
			{
				$("#propertyRowtryThisSearchInTalpaText").hide();
				$("#propertyRowtryThisSearchInTalpaSidebarSwitch").hide();
				$("#propertyRowtryThisSearchInTalpaNoResultsSwitch").hide();
			}
			else
			{
				$("#propertyRowtryThisSearchInTalpaText").show();
				$("#propertyRowtryThisSearchInTalpaSidebarSwitch").show();
				$("#propertyRowtryThisSearchInTalpaNoResultsSwitch").show();
			}
			return false;
		}
	};
}(AspenDiscovery.Talpa || {}));