'use strict';

let router ;
let app ;
let wd = new WikiData() ;
let external_ids = {
    cache: {},

    load_node(n,callback) {
        if ( n.kind=='Quarry' ) {
            if ( typeof n.parameters.query_id!='undefined' ) this.load_external_header('quarry_id',n.parameters.query_id,callback);
            // TODO result ID
        } else if ( n.kind=='Sparql' ) {
            if ( typeof n.parameters.sparql!='undefined' ) this.load_external_header('sparql',n.parameters.sparql,callback);
        } else if ( n.kind=='PetScan' ) {
            if ( typeof n.parameters.psid!='undefined' ) this.load_external_header('psid',n.parameters.psid,callback);
        } else {
            callback([])
        }
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
}

let wiki_namespaces = {
    cache: {},

    load_wiki(wiki,callback) {
        if ( typeof this.cache[wiki]!='undefined' ) {
            if (typeof callback!='undefined') callback(this.cache[wiki]);
            return;
        }

        let server = '' ;
        let capture ;
        if ( wiki=='wikidatawiki' ) server = 'www.wikidata.org';
        else if ( wiki=='commonswiki' ) server = 'commons.wikimedia.org';
        else if ( wiki=='metawiki' ) server = 'meta.wikimedia.org';
        else if ( (capture=wiki.match(/^(.+?)wiki$/)) !== null ) server = capture[0]+'.wikipedia.org';
        else if ( (capture=wiki.match(/^(.+?)(wik.+)$/)) !== null ) server = capture[0]+'.'+capture[1]+'.org';
        else {
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
    }
}

let config = {
	toolflow_api:"https://toolflow.toolforge.org/api.php",
	wikibase_api:"https://www.wikidata.org/w/api.php",
} ;

function node_ext_url(n) {
    if ( n.kind=='Sparql' && typeof n.parameters.sparql!='undefined' ) return 'https://query.wikidata.org/#'+encodeURIComponent(n.parameters.sparql);
    if ( n.kind=='Quarry' && typeof n.parameters.query_id!='undefined' ) return 'https://quarry.wmcloud.org/query/'+n.parameters.query_id;
    if ( n.kind=='PetScan' && typeof n.parameters.psid!='undefined' ) return 'https://petscan.toolforge.org/?psid='+n.parameters.psid;
    return '';
}

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
          ] ;
          router = new VueRouter({routes}) ;
          app = new Vue ( { router } ) .$mount('#app') ;
          $('#help_page').attr('href',wd.page_path.replace(/\$1/,config.source_page));
        } ) ;

    } ) ;
} ) ;
