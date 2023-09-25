'use strict';

let router ;
let app ;
let wd = new WikiData() ;
let external_ids = {
    header_cache: {},
    nodes:{},

    load_nodes_definition() {
        const myRequest = new Request("/nodes.json");
        fetch(myRequest)
            .then((response) => response.json())
            .then((data) => {
                this.nodes = data.nodes;
            })
            .catch(console.error);
    },

    kind2params(kind) {
        return (this.nodes[kind]??{}).ui
    },

    load_node(n,callback) {
        if ( typeof (this.nodes[n.kind]??{}).params!='undefined' ) {
            let kv = [];
            this.nodes[n.kind].params.forEach(function(key){ kv.push(key+'='+encodeURIComponent(n.parameters[key]??'')); });
            return this.load_external_header(n.kind,kv,callback);
        }
        callback([]);
    },

    load_external_header(kind,parameters,callback) {
        parameters.sort();
        parameters = parameters.join('&');
        let header_cache_key = kind+":"+parameters;
        if ( typeof this.header_cache[header_cache_key]!='undefined') {
            if ( typeof callback!='undefined' ) callback(this.header_cache[header_cache_key]);
            return;
        }

        const myRequest = new Request("./api.php?action=get_external_header&kind="+kind+"&"+parameters);
        fetch(myRequest)
            .then((response) => response.json())
            .then((data) => {
                if ( data.status=='OK' ) this.header_cache[header_cache_key] = data ;
                if ( typeof callback!='undefined' ) callback(this.header_cache[header_cache_key]??{header:[]});
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

        if ( typeof (self.nodes[kind]??{}).header_template!='undefined' ) {
            node.header_mapping = self.nodes[kind].header_template;
        } else {
            ((self.nodes[kind]??{}).headers??[]).forEach(function(dh_kind){
                if ( dh_kind=="WikiPage" ) node.header_mapping.data.push ( self.get_wiki_page_header() );
                if ( dh_kind=="WikidataItem" ) {
                    let item = self.get_wiki_page_header();
                    item.header.kind.WikiPage.ns_id = 0;
                    item.header.kind.WikiPage.wiki = "wikidatawiki";
                    item.header.name = "wikidata_item";
                    node.header_mapping.data.push ( item );
                }
            });
        }

        if ( ((self.nodes[kind]??{}).mappings??[]).length>0 ) {
            for ( let m = 0 ; m < self.nodes[kind].mappings.length; m++ ) {
                node.header_mapping.data[m].mapping = self.nodes[kind].mappings[m];
            }
        }

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
        // console.log(JSON.parse(JSON.stringify(n)));

        if ( n.kind=="Generator" ) {
            if ( n.parameters.mode=='wikipage' ) {
                return "https://"+wiki_namespaces.wiki2server(n.parameters.wiki)+"/wiki/"+(n.parameters.page??'');
            }
            return ''; // Fallback, no URL
        }

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
        else if ( (capture=wiki.match(/^(.+?)wiki$/)) !== null ) server = capture[1]+'.wikipedia.org';
        else if ( (capture=wiki.match(/^(.+?)(wik.+)$/)) !== null ) server = capture[1]+'.'+capture[2]+'.org';
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

external_ids.load_nodes_definition();
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
            'vue_components/scheduler.html',
            'vue_components/header-mapping.html',
            'vue_components/file.html',
            'vue_components/user.html',
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
            { path: '/workflows/:mode/:id', component: Workflows , props:true },
            { path: '/run/:id', component: Run , props:true },
            { path: '/file/:uuid', component: File , props:true },
            { path: '/user/:id', component: User , props:true },
            { path: '/schedule/:workflow_id', component: Schedule , props:true },
          ] ;
          router = new VueRouter({routes}) ;
          app = new Vue ( { router } ) .$mount('#app') ;
          $('#help_page').attr('href','https://meta.wikimedia.org/wiki/ToolFlow');
        } ) ;

    } ) ;
} ) ;
