<?php
/** Fault-injected migration policy checks with an isolated SQL boundary. */
error_reporting(E_ALL);
set_error_handler(static function($severity,$message,$file,$line){if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return true;});
define('PETISH_CLUB_INDEX_LIBRARY',true);define('ARRAY_A','ARRAY_A');
require dirname(__DIR__).'/scripts/pet-club-index-migration.php';
class WP_Error{}
function is_wp_error($value){return$value instanceof WP_Error;}
function hook_id($callback){return is_array($callback)?implode('::',$callback):(is_string($callback)?$callback:spl_object_hash($callback));}
function add_filter($hook,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$hook][$priority][hook_id($callback)]=$callback;}
function add_action(...$args){add_filter(...$args);}
function remove_filter($hook,$callback,$priority=10){unset($GLOBALS['hooks'][$hook][$priority][hook_id($callback)]);}
function remove_action(...$args){remove_filter(...$args);}
function apply_filters($hook,$value){
 foreach($GLOBALS['hooks']['all']??[]as$callbacks)foreach($callbacks as$callback)$callback($hook);
 $priorities=$GLOBALS['hooks'][$hook]??[];ksort($priorities);
 foreach($priorities as$callbacks)foreach($callbacks as$callback)$value=$callback($value);return$value;
}
function wp_using_ext_object_cache(){return$GLOBALS['external_cache'];}
function wp_get_environment_type(){return$GLOBALS['environment']??'staging';}
function remove_accents($value,$locale){if($locale!=='en_US')throw new Exception('locale');return strtr($value,['é'=>'e','É'=>'E','ö'=>'o']);}
class Petish_Passport_Store {
 public static function with_pet_lock($id,$callback){
  if($GLOBALS['lock_failure'])return new WP_Error();
  if($GLOBALS['lock_mutation']){$hook=$GLOBALS['lock_mutation'];$GLOBALS['lock_mutation']=null;$hook($id);}
  $GLOBALS['locks'][]=$id;return$callback();
 }
}
class Petish_Passport_Community {
 public static function public_profile($token){
  global$wpdb;$matches=[];
  foreach($wpdb->data['wp_postmeta']as$row)if($row['meta_key']==='_petish_club_token'&&$row['meta_value']===$token)$matches[]=$row['post_id'];
  if(count($matches)!==1)return new WP_Error();$id=$matches[0];$snapshot=null;
  foreach($wpdb->data['wp_postmeta']as$row)if($row['post_id']===$id){
   if(in_array($row['meta_key'],['_petish_club_revoked','_petish_club_moderated'],true))return new WP_Error();
   if($row['meta_key']==='_petish_club_public')$snapshot=unserialize($row['meta_value'],['allowed_classes'=>false]);
  }
  if(!is_array($snapshot)||($snapshot['token']??'')!==$token||!is_string($snapshot['name']??null)||!is_string($snapshot['species']??null))return new WP_Error();
  return['name'=>$snapshot['name'],'species'=>$snapshot['species'],'variety'=>$snapshot['variety']??''];
 }
}
class Index_SQL_Fixture {
 public $prefix='wp_',$posts='wp_posts',$postmeta='wp_postmeta',$users='wp_users',$last_error='',$insert_id=0;
 public $engine='InnoDB',$triggers=[],$autocommit='1',$sql_mode='',$transaction=null,$writes=[],$reads=[],$next=100;
 public $insert_failure=0,$insert_count=0,$commit_failure='';
 public $data=[];
 public function __construct(){
  $this->data=['wp_options'=>[['option_id'=>1,'option_name'=>'protected','option_value'=>'unchanged']],
   'wp_postmeta'=>[],'wp_posts'=>[['ID'=>10,'post_author'=>1,'post_type'=>'petish_pet','post_status'=>'private','post_title'=>'CURRENT PRIVATE NAME'],['ID'=>20,'post_author'=>1,'post_type'=>'product','post_status'=>'publish','post_title'=>'Unrelated product']],
   'wp_users'=>[['ID'=>1,'user_login'=>'owner']], 'wp_usermeta'=>[['umeta_id'=>1,'user_id'=>1,'meta_key'=>'private','meta_value'=>'secret']]];
 }
 public function esc_like($value){return addcslashes($value,'_%\\');}
 public function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[ds]/',static function($m)use(&$i,$args){$v=$args[$i++];return$m[0]==='%d'?(string)(int)$v:"'".str_replace(["\\","'"],["\\\\","\\'"],(string)$v)."'";},$sql);}
 public function literal($value){return stripslashes(substr($value,1,-1));}
 public function query($sql){
  $this->last_error='';$sql=apply_filters('query',$sql);
  if($sql==='START TRANSACTION'){if($this->transaction!==null)throw new RuntimeException('nested');$this->transaction=$this->data;return 0;}
  if($sql==='COMMIT'){
   if($this->commit_failure==='before')throw new RuntimeException('commit_before');
   $this->transaction=null;if($this->commit_failure==='after')throw new RuntimeException('commit_after');return 0;
  }
  if($sql==='ROLLBACK'){if($this->transaction!==null)$this->data=$this->transaction;$this->transaction=null;return 0;}
  if(str_starts_with($sql,'INSERT INTO')){
   $this->writes[]=$sql;if(++$this->insert_count===$this->insert_failure){$this->last_error='injected';return false;}
   if(!preg_match("/VALUES \(([0-9]+),('(?:\\\\.|[^'])*'),('(?:\\\\.|[^'])*')\)$/s",$sql,$m))throw new RuntimeException('insert_parse');
   $this->insert_id=$this->next++;$this->data['wp_postmeta'][]=['meta_id'=>$this->insert_id,'post_id'=>(int)$m[1],'meta_key'=>$this->literal($m[2]),'meta_value'=>$this->literal($m[3])];return 1;
  }
  if(str_starts_with($sql,'DELETE FROM')){
   $this->writes[]=$sql;
   if(!preg_match("/WHERE meta_id=([0-9]+) AND post_id=([0-9]+) AND BINARY meta_key=('(?:\\\\.|[^'])*') AND BINARY meta_value=('(?:\\\\.|[^'])*')$/s",$sql,$m))throw new RuntimeException('delete_parse');
   foreach($this->data['wp_postmeta']as$i=>$row)if($row['meta_id']===(int)$m[1]&&$row['post_id']===(int)$m[2]&&$row['meta_key']===$this->literal($m[3])&&$row['meta_value']===$this->literal($m[4])){array_splice($this->data['wp_postmeta'],$i,1);return 1;}return 0;
  }
  if($sql==='SELECT 0 WHERE 1=0')return 0;
  throw new RuntimeException('unexpected_write');
 }
 public function get_results($sql,$output){
  $this->last_error='';$sql=apply_filters('query',$sql);$this->reads[]=$sql;
  if(str_contains($sql,'information_schema.TABLES')){$rows=[];foreach(array_keys($this->data)as$table)$rows[]=['TABLE_NAME'=>$table,'ENGINE'=>$table==='wp_postmeta'?$this->engine:'InnoDB'];return$rows;}
  if($sql==='SHOW TRIGGERS')return$this->triggers;
  if(preg_match('/^SHOW COLUMNS FROM `([^`]+)`$/',$sql,$m)){return array_map(static fn($key)=>['Field'=>$key],$this->columns($m[1]));}
  if(str_starts_with($sql,'SELECT p.ID,p.post_author')){
   preg_match('/WHERE p.ID=([0-9]+)/',$sql,$m);$out=[];
   foreach($this->data['wp_posts']as$p)if($p['ID']===(int)$m[1]&&$p['post_type']==='petish_pet'&&$p['post_status']==='private'&&in_array($p['post_author'],array_column($this->data['wp_users'],'ID'),true))$out[]=['ID'=>(string)$p['ID'],'post_author'=>(string)$p['post_author'],'post_type'=>$p['post_type'],'post_status'=>$p['post_status']];return$out;
  }
  if(str_starts_with($sql,'SELECT p.ID FROM')){
   $ids=[];foreach($this->data['wp_posts']as$p){if($p['post_type']!=='petish_pet'||$p['post_status']!=='private')continue;
    $keys=array_column(array_filter($this->data['wp_postmeta'],static fn($r)=>$r['post_id']===$p['ID']),'meta_key');
    if(in_array('_petish_club_token',$keys,true)&&!array_intersect($keys,['_petish_club_revoked','_petish_club_moderated'])&&array_diff(Petish_Club_Index_Migration::KEYS,$keys))$ids[]=['ID'=>(string)$p['ID']];
   }return array_slice($ids,0,101);
  }
  if(str_starts_with($sql,'SELECT meta_id,post_id,meta_key,meta_value')){
   if(preg_match('/WHERE meta_id=([0-9]+)/',$sql,$m))$rows=array_filter($this->data['wp_postmeta'],static fn($r)=>$r['meta_id']===(int)$m[1]);
   else{preg_match('/WHERE post_id=([0-9]+)/',$sql,$m);preg_match_all("/'(_petish_[a-z_]+)'/",$sql,$keys);$rows=array_filter($this->data['wp_postmeta'],static fn($r)=>$r['post_id']===(int)$m[1]&&in_array($r['meta_key'],$keys[1],true));}
   usort($rows,static fn($a,$b)=>$a['meta_id']<=>$b['meta_id']);return array_map(static fn($r)=>array_map(static fn($v)=>(string)$v,$r),$rows);
  }
  throw new RuntimeException('unexpected_read:'.$sql);
 }
 public function columns($table){return $table==='wp_postmeta'?['meta_id','post_id','meta_key','meta_value']:array_keys($this->data[$table][0]);}
 public function get_var($sql){
  $this->last_error='';$sql=apply_filters('query',$sql);$this->reads[]=$sql;
  if($sql==='SELECT @@SESSION.autocommit')return$this->autocommit;
  if($sql==='SELECT @@SESSION.in_transaction')return$this->transaction===null?'0':'1';
  if($sql==='SELECT @@SESSION.sql_mode')return$this->sql_mode;
  if(str_starts_with($sql,'SHOW TABLES LIKE ')){$pattern=str_replace(['\\_','\\%'],['_','%'],$this->literal(substr($sql,17)));return array_key_exists($pattern,$this->data)?$pattern:null;}
  throw new RuntimeException('unexpected_var');
 }
 public function get_col($sql){
  $this->last_error='';$sql=apply_filters('query',$sql);$this->reads[]=$sql;
  if(!preg_match('/FROM `([^`]+)`/',$sql,$m))throw new RuntimeException('hash_parse');$table=$m[1];$rows=$this->data[$table];
  if(preg_match('/WHERE meta_id NOT IN \(([0-9,]+)\)/',$sql,$m)){$omit=array_map('intval',explode(',',$m[1]));$rows=array_filter($rows,static fn($r)=>!in_array($r['meta_id'],$omit,true));}
  $hashes=array_map(static fn($row)=>hash('sha256',json_encode(array_values($row))),$rows);sort($hashes);return$hashes;
 }
}
function addmeta($key,$value,$id=10){global$wpdb;$wpdb->data['wp_postmeta'][]=['meta_id'=>$wpdb->next++,'post_id'=>$id,'meta_key'=>$key,'meta_value'=>$value];}
function reset_fixture(){
 global$wpdb,$root,$journal_path;$wpdb=new Index_SQL_Fixture();$GLOBALS['hooks']=[];$GLOBALS['cache_deletes']=[];$GLOBALS['locks']=[];$GLOBALS['lock_failure']=false;$GLOBALS['lock_mutation']=null;
 $GLOBALS['external_cache']=null;$GLOBALS['wp_object_cache']=new WP_Object_Cache();
 $GLOBALS['environment']='staging';
 foreach(['enable_loading_object_cache_dropin','enable_loading_advanced_cache_dropin']as$hook)add_filter($hook,static fn()=>false,PHP_INT_MAX);
 $GLOBALS['snapshot']=['token'=>str_repeat('a',32),'name'=>"Bélla's \\ club",'species'=>'rabbit','variety'=>'Mini Löp','bio'=>'Shared bio','photo'=>'','moments'=>[]];
 addmeta('_petish_club_public',serialize($GLOBALS['snapshot']));addmeta('_petish_club_token',$GLOBALS['snapshot']['token']);addmeta('_petish_bio','private edits must never be indexed');addmeta('_petish_variety','CURRENT PRIVATE VARIETY');addmeta('_price','19.99',20);
 if(is_file($journal_path))unlink($journal_path);
}
function run_gate($callback){return Petish_Club_Index_Migration::with_gate($callback);}
function planned(){global$root;return run_gate(static fn()=>Petish_Club_Index_Migration::plan($root,'12345-1'));}
function applied(&$journal){global$root,$journal_path;Petish_Club_Index_Migration::write($journal_path,$journal);return run_gate(static function()use(&$journal,$root,$journal_path){return Petish_Club_Index_Migration::apply($root,$journal,$journal_path);});}
function rollback(&$journal){global$root,$journal_path;return run_gate(static function()use(&$journal,$root,$journal_path){return Petish_Club_Index_Migration::rollback($root,$journal,$journal_path);});}
function rejects($callback){try{$callback();return false;}catch(RuntimeException$e){return true;}}
function check($name,$value){if(!$value)throw new RuntimeException('FAILED '.$name);$GLOBALS['checks'][$name]=true;}
$checks=[];$base=sys_get_temp_dir().'/petish-index-migration-'.bin2hex(random_bytes(6));mkdir($base,0700);$root=$base.'/site';mkdir($root,0700);$journal_path=$base.'/journal.json';
mkdir($root.'/wp-includes',0700);file_put_contents($root.'/wp-includes/class-wp-object-cache.php','<?php class WP_Object_Cache {}');
$cache='<?php function wp_cache_delete($id,$group){$GLOBALS["cache_deletes"][]=[$id,$group];return true;}' ."\n";
foreach(['init','get','get_multiple','set','set_multiple','add','add_multiple','replace','delete_multiple','incr','decr','flush','flush_runtime','flush_group','supports','close','switch_to_blog','add_global_groups','add_non_persistent_groups','reset']as$suffix)$cache.='function wp_cache_'.$suffix.'(){return true;}' ."\n";
file_put_contents($root.'/wp-includes/cache.php',$cache);require$root.'/wp-includes/class-wp-object-cache.php';require$root.'/wp-includes/cache.php';
try{
 reset_fixture();$before=$wpdb->data;$journal=planned();
 check('plan_is_read_only',$wpdb->data===$before&&$wpdb->writes===[]);
 check('plan_reads_frozen_public_values',$journal['entries'][0]['fields']['_petish_club_find_name']['value']==="bella's \\ club"&&$journal['entries'][0]['fields']['_petish_club_find_variety']['value']==='mini lop');
 check('no_private_current_fields_in_plan',!str_contains(json_encode($journal),'CURRENT PRIVATE')&&!str_contains(json_encode($journal),'private edits'));
 check('plan_has_exact_scope_and_three_keys',count($journal['entries'])===1&&array_keys($journal['entries'][0]['fields'])===Petish_Club_Index_Migration::KEYS);
 check('apply_succeeds',applied($journal)===true&&$journal['status']==='applied'&&count($journal['inserted'])===3);
 check('only_three_missing_rows_added',count($wpdb->data['wp_postmeta'])===count($before['wp_postmeta'])+3&&$wpdb->data['wp_posts']===$before['wp_posts']);
 check('exact_delta_verified',run_gate(static fn()=>Petish_Club_Index_Migration::verify($root,$journal))===true);
 check('durable_journal_has_exact_insert_ids',json_decode(file_get_contents($journal_path),true)['inserted']===$journal['inserted']&&(fileperms($journal_path)&07777)===0600);
 check('state_hashes_are_nonvacuous',count($journal['before']['tables'])===5&&!array_filter($journal['before']['tables'],static fn($hash)=>!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)));
 check('backslash_and_quote_value_roundtrip',$journal['inserted'][0]['meta_value']==="bella's \\ club");
 check('cache_invalidation_scoped',!array_filter($GLOBALS['cache_deletes'],static fn($row)=>$row!==[10,'post_meta']));
 check('apply_idempotent',applied($journal)===true&&count($wpdb->data['wp_postmeta'])===count($before['wp_postmeta'])+3);
 check('rollback_restores_exact_rows',rollback($journal)===true&&$journal['status']==='rolled_back'&&$wpdb->data===$before);
 check('rollback_idempotent',rollback($journal)===true&&$wpdb->data===$before);

 reset_fixture();addmeta('_petish_club_find_species','rabbit');$before=$wpdb->data;$journal=planned();applied($journal);
 check('matching_existing_index_preserved',count($journal['inserted'])===2&&array_slice($wpdb->data['wp_postmeta'],0,count($before['wp_postmeta']))===$before['wp_postmeta']);
 rollback($journal);check('partial_index_rollback_preserves_original',$wpdb->data===$before);
 reset_fixture();addmeta('_petish_club_find_species','hamster');check('conflicting_existing_index_refused',rejects('planned')&&$wpdb->writes===[]);
 reset_fixture();addmeta('_petish_club_find_species','rabbit');addmeta('_petish_club_find_species','rabbit');check('duplicate_existing_index_refused',rejects('planned')&&$wpdb->writes===[]);
 reset_fixture();addmeta('_petish_club_revoked','1');$journal=planned();check('revoked_pet_not_planned',$journal['entries']===[]);
 reset_fixture();addmeta('_petish_club_moderated','1');$journal=planned();check('moderated_pet_not_planned',$journal['entries']===[]);
 reset_fixture();$wpdb->data['wp_postmeta']=array_values(array_filter($wpdb->data['wp_postmeta'],static fn($r)=>$r['meta_key']!=='_petish_club_token'));check('private_unshared_pet_not_planned',planned()['entries']===[]);
 reset_fixture();$wpdb->data['wp_postmeta'][0]['meta_value']=serialize(['token'=>str_repeat('b',32)]);check('invalid_frozen_snapshot_refused',rejects('planned'));
 reset_fixture();$journal=planned();$wpdb->data['wp_postmeta'][0]['meta_value']=serialize(array_replace($GLOBALS['snapshot'],['name'=>'Changed public name']));check('changed_public_snapshot_refused',rejects(static function()use(&$journal){applied($journal);})&&$wpdb->writes===[]);
 reset_fixture();$journal=planned();$wpdb->data['wp_posts'][0]['post_title']='Changed private name';check('private_edit_never_replaces_snapshot',applied($journal)&&$journal['inserted'][0]['meta_value']==="bella's \\ club");rollback($journal);
 reset_fixture();$journal=planned();$GLOBALS['lock_mutation']=static fn($id)=>addmeta('_petish_club_revoked','1');check('source_rechecked_after_lock',rejects(static function()use(&$journal){applied($journal);})&&$wpdb->writes===[]);
 reset_fixture();$GLOBALS['lock_failure']=true;check('failed_pet_lock_refused',rejects('planned'));

 reset_fixture();$journal=planned();$wpdb->engine='MyISAM';check('nontransactional_apply_refused',rejects(static function()use(&$journal){applied($journal);})&&$wpdb->writes===[]);
 reset_fixture();$journal=planned();$wpdb->triggers=[['Table'=>'wp_postmeta']];check('metadata_trigger_apply_refused',rejects(static function()use(&$journal){applied($journal);})&&$wpdb->writes===[]);
 reset_fixture();$journal=planned();$wpdb->autocommit='0';check('autocommit_off_refused',rejects(static function()use(&$journal){applied($journal);})&&$wpdb->writes===[]);
 reset_fixture();$journal=planned();$wpdb->transaction=$wpdb->data;check('active_transaction_apply_refused',rejects(static function()use(&$journal){applied($journal);})&&$wpdb->writes===[]&&$wpdb->transaction!==null);
 reset_fixture();$journal=planned();$before=$wpdb->data;$wpdb->insert_failure=2;check('insert_failure_aborts',rejects(static function()use(&$journal){applied($journal);}));check('partial_insert_transaction_rolled_back',$wpdb->data===$before&&$wpdb->transaction===null);
 reset_fixture();$journal=planned();$before=$wpdb->data;$bad=$base.'/missing/journal.json';check('journal_write_failure_aborts',rejects(static function()use(&$journal,$root,$bad){run_gate(static function()use(&$journal,$root,$bad){Petish_Club_Index_Migration::apply($root,$journal,$bad);});}));check('journal_failure_leaves_no_rows',$wpdb->data===$before);
 reset_fixture();$journal=planned();$before=$wpdb->data;$wpdb->commit_failure='before';check('precommit_crash_aborts',rejects(static function()use(&$journal){applied($journal);}));$journal=json_decode(file_get_contents($journal_path),true);$wpdb->commit_failure='';check('precommit_crash_has_prepared_owned_ids',$journal['status']==='prepared'&&count($journal['inserted'])===3&&$wpdb->data===$before);check('precommit_crash_rollback_safe',rollback($journal)&&$wpdb->data===$before);
 reset_fixture();$journal=planned();$before=$wpdb->data;$wpdb->commit_failure='after';check('postcommit_crash_reported',rejects(static function()use(&$journal){applied($journal);}));$journal=json_decode(file_get_contents($journal_path),true);$wpdb->commit_failure='';check('postcommit_crash_retains_prepared_exact_rows',$journal['status']==='prepared'&&count($wpdb->data['wp_postmeta'])===count($before['wp_postmeta'])+3);check('postcommit_crash_rollback_safe',rollback($journal)&&$wpdb->data===$before);

 reset_fixture();$journal=planned();applied($journal);$writes=count($wpdb->writes);$wpdb->engine='MyISAM';check('rollback_checks_engine_before_deletes',rejects(static function()use(&$journal){rollback($journal);})&&count($wpdb->writes)===$writes);
 reset_fixture();$journal=planned();applied($journal);$writes=count($wpdb->writes);$wpdb->triggers=[['Table'=>'wp_postmeta']];check('rollback_checks_triggers_before_deletes',rejects(static function()use(&$journal){rollback($journal);})&&count($wpdb->writes)===$writes);
 reset_fixture();$journal=planned();applied($journal);$writes=count($wpdb->writes);$wpdb->transaction=$wpdb->data;check('active_transaction_rollback_refused',rejects(static function()use(&$journal){rollback($journal);})&&count($wpdb->writes)===$writes&&$wpdb->transaction!==null);
 reset_fixture();$journal=planned();applied($journal);$last=count($wpdb->data['wp_postmeta'])-1;$wpdb->data['wp_postmeta'][$last]['meta_value']='another edit';$changed=$wpdb->data;check('rollback_preserves_changed_owned_row',rejects(static function()use(&$journal){rollback($journal);})&&$wpdb->data===$changed);
 reset_fixture();$journal=planned();applied($journal);$last=count($wpdb->data['wp_postmeta'])-1;$wpdb->data['wp_postmeta'][$last]['post_id']=20;$changed=$wpdb->data;check('rollback_rejects_reassigned_meta_id',rejects(static function()use(&$journal){rollback($journal);})&&$wpdb->data===$changed);
 reset_fixture();$journal=planned();applied($journal);addmeta('_petish_club_find_name',"bella's \\ club");$changed=$wpdb->data;check('same_value_foreign_row_not_adopted',rejects(static function()use(&$journal){rollback($journal);})&&$wpdb->data===$changed);
 reset_fixture();$journal=planned();applied($journal);$wpdb->data['wp_options'][0]['option_value']='unrelated changed';check('unrelated_table_change_detected',rejects(static fn()=>run_gate(static fn()=>Petish_Club_Index_Migration::verify($root,$journal))));$changed=$wpdb->data;check('rollback_does_not_erase_unrelated_drift',rejects(static function()use(&$journal){rollback($journal);})&&$wpdb->data===$changed);

 reset_fixture();$journal=planned();$tampered=$journal;$tampered['entries'][0]['fields']['_petish_club_find_name']['value']='tampered';check('plan_hash_tamper_rejected',rejects(static fn()=>Petish_Club_Index_Migration::validate($tampered)));
 $tampered=$journal;$tampered['inserted']=[['meta_id'=>1,'post_id'=>20,'meta_key'=>'_price','meta_value'=>'1']];check('foreign_metadata_journal_rejected',rejects(static fn()=>Petish_Club_Index_Migration::validate($tampered)));
 run_gate(static function()use(&$journal){Petish_Club_Index_Migration::plan($GLOBALS['root'],'12345-1');
  check('unapproved_insert_denied',rejects(static fn()=>Petish_Club_Index_Migration::query_guard("INSERT INTO wp_postmeta (post_id) VALUES (20)")));
  check('comment_outfile_bypass_denied',rejects(static fn()=>Petish_Club_Index_Migration::query_guard("SELECT 1 INTO/**/OUTFILE '/tmp/never-written'")));
  check('plain_outfile_denied',rejects(static fn()=>Petish_Club_Index_Migration::query_guard("SELECT 1 INTO OUTFILE '/tmp/never-written'")));
  check('multi_statement_denied',rejects(static fn()=>Petish_Club_Index_Migration::query_guard('SELECT 1; DELETE FROM wp_posts')));
  check('normal_reads_allowed',Petish_Club_Index_Migration::query_guard('SELECT 1')==='SELECT 1');
 });
 check('scoped_gate_removed_for_owned_fixture_cleanup',empty($GLOBALS['hooks']['query'][PHP_INT_MAX]));
 reset_fixture();check('core_null_cache_flag_allowed',Petish_Club_Index_Migration::runtime_cache($root));$GLOBALS['external_cache']=true;check('persistent_cache_flag_rejected',rejects('planned'));$GLOBALS['external_cache']=0;check('nonboolean_cache_flag_rejected',rejects('planned'));
 reset_fixture();$GLOBALS['wp_object_cache']=new stdClass();check('foreign_cache_object_rejected',rejects('planned'));
 reset_fixture();$GLOBALS['environment']='production';check('fixture_scope_never_allowed_in_production',rejects(static fn()=>run_gate(static fn()=>Petish_Club_Index_Migration::plan($root,'12345-1',[10]))));
 echo json_encode(['status'=>'success','checks'=>$checks],JSON_PRETTY_PRINT)."\n";
}finally{if(is_file($journal_path))unlink($journal_path);foreach(glob($base.'/.index-journal-*')as$file)unlink($file);unlink($root.'/wp-includes/cache.php');unlink($root.'/wp-includes/class-wp-object-cache.php');rmdir($root.'/wp-includes');rmdir($root);rmdir($base);}
