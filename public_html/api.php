<?PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once ( "php/Widar.php" );

# OAuth login stuff
$config = json_decode(file_get_contents("../config.json"));
$oauth_url = $config->oauth_url ;
$widar = new Widar ( 'toolflow' , $oauth_url ) ;
$widar->attempt_verification_auto_forward ( $config->toolflow_url ) ;
$widar->authorization_callback = $config->toolflow_api ;
try {
	if ( $widar->render_reponse ( true ) ) exit ( 0 ) ;
} catch ( Exception $e ) {

}


# Actual code
$tfc = $widar->tfc;
$action = $tfc->getRequest("action","");

function ensure_db() {
	global $db, $tfc;
	if ( !isset($db) ) $db = $tfc->openDBtool('toolflow_p');
}

function get_file_path($uuid) {
	$path = "/data/project/toolflow/data/{$uuid}.jsonl";
	return $path;
}

function add_users() {
	global $db , $user_ids , $j , $tfc ;
	$j->users = [];
	if ( count($user_ids)==0 ) return;
	$user_ids = array_unique($user_ids);
	$sql = "SELECT * FROM `user` WHERE `id` IN (".implode($user_ids).")" ;
	ensure_db();
	$result = $tfc->getSQL($db,$sql);
	while($o = $result->fetch_object()) $j->users[$o->id] = $o;
}

function get_user_id() {
	global $db, $widar, $tfc;
	try {
		ensure_db();
		$id = $widar->get_user_id()*1;
		$name = $widar->get_username();
		$name = $db->real_escape_string ( $name ) ;
		$sql = "REPLACE INTO `user` (`id`,`name`) VALUES ({$id},'{$name}')" ;
		$tfc->getSQL($db,$sql);
		return $id;
	} catch ( Exception $e ) {
		# Ignore
	}
}

$j = (object) ['status'=>'UNKNOWN ACTION'];
$user_ids = [];

if ( $action == 'get_workflow' ) {

	$id = $tfc->getRequest("id",0)*1;
	if ( $id>0 ) {
		$user_id = get_user_id();
		$user_ids[] = $user_id;
		$j->status = 'OK';
		$sql = "SELECT * FROM `workflow` WHERE `id`={$id}" ;
		ensure_db();
		$result = $tfc->getSQL($db,$sql);
		if($o = $result->fetch_object()) {
			$o->json = json_decode($o->json);
			$j->workflow = $o ;
		} else $j->status = 'DB query failed';
	} else {
		$j->status = 'Missing/bad workflow ID';
	}

} else if ( $action == 'get_run' ) {

	$id = $tfc->getRequest("id",0)*1;
	if ( $id>0 ) {
		$j->status = 'OK';
		$sql = "SELECT * FROM `run` WHERE `id`={$id}" ;
		ensure_db();
		$result = $tfc->getSQL($db,$sql);
		if($o = $result->fetch_object()) $j->run = $o ;
		else $j->status = 'DB query failed';

		$j->files = [];
		$sql = "SELECT * FROM `file` WHERE `run_id`={$id}" ;
		$result = $tfc->getSQL($db,$sql);
		while($o = $result->fetch_object()) {
			$o->size = filesize(get_file_path($o->uuid));
			$j->files[] = $o;
		}
	} else {
		$j->status = 'Missing/bad workflow ID';
	}

} else if ( $action == 'download_file' ) {

	$uuid = strtolower($tfc->getRequest('uuid',''));
	header('Content-type: application/jsonl');
	if ( preg_match('/^[a-f0-9-]+$/', $uuid) ) {
		header("content-disposition:attachment; filename=\"{$uuid}.jsonl\"");
		$j->status = 'OK';
		$path = get_file_path($uuid);
		
		$fp = fopen($path, 'rb');
		fpassthru($fp);
		fclose($fp);
		$tfc->flush();
		exit(0);
	} else {
		$j->status = 'Missing/bad UUID: '.$uuid;
	}

	print json_encode ( $j ) ;
	$tfc->flush();
	exit(0);

} else if ( $action == 'get_workflows' ) {

	$mode = $tfc->getRequest('mode','');
	$j->workflows = [];
	$j->status = 'OK';
	ensure_db();
	if ( $mode == 'recent' ) {
		$sql = "SELECT * FROM `workflow` ORDER BY `ts_created` DESC LIMIT 25" ;
		$result = $tfc->getSQL($db,$sql);
		while($o = $result->fetch_object()) {
			$j->workflows[$o->id] = $o ;
			$user_ids[] = $o->user_id;
		}
		$workflow_ids = array_keys($j->workflows);

		$j->runs = [];
		$sql = "SELECT * FROM run  INNER JOIN
			(select workflow_id, max(ts_last) as ts from run group by workflow_id) maxt
			ON (run.workflow_id = maxt.workflow_id AND run.ts_last = maxt.ts)
			WHERE run.workflow_id IN (".implode(',',$workflow_ids).") ORDER BY run.ts_last" ;
		$result = $tfc->getSQL($db,$sql);
		while($o = $result->fetch_object()) $j->runs[] = $o ;
	} else {
		$j->status = 'Missing/bad mode: '.$mode;
	}

}

if ( $j->status=='OK' ) add_users() ;
print json_encode ( $j ) ;

?>