<?php
/**
 * Real WooCommerce regression, run only in a disposable local WordPress database:
 * PETISH_FYP_ISOLATED=1 wp eval-file tests/for-your-pet-review-lifecycle.php
 * The local site must use http://petish.invalid and block HTTP/email in its MU guard.
 * This file is never uploaded to staging or production.
 */
if(!defined('WP_CLI')||!WP_CLI||getenv('PETISH_FYP_ISOLATED')!=='1'
 ||home_url()!=='http://petish.invalid'||!class_exists('WC_Product_Variable')
 ||!is_wp_error(apply_filters('pre_http_request',false,[],'https://example.invalid/'))
 ||apply_filters('pre_wp_mail',null,[])!==true)throw new RuntimeException('Isolated local runtime required.');
define('PETISH_FYP_PROOF_LIBRARY',true);
require dirname(__DIR__).'/scripts/for-your-pet-proof.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-store.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-product-review.php';
require dirname(__DIR__).'/wordpress/wp-content/themes/dt-the7-child/includes/for-your-pet.php';
Petish_Product_Review::boot();
wp_set_current_user(1);
if(!current_user_can('manage_options'))throw new RuntimeException('Synthetic local administrator required.');
$checks=[];$report=['checks'=>[]];$ids=[];
$check=static function($name,$value)use(&$checks){if(!$value)throw new RuntimeException($name);$checks[$name]=true;};
global $wpdb;
if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('Local transaction required.');
try{
 $product=new WC_Product_Variable();$product->set_name('Synthetic review lifecycle');
 $product->set_status('publish');$product->set_regular_price('1.00');$product->set_manage_stock(false);$product->save();$ids[]=$product->get_id();
 petish_fyp_proof_approve($product,'rabbit','Synthetic');
 $check('first_real_editor_approval_valid',Petish_Product_Review::record($product,'rabbit')['status']==='eligible');
 $retained_signature=$product->get_meta(Petish_Product_Review::key('rabbit','signature'),true,'edit');
 $variation=new WC_Product_Variation();$variation->set_parent_id($product->get_id());
 $variation->set_status('publish');$variation->set_regular_price('1.00');$variation->save();$ids[]=$variation->get_id();
 $check('variation_revokes_parent_review',Petish_Product_Review::record($product,'rabbit')['status']==='unreviewed');
 $check('revoked_signature_deleted_in_database',get_post_meta($product->get_id(),Petish_Product_Review::key('rabbit','signature'),true)==='');
 $check('retained_object_still_has_stale_signature',$retained_signature!==''&&$product->get_meta(Petish_Product_Review::key('rabbit','signature'),true,'edit')===$retained_signature);
 // Reproduce the old fixture path: the same metadata value appears unchanged,
 // so WooCommerce does not recreate the row deleted by variation invalidation.
 $old_post=$_POST;
 try{
  $_POST=['petish_review_marker'=>'1','petish_review_confirm'=>'1','petish_review_nonce'=>wp_create_nonce('petish_review_'.$product->get_id()),
   'petish_reviews'=>['rabbit'=>Petish_Product_Review::raw($product,'rabbit')]];
  Petish_Product_Review::save_editor($product);$product->save();
 }finally{$_POST=$old_post;}
 $check('old_reused_object_path_fails_real_validation',Petish_Product_Review::record($product,'rabbit')['status']==='unreviewed');
 // Exercise the actual proof helper, rather than reproducing its repair here.
 petish_fyp_proof_approve($product,'rabbit','Synthetic');
 $check('refreshed_fixture_reapproval_valid',Petish_Product_Review::record($product,'rabbit')['status']==='eligible');
 $check('persisted_signature_restored_by_explicit_editor',get_post_meta($product->get_id(),Petish_Product_Review::key('rabbit','signature'),true)!=='');
 $check('persisted_valid_flag_restored',get_post_meta($product->get_id(),Petish_Product_Review::key('rabbit','valid'),true)==='1');
 $check('fresh_product_passes_signed_review',Petish_Product_Review::record(wc_get_product($product->get_id()),'rabbit')['status']==='eligible');
}finally{
 $check('local_transaction_rolled_back',$wpdb->query('ROLLBACK')!==false);
 foreach($ids as$id)$check('synthetic_row_absent_'.count($checks),$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d",$id))===null);
}
echo json_encode(['status'=>'success','checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
