<?php
/** Cache-isolation boundary fixtures; optional real WordPress source integration. */
define('PETISH_FYP_PROOF_LIBRARY',true);
require dirname(__DIR__).'/scripts/for-your-pet-proof.php';
$mode=$argv[1]??'core';$core=$argv[2]??'';$checks=[];
function cache_check($name,$value){global$checks;if(!$value)throw new RuntimeException($name);$checks[$name]=true;}
function cache_rejects($callback){try{$callback();return false;}catch(RuntimeException$ignored){return true;}}
$base=sys_get_temp_dir().'/petish-fyp-cache-'.bin2hex(random_bytes(8));
mkdir($base,0700);mkdir($base.'/wp-includes',0700);mkdir($base.'/wp-content',0700);mkdir($base.'/foreign',0700);
define('ABSPATH',$base.'/');define('WPINC','wp-includes');define('WP_CONTENT_DIR',$base.'/wp-content');
try{
 if($core!==''){
  foreach(['plugin.php','class-wp-hook.php','load.php','cache.php','cache-compat.php','class-wp-object-cache.php']as$file)
   if(!copy($core.'/'.$file,$base.'/wp-includes/'.$file))throw new RuntimeException('fixture_copy');
  if(is_file($core.'/class-wp-filter-sentinel.php'))copy($core.'/class-wp-filter-sentinel.php',$base.'/wp-includes/class-wp-filter-sentinel.php');
  require$base.'/wp-includes/plugin.php';require$base.'/wp-includes/load.php';
 }else{
  // Small dispatch fixture follows the public hooks contract, including `all`
  // before the target filter. Real core can run the same assertions below.
  function add_filter($hook,$callback,$priority=10,$args=1){$GLOBALS['cache_hooks'][$hook][$priority][]=$callback;}
  function add_action(...$args){add_filter(...$args);}
  function remove_filter($hook,$callback,$priority=10){foreach($GLOBALS['cache_hooks'][$hook][$priority]??[]as$key=>$value)if($value===$callback)unset($GLOBALS['cache_hooks'][$hook][$priority][$key]);}
  function apply_filters($hook,$value){
   foreach($GLOBALS['cache_hooks']['all']??[]as$callbacks)foreach($callbacks as$callback)$callback($hook);
   $priorities=$GLOBALS['cache_hooks'][$hook]??[];ksort($priorities);
   foreach($priorities as$callbacks)foreach($callbacks as$callback)$value=$callback($value);return$value;
  }
  function wp_using_ext_object_cache(){return$GLOBALS['_wp_using_ext_object_cache']??null;}
  $class="<?php class WP_Object_Cache { public \$values=[]; }";
  file_put_contents($base.'/wp-includes/class-wp-object-cache.php',$class);
  $functions="<?php\nfunction wp_cache_init(){\$GLOBALS['wp_object_cache']=new WP_Object_Cache();}\n";
  foreach(['get','get_multiple','set','set_multiple','add','add_multiple','replace','delete','delete_multiple',
   'incr','decr','flush','flush_runtime','flush_group','supports','close','switch_to_blog',
   'add_global_groups','add_non_persistent_groups','reset']as$name){
   if($mode==='missing_api'&&$name==='reset')continue;
   $functions.='function wp_cache_'.$name.'(){return true;}' ."\n";
  }
  file_put_contents($base.'/wp-includes/cache.php',$functions);
 }
 cache_check('virgin_cache_flag_not_forced',!array_key_exists('_wp_using_ext_object_cache',$GLOBALS));
 if($mode==='preinitialized'){
  $GLOBALS['wp_object_cache']=new stdClass();
  cache_check('already_initialized_cache_rejected',cache_rejects('petish_fyp_proof_isolate'));
  cache_check('preinitialization_failure_category',$report['category']==='cache_preinitialized');
 }else{
  cache_check('isolation_precedes_cache_initialization',!function_exists('wp_cache_init')&&!class_exists('WP_Object_Cache',false));
  petish_fyp_proof_isolate();
  foreach(['enable_loading_object_cache_dropin','enable_loading_advanced_cache_dropin']as$hook){
   add_filter($hook,static fn()=>true,PHP_INT_MAX);
   cache_check($hook.'_late_callback_cannot_enable',apply_filters($hook,true)===false);
  }
  if($core!==''){
   file_put_contents(WP_CONTENT_DIR.'/object-cache.php',"<?php throw new RuntimeException('persistent_dropin_was_loaded');");
   wp_start_object_cache();
  }else{
   $classfile=$base.'/wp-includes/class-wp-object-cache.php';$functionfile=$base.'/wp-includes/cache.php';
   if($mode==='foreign_class'){copy($classfile,$base.'/foreign/class-wp-object-cache.php');$classfile=$base.'/foreign/class-wp-object-cache.php';}
   if($mode==='foreign_functions'){copy($functionfile,$base.'/foreign/cache.php');$functionfile=$base.'/foreign/cache.php';}
   require$classfile;require$functionfile;wp_cache_init();
  }
  if($mode==='foreign_class'||$mode==='foreign_functions'){
   cache_check('matching_names_from_foreign_source_rejected',!petish_fyp_proof_runtime_cache($base));
   cache_check('foreign_source_failure_category',$report['category']===($mode==='foreign_class'?'cache_class_source':'cache_api_source_init'));
  }elseif($mode==='missing_api'){
   cache_check('missing_api_failure_category',!petish_fyp_proof_runtime_cache($base)&&$report['category']==='cache_api_missing_reset');
  }else{
   cache_check('virgin_core_flag_is_null',wp_using_ext_object_cache()===null);
   cache_check('canonical_core_cache_accepted',petish_fyp_proof_runtime_cache($base));
   cache_check('noncanonical_root_category',!petish_fyp_proof_runtime_cache($base.'/missing')&&$report['category']==='cache_path_root');
   foreach(['class'=>'class-wp-object-cache.php','api'=>'cache.php']as$kind=>$file){
    rename($base.'/wp-includes/'.$file,$base.'/wp-includes/'.$file.'.saved');
    cache_check('missing_'.$kind.'_path_category',!petish_fyp_proof_runtime_cache($base)&&$report['category']==='cache_path_'.$kind);
    rename($base.'/wp-includes/'.$file.'.saved',$base.'/wp-includes/'.$file);
   }
   $GLOBALS['_wp_using_ext_object_cache']=true;
   cache_check('external_flag_rejected',!petish_fyp_proof_runtime_cache($base)&&$report['category']==='cache_flag_enabled');
   $GLOBALS['_wp_using_ext_object_cache']=null;
   cache_check('unset_flag_with_core_provenance_accepted',petish_fyp_proof_runtime_cache($base));
   foreach([0,1,'','false','0',[]]as$index=>$flag){
    $GLOBALS['_wp_using_ext_object_cache']=$flag;
    cache_check('invalid_flag_rejected_'.$index,!petish_fyp_proof_runtime_cache($base)&&$report['category']==='cache_flag_invalid');
   }
   $GLOBALS['_wp_using_ext_object_cache']=false;
   cache_check('explicit_false_with_core_provenance_accepted',petish_fyp_proof_runtime_cache($base));
   $object=$GLOBALS['wp_object_cache'];$GLOBALS['wp_object_cache']=new stdClass();
   cache_check('foreign_cache_object_rejected',!petish_fyp_proof_runtime_cache($base)&&$report['category']==='cache_class');$GLOBALS['wp_object_cache']=$object;
   class Derived_Proof_Cache extends WP_Object_Cache{}
   $GLOBALS['wp_object_cache']=new Derived_Proof_Cache();
   cache_check('core_cache_subclass_rejected',!petish_fyp_proof_runtime_cache($base));$GLOBALS['wp_object_cache']=$object;
   if($core!==''){
    cache_check('core_store_works',wp_cache_set('owned-proof','private-value','proof')&&wp_cache_get('owned-proof','proof')==='private-value');
    cache_check('core_runtime_flush_works',petish_fyp_proof_runtime_cache($base)&&wp_cache_flush_runtime()&&wp_cache_get('owned-proof','proof')===false);
   }
   cache_check('reinitialized_cache_rejected',cache_rejects('petish_fyp_proof_isolate'));
   cache_check('raw_diagnostic_category_rejected',cache_rejects(static fn()=>petish_fyp_proof_cache_reject('/private/raw/runtime/path')));
   if(in_array($mode,['object_dropin','advanced_dropin'],true)){
    $kind=$mode==='object_dropin'?'object':'advanced';$dropin=WP_CONTENT_DIR.'/'.$kind.'-cache.php';
    file_put_contents($dropin,'<?php /* inert included-file fixture */');require$dropin;
    cache_check('loaded_dropin_failure_category',!petish_fyp_proof_runtime_cache($base)&&$report['category']==='cache_dropin_'.$kind.'_loaded');
   }
   if(in_array($mode,['object_filter','advanced_filter'],true)){
    $kind=$mode==='object_filter'?'object':'advanced';$target='enable_loading_'.$kind.'_cache_dropin';
    add_action('all',static function($hook)use($target){if($hook===$target)add_filter($target,static fn()=>true,PHP_INT_MAX);},PHP_INT_MAX);
    cache_check('broken_filter_failure_category',!petish_fyp_proof_runtime_cache($base)&&$report['category']==='cache_filter_'.$kind);
   }
  }
 }
 echo json_encode(['status'=>'success','checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}finally{
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($base);
}
