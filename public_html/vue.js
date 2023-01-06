let doc = {
	nodes:[
		{
			type:"sparql",
			params:{
				query:"select ?street ?streetLabel { ?street wdt:P31 wd:Q79007 ; wdt:P131* wd:Q1731 . MINUS { ?street wdt:P138 [] } SERVICE wikibase:label { bd:serviceParam wikibase:language \"[AUTO_LANGUAGE],de\". } }",
			},
			mapping:{
				street:{id:"item",type:"wikidata_item"},
				stretLabel:{id:"label",type:"text"}
			}
		},
		{
			type:"wikitext",
			params:{
				wiki:"dewiki",
				page:"Liste der nach Personen benannten Straßen und Plätze in Dresden"
			}
		},
		{
			type:"process_text",
			params:{
				regexp:[
					{
						search:"^\\*+ *'''(.+?)''': *\\[\\[ *(.+?) *[\\}\\|]",
						mapping:[
							{id:"label",type:"text"},
							{id:"page",type:"wiki_page",wiki:"dewiki"}
						]
					}
				]
			}
		},
		{
			type:"merge",
			params:{
				keys:[["label","label"]]
			}
		}
	],
	edges:[
		{from:1,to:2},
		{from:0,to:3,as:0},
		{from:2,to:3,as:1},
	]
};

$(document).ready ( function () {
	console.log(JSON.stringify(doc));
});
