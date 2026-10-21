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
  function wp_using_ext_object_cache(){return$GLOBALS['_wp_using_ext_object_cache'];}
  $class="<?php class WP_Object_Cache { public \$values=[]; }";
  file_put_contents($base.'/wp-includes/class-wp-object-cache.php',$class);
  $functions="<?php\nfunction wp_cache_init(){\$GLOBALS['wp_object_cache']=new WP_Object_Cache();}\n";
  foreach(['get','get_multiple','set','set_multiple','add','add_multiple','replace','delete','delete_multiple',
   'incr','decr','flush','flush_runtime','flush_group','supports','close','switch_to_blog',
   'add_global_groups','add_non_persistent_groups','reset']as$name)$functions.='function wp_cache_'.$name.'(){return true;}' ."\n";
  file_put_contents($base.'/wp-includes/cache.php',$functions);
 }
 $GLOBALS['_wp_using_ext_object_cache']=false;
 if($mode==='preinitialized'){
  $GLOBALS['wp_object_cache']=new stdClass();
  cache_check('already_initialized_cache_rejected',cache_rejects('petish_fyp_proof_isolate'));
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
  }else{
   cache_check('canonical_core_cache_accepted',petish_fyp_proof_runtime_cache($base));
   $GLOBALS['_wp_using_ext_object_cache']=true;
   cache_check('external_flag_rejected',!petish_fyp_proof_runtime_cache($base));$GLOBALS['_wp_using_ext_object_cache']=false;
   $object=$GLOBALS['wp_object_cache'];$GLOBALS['wp_object_cache']=new stdClass();
   cache_check('foreign_cache_object_rejected',!petish_fyp_proof_runtime_cache($base));$GLOBALS['wp_object_cache']=$object;
   class Derived_Proof_Cache extends WP_Object_Cache{}
   $GLOBALS['wp_object_cache']=new Derived_Proof_Cache();
   cache_check('core_cache_subclass_rejected',!petish_fyp_proof_runtime_cache($base));$GLOBALS['wp_object_cache']=$object;
   if($core!==''){
    cache_check('core_store_works',wp_cache_set('owned-proof','private-value','proof')&&wp_cache_get('owned-proof','proof')==='private-value');
    cache_check('core_runtime_flush_works',petish_fyp_proof_runtime_cache($base)&&wp_cache_flush_runtime()&&wp_cache_get('owned-proof','proof')===false);
   }
   cache_check('reinitialized_cache_rejected',cache_rejects('petish_fyp_proof_isolate'));
  }
 }
 echo json_encode(['status'=>'success','checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}finally{
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($base);
}
