<?php
/** Header fragment boundaries: no database writes, network or remote avatar fetches. */
define('ABSPATH', __DIR__.'/');
$GLOBALS['account_fixture']=array('signed_in'=>false,'uid'=>21,'user'=>(object)array('ID'=>21,'display_name'=>'Nathan'),
    'avatar'=>'https://secure.gravatar.com/avatar/PRIVATE-HASH','items'=>array('dashboard'=>'Dashboard','orders'=>'Orders','my-pets'=>'My Pets','for-my-pet'=>'For my pet','my-reviews'=>'My reviews','customer-logout'=>'Log out'),
    'hooks'=>array(),'filters'=>array(),'styles'=>array(),'scripts'=>array(),'inline'=>array(),'menu_reads'=>0,'avatar_reads'=>0,'user_reads'=>0,'admin'=>false);
class FragmentResponse extends RuntimeException { public function __construct(public $value,public $success,public $status=200) {} }
function add_action(...$a){$GLOBALS['account_fixture']['hooks'][]=$a;}
function add_filter(...$a){$GLOBALS['account_fixture']['filters'][]=$a;}
function home_url($path='/'){return 'https://petish.example'.$path;}
function admin_url($path){return 'https://petish.example/wp-admin/'.$path;}
function wp_parse_url($url){return parse_url($url);}
function untrailingslashit($url){return rtrim($url,'/');}
function wc_get_page_permalink($page){return 'https://petish.example/my-account/';}
function wc_get_account_menu_items(){$GLOBALS['account_fixture']['menu_reads']++;return $GLOBALS['account_fixture']['items'];}
function wc_get_account_endpoint_url($key){
    if($key==='my-reviews')return 'https://petish.example/my-account/?my-reviews=1';
    if($key==='customer-logout')return 'https://petish.example/my-account/customer-logout/?_wpnonce=PRIVATE-OWN-NONCE';
    if($key==='dashboard')return wc_get_page_permalink('myaccount');
    if($key==='external')return 'https://attacker.example/steal';
    return 'https://petish.example/my-account/'.$key.'/';
}
function is_user_logged_in(){return $GLOBALS['account_fixture']['signed_in'];}
function get_current_user_id(){return $GLOBALS['account_fixture']['uid'];}
function wp_get_current_user(){$GLOBALS['account_fixture']['user_reads']++;return $GLOBALS['account_fixture']['user'];}
function get_avatar_url($id,$args){$GLOBALS['account_fixture']['avatar_reads']++;$GLOBALS['account_fixture']['avatar_args']=array($id,$args);return $GLOBALS['account_fixture']['avatar'];}
function wp_strip_all_tags($value){return strip_tags($value);}
function is_admin(){return $GLOBALS['account_fixture']['admin'];}
function get_stylesheet_directory(){return __DIR__.'/../wordpress/wp-content/themes/dt-the7-child';}
function get_stylesheet_directory_uri(){return 'https://petish.example/wp-content/themes/dt-the7-child';}
function wp_enqueue_style(...$a){$GLOBALS['account_fixture']['styles'][]=$a;}
function wp_enqueue_script(...$a){$GLOBALS['account_fixture']['scripts'][]=$a;}
function wp_add_inline_script(...$a){$GLOBALS['account_fixture']['inline'][]=$a;}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
function __($v,$domain){return $v;}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html($v){return esc_attr($v);}
function esc_url($v){return esc_attr($v);}
function esc_attr__($v,$domain){return esc_attr($v);}
function esc_html__($v,$domain){return esc_html($v);}
function presscore_get_mini_widget_class($setting,$extra){
    $GLOBALS['account_fixture']['widget_class_args']=array($setting,$extra);
    return array_merge(array('show-on-desktop','near-logo-first-switch','near-logo-second-switch'),(array)$extra);
}
function nocache_headers(){$GLOBALS['account_fixture']['no_cache']=true;}
function wp_send_json_error($v,$status=200){throw new FragmentResponse($v,false,$status);}
function wp_send_json_success($v,$status=200){throw new FragmentResponse($v,true,$status);}
function wp_remote_get(...$a){throw new RuntimeException('No remote profile image fetch');}
function update_user_meta(...$a){throw new RuntimeException('No identity writes');}
function wp_mail(...$a){throw new RuntimeException('No mail from header');}
require __DIR__.'/../wordpress/wp-content/themes/dt-the7-child/includes/account-dropdown.php';
$checks=array();$f=&$GLOBALS['account_fixture'];$class=Petish_Account_Dropdown::class;
function check($key,$value){global$checks;$checks[$key]=$value===true;if(!$value)throw new RuntimeException($key);}
function item($id,$url,$parent=0,$classes=array()){return(object)array('ID'=>$id,'url'=>$url,'menu_item_parent'=>$parent,'classes'=>$classes);}
function state(){try{Petish_Account_Dropdown::state();}catch(FragmentResponse$r){return$r;}throw new RuntimeException('Response missing');}
if(($argv[1]??'')==='shell'){$class::render();exit;}
check('include_inert',$f['hooks']===array()&&$f['filters']===array());
$class::boot();
check('narrow_hooks_registered',count($f['hooks'])===4&&count($f['filters'])===1
    &&$f['filters'][0]===array('wp_nav_menu_objects',array($class,'items'),30,2)
    &&in_array(array('presscore_render_header_element-search',array($class,'render'),20),$f['hooks'],true)
    &&in_array(array('wp_ajax_'.$class::AJAX_ACTION,array($class,'state')),$f['hooks'],true)
    &&in_array(array('wp_ajax_nopriv_'.$class::AJAX_ACTION,array($class,'state')),$f['hooks'],true));
