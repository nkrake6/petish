<?php
/** Staging-only WooCommerce purchase-history proof. Every fixture is rolled back. */
if(PHP_SAPI!=='cli')exit(1);
define('PETISH_CLUB_VERIFY_LIBRARY',true);
require_once __DIR__.'/verify-pet-club.php';

const PETISH_FYP_PROOF_COMMON=[
 'target_paths_verified','target_environment_verified','woocommerce_runtime_verified','staging_database_separate',
 'staging_guard_verified','jetpack_sync_isolated','all_outbound_http_blocked','mail_calls_blocked',
 'remote_file_access_disabled','cron_and_async_runner_disabled','private_runtime_cache_verified',
 'transaction_tables_verified','transaction_rolled_back','fixture_rows_absent','fixture_cleanup_verified',
 'orders_unchanged','users_and_authentication_unchanged','product_prices_and_stock_unchanged',
 'settings_unchanged','existing_passports_unchanged','private_photo_files_unchanged','touched_tables_unchanged',
];
const PETISH_FYP_PROOF_VERIFY=[
 'for_your_pet_classes_available','fixture_scope_verified','matching_rules_verified',
 'paid_and_refunded_purchases_excluded','guest_email_purchases_excluded','parent_and_variation_excluded',
 'unpaid_orders_remain_eligible','other_customer_history_isolated','newest_eligible_first',
 'private_profile_session_isolation','active_pet_read_only',
];

