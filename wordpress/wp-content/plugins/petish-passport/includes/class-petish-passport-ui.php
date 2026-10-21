<?php
/** Owner Passport and opt-in public Pet Club interfaces. */
defined('ABSPATH') || exit;
final class Petish_Passport_UI {
 const ENDPOINT='my-pets';
 public static function boot() {
  add_action('init',array(__CLASS__,'register_endpoint'));
  add_filter('woocommerce_get_query_vars',array(__CLASS__,'query_vars'));
  add_filter('woocommerce_endpoint_my-pets_title',static function(){return __('My Pets','petish-passport');});
  add_filter('woocommerce_account_menu_items',array(__CLASS__,'account_menu'));
  add_action('woocommerce_account_my-pets_endpoint',array(__CLASS__,'render'));
  add_action('wp_enqueue_scripts',array(__CLASS__,'enqueue'),50);
  add_filter('body_class',array(__CLASS__,'body_class'));
  add_filter('wp_nav_menu_items',array(__CLASS__,'navigation'),20,2);
  add_filter('wp_page_menu',array(__CLASS__,'fallback_navigation'),20,2);
  foreach(array('create','update','set_active','delete','create_moment','update_moment','delete_moment') as $op) {
   add_action('admin_post_petish_passport_'.$op,array(__CLASS__,'handle_'.$op));
   add_action('admin_post_nopriv_petish_passport_'.$op,array(__CLASS__,'guest_redirect'));
  }
 }
 public static function query_vars($vars){$vars[self::ENDPOINT]=self::ENDPOINT;return $vars;}
 public static function register_endpoint() { add_rewrite_endpoint(self::ENDPOINT,EP_ROOT|EP_PAGES); }
 public static function account_menu($items) {
  $out=array(); foreach($items as $key=>$label) {
   if('customer-logout'===$key) $out[self::ENDPOINT]=__('My Pets','petish-passport');
   $out[$key]=$label;
  }
  if(!isset($out[self::ENDPOINT])) $out[self::ENDPOINT]=__('My Pets','petish-passport');
  return $out;
 }
 public static function url($view='',$id=0) {
  $url=wc_get_account_endpoint_url(self::ENDPOINT);
  return $view ? add_query_arg(array('passport'=>$view,'pet_id'=>absint($id)),$url) : $url;
 }
 private static function on_endpoint() { return function_exists('is_account_page') && is_account_page() && is_wc_endpoint_url(self::ENDPOINT); }
 public static function body_class($classes) { if(self::on_endpoint()) $classes[]='petish-passport-account';if(self::public_request()) $classes[]='petish-club-public';return $classes; }
 public static function public_request() { return isset($_GET['petish_pet']) || isset($_GET['petish_club']); }
 public static function enqueue() {
  $account=function_exists('is_account_page') && is_account_page();
  $club=self::public_request() || self::on_endpoint();
  if(!$account && !$club) return;
  $dir=dirname(__DIR__);$url=plugin_dir_url($dir.'/petish-passport.php');
  $deps=wp_style_is('petish-design','enqueued')?array('petish-design'):array();
  wp_enqueue_style('petish-passport',$url.'assets/passport.css',$deps,filemtime($dir.'/assets/passport.css'));
  if($account) {
   wp_enqueue_script('petish-account-navigation',$url.'assets/account-navigation.js',array(),filemtime($dir.'/assets/account-navigation.js'),true);
   wp_localize_script('petish-account-navigation','petishAccountNavigation',array(
    'menuLabel'=>__('Account menu','petish-passport'),
    'currentSection'=>__('Current section: %s','petish-passport'),
   ));
  }
  if($club) {
   wp_enqueue_style('petish-club',$url.'assets/pet-club.css',array('petish-passport'),filemtime($dir.'/assets/pet-club.css'));
   wp_enqueue_script('petish-passport',$url.'assets/passport.js',array(),filemtime($dir.'/assets/passport.js'),true);
  }
 }
 private static function nav_item() {
  $current=self::on_endpoint();
  return '<li class="menu-item petish-passport-menu'.($current?' current-menu-item':'').'"><a href="'.esc_url(self::url()).'"'.($current?' aria-current="page"':'').'><span class="menu-item-text"><span class="menu-text">'.esc_html__('My Pets','petish-passport').'</span></span></a></li>';
 }
 public static function navigation($items,$args) {
  if(!function_exists('wc_get_account_endpoint_url') || !in_array($args->theme_location??'',array('primary','mobile'),true) || false!==strpos($items,esc_url(self::url()))) return $items;
  return $items.self::nav_item();
 }
 public static function fallback_navigation($items,$args) {
  if(!function_exists('wc_get_account_endpoint_url') || !in_array($args['theme_location']??'',array('primary','mobile'),true) || false!==strpos($items,esc_url(self::url()))) return $items;
  $pos=strrpos($items,'</ul>');
  return false!==$pos ? substr_replace($items,self::nav_item(),$pos,0) : $items.self::nav_item();
 }
 public static function render() {
  if(!is_user_logged_in()) {woocommerce_login_form();return;}
  $uid=get_current_user_id();
  $view=isset($_GET['passport'])&&is_string($_GET['passport'])?sanitize_key(wp_unslash($_GET['passport'])):'';
  $id=isset($_GET['pet_id'])&&is_scalar($_GET['pet_id'])?absint($_GET['pet_id']):0;
  $pet=null;$moment=null;
  if(in_array($view,array('edit','delete','moment-create','moment-edit','moment-delete'),true)) {
   $pet=Petish_Passport_Store::get($id,$uid);
   if(is_wp_error($pet)) {self::unavailable();return;}
  }
  if(in_array($view,array('moment-edit','moment-delete'),true)) {
   $mid=isset($_GET['moment_id'])&&is_scalar($_GET['moment_id'])?absint($_GET['moment_id']):0;
   $moment=Petish_Passport_Moments::get($mid,$uid);
   if(is_wp_error($moment)||(int)$moment['pet_id']!==$id) {self::unavailable();return;}
  }
  echo '<div class="petish-passport petish-club" id="petish-passport">';
  if('create'===$view || 'edit'===$view) {
   $editing='edit'===$view;
   $values=$pet?:array('name'=>'','species'=>'','variety'=>'','variety_other'=>'','age'=>'unsure','interest'=>'browsing','bio'=>'','traits'=>array());
   $draft=self::read_draft($view,$editing?$id:0);if($draft) $values=array_merge($values,$draft);
   self::template('form',array('pet'=>$pet,'editing'=>$editing,'values'=>$values));
  } elseif('delete'===$view) self::template('delete',array('pet'=>$pet));
  elseif(in_array($view,array('moment-create','moment-edit','moment-delete'),true)) {
   $editing='moment-edit'===$view;$deleting='moment-delete'===$view;
   $kind=isset($_GET['kind'])&&'journal'===$_GET['kind']?'journal':'photo';
   $values=$moment?:array('kind'=>$kind,'caption'=>'');
   $draft=self::read_draft($view,$moment?$moment['id']:$id);if($draft) $values=array_merge($values,$draft);
   self::template('moment-form',compact('pet','moment','editing','deleting','values'));
  } else {
   $pets=Petish_Passport_Store::all_for_user($uid);$active=Petish_Passport_Store::active($uid);
   $moments=$active?Petish_Passport_Moments::all_for_pet($active['id'],$uid):array();
   if(is_wp_error($moments))$moments=array();
   $sharing=$active?Petish_Passport_Community::published_for_owner($active['id'],$uid):array('shared'=>false,'blocked'=>false);
   if(is_wp_error($sharing))$sharing=array('shared'=>false,'blocked'=>false);
   $neighbours=Petish_Passport_Community::discover(3,$sharing['token']??'');
   self::template('dashboard',compact('pets','active','moments','sharing','neighbours'));
  }
  echo '</div>';
 }
 private static function unavailable() {
  wc_print_notice(__('This item is not available in your account.','petish-passport'),'error');
  echo '<p><a href="'.esc_url(self::url()).'">'.esc_html__('Back to My Pets','petish-passport').'</a></p>';
 }
 public static function moment_url($view,$pet_id,$moment_id=0,$kind='photo') {
  $url=self::url($view,$pet_id);
  return add_query_arg($moment_id?array('moment_id'=>absint($moment_id)):array('kind'=>'journal'===$kind?'journal':'photo'),$url);
 }
 public static function render_public($profile,$neighbours=null) {
  echo '<main class="pc-public-shell"><div class="petish-passport petish-club" id="petish-passport">';
  self::template('public',array('profile'=>$profile,'neighbours'=>null===$neighbours?Petish_Passport_Community::discover(3,$profile['token']):$neighbours));
  echo '</div></main>';
 }
 public static function render_community($profiles) {
  echo '<main class="pc-public-shell"><div class="petish-passport petish-club" id="petish-passport">';
  self::template('community',array('profiles'=>$profiles));
  echo '</div></main>';
 }
 public static function public_avatar($profile,$large=false) {
  if(!empty($profile['photo_url'])) return '<span class="pp-avatar'.($large?' pp-avatar-large':'').'"><img src="'.esc_url($profile['photo_url']).'" alt="'.esc_attr(sprintf(__('Photo of %s','petish-passport'),$profile['name'])).'" width="480" height="480" decoding="async"></span>';
  return self::avatar(array('species'=>$profile['species'],'photo'=>''),$large);
 }
 public static function date_label($value) {
  $timestamp=is_string($value)?strtotime($value.' UTC'):false;
  return $timestamp?wp_date(get_option('date_format'),$timestamp):'';
 }
 public static function heart_control($profile) {
  $count=max(0,(int)($profile['hearts']??0));
  if(!empty($profile['can_heart'])) {
   echo '<form class="pc-heart-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   echo '<input type="hidden" name="action" value="petish_club_heart"><input type="hidden" name="token" value="'.esc_attr($profile['token']).'"><input type="hidden" name="hearted" value="'.(!empty($profile['hearted'])?'0':'1').'">';
   wp_nonce_field('petish_club_heart_'.$profile['token'],'petish_club_nonce');
   echo '<button type="submit" class="pc-heart" aria-pressed="'.(!empty($profile['hearted'])?'true':'false').'" aria-label="'.esc_attr(sprintf(!empty($profile['hearted'])?__('Remove your heart from %s','petish-passport'):__('Give %s a heart','petish-passport'),$profile['name'])).'">'.self::icon('heart').'<span>'.esc_html(number_format_i18n($count)).'</span><span>'.esc_html__('hearts','petish-passport').'</span></button></form>';
  } else {
   echo '<span class="pc-heart pc-heart-count">'.self::icon('heart').'<span>'.esc_html(number_format_i18n($count)).'</span> '.esc_html__('hearts','petish-passport').'</span>';
   if(!is_user_logged_in())echo '<a class="pc-sign-in" href="'.esc_url(wc_get_page_permalink('myaccount')).'">'.esc_html__('Sign in to give a heart','petish-passport').'</a>';
  }
 }
 public static function club_notice() {
  if(function_exists('WC')&&WC()->session&&function_exists('wc_print_notices')) wc_print_notices();
 }
 public static function gallery_dialog() {
  echo '<dialog class="pc-lightbox" data-pc-lightbox aria-labelledby="pc-lightbox-title"><div class="pc-lightbox-head"><h3 id="pc-lightbox-title">'.esc_html__('A little moment','petish-passport').'</h3><button type="button" class="pc-icon-button" data-pc-close aria-label="'.esc_attr__('Close photo','petish-passport').'">'.self::icon('close').'</button></div><img data-pc-lightbox-image alt=""><p data-pc-lightbox-caption></p></dialog>';
 }
 private static function template($name,$data) { extract($data,EXTR_SKIP);include dirname(__DIR__).'/templates/'.$name.'.php'; }
 public static function label($group,$key) { $labels=call_user_func(array('Petish_Passport_Store',$group));return $labels[$key]??''; }
 public static function avatar($pet,$large=false) {
  $size=$large?' pp-avatar-large':'';
  if(!empty($pet['photo'])) return '<span class="pp-avatar'.esc_attr($size).'"><img src="'.esc_url(Petish_Passport_Media::url($pet['id'])).'" alt="'.esc_attr(sprintf(__('Photo of %s','petish-passport'),$pet['name'])).'" width="240" height="240" decoding="async"></span>';
  $symbols=array('rabbit'=>'🐰','hamster'=>'🐹','guinea-pig'=>'🐹','chinchilla'=>'🐭','other'=>'🐾');
  return '<span class="pp-avatar pp-avatar-illustration'.esc_attr($size).'" aria-hidden="true"><span>'.esc_html($symbols[$pet['species']??'']??'🐾').'</span></span>';
 }
 public static function icon($name) {
  $paths=array('lock'=>'<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>','plus'=>'<path d="M12 5v14M5 12h14"/>','bag'=>'<path d="M5 8h14l1 12H4L5 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>','spark'=>'<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z"/>','home'=>'<path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/>','camera'=>'<path d="M8 6 9 3h6l1 3h4v15H4V6h4Z"/><circle cx="12" cy="13" r="4"/>','arrow'=>'<path d="M19 12H5m6-6-6 6 6 6"/>','heart'=>'<path d="M20.6 4.9a5.5 5.5 0 0 0-7.8 0L12 5.7l-.8-.8a5.5 5.5 0 0 0-7.8 7.8L12 21l8.6-8.3a5.5 5.5 0 0 0 0-7.8Z"/>','globe'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z"/>','close'=>'<path d="m6 6 12 12M18 6 6 18"/>');
  return '<svg class="pp-icon" aria-hidden="true" focusable="false" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['spark']).'</svg>';
 }
 private static function value($key) { return isset($_POST[$key])&&is_string($_POST[$key])?sanitize_text_field(wp_unslash($_POST[$key])):''; }
 private static function textarea_value($key) { return isset($_POST[$key])&&is_string($_POST[$key])?sanitize_textarea_field(wp_unslash($_POST[$key])):''; }
 private static function data() { return array('name'=>self::value('name'),'species'=>self::value('species'),'variety'=>self::value('variety'),'variety_other'=>self::value('variety_other'),'age'=>self::value('age'),'interest'=>self::value('interest'),'remove_photo'=>'1'===self::value('remove_photo'),'bio'=>self::textarea_value('bio'),'traits'=>self::value('traits')); }
 private static function authorize($operation,$id=0) {
  if(!is_user_logged_in()) self::guest_redirect();
  if(!function_exists('wc_add_notice')) require_once WC_ABSPATH.'includes/wc-notice-functions.php';
  if(function_exists('WC')&&!WC()->session) WC()->initialize_session();
  $action='petish_passport_'.$operation.('set_active'===$operation?'':'_'.absint($id));
  if('POST'!==($_SERVER['REQUEST_METHOD']??'') || !wp_verify_nonce(self::value('petish_passport_nonce'),$action)) {wc_add_notice(__('Your session could not be verified. Please try again from My Pets.','petish-passport'),'error');self::redirect();}
 }
 private static function redirect($view='',$id=0) { wp_safe_redirect(self::url($view,$id).'#petish-passport',303);exit; }
 public static function guest_redirect() { wp_safe_redirect(function_exists('wc_get_account_endpoint_url')?self::url():wp_login_url(),303);exit; }
 private static function file() { return $_FILES['photo']??null; }
 private static function save_draft($view,$id,$data) { if(function_exists('WC')&&WC()->session) WC()->session->set('petish_passport_form',array('view'=>$view,'id'=>absint($id),'values'=>$data)); }
 private static function read_draft($view,$id) {
  if(!function_exists('WC')||!WC()->session) return array();
  $d=WC()->session->get('petish_passport_form');WC()->session->__unset('petish_passport_form');
  if(!is_array($d)||($d['view']??'')!==$view||($d['id']??-1)!==absint($id)) return array();return is_array($d['values']??null)?$d['values']:array();
 }
 private static function save_result($result,$view,$id,$data) {
  if(is_wp_error($result)) {self::save_draft($view,$id,$data);wc_add_notice(esc_html($result->get_error_message()),'error');self::redirect($view,$id);}
  wc_add_notice('create'===$view?__('Your pet’s Passport is ready. Welcome aboard!','petish-passport'):__('Your pet’s Passport has been updated.','petish-passport'),'success');self::redirect();
 }
 public static function handle_create() { self::authorize('create');$data=self::data();self::save_result(Petish_Passport_Store::create(get_current_user_id(),$data,self::file()),'create',0,$data); }
 public static function handle_update() { $id=absint(self::value('pet_id'));self::authorize('update',$id);$data=self::data();self::save_result(Petish_Passport_Store::update($id,get_current_user_id(),$data,self::file()),'edit',$id,$data); }
 public static function handle_set_active() { self::authorize('set_active');$r=Petish_Passport_Store::set_active(absint(self::value('pet_id')),get_current_user_id());wc_add_notice(is_wp_error($r)?esc_html($r->get_error_message()):__('Active pet changed. Your choice is saved to your account.','petish-passport'),is_wp_error($r)?'error':'success');self::redirect(); }
 public static function handle_delete() {
  $id=absint(self::value('pet_id'));self::authorize('delete',$id);
  if('1'!==self::value('confirm_delete')) {wc_add_notice(__('Please confirm that you want to permanently delete this Passport.','petish-passport'),'error');self::redirect('delete',$id);}
  $r=Petish_Passport_Store::delete($id,get_current_user_id());wc_add_notice(is_wp_error($r)?esc_html($r->get_error_message()):__('The pet’s Passport, moments and published profile have been deleted.','petish-passport'),is_wp_error($r)?'error':'success');self::redirect();
 }
 private static function moment_data() { return array('kind'=>self::value('kind'),'caption'=>self::textarea_value('caption')); }
 private static function moment_redirect($view,$pet_id,$moment_id=0,$kind='photo') { wp_safe_redirect(self::moment_url($view,$pet_id,$moment_id,$kind),303);exit; }
 public static function handle_create_moment() {
  $id=absint(self::value('pet_id'));self::authorize('create_moment',$id);$data=self::moment_data();
  $result=Petish_Passport_Moments::create($id,get_current_user_id(),$data,self::file());
  if(is_wp_error($result)) {self::save_draft('moment-create',$id,$data);wc_add_notice(esc_html($result->get_error_message()),'error');self::moment_redirect('moment-create',$id,0,$data['kind']);}
  wc_add_notice(__('Your little moment has been saved privately.','petish-passport'),'success');self::redirect();
 }
 public static function handle_update_moment() {
  $id=absint(self::value('moment_id'));self::authorize('update_moment',$id);$data=self::moment_data();
  $moment=Petish_Passport_Moments::get($id,get_current_user_id());
  if(is_wp_error($moment)){wc_add_notice(esc_html($moment->get_error_message()),'error');self::redirect();}
  $result=Petish_Passport_Moments::update($id,get_current_user_id(),$data,self::file());
  if(is_wp_error($result)){self::save_draft('moment-edit',$id,$data);wc_add_notice(esc_html($result->get_error_message()),'error');self::moment_redirect('moment-edit',$moment['pet_id'],$id,$moment['kind']);}
  wc_add_notice(__('Your little moment has been updated privately.','petish-passport'),'success');self::redirect();
 }
 public static function handle_delete_moment() {
  $id=absint(self::value('moment_id'));self::authorize('delete_moment',$id);
  if('1'!==self::value('confirm_delete')){wc_add_notice(__('Confirm that you want to remove this moment.','petish-passport'),'error');self::redirect();}
  $result=Petish_Passport_Moments::delete($id,get_current_user_id());
  wc_add_notice(is_wp_error($result)?esc_html($result->get_error_message()):__('The moment and its photo have been removed.','petish-passport'),is_wp_error($result)?'error':'success');self::redirect();
 }

}
