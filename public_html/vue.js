'use strict';

let router ;
let app ;
let wd = new WikiData() ;
let external_ids = {
    cache: {},
    nodes:{
        QuarryQuery: {
            params:['quarry_query_id'],
            ui: [{tag:"text",key:"quarry_query_id",label:"quarry_id"}],
            url: 'https://quarry.wmcloud.org/query/{quarry_query_id}'
        },
        Sparql: {
            params:['sparql'],
            ui: [{tag:"textarea",key:"sparql"}],
            url: 'https://query.wikidata.org/#{sparql}'
        },
        PetScan: {
            params: ['psid'],
            default_headers: ["WikiPage"],
            ui: [{tag:"text",key:"psid"}],
            url: 'https://petscan.toolforge.org/?psid={psid}'
        },
        PagePile: {
            params: ['pagepile_id'],
            default_headers: ["WikiPage"],
            ui: [{tag:"text",key:"pagepile_id"}],
            url: 'https://pagepile.toolforge.org/api.php?action=get_data&format=html&id={pagepile_id}'
        },
        AListBuildingTool: {
            params: ['a_list_building_tool_wiki','a_list_building_tool_qid'],
            default_headers: ["WikiPage","WikidataItem"],
            ui: [{tag:"wiki",key:"a_list_building_tool_wiki",label:"wiki"},{tag:"text",key:"a_list_building_tool_qid",label:"qid"}],
            url: 'https://a-list-bulding-tool.toolforge.org/API/?wiki_db={a_list_building_tool_wiki}&QID={a_list_building_tool_qid}'
        },
    },

    kind2params(kind) {
        return (this.nodes[kind]??{}).ui
    },

    load_node(n,callback) {
        if ( typeof (this.nodes[n.kind]??{}).params!='undefined' ) {
            let kv = [];
            this.nodes[n.kind].params.forEach(function(key){
                if ( n.parameters[key]??''!='' ) kv.push(key+'='+encodeURIComponent(n.parameters[key]));
            });
            if ( kv.length==this.nodes[n.kind].params ) { // All keys have non-blank values
                return this.load_external_header(key,n.parameters[key],callback);
            }
        }
        callback([]);
    },

    load_external_header(mode,id,callback) {
        if ( typeof this.cache[mode]!='undefined' && typeof this.cache[mode][id]!='undefined') {
            if ( typeof callback!='undefined' ) callback(this.cache[mode][id]);
            return;
        }

        const myRequest = new Request("./api.php?action=get_external_header&"+mode+"="+id);
        fetch(myRequest)
            .then((response) => response.json())
            .then((data) => {
                if ( data.status=='OK' ) {
                    if ( typeof this.cache[mode]=='undefined' ) this.cache[mode] = {};
                    this.cache[mode][id] = data.header ;
                }
                if ( typeof callback!='undefined' ) callback((this.cache[mode]??{})[id]??[]);
            })
            .catch(console.error);
    },

    new_node(kind) {
        let self = this;
        let node = {
          "header_mapping": {"data": []},
          "kind": kind,
          "parameters": {}
        };

        // Initialize parameters as blank
        ((self.nodes[kind]??{}).params??[]).forEach(function(key){ node.parameters[key] = ''; });

        ((self.nodes[kind]??{}).default_headers??[]).forEach(function(dh_kind){
            if ( dh_kind=="WikiPage" ) node.header_mapping.data.push ( self.get_wiki_page_header() );
            if ( dh_kind=="WikidataItem" ) {
                let item = self.get_wiki_page_header();
                item.header.kind.WikiPage.ns_id = 0;
                item.header.kind.WikiPage.wiki = "wikidatawiki";
                item.header.name = "wikidata_item";
                node.header_mapping.data.push ( item );
            }
        });

        return node;
    },

    get_wiki_page_header() {
        return {
            "header": {
              "kind": {
                "WikiPage": {
                  "ns_id": null,
                  "ns_prefix": null,
                  "page_id": null,
                  "prefixed_title": null,
                  "title": null,
                  "wiki": ''
                }
              },
              "name": "wiki_page"
            },
            "mapping": []
          };
    },

    node_ext_url(n) {
        if ( typeof (this.nodes[n.kind]??{}).params=='undefined' ) return '';
        if ( typeof (this.nodes[n.kind]??{}).url=='undefined' ) return ''

        let kv = [];
        this.nodes[n.kind].params.forEach(function(key){
            if ( n.parameters[key]??''!='' ) kv.push([key,n.parameters[key]]);
        });
        if ( kv.length!=this.nodes[n.kind].params.length ) return '';

        // All keys have non-blank values
        let url = this.nodes[n.kind].url;
        kv.forEach(function(x){
            let pattern = new RegExp('\\{'+x[0]+'\\}');
            let value = x[1];
            url = url.replace(pattern,value);
        });
        return url;
    }

}

