#!/usr/bin/php
<?PHP
error_reporting(E_ALL);
require_once ( '/data/project/toolflow/toolflow.php' ) ;

/*
$global_toolflow_batch_id = '' ;

function thisIsTheEnd ( $ok = true ) {
	global $global_toolflow_batch_id ;
	if ( $global_toolflow_batch_id > 0 ) {
		print "BATCH: {$global_toolflow_batch_id}\n" ;
		print ($ok?"OK":"OH NO") . "\n" ;
	}
	exit(0) ;
}

function thisIsTheEndSig ( $signo , $signinfo ) {
	print "SIGNO: {$signo}\n" ;
	print_r ( $signinfo ) ;
	$thisIsTheEnd ( false ) ;
}
*/

/*
register_shutdown_function ( 'thisIsTheEnd' ) ;
pcntl_signal ( SIGTERM , 'thisIsTheEndSig' ) ;
*/

$workflow_id = 1 ;

$tf = new ToolFlow ( 'toolflow_bot' ) ;
$tf->load_workflow($workflow_id) ;
$tf->run_workflow();


/*
if ( !$tf->useNextReadyWorkflow () ) {
	print "No 'READY' workflow found.\n" ;
	exit(0) ;
}
print "Using batch " . $tf->getBatchID() . "\n" ;
*/


?>