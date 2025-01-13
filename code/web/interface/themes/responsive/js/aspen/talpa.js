AspenDiscovery.Talpa = (function(){
	return {
		getTalpaResults: function(searchTerm){
			var url = Globals.path + "/Search/AJAX";
			var params = "method=getTalpaResults&searchTerm=" + encodeURIComponent(searchTerm);
			var fullUrl = url + "?" + params;
			$.ajax({
				url: fullUrl,
				dataType:"json",
				success: function(data) {
					var searchResults = data.formattedResults;
					if (searchResults) {
						if (searchResults.length > 0){
							$("#talpaSearchResultsPlaceholder").html(searchResults);
						}
					}
				}
			});
		}
	}
}(AspenDiscovery.Talpa || {}));
