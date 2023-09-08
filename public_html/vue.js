'use strict';

let router ;
let app ;
let wd = new WikiData() ;

let config = {
	toolflow_api:"https://toolflow.toolforge.org/api.php",
	wikibase_api:"https://www.wikidata.org/w/api.php",
} ;

$(document).ready ( function () {


    vue_components.toolname = 'toolflow' ;
//    vue_components.components_base_url = 'https://tools.wmflabs.org/magnustools/resources/vue/' ; // For testing; turn off to use tools-static
    Promise.all ( [
        vue_components.loadComponents ( ['wd-date','wd-link','tool-translate','tool-navbar','commons-thumbnail','widar','autodesc','typeahead-search','value-validator',
            'vue_components/main-page.html',
            'vue_components/workflow.html',
            'vue_components/workflows.html',
            'vue_components/run.html',
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
