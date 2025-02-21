AspenDiscovery.Talpa = (function(){
	return {
		updateTalpaButtonFields: function(){
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