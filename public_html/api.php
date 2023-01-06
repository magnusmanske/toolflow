<?PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once ( '/data/project/toolflow/toolflow.php' ) ;

function fin() {
	global $out ;
	header('Content-type: application/json; charset=utf-8');
	print json_encode($out);
	exit(0);
}

$tf = new ToolFlow ( 'toolflow' ) ;

$action = $tf->tfc->getRequest('action','');

$out = ["status"=>"OK"] ;
if ( $action == "get_workflow" ) {
	$workflow_id = $tf->tfc->getRequest('id','0');
	$tf->load_workflow($workflow_id*1) ;
	if ( isset($tf->workflow) ) {
		$tf->init_status();
		$out['workflow'] = $tf->workflow;
	} else $o['status'] = "Unknown workflow '{$workflow_id}'";
} else if ( $action == "run_workflow" ) {
	$workflow_id = $tf->tfc->getRequest('id','0');
	$tf->load_workflow($workflow_id*1) ;
	$tf->run_workflow();
} else {
	$out["status"] = "Unknown action '{$action}'";
}

fin();

?>