<?php

require_once ( '/data/project/toolflow/public_html/php/ToolforgeCommon.php' ) ;


final class JSONLfile {
	public $id ;
	public $name ;
	public $handle ;

	public function __construct ( int $workflow_id , int $node_id, ToolFlow &$tf ) {
		$dir = "/data/project/toolflow/data/{$workflow_id}" ;
		if ( !is_dir($dir) ) mkdir($dir);
		$uuid = $tf->guidv4();
		$filename = "{$dir}/{$uuid}.jsonl.gz";
		if ( file_exists($filename) ) unlink($filename);
		$this->name = $filename ;
		$secure_path = $tf->dbt->real_escape_string($filename);
		$sql = "INSERT INTO `file` (`path`,`workflow_id`,`status`,`type`) VALUES('{$secure_path}',{$workflow_id},'RUNNING','jsonl')" ;
		print "{$sql}\n" ;
		$tf->getSQL($sql);
		$this->id = $tf->dbt->insert_id;
		$this->handle = gzopen($filename,'w9');
	}

	function __destruct() {
		print "Closing {$this->name}\n" ;
		gzclose($this->handle);
	}

	public function writeln($s) {
		gzwrite($this->handle,"{$s}\n");
	}

}

final class ToolFlow {
	public $tfc ;
	public $dbt ;
	public $workflow ;

	public function __construct ( string $toolname = '' ) {
		$this->tfc = new ToolforgeCommon ( $toolname ) ;
		$this->dbt = $this->tfc->openDBtool ( 'toolflow_p' ) ;
		$this->dbwd = $this->tfc->openDBwiki ( 'wikidatawiki' ) ;
	}

	public function load_workflow(int $workflow_id) {
		unset($this->workflow);
		$sql = "SELECT * FROM `workflow` WHERE `id`={$workflow_id}";
		$result = $this->getSQL($sql);
		if($o = $result->fetch_object()){
			$o->json = json_decode($o->json);
			$this->workflow = $o ;
		}
	}

	public function run_workflow() {
		if ( !isset($this->workflow) ) throw new Exception("No workflow loaded");
		$this->set_workflow_status('RUNNING');
		$this->init_status();
		while ( true ) {
			$tasks_todo = $this->get_tasks_todo();
			print_r($tasks_todo);
			if ( count($tasks_todo) == 0 ) break ;
			$node_id = $tasks_todo[0] ;
			try {
				$this->run_task($node_id);
			} catch (Exception $ex) {
				echo $ex->getMessage();
				$this->set_workflow_status('FAILED');
				throw $ex;
			}
			$this->save_workflow_json();
		}
		$this->set_workflow_status('DONE');
	}

	public function getSQL ( $sql ) {
		return $this->tfc->getSQL ( $this->dbt , $sql ) ;
	}

	public function init_status() {
		$j = $this->workflow->json;
		foreach ( $j->nodes AS $node ) {
			if ( !isset($node->status) ) $node->status = 'TODO';
			if ( !isset($node->file_id) ) $node->status = 'TODO';
		}
	}