ob_start();$class::render();$public_shell=ob_get_clean();
check('native_widget_shell_rendered',str_contains($public_shell,'data-petish-account')&&str_contains($public_shell,'petish-account-widget')
    &&str_contains($public_shell,'https://petish.example/my-account/')&&str_contains($public_shell,'My account'));
check('widget_uses_search_native_visibility_classes',$f['widget_class_args'][0]==='header-elements-search'
    &&str_contains($public_shell,'show-on-desktop')&&str_contains($public_shell,'near-logo-second-switch'));
check('public_widget_no_private_reads',$f['menu_reads']===0&&$f['avatar_reads']===0&&$f['user_reads']===0
    &&!str_contains($public_shell,'PRIVATE-')&&!str_contains($public_shell,'Nathan')&&!str_contains($public_shell,'_wpnonce'));
$menu=array(0=>item(1,'https://petish.example/'),1=>item(2,'https://petish.example/my-account/',0,array('native-account')),2=>item(3,'https://petish.example/my-account/orders/',2),3=>item(4,'https://petish.example/cart/'));
$primary=(object)array('theme_location'=>'primary');
$moved=$class::items($menu,$primary);
check('account_branch_removed_from_primary',array_map(static fn($i)=>$i->ID,array_values($moved))===array(1,4));
check('other_menu_objects_and_order_preserved',$moved[0]===$menu[0]&&$moved[3]===$menu[3]);
check('original_objects_unmodified',$menu[1]->classes===array('native-account'));
check('cart_object_preserved',$moved[3]===$menu[3]&&$moved[3]->url==='https://petish.example/cart/');
check('footer_unchanged',$class::items($menu,(object)array('theme_location'=>'footer'))===$menu);
check('mobile_supported',array_keys($class::items($menu,(object)array('theme_location'=>'mobile')))===array_keys($moved));
$nested=$menu+array(4=>item(5,'https://petish.example/my-account/deep/',3));
check('nested_account_descendants_removed',array_keys($class::items($nested,$primary))===array(0,3));
$duplicates=$nested+array(5=>item(6,'https://petish.example/my-account/'),6=>item(7,'https://petish.example/my-account/orders/',6));
check('duplicate_account_branches_removed',array_keys($class::items($duplicates,$primary))===array(0,3));
$foreign=array(item(5,'https://attacker.example/my-account/'));
check('external_account_not_tagged',$class::items($foreign,$primary)===$foreign);
$query=array(item(6,'https://petish.example/my-account/?my-reviews=1'));
check('endpoint_not_mistaken_for_parent',$class::items($query,$primary)===$query);
check('guest_payload_exact',$class::payload()===array('signed_in'=>false,'avatar'=>'','initial'=>'','items'=>array()));
check('guest_no_identity_or_avatar_reads',$f['menu_reads']===0&&$f['avatar_reads']===0&&$f['user_reads']===0);
$class::assets();
check('assets_registered',count($f['styles'])===1&&count($f['scripts'])===1&&count($f['inline'])===1
    &&$f['styles'][0][0]===$class::STYLE_HANDLE&&str_ends_with($f['styles'][0][1],'/assets/css/account-dropdown.css')
    &&$f['scripts'][0][0]===$class::SCRIPT_HANDLE&&str_ends_with($f['scripts'][0][1],'/assets/js/account-dropdown.js'));