function petish_fyp_proof_fail(){throw new RuntimeException();}
function petish_fyp_proof_mark($name,$value){
 global $report;
 if(!in_array($name,array_merge(PETISH_FYP_PROOF_COMMON,PETISH_FYP_PROOF_VERIFY),true)||!is_bool($value))petish_fyp_proof_fail();
 $report['checks'][$name]=$value;if(!$value)petish_fyp_proof_fail();
}
function petish_fyp_proof_path($run){
 $proof_home=getenv('HOME');$tools=$proof_home.'/.petish-homepage-deploy-'.$run;
 if(!petish_club_verify_run($run)||__DIR__!==$tools||!petish_club_verify_path($tools,true)
  ||(fileperms($tools)&07777)!==0700)petish_fyp_proof_fail();
 $marker=$tools.'/.homepage-owned';
 if(!petish_club_verify_path($marker)||(fileperms($marker)&07777)!==0600||file_get_contents($marker)!==$run."\n")petish_fyp_proof_fail();
 return $tools.'/for-your-pet-state.json';
}
/** Remove quoted data before checking boundaries; fixture SQL never needs comments. */
function petish_fyp_proof_sql_shape($sql){
 if(!is_string($sql)||strlen($sql)>8388608||str_contains($sql,"\0"))return false;
 $result='';$length=strlen($sql);
 for($i=0;$i<$length;$i++){
  $c=$sql[$i];
  if($c==="'"||$c==='"'){
   $quote=$c;$closed=false;$result.='?';
   while(++$i<$length){if($sql[$i]==='\\'){++$i;continue;}if($sql[$i]===$quote){if(($sql[$i+1]??'')===$quote){++$i;continue;}$closed=true;break;}}
   if(!$closed)return false;continue;
  }
  if($c==='`'){
   $end=strpos($sql,'`',$i+1);if($end===false)return false;
   $name=substr($sql,$i+1,$end-$i-1);if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$name))return false;
   $result.=$name;$i=$end;continue;
  }
  if(($c==='/'&&($sql[$i+1]??'')==='*')||($c==='-'&&($sql[$i+1]??'')==='-')||$c==='#')return false;
  $result.=$c;
 }
 $result=trim($result);if(str_ends_with($result,';'))$result=rtrim(substr($result,0,-1));
 return str_contains($result,';')?false:$result;
}
/** Whole-request barrier, with an explicitly bounded rollback-only DML phase. */
function petish_fyp_proof_query_guard($sql){
 $shape=petish_fyp_proof_sql_shape($sql);$phase=$GLOBALS['petish_fyp_phase']??'readonly';
 if(is_string($shape)&&preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC)\b/i',$shape)
  &&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b|\b(?:GET_LOCK|RELEASE_LOCK)\b/i',$shape))return $sql;
 // Passport user/pet locks are connection-local; they never commit the transaction.
 if(is_string($shape)&&preg_match('/\ASELECT\s+(?:GET_LOCK|RELEASE_LOCK)\s*\(/i',$shape))return $sql;
 if(is_string($shape)&&($GLOBALS['petish_fyp_control']??null)===$shape
  &&in_array($shape,['START TRANSACTION','ROLLBACK'],true))return $sql;
 $table=null;
 if(is_string($shape)&&$phase==='transaction'){
  if(preg_match('/\A(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO)\s+([A-Za-z0-9_]+)\s*\(/i',$shape,$match)
   ||preg_match('/\AUPDATE\s+([A-Za-z0-9_]+)\s+SET\s/i',$shape,$match)
   ||preg_match('/\ADELETE\s+FROM\s+([A-Za-z0-9_]+)\s+WHERE\s/i',$shape,$match))$table=$match[1];
  if(preg_match('/\b(?:JOIN|USING)\b/i',$shape))$table=null;
  if($table&&isset($GLOBALS['petish_fyp_tables'][$table])){
   if(!isset($GLOBALS['petish_fyp_state']['touched'][$table])){
    $GLOBALS['petish_fyp_state']['touched'][$table]=petish_club_verify_table_state($table);
    petish_club_verify_write_json($GLOBALS['petish_fyp_journal'],$GLOBALS['petish_fyp_state'],true);
   }
   return $sql;
  }
 }
 if($phase==='transaction'){$GLOBALS['petish_fyp_disallowed']=true;petish_fyp_proof_fail();}
 return 'SELECT 0 WHERE 1=0';
}
function petish_fyp_proof_control($command){
 global $wpdb;$GLOBALS['petish_fyp_control']=$command;
 try{if(false===$wpdb->query($command))petish_fyp_proof_fail();}
 finally{unset($GLOBALS['petish_fyp_control']);}
}
function petish_fyp_proof_isolate(){
 // No status transition, stock reduction, sales-counter or integration event is
 // needed to test history queries. The fixture never calls a gateway or refund API.
 $stock=static fn()=>false;$zero=static fn()=>0;
 foreach(['woocommerce_can_reduce_order_stock','woocommerce_can_restore_order_stock']as$hook)add_filter($hook,$stock,PHP_INT_MAX);
 foreach(['pre_as_enqueue_async_action','pre_as_schedule_single_action','pre_as_schedule_recurring_action','pre_as_schedule_cron_action']as$hook)add_filter($hook,$zero,PHP_INT_MAX);
 add_action('all',static function($hook){
  if($hook==='query'){remove_filter('query','petish_fyp_proof_query_guard',PHP_INT_MAX);add_filter('query','petish_fyp_proof_query_guard',PHP_INT_MAX);}
  if(str_starts_with($hook,'woocommerce_order_status_')||in_array($hook,[
   'woocommerce_new_order','woocommerce_update_order','woocommerce_new_order_item','woocommerce_update_order_item',
   'woocommerce_payment_complete','woocommerce_order_refunded','save_post_shop_order','save_post_shop_order_refund',
  ],true))remove_all_actions($hook);
 },PHP_INT_MAX,1);
}
function petish_fyp_proof_tables(){
 global $wpdb;
 if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->prefix))petish_fyp_proof_fail();
 $rows=$wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'),ARRAY_A);
 if($wpdb->last_error!==''||!is_array($rows)||!$rows||count($rows)>500)petish_fyp_proof_fail();
 $tables=[];foreach($rows as$row){if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$row['TABLE_NAME']??''))petish_fyp_proof_fail();if(strtoupper($row['ENGINE']??'')==='INNODB')$tables[$row['TABLE_NAME']]=true;}
 $triggers=$wpdb->get_results('SHOW TRIGGERS',ARRAY_A);
 if($wpdb->last_error!==''||!is_array($triggers))petish_fyp_proof_fail();
 foreach($triggers as$trigger)if(isset($tables[$trigger['Table']??'']))petish_fyp_proof_fail();
 foreach(['posts','postmeta','users','usermeta','options']as$key)if(!isset($tables[$wpdb->$key]))petish_fyp_proof_fail();
 $mode=(string)$wpdb->get_var('SELECT @@SESSION.sql_mode');
 if($wpdb->last_error!==''||str_contains($mode,'NO_BACKSLASH_ESCAPES')||(string)$wpdb->get_var('SELECT @@SESSION.autocommit')!=='1'||$wpdb->last_error!=='')petish_fyp_proof_fail();
 $GLOBALS['petish_fyp_tables']=$tables;petish_fyp_proof_mark('transaction_tables_verified',true);
}
function petish_fyp_proof_state($run){
 $state=petish_club_verify_read_json(petish_fyp_proof_path($run));
 if(($state['format']??null)!=='petish-for-your-pet-proof-v1'||($state['run']??null)!==$run
  ||!preg_match('/\A[a-f0-9]{24}\z/D',$state['token']??'')||!is_array($state['before']??null)
  ||!is_array($state['touched']??null)||!is_array($state['ids']??null))petish_fyp_proof_fail();
 return $state;
}
function petish_fyp_proof_absent($state){
 global $wpdb;
 foreach($state['ids']['users']??[]as$id)if($wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$wpdb->users}` WHERE ID=%d",$id)))return false;
 foreach($state['ids']['posts']??[]as$id)if($wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$wpdb->posts}` WHERE ID=%d",$id)))return false;
 foreach($state['ids']['orders']??[]as$id){
  if(wc_get_order($id))return false;
 }
 $prefix='petish-fyp-'.$state['token'];
 return !$wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$wpdb->users}` WHERE user_login LIKE %s",$wpdb->esc_like($prefix).'%'))
  &&!$wpdb->get_var($wpdb->prepare("SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key='_petish_fyp_fixture' AND meta_value=%s",$state['token']));
}
function petish_fyp_proof_compare($root,$state){
 petish_fyp_proof_mark('transaction_rolled_back',($GLOBALS['petish_fyp_phase']??'readonly')==='readonly');
 petish_fyp_proof_mark('fixture_rows_absent',petish_fyp_proof_absent($state));
 petish_club_verify_state_equal($state['before'],petish_club_verify_state($root));
 $equal=true;foreach($state['touched']as$table=>$before){if(!isset($GLOBALS['petish_fyp_tables'][$table])||petish_club_verify_table_state($table)!==$before)$equal=false;}
 petish_fyp_proof_mark('touched_tables_unchanged',$equal);
 petish_fyp_proof_mark('fixture_cleanup_verified',true);
}
function petish_fyp_proof_remember($kind,$id){
 if(!is_int($id)||$id<1)petish_fyp_proof_fail();$GLOBALS['petish_fyp_state']['ids'][$kind][]=$id;
 petish_club_verify_write_json($GLOBALS['petish_fyp_journal'],$GLOBALS['petish_fyp_state'],true);
}
function petish_fyp_proof_user($role){
 $token=$GLOBALS['petish_fyp_state']['token'];$login='petish-fyp-'.$token.'-'.$role;$email=$login.'@petish.invalid';
 if(username_exists($login)||email_exists($email))petish_fyp_proof_fail();
 $id=wp_insert_user(['user_login'=>$login,'user_email'=>$email,'user_pass'=>bin2hex(random_bytes(24)),
  'display_name'=>'Isolated Petish recommendation proof','role'=>'customer']);
 if(is_wp_error($id)||!$id)petish_fyp_proof_fail();petish_fyp_proof_remember('users',(int)$id);
 return ['id'=>(int)$id,'email'=>$email];
}
function petish_fyp_proof_product($name,$species,$keyword,$rank=0,$type='simple',$review=true){
 $product=$type==='variable'?new WC_Product_Variable():new WC_Product_Simple();
 $product->set_name('Isolated FYP '.$name);$product->set_status('publish');$product->set_catalog_visibility('visible');
 $product->set_regular_price('1.00');$product->set_manage_stock(false);$product->set_stock_status('instock');
 $product->set_date_created(time()-$rank);$product->update_meta_data('_petish_fyp_fixture',$GLOBALS['petish_fyp_state']['token']);
 $id=$product->save();if(!$id)petish_fyp_proof_fail();petish_fyp_proof_remember('posts',(int)$id);
 petish_fyp_proof_approve($product,$species,$keyword,$review);
 return $product;
}
function petish_fyp_proof_approve($product,$species,$keyword,$review=true){
 $current=wp_get_current_user();$caps=$current->allcaps;$post=$_POST;
 foreach(['manage_woocommerce','edit_products','edit_others_products','edit_published_products']as$cap)$current->allcaps[$cap]=true;
 try{
  $_POST=['petish_review_marker'=>'1','petish_review_nonce'=>wp_create_nonce('petish_review_'.$product->get_id()),'petish_review_confirm'=>'1',
   'petish_reviews'=>[$species=>['status'=>$review?'eligible':'unreviewed','source'=>'https://example.invalid/isolated-proof',
    'date'=>Petish_Product_Review::today(),'due'=>(new DateTimeImmutable(Petish_Product_Review::today()))->modify('+1 day')->format('Y-m-d'),
    'limits'=>'Synthetic isolated fixture; never offered for sale.']],
   'petish_fyp_marker'=>'1','petish_fyp_nonce'=>wp_create_nonce('petish_fyp_'.$product->get_id()),
   'petish_fyp_enabled'=>'1','petish_fyp_species'=>[$species],'petish_fyp_keywords'=>$keyword];
  Petish_Product_Review::save_editor($product);Petish_For_Your_Pet::save_editor($product);$product->save();
 }finally{$_POST=$post;$current->allcaps=$caps;}
 if($review&&(Petish_Product_Review::record(wc_get_product($product->get_id()),$species)['status']??'')!=='eligible')petish_fyp_proof_fail();
}
function petish_fyp_proof_order($customer,$email,$status,$product,$variation=null){
 $order=new WC_Order();$order->set_customer_id($customer);$order->set_billing_email($email);$order->set_currency('AUD');
 $order->set_created_via('petish-isolated-proof');$order->set_status($status);$order->set_total('1.00');
 $order->update_meta_data('_petish_fyp_fixture',$GLOBALS['petish_fyp_state']['token']);
 $item=new WC_Order_Item_Product();$item->set_name('Isolated synthetic fixture');$item->set_product_id($product->get_id());
 if($variation)$item->set_variation_id($variation->get_id());$item->set_quantity(1);$item->set_subtotal('1.00');$item->set_total('1.00');$order->add_item($item);
 $id=$order->save();if(!$id)petish_fyp_proof_fail();petish_fyp_proof_remember('orders',(int)$id);
 if($order->get_transaction_id()||$order->get_payment_method())petish_fyp_proof_fail();return $order;
}
function petish_fyp_proof_fixtures(){
 global $report;$report['category']='fixtures';
 petish_fyp_proof_mark('for_your_pet_classes_available',class_exists('Petish_For_Your_Pet')&&class_exists('Petish_Product_Review')&&class_exists('WC_Order_Item_Product'));
 $owner=petish_fyp_proof_user('owner');$other=petish_fyp_proof_user('other');wp_set_current_user($owner['id']);
 $keyword='Fyp'.substr($GLOBALS['petish_fyp_state']['token'],0,12);
 $pet=Petish_Passport_Store::create($owner['id'],['name'=>'Isolated private pet','species'=>'rabbit','age'=>'1-3','interest'=>'browsing','bio'=>'Enjoys '.$keyword.'.','traits'=>[]]);
 if(is_wp_error($pet))petish_fyp_proof_fail();petish_fyp_proof_remember('posts',$pet['id']);
 $products=[];$names=['paid','completed','refunded','guest_paid','guest_refunded','pending','failed','cancelled','foreign','fresh_old','fresh_new'];
 foreach($names as$i=>$name)$products[$name]=petish_fyp_proof_product($name,'rabbit',$keyword,30-$i,$name==='paid'?'variable':'simple');
 $variation=new WC_Product_Variation();$variation->set_parent_id($products['paid']->get_id());$variation->set_status('publish');$variation->set_regular_price('1.00');$variation->set_manage_stock(false);$variation->save();
 petish_fyp_proof_remember('posts',(int)$variation->get_id());petish_fyp_proof_approve($products['paid'],'rabbit',$keyword);
 $unreviewed=petish_fyp_proof_product('unreviewed','rabbit',$keyword,0,'simple',false);
 $wrong_species=petish_fyp_proof_product('other species','hamster',$keyword,0);
 $wrong_keyword=petish_fyp_proof_product('other personality','rabbit','Unrelated'.substr($keyword,-6),0);
 foreach(['paid'=>'processing','completed'=>'completed','refunded'=>'refunded','pending'=>'pending','failed'=>'failed','cancelled'=>'cancelled']as$name=>$status)
  petish_fyp_proof_order($owner['id'],$owner['email'],$status,$products[$name],$name==='paid'?$variation:null);
 petish_fyp_proof_order(0,$owner['email'],'completed',$products['guest_paid']);
 petish_fyp_proof_order(0,$owner['email'],'refunded',$products['guest_refunded']);
 petish_fyp_proof_order($other['id'],$owner['email'],'completed',$products['foreign']);
 petish_fyp_proof_mark('fixture_scope_verified',count($GLOBALS['petish_fyp_state']['ids']['users'])===2&&count($GLOBALS['petish_fyp_state']['ids']['orders'])===9);
 $report['category']='matching';
 petish_fyp_proof_mark('matching_rules_verified',Petish_For_Your_Pet::matches($pet,[$keyword])===true
  &&Petish_For_Your_Pet::matches($pet,['unrelated-personality'])===false
  &&Petish_For_Your_Pet::matches(array_merge($pet,['bio'=>'Does not enjoy '.$keyword.'.','traits'=>[]]),[$keyword])===false);
 $report['category']='purchases';$history=Petish_For_Your_Pet::purchase_history();if(is_wp_error($history)||!is_array($history))petish_fyp_proof_fail();
 $has=static fn($name)=>in_array((int)$products[$name]->get_id(),$history,true);
 petish_fyp_proof_mark('paid_and_refunded_purchases_excluded',$has('paid')&&$has('completed')&&$has('refunded'));
 petish_fyp_proof_mark('guest_email_purchases_excluded',$has('guest_paid')&&$has('guest_refunded'));
 petish_fyp_proof_mark('parent_and_variation_excluded',$has('paid')&&in_array((int)$variation->get_id(),$history,true));
 petish_fyp_proof_mark('unpaid_orders_remain_eligible',!$has('pending')&&!$has('failed')&&!$has('cancelled'));
 petish_fyp_proof_mark('other_customer_history_isolated',!$has('foreign'));
 $active=get_user_meta($owner['id'],Petish_Passport_Store::ACTIVE,true);$selected=Petish_For_Your_Pet::selection();
 $actual=array_map(static fn($product)=>(int)$product->get_id(),$selected['products']??[]);
 $expected=array_map(static fn($name)=>(int)$products[$name]->get_id(),['fresh_new','fresh_old','foreign','cancelled']);
 petish_fyp_proof_mark('newest_eligible_first',($selected['state']??null)==='ready'&&$actual===$expected);
 petish_fyp_proof_mark('active_pet_read_only',get_user_meta($owner['id'],Petish_Passport_Store::ACTIVE,true)===$active);
 wp_set_current_user($other['id']);$_GET=['user_id'=>(string)$owner['id'],'pet_id'=>(string)$pet['id']];
 $other_view=Petish_For_Your_Pet::selection();$other_history=Petish_For_Your_Pet::purchase_history();wp_set_current_user(0);$guest=Petish_For_Your_Pet::selection();$_GET=[];
 petish_fyp_proof_mark('private_profile_session_isolation',($other_view['state']??null)==='no_pet'&&($other_view['products']??null)===[]
  &&is_array($other_history)&&in_array((int)$products['foreign']->get_id(),$other_history,true)
  &&!in_array((int)$products['paid']->get_id(),$other_history,true)&&($guest['state']??null)==='guest'&&($guest['products']??null)===[]);
}
function petish_fyp_proof_main($argv){
 global $report;petish_club_verify_report_start();$started=false;$root=null;
 try{
  if(count($argv)!==3||!in_array($argv[2],['verify','cleanup'],true))petish_fyp_proof_fail();$run=$argv[1];$mode=$argv[2];
  $journal=petish_fyp_proof_path($run);$GLOBALS['petish_fyp_journal']=$journal;$GLOBALS['petish_fyp_phase']='readonly';
  $plugin=getenv('HOME').'/domains/staging.petish.biz/public_html/wp-includes/plugin.php';
  if(!petish_club_verify_path($plugin))petish_fyp_proof_fail();require_once $plugin;
  add_filter('query','petish_fyp_proof_query_guard',PHP_INT_MAX);petish_fyp_proof_isolate();
  $root=petish_club_verify_bootstrap('staging',false);
  petish_fyp_proof_mark('private_runtime_cache_verified',wp_using_ext_object_cache()===false);
  petish_fyp_proof_tables();$report['category']='snapshot';
  if($mode==='cleanup'){
   if(file_exists($journal)||is_link($journal))$state=petish_fyp_proof_state($run);
   else $state=['before'=>petish_club_verify_state($root),'touched'=>[],'ids'=>[],'token'=>str_repeat('0',24)];
   petish_fyp_proof_compare($root,$state);
  }else{
   if(file_exists($journal)||is_link($journal))petish_fyp_proof_fail();
   $GLOBALS['petish_fyp_state']=['format'=>'petish-for-your-pet-proof-v1','run'=>$run,'token'=>bin2hex(random_bytes(12)),
    'before'=>petish_club_verify_state($root),'touched'=>[],'ids'=>['users'=>[],'posts'=>[],'orders'=>[]]];
   petish_club_verify_write_json($journal,$GLOBALS['petish_fyp_state']);$report['category']='transaction';
   petish_fyp_proof_control('START TRANSACTION');$started=true;$GLOBALS['petish_fyp_phase']='transaction';
   try{petish_fyp_proof_fixtures();}
   finally{
    petish_fyp_proof_control('ROLLBACK');$started=false;$GLOBALS['petish_fyp_phase']='readonly';wp_set_current_user(0);
    // Only the process-local WordPress cache is active. Clear stale rolled-back objects.
    if(wp_using_ext_object_cache()!==false||!function_exists('wp_cache_flush_runtime')||!wp_cache_flush_runtime())petish_fyp_proof_fail();
   }
   $report['category']='cleanup';petish_fyp_proof_compare($root,$GLOBALS['petish_fyp_state']);
  }
  $expected=array_merge(PETISH_FYP_PROOF_COMMON,$mode==='verify'?PETISH_FYP_PROOF_VERIFY:[]);
  if(array_diff($expected,array_keys($report['checks'])))petish_fyp_proof_fail();
  $report['status']='success';$report['category']='complete';
 }catch(Throwable$ignored){$report['status']='failure';}
 finally{if($started){try{petish_fyp_proof_control('ROLLBACK');}catch(Throwable$ignored){}$GLOBALS['petish_fyp_phase']='readonly';}}
 return $report['status']==='success'?0:1;
}
if(!defined('PETISH_FYP_PROOF_LIBRARY'))exit(petish_fyp_proof_main($argv));