	public function guidv4($data = null) {
	    // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
	    $data = $data ?? random_bytes(16);
	    assert(strlen($data) == 16);

	    // Set version to 0100
	    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
	    // Set bits 6-7 to 10
	    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

	    // Output the 36 character UUID.
	    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	protected function get_tasks_todo() {
		$tasks_waiting = [] ;
		$j = $this->workflow->json;
		foreach ( $j->edges AS $edge ) {
			if ( !isset($j->nodes[$edge->from]) ) continue ;
			if ( $j->nodes[$edge->from]->status=='DONE' ) continue ;
			$tasks_waiting[] = $edge->to ;
		}
		$tasks_waiting = array_unique($tasks_waiting);

		$tasks_todo = [] ;
		foreach ( $j->nodes AS $node_id => $node ) {
			if ( $node->status=='DONE' ) continue ;
			if ( in_array($node_id, $tasks_waiting) ) continue ;
			$tasks_todo[] = $node_id ;
		}
		return $tasks_todo;
	}

	protected function save_workflow_json() {
		$json = json_encode($this->workflow->json, JSON_PRETTY_PRINT);
		$json = $this->dbt->real_escape_string($json);
		$sql = "UPDATE `workflow` SET `json`='{$json}' WHERE `id`={$this->workflow->id}" ;
		$this->getSQL($sql);
	}

	protected function set_workflow_status($status) {
		$status = $this->dbt->real_escape_string($status);
		$sql = "UPDATE `workflow` SET `status`='{$status}' WHERE `id`={$this->workflow->id}" ;
		$this->getSQL($sql);
	}

	protected function set_node_status($node,$status) {
		$node->status = $status ;
		$this->save_workflow_json();
	}

	protected function run_task($node_id) {
		$j = $this->workflow->json;
		if ( !isset($j->nodes[$node_id]) ) throw new Exception("No node {$node_id} in workflow {$this->workflow->id}");
		$node = $j->nodes[$node_id] ;
		$node->id = $node_id ;
		$this->run_node($node);
		unset($node->id);
	}

	protected function run_node($node) {
		if ( !isset($node->params) ) throw new Exception("No params in node {$node_id}, workflow {$this->workflow->id}") ;
		$this->set_node_status($node,'RUNNING');
		if ( $node->type == 'sparql' ) $this->run_task_sparql($node);
		else if ( $node->type == 'wikitext' ) $this->run_task_wikitext($node);
		else if ( $node->type == 'process_text' ) $this->run_task_process_text($node);
		else if ( $node->type == 'merge_on_key' ) $this->run_task_merge_on_key($node);
		else if ( $node->type == 'add_wikidata_item' ) $this->run_task_add_wikidata_item($node);		
		else {
			$this->set_node_status($node,'FAILED');
			$node->status = "FAILED";
			throw new Exception("Unknown type {$node->type} in node {$node->id}, workflow {$this->workflow->id}");
		}
		$this->set_node_status($node,'DONE');
	}

	protected function get_filename_from_id ( int $file_id ) {
		$sql = "SELECT * FROM `file` WHERE `id`={$file_id}" ;
		$result = $this->getSQL($sql);
		if($o = $result->fetch_object()){
			if ( file_exists($o->path) ) return $o->path ;
		}
	}

	protected function delete_file ( int $file_id ) {
		# Get filename, if any, and delete file
		$filename = $this->get_filename_from_id($file_id);
		if ( isset($filename) and file_exists($filename) ) unlink($o->path);

		# Delete file entry
		$sql = "DELETE FROM `file` WHERE `id`={$file_id}" ;
		$this->getSQL($sql);
	}


	protected function get_new_jsonl_file($node) {
		if ( isset($node->file_id) ) $this->delete_file($node->file_id);
		$file = new JSONLfile($this->workflow->id,$node->id,$this);
		$node->file_id = $file->id ;
		return $file ;
	}

	protected function close_file($file,$status='DONE') {
		$sql = "UPDATE `file` SET `status`='{$status}' WHERE `id`={$file->id}" ;
		$this->getSQL($sql);
	}

	protected function node_input_json($node) {
		$filename = $this->get_filename_from_id($node->file_id);
		if ( isset($filename) ) {
			$handle = gzopen($filename,'r');
			while (!gzeof($handle)) {
				$buffer = gzgets($handle);
				yield json_decode($buffer);
			}
			gzclose($handle);
		}
		yield from [];
	}

	protected function node_input_json_all($node) {
		$j = $this->workflow->json ;
		foreach ( $j->edges AS $edge ) {
			if ( $edge->to != $node->id ) continue ;
			$node_from = $j->nodes[$edge->from] ;
			foreach ( $this->node_input_json($node_from) AS $row ) {
				yield $row ;
			}
		}
		yield from [];
	}





	protected function run_task_sparql($node) {
		$file = $this->get_new_jsonl_file($node);
		print_r($file);
		foreach ( $this->tfc->getSPARQL_TSV($node->params->query) AS $o ) {
			$row = [] ;
			foreach ( $node->mapping AS $varname => $info ) {
				if ( !isset($o[$varname]) ) {
					$this->close_file($file,'ERROR');
					throw new Exception("Missing variable '{$varname}' in node {$node->id}, workflow {$this->workflow->id}");
				}
				if ( $info->type=='wikidata_item' ) {
					$row[$info->id] = ['value'=>$this->tfc->parseItemFromURL($o[$varname])] ;
				} else if ( $info->type=='text' ) {
					$row[$info->id] = ['value'=>$o[$varname]] ;
				} else {
					$this->close_file($file,'ERROR');
					throw new Exception("Unknown variable type '{$info->type}' in node {$node->id}, workflow {$this->workflow->id}");
				}
				$row[$info->id]['type'] = $info->type ;
			}
			$file->writeln(json_encode($row));
		}
		$this->close_file($file,'DONE');
	}

	protected function run_task_wikitext($node) {
		if ( !isset($node->params->wiki) ) throw new Exception("No 'wiki' parameter in node {$node_id}, workflow {$this->workflow->id}") ;
		if ( !isset($node->params->page) ) throw new Exception("No 'page' parameter in node {$node_id}, workflow {$this->workflow->id}") ;
		$file = $this->get_new_jsonl_file($node);
		print_r($file);
		$row = [
			"type" => "wikitext",
			"wiki" => $node->params->wiki,
			"page" => $node->params->page,
			"value" => $this->tfc->getWikiPageText($node->params->wiki,$node->params->page)
		];
		$file->writeln(json_encode($row));
		$this->close_file($file,'DONE');
	}

	protected function run_task_process_text($node) {
		$file = $this->get_new_jsonl_file($node);
		foreach ( $this->node_input_json_all($node) AS $j ) {
			$text = '' ;
			if ( $j->type=='wikitext' ) {
				$text = $j->value ;
			} else {
				throw new Exception("Unknown type '{$j->type}' in node {$node_id}, workflow {$this->workflow->id}") ;
			}

			foreach ( ($node->params->regexp??[]) AS $r ) {
				$result = preg_match_all($r->pattern, $text, $matches, PREG_SET_ORDER);
				if ( !$result ) continue ;
				foreach ( $matches AS $m ) {
					$row = [] ;
					foreach ( ($r->mapping??[]) AS $num => $map ) {
						if ( !$map or !isset($map->id) ) continue ;
						$element = json_decode(json_encode($map)) ;
						$element->value = $m[$num+1];
						$id = $element->id ;
						unset($element->id);
						$row[$id] = $element ;
					}
					$file->writeln(json_encode($row));
				}
			}
		}
		$this->close_file($file,'DONE');
	}

	protected function run_task_merge_on_key($node) {
		# Get all input files to merge
		$input_files = [] ;
		$j = $this->workflow->json ;
		foreach ( $j->edges AS $edge ) {
			if ( $edge->to != $node->id ) continue ;
			$node_from = $j->nodes[$edge->from] ;
			$input_files[] = $this->get_filename_from_id($node_from->file_id);
		}
		
		# Construct a temporary file that holds all rows from all files,
		# prefixed with a (shared) key and the number of the file it came from
		$outfile = "/tmp/toolflow_{$this->workflow->id}_".$this->guidv4().".gz";
		$outhandle = gzopen($outfile,'w9');
		foreach ( $input_files AS $num => $infile ) {
			if ( !file_exists($infile) ) throw new Exception("File '{$infile}' does not exist, required as input for node {$node->id} in workflow {$this->workflow->id}");
			$inhandle = gzopen($infile,'r');
			while (!gzeof($inhandle)) {
				$j = json_decode(gzgets($inhandle)); # one input row
				$key_value = [] ;
				foreach ( $node->params->key[$num] AS $key ) {
					$key_value[] = $j->$key->value ;
				}
				$key_value = implode('|',$key_value);
				$row = "{$key_value}\t{$num}\t".json_encode($j)."\n" ;
				gzwrite($outhandle,$row);
			}
			gzclose($inhandle);

		}
		gzclose($outhandle);

		# Now go through the sorted temporary file and find rows that have the same key
		# for all input files. Output the merged rows
		$file = $this->get_new_jsonl_file($node);
		$outrow = (object) [];
		$num_read = [] ;
		$last_key = '' ;
		$inhandle = popen("zcat '{$outfile}' | sort","r");
		while (!feof($inhandle)) {
			$row = trim(fgets($inhandle));
			$row = explode ( "\t" , $row , 3 ) ;
			if ( count($row)<3 ) continue ;
			$key = $row[0] ;
			$num = $row[1]*1 ;
			$j = json_decode($row[2]) ;
			if ( $key != $last_key ) {
				$last_key = $key ;
				$outrow = $j ;
				$num_read[$num] = "1" ;
				continue ;
			}
			foreach ( $j AS $k => $v ) {
				if ( !isset($outrow->k) ) $outrow->$k = $v ;
			}
			$num_read[$num] = "1" ;
			if ( array_count_values($num_read)["1"] == count($input_files)) {
				$file->writeln(json_encode($outrow));
			}
		}
		fclose($inhandle);
		unlink($outfile);
		$this->close_file($file,'DONE');
	}

	protected function run_task_add_wikidata_item($node) {
		# TODO do as batches to group SQL for the same wikis
		$page_key = $node->params->page_key ;
		$file = $this->get_new_jsonl_file($node);
		foreach ( $this->node_input_json_all($node) AS $row ) {
			if ( !isset($row->$page_key) ) continue ;
			if ( isset($row->$page_key->wikidata_item) ) continue ; # Already has
			if ( ($row->$page_key->type??'')!='wiki_page' ) continue ;
			if ( !isset($row->$page_key->wiki) ) continue ;
			$wiki = $row->$page_key->wiki ;
			if ( !isset($row->$page_key->value) ) continue ;
			$page = $row->$page_key->value ;
			$item = $this->get_wikidata_item_for_page($wiki,$page);
			if ( !isset($item) ) continue ;
			$row->$page_key->wikidata_item = $item ;
			$file->writeln(json_encode($row));
		}
		$this->close_file($file,'DONE');
	}

	protected function get_wikidata_item_for_page ( string $wiki , string $page ) {
		$page = ucfirst(trim(str_replace('_',' ',$page)));
		$wiki = strtolower($wiki);
		$page_safe = $this->dbwd->real_escape_string($page);
		$wiki_safe = $this->dbwd->real_escape_string($wiki);
		$sql = "SELECT `ips_item_id` FROM `wb_items_per_site` WHERE `ips_site_id`='{$wiki_safe}' AND `ips_site_page`='{$page_safe}'" ;
		$result = $this->tfc->getSQL ( $this->dbwd , $sql ) ;
		if($o = $result->fetch_object()) return "Q{$o->ips_item_id}";
	}

}

?>