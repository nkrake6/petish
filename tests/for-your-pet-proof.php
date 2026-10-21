<?php
/** Transaction-barrier tests without a WordPress database or remote connection. */
define('PETISH_FYP_PROOF_LIBRARY',true);define('ARRAY_A','ARRAY_A');
require dirname(__DIR__).'/scripts/for-your-pet-proof.php';
error_reporting(E_ALL);
set_error_handler(static function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
$checks=[];$report=['checks'=>[]];
function check($name,$value){global $checks;if(!$value)throw new RuntimeException('Failed: '.$name);$checks[$name]=true;}
function rejects($callback){try{$callback();return false;}catch(RuntimeException $error){return true;}}
class ProofDB {
 public $prefix='wp_';public $posts='wp_posts';public $postmeta='wp_postmeta';public $users='wp_users';public $usermeta='wp_usermeta';public $options='wp_options';
 public $last_error='';public $engine='InnoDB';public $triggers=[];public $mode='';public $autocommit='1';public $queries=[];public $trigger_error=false;
 function prepare($query,...$args){return [$query,$args];}
 function esc_like($s){return $s;}
 function get_results($query,$format){
  if(is_array($query))return array_map(fn($table)=>['TABLE_NAME'=>$table,'ENGINE'=>$this->engine],['wp_posts','wp_postmeta','wp_users','wp_usermeta','wp_options']);
  if($query==='SHOW TRIGGERS'){if($this->trigger_error){$this->last_error='fixture permissions error';return [];}return $this->triggers;}
  if(str_starts_with($query,'SHOW COLUMNS'))return [['Field'=>'ID'],['Field'=>'value']];
  throw new RuntimeException('Unexpected result query');
 }
 function get_var($query){
  if(is_array($query))return $query[1][0];
  if($query==='SELECT @@SESSION.sql_mode')return $this->mode;
  if($query==='SELECT @@SESSION.autocommit')return $this->autocommit;
  throw new RuntimeException('Unexpected scalar query');
 }
 function get_col($query){return [str_repeat('a',64)];}
 function query($sql){$this->queries[]=petish_fyp_proof_query_guard($sql);return 0;}
}
$wpdb=new ProofDB();
$folder=sys_get_temp_dir().'/petish-fyp-proof-'.bin2hex(random_bytes(8));mkdir($folder,0700);
$GLOBALS['petish_fyp_journal']=$folder.'/state.json';
$GLOBALS['petish_fyp_state']=['touched'=>[]];
petish_club_verify_write_json($GLOBALS['petish_fyp_journal'],$GLOBALS['petish_fyp_state']);
try{
 check('single_quoted_payload_hidden',petish_fyp_proof_sql_shape("UPDATE `wp_options` SET value='x; COMMIT; -- still data' WHERE ID=1")==='UPDATE wp_options SET value=? WHERE ID=1');
 check('escaped_quote_payload_hidden',petish_fyp_proof_sql_shape("INSERT INTO wp_options (value) VALUES ('x".chr(92)."'y')")==='INSERT INTO wp_options (value) VALUES (?)');
 check('double_quote_payload_hidden',petish_fyp_proof_sql_shape('INSERT INTO wp_options (value) VALUES ("x; COMMIT")')==='INSERT INTO wp_options (value) VALUES (?)');
 check('one_trailing_semicolon_allowed',petish_fyp_proof_sql_shape('SELECT 1;')==='SELECT 1');
 foreach(["SELECT 1; COMMIT",'SELECT /* comment */ 1','SELECT -- comment\n 1','SELECT # comment','SELECT `bad-name`',"SELECT 'unterminated",'SELECT `unterminated',"SELECT \0"]as$i=>$sql)
  check('ambiguous_shape_rejected_'.$i,petish_fyp_proof_sql_shape($sql)===false);
 $GLOBALS['petish_fyp_phase']='readonly';
 check('bootstrap_read_allowed',petish_fyp_proof_query_guard('SELECT ID FROM wp_posts')==='SELECT ID FROM wp_posts');
 check('bootstrap_write_blocked',petish_fyp_proof_query_guard('INSERT INTO wp_users (ID) VALUES (1)')==='SELECT 0 WHERE 1=0');
 check('outfile_blocked',petish_fyp_proof_query_guard("SELECT ID INTO OUTFILE '/tmp/x' FROM wp_users")==='SELECT 0 WHERE 1=0');
 check('analyze_modification_blocked',petish_fyp_proof_query_guard('EXPLAIN ANALYZE UPDATE wp_users SET ID=1')==='SELECT 0 WHERE 1=0');
 check('pet_lock_read_allowed',petish_fyp_proof_query_guard("SELECT GET_LOCK('owned',1)")==="SELECT GET_LOCK('owned',1)");
 petish_fyp_proof_tables();
 check('only_verified_innodb_tables_allowed',count($GLOBALS['petish_fyp_tables'])===5&&$report['checks']['transaction_tables_verified']);
 $wpdb->engine='MyISAM';check('nontransactional_core_tables_rejected',rejects(fn()=>petish_fyp_proof_tables()));$wpdb->engine='InnoDB';
 $wpdb->triggers=[['Table'=>'wp_posts']];check('database_trigger_rejected',rejects(fn()=>petish_fyp_proof_tables()));$wpdb->triggers=[];
 $wpdb->trigger_error=true;check('empty_result_trigger_error_rejected',rejects(fn()=>petish_fyp_proof_tables()));$wpdb->trigger_error=false;$wpdb->last_error='';
 $wpdb->last_error='fixture table inspection error';check('table_engine_query_error_rejected',rejects(fn()=>petish_fyp_proof_tables()));$wpdb->last_error='';
 $wpdb->mode='NO_BACKSLASH_ESCAPES';check('ambiguous_sql_mode_rejected',rejects(fn()=>petish_fyp_proof_tables()));$wpdb->mode='';
 $wpdb->autocommit='0';check('preexisting_transaction_rejected',rejects(fn()=>petish_fyp_proof_tables()));$wpdb->autocommit='1';
 $GLOBALS['petish_fyp_phase']='transaction';
 foreach(['INSERT INTO `wp_posts` (`ID`,`value`) VALUES (1,\'fixture\')','REPLACE INTO wp_options (ID,value) VALUES (2,\'fixture\')',
  'UPDATE wp_users SET value=\'fixture\' WHERE ID=1','DELETE FROM wp_postmeta WHERE ID=1']as$i=>$sql)
  check('single_table_dml_allowed_'.$i,petish_fyp_proof_query_guard($sql)===$sql);
 $saved=json_decode(file_get_contents($GLOBALS['petish_fyp_journal']),true);
 check('table_baseline_journaled_before_dml',count($saved['touched'])===4&&$saved['touched']===$GLOBALS['petish_fyp_state']['touched']);
 check('journal_mode_private',(fileperms($GLOBALS['petish_fyp_journal'])&0777)===0600);
 foreach(['COMMIT','START TRANSACTION','BEGIN','SAVEPOINT x','RELEASE SAVEPOINT x','ROLLBACK','SET autocommit=1',
  'ALTER TABLE wp_posts ADD secret INT','CREATE TABLE new_table (ID INT)','TRUNCATE TABLE wp_posts',
  'INSERT INTO other_table (ID) VALUES (1)','INSERT INTO other_db.wp_posts (ID) VALUES (1)',
  'UPDATE wp_posts JOIN other_table USING (ID) SET other_table.value=1',
  'UPDATE wp_posts,other_table SET wp_posts.value=1,other_table.value=2',
  'UPDATE wp_posts p SET p.value=1','DELETE FROM wp_posts USING wp_posts,other_table WHERE ID=1',
  'DELETE wp_posts,other_table FROM wp_posts JOIN other_table USING (ID)',
  'DELETE FROM wp_posts','INSERT INTO wp_posts (ID) VALUES (1); COMMIT']as$i=>$sql)
  check('transaction_escape_rejected_'.$i,rejects(fn()=>petish_fyp_proof_query_guard($sql)));
 $GLOBALS['petish_fyp_control']='COMMIT';check('commit_control_not_supported',rejects(fn()=>petish_fyp_proof_query_guard('COMMIT')));unset($GLOBALS['petish_fyp_control']);
 petish_fyp_proof_control('START TRANSACTION');petish_fyp_proof_control('ROLLBACK');
 check('only_exact_owned_transaction_control',$wpdb->queries===['START TRANSACTION','ROLLBACK']&&!isset($GLOBALS['petish_fyp_control']));
 petish_fyp_proof_fixture_step('fixture_user_owner_create');
 check('fixture_sql_boundary_retains_exact_step',rejects(fn()=>petish_fyp_proof_query_guard('COMMIT'))
  &&$report['category']==='fixture_user_owner_create_sql_boundary');
 petish_fyp_proof_fixture_step('fixture_product_save');petish_fyp_proof_fixture_error('runtime');
 check('fixture_runtime_is_separate_from_sql_boundary',$report['category']==='fixture_product_save_runtime');
 check('fixture_step_rejects_private_runtime_values',rejects(fn()=>petish_fyp_proof_fixture_step('fixture_private-sentinel')));
 check('fixture_failure_type_is_finite',rejects(fn()=>petish_fyp_proof_fixture_error('private-sentinel')));
 $report['category']='purchases';
 check('nonfixture_sql_boundary_remains_controlled',rejects(fn()=>petish_fyp_proof_query_guard('COMMIT'))
  &&$report['category']==='transaction_sql_boundary');
 check('finite_marker_rejects_unlisted',rejects(fn()=>petish_fyp_proof_mark('customer_private_value',true)));
 check('finite_marker_rejects_nonboolean',rejects(fn()=>petish_fyp_proof_mark('fixture_scope_verified','secret')));
 petish_fyp_proof_mark('fixture_scope_verified',true);check('finite_marker_accepts_contract',$report['checks']['fixture_scope_verified']===true);
 echo json_encode(['status'=>'success','checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}finally{unlink($GLOBALS['petish_fyp_journal']);rmdir($folder);}
