<?PHP
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

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
	if ( count($user_ids)==0 or $user_ids==['']) return;
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
		if ( !isset($id) ) return ;
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
		if ( isset($user_id) ) $user_ids[] = $user_id;
		$j->status = 'OK';
		$sql = "SELECT * FROM `workflow` WHERE `id`={$id}" ;
		ensure_db();
		$result = $tfc->getSQL($db,$sql);
		if($o = $result->fetch_object()) {
			$o->json = json_decode($o->json);
			$user_ids[] = $o->user_id;
			$j->workflow = $o ;
		} else $j->status = 'DB query failed';

		$sql = "SELECT max(`id`) as `id` FROM `run` WHERE `workflow_id`={$id}" ;
		$result = $tfc->getSQL($db,$sql);
		if($o = $result->fetch_object()) {
			$j->last_run_id = $o->id;
		}
	} else {
		$j->status = 'Missing/bad workflow ID';
	}

} else if ( $action == 'set_workflow' ) {

	$workflow = $tfc->getRequest('workflow','{}');
	$workflow = json_decode($workflow);
	$user_id = get_user_id();
	if ( !isset($user_id) ) {
		$j->status = 'Not logged in';
	} else if ( isset($workflow->id) ) {
		ensure_db();
		$name_safe = $db->real_escape_string ( $workflow->name ) ;
		$state_safe = $db->real_escape_string ( $workflow->state ) ;
		$json_safe = json_encode ( $workflow->json ) ;
		$workflow_id = $workflow->id*1 ;
		$sql = "UPDATE `workflow` SET `json`='{$json_safe}',`name`='{$name_safe}',`state`='{$state_safe}',`ts_last`=NOW() WHERE `id`={$workflow_id} AND `user_id`={$user_id}" ;
		$tfc->getSQL($db,$sql);
		if ( $db->affected_rows == 1 ) $j->status = 'OK';
		else $j->status = "Workflow {$workflow_id} does not exist, or does not belong to you";
	}

} else if ( $action == 'create_new_workflow' ) {

	ensure_db();
	$user_id = get_user_id();
	if ( !isset($user_id) ) {
		$j->status = 'Not logged in';
	} else {
		$json = [ "nodes"=>[] , "edges"=>[] ];
		$name_safe = $db->real_escape_string ( "Unnamed workflow" ) ;
		$state_safe = $db->real_escape_string ( "DRAFT" ) ;
		$json_safe = json_encode ( $json ) ;
		$sql = "INSERT INTO `workflow` (`name`,`json`,`state`,`user_id`,`ts_created`) VALUES ('{$name_safe}','{$json_safe}','{$state_safe}',{$user_id},NOW())" ;
		$j->sql = $sql ; // DEBUG TEST FIXME
		$tfc->getSQL($db,$sql);
		if ( $db->affected_rows == 1 ) {
			$j->status = 'OK';
			$j->workflow_id = $db->insert_id;
			$sql = "UPDATE `workflow` SET `name`='New workflow #{$j->workflow_id}' WHERE `id`={$j->workflow_id}" ;
			$tfc->getSQL($db,$sql);
		} else $j->status = "Could not create new workflow";
	}

} else if ( $action == 'cancel_run' ) {

	$run_id = $tfc->getRequest("id",0)*1;
	ensure_db();
	$sql = "UPDATE `run` SET `status`='CANCEL' WHERE `id`={$run_id}";
	$tfc->getSQL($db,$sql);
	$j->status = 'OK';


} else if ( $action == 'start_run' ) {

	$run_id = $tfc->getRequest("id",0)*1;
	$reset_nodes = json_decode($tfc->getRequest("reset_nodes",'[]'));
	ensure_db();
	if ( count($reset_nodes) > 0 ) {
		// TODO actually delete files
		$sql = "DELETE FROM `file` WHERE `run_id`={$run_id} AND `node_id` IN (".implode(",",$reset_nodes).")" ;
		$tfc->getSQL($db,$sql);
	}

	# Start run
	$sql = "UPDATE `run` SET `status`='WAIT',`nodes_done`=0 WHERE `id`={$run_id}"; # TODO node totals?
	$tfc->getSQL($db,$sql);
	$j->status = 'OK';

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
			$j->files[$o->node_id] = $o;
		}
	} else {
		$j->status = 'Missing/bad workflow ID';
	}

} else if ( $action == 'file_info' ) {

	ensure_db();
	$max = $tfc->getRequest('max',10)*1;
	$uuid = $tfc->getRequest('uuid','');
	$uuid_safe = $db->real_escape_string ( $uuid ) ;
	$sql = "SELECT * FROM `file` WHERE `uuid`='{$uuid_safe}'" ;
	$result = $tfc->getSQL($db,$sql);
	if($o = $result->fetch_object()) {
		$j->status = 'OK';
		$j->file = $o ;
		$file_path = get_file_path($uuid);
		$fp = @fopen($file_path, "r");
		$j->rows = [];
		while (($buffer = fgets($fp)) !== false and count($j->rows)<($max+1) ) $j->rows[] = json_decode($buffer);
	} else {
		$j->status = 'Missing/bad file UUID';
	}

} else if ( $action == 'get_external_header' ) {

	$quarry_query_id = $tfc->getRequest('quarry_query_id','');
	$psid = $tfc->getRequest('psid',''); # PetScan
	$sparql = $tfc->getRequest('sparql','');
	$pagepile_id = $tfc->getRequest('pagepile_id','');
	$a_list_building_tool_wiki = $tfc->getRequest('a_list_building_tool_wiki','');
	$a_list_building_tool_qid = $tfc->getRequest('a_list_building_tool_qid','');

	try {
	
		if ( $quarry_query_id!='' ) {

			$url = "https://quarry.wmcloud.org/query/{$quarry_query_id}/result/latest/0/tsv";
			$file = fopen($url,'r');
			if ( isset($file) && $file ) {
				$header = trim(fgets($file));
				fclose($file);
				$j->header = explode("\t",$header);
			}

		} else if ( $psid!='' ) {

			# Generic header
			$j->header = ["number","title","pageid","namespace","length","touched","img_size","img_width","img_height","img_media_type","img_major_mime","img_minor_mime","img_user_text","img_timestamp","img_sha1","image","coordinates","defaultsort","disambiguation","fileusage"];

			# Actually run the query, slower
			/*
			$url = "https://petscan.wmflabs.org/?psid={$psid}&format=tsv";
			$file = @fopen($url,'r');
			$header = trim(fgets($file));
			fclose($file);
			$j->header = explode("\t",$header);
			*/

		} else if ( $sparql!='' ) {

			# Parse from query; not perfect but...
			$sparql = preg_replace('/\{.*$/','',$sparql);
			# TODO ?x AS ?y
			if ( preg_match_all('/\?([A-Za-z_][A-Za-z_0-9]*)/',$sparql,$m) ) {
				$j->header = array_unique($m[1]) ;
			}

		} else if ( $pagepile_id!='' ) {

			// Always the same
			$j->header = ["page"];

			// Get wiki from PagePile; optional (slow)
			$result = @json_decode(@file_get_contents("https://pagepile.toolforge.org/api.php?id={$pagepile_id}&action=get_data&doit&format=json"));
			if ( isset($j) and isset($j->wiki) ) $j->wiki = $result->wiki;


		} else if ( $a_list_building_tool_wiki!='' && $a_list_building_tool_qid!='' ) {

			// Always the same
			$j->header = ["title","qid"];

		}

	} catch (Exception $e) {
		$j->status = "UPSTREAM ERROR";
	}

	if ( $j->header==[""] ) $j->status = "UPSTREAM ERROR";
	else if ( isset($j->header) ) $j->status = 'OK';

} else if ( $action == 'download_file' ) {

	$uuid = strtolower($tfc->getRequest('uuid',''));
	$format = strtolower($tfc->getRequest('format','jsonl'));

	if ( preg_match('/^[a-f0-9-]+$/', $uuid) ) {
		$j->status = 'OK';

		if ( $format=='jsonl' ) {
			header('Content-type: application/jsonl');
			header("content-disposition:attachment; filename=\"{$uuid}.{$format}\"");
			$path = get_file_path($uuid);
			$fp = fopen($path, 'rb');
			fpassthru($fp);
			fclose($fp);
		} else if ( $format=='json' ) {
			header('Content-type: application/json');
			header("content-disposition:attachment; filename=\"{$uuid}.{$format}\"");
			$path = get_file_path($uuid);
			$fp = fopen($path, 'rb');
			$header = fgets($fp);
			print "{\"header\":".trim($header).",\"rows\":[\n";
			$first = true ;
			while (($buffer = fgets($fp)) !== false) {
				if ( $first ) $first = false;
				else print ",\n";
				print trim($buffer);
			}
			print "]}";
			fclose($fp);
		} else {
			$j->status = "Unknown format '{$format}'";
		}
		if ( $j->status=='OK' ) {
			$tfc->flush();
			exit(0);
		}
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

	} else if ( $mode == 'new' ) {
		$sql = "SELECT * FROM `workflow` ORDER BY `ts_created` DESC LIMIT 25" ;
		$result = $tfc->getSQL($db,$sql);
		while($o = $result->fetch_object()) {
			$j->workflows[$o->id] = $o ;
			$user_ids[] = $o->user_id;
		}
		$workflow_ids = array_keys($j->workflows);

		$j->runs = [];
		foreach ( $workflow_ids AS $workflow_id ) {
			$j->runs[$workflow_id] = [ "workflow_id" => $workflow_id , "status" => "NEVER RUN" ] ;
		}
		$sql = "SELECT * FROM run  INNER JOIN
			(select workflow_id, max(ts_last) as ts from run group by workflow_id) maxt
			ON (run.workflow_id = maxt.workflow_id AND run.ts_last = maxt.ts)
			WHERE run.workflow_id IN (".implode(',',$workflow_ids).") ORDER BY run.ts_last" ;
		$result = $tfc->getSQL($db,$sql);
		while($o = $result->fetch_object()) $j->runs[$workflow_id] = $o ;
		$j->runs = array_values($j->runs);

	} else {
		$j->status = 'Missing/bad mode: '.$mode;
	}

}

if ( $j->status=='OK' ) add_users() ;
print json_encode ( $j ) ;

?>