let wiki_namespaces = {
    cache: {},
    site_list: [],

    wiki2server(wiki) {
        let server;
        let capture ;
        if ( wiki=='wikidatawiki' ) server = 'www.wikidata.org';
        else if ( wiki=='commonswiki' ) server = 'commons.wikimedia.org';
        else if ( wiki=='metawiki' ) server = 'meta.wikimedia.org';
        else if ( (capture=wiki.match(/^(.+?)wiki$/)) !== null ) server = capture[0]+'.wikipedia.org';
        else if ( (capture=wiki.match(/^(.+?)(wik.+)$/)) !== null ) server = capture[0]+'.'+capture[1]+'.org';
        if ( typeof server!='undefined' ) return server;

        this.site_list.forEach(function(site){
            if ( site.wiki==wiki ) server = site.server;
        });
        return server;
    },

    load_wiki(wiki,callback) {
        if ( typeof this.cache[wiki]!='undefined' ) {
            if (typeof callback!='undefined') callback(this.cache[wiki]);
            return;
        }

        let server = this.wiki2server(wiki) ;
        if ( typeof server=='undefined' ) {
            console.log("Can't find a server name for "+wiki);
            if (typeof callback!='undefined') callback({});
            return;
        }

        let self = this;
        let url = "https://"+server+"/w/api.php?action=query&meta=siteinfo&siprop=namespaces|namespacealiases&format=json&callback=?";
        $.getJSON(url,function(j){
            self.cache[wiki] = j.query.namespaces;
            if (typeof callback!='undefined') callback(self.cache[wiki]);
        });
    },

    fetch_sitematrix() {
        let self = this;
        let url = 'https://www.wikidata.org/w/api.php?action=sitematrix&format=json&callback=?';
        self.site_list.push({label:"Wikidata",wiki:"wikidatawiki",server:"www.wikidata.org"});
        $.getJSON(url,function(data){
            $.each(data.sitematrix,function(k,v){
                if ( typeof v.site=='undefined' ) return;
                v.site.forEach(function(site){
                    let label = v.name+" "+site.sitename;
                    let wiki = site.dbname;
                    let server = site.url.replace(/^.+:\/\//,'');
                    self.site_list.push({label:label,wiki:wiki,server:server});
                });
            });
            $.each(data.sitematrix.specials,function(k,site){
                if ( typeof site.closed=='undefined' && typeof site.private=='undefined' && site.sitename!='Wikipedia' && site.sitename!='Wikimedia' ) {
                    let label = site.sitename;
                    let wiki = site.dbname;
                    let server = site.url.replace(/^.+:\/\//,'');
                    self.site_list.push({label:label,wiki:wiki,server:server});
                }
            });
            self.site_list.sort(function(x,y){
                if(x.label.toLowerCase()<y.label.toLowerCase()) return -1;
                if(x.label.toLowerCase()>y.label.toLowerCase()) return 1;
                return 0;
            });
        });
    }

}

let config = {
	toolflow_api:"https://toolflow.toolforge.org/api.php",
	wikibase_api:"https://www.wikidata.org/w/api.php",
} ;

wiki_namespaces.fetch_sitematrix();
$(document).ready ( function () {

    vue_components.toolname = 'toolflow' ;
//    vue_components.components_base_url = 'https://tools.wmflabs.org/magnustools/resources/vue/' ; // For testing; turn off to use tools-static
    Promise.all ( [
        vue_components.loadComponents ( ['wd-date','wd-link','tool-translate','tool-navbar','commons-thumbnail','widar','autodesc','typeahead-search','value-validator',
            'vue_components/main-page.html',
            'vue_components/workflow.html',
            'vue_components/workflows.html',
            'vue_components/run.html',
            'vue_components/node-editor.html',
            'vue_components/header-mapping.html',
            'vue_components/file.html',
            ] )
    ] )
    .then ( () => {
        widar_api_url = config.toolflow_api ;

        wd.set_custom_api ( config.wikibase_api , function () {
        wd_link_wd = wd ;
          const routes = [
            { path: '/', component: MainPage , props:true },
            { path: '/workflow', component: Workflow , props:true },
            { path: '/workflow/:id', component: Workflow , props:true },
            { path: '/workflows/:mode', component: Workflows , props:true },
            { path: '/run/:id', component: Run , props:true },
            { path: '/file/:uuid', component: File , props:true },
          ] ;
          router = new VueRouter({routes}) ;
          app = new Vue ( { router } ) .$mount('#app') ;
          $('#help_page').attr('href',wd.page_path.replace(/\$1/,config.source_page));
        } ) ;

    } ) ;
} ) ;