check('asset_versions_follow_content_not_archive_time',$f['styles'][0][3]===substr(hash_file('sha256',get_stylesheet_directory().'/assets/css/account-dropdown.css'),0,12)
    &&$f['scripts'][0][3]===substr(hash_file('sha256',get_stylesheet_directory().'/assets/js/account-dropdown.js'),0,12));
check('cacheable_configuration_has_no_identity',!str_contains($f['inline'][0][1],'PRIVATE-')&&!str_contains($f['inline'][0][1],'Nathan')&&!str_contains($f['inline'][0][1],'_wpnonce'));
check('shared_config_no_payload_reads',$f['menu_reads']===0&&$f['user_reads']===0);
$f['signed_in']=true;$data=$class::payload();
check('signed_in_fragment_own_only',$data['signed_in']===true&&$data['initial']==='N'&&count($data['items'])===6);
check('remote_gravatar_not_exposed',$data['avatar']===''&&!str_contains(json_encode($data),'gravatar'));
$bykey=array_column($data['items'],null,'key');
check('native_my_reviews_query_preserved',$bykey['my-reviews']['url']==='https://petish.example/my-account/?my-reviews=1');
check('own_logout_nonce_private_fragment_only',$bykey['customer-logout']['url']==='https://petish.example/my-account/customer-logout/?_wpnonce=PRIVATE-OWN-NONCE');
check('all_native_account_items_in_order',array_column($data['items'],'key')===array_keys($f['items']));
$f['avatar']='https://petish.example/wp-content/uploads/profile.jpg';$data=$class::payload();
check('local_native_photo_used',$data['avatar']===$f['avatar']);
check('avatar_native_read_with_current_identity',$f['avatar_args'][0]===21&&$f['avatar_args'][1]['size']===64&&$f['avatar_args'][1]['default']==='blank');
foreach(array('http://petish.example/a.png','https://evil.example/a.png','https://user:password@petish.example/a.png','https://petish.example:444/a.png','javascript:alert(1)','https://petish.example/a%250A.png')as$url){$f['avatar']=$url;check('unsafe_avatar_'.hash('sha256',$url),$class::payload()['avatar']==='');}
$f['avatar']='';$f['items']['external']='External';$f['items']['bad<script>']='Bad';$f['items']['empty']='<b>Safe label</b>';
$data=$class::payload();$bykey=array_column($data['items'],null,'key');
check('arbitrary_external_or_bad_keys_dropped',!isset($bykey['external'])&&!isset($bykey['bad<script>']));
check('labels_are_plain_text',$bykey['empty']['label']==='Safe label');
$f['user']=(object)array('ID'=>22,'display_name'=>'Wrong user');check('mismatched_identity_is_guest',$class::payload()['signed_in']===false);
$f['user']=(object)array('ID'=>21,'display_name'=>'<b>Ωmega</b>');$data=$class::payload();check('unicode_initial_bounded',preg_match('//u',$data['initial'])===1&&strlen($data['initial'])<=8);
$_SERVER['REQUEST_METHOD']='GET';$count=$f['menu_reads'];$response=state();
check('get_denied_before_identity_read',$response->success===false&&$response->status===403&&$f['menu_reads']===$count);
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_ORIGIN']='https://attacker.example';$response=state();
check('cross_origin_denied_before_identity_read',$response->success===false&&$response->status===403&&$f['menu_reads']===$count);
$_SERVER['HTTP_ORIGIN']='https://petish.example';$_SERVER['HTTP_SEC_FETCH_SITE']='cross-site';$response=state();
check('cross_site_fetch_denied',$response->success===false&&$response->status===403);
$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';$response=state();
check('same_origin_post_succeeds',$response->success===true&&$response->value['signed_in']===true);
check('private_response_nocache',$f['no_cache']===true&&defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE===true);
$f['signed_in']=false;$response=state();check('anonymous_post_only_generic_snapshot',$response->success===true&&$response->value===array('signed_in'=>false,'avatar'=>'','initial'=>'','items'=>array()));
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
