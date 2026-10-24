<?php
/** Exercise the real hero settings resolver without database writes or network. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['hero_fixture'] = array('manager'=>true,'options'=>array(),'posts'=>array(),'images'=>array(),'meta'=>array(),'errors'=>array(),'actions'=>array(),'filters'=>array(),'registered'=>array(),'styles'=>array(),'scripts'=>array(),'media'=>0);
function current_user_can($cap) { return $GLOBALS['hero_fixture']['manager'] && in_array($cap,array('edit_theme_options','manage_options','upload_files'),true); }
function get_option($key,$default=false) { return $GLOBALS['hero_fixture']['options'][$key] ?? $default; }
function update_option(...$args) { throw new RuntimeException('Settings resolution must not write options.'); }
function add_option(...$args) { throw new RuntimeException('Loading settings must not create options.'); }
function wp_remote_get(...$args) { throw new RuntimeException('No image downloads or remote validation.'); }
function wp_mail(...$args) { throw new RuntimeException('No hero email.'); }
function get_post($id) { return $GLOBALS['hero_fixture']['posts'][$id] ?? null; }
function get_post_status($id) { $post=is_object($id)?$id:get_post($id);return $post->post_status ?? false; }
function get_post_mime_type($id) { $post=is_object($id)?$id:get_post($id);return $post->post_mime_type ?? false; }
function get_post_field($field,$id,$context='display') { return get_post($id)->$field ?? ''; }
function get_post_meta($id,$key,$single=true) { return $GLOBALS['hero_fixture']['meta'][$id][$key] ?? ($single?'':array()); }
function wp_attachment_is_image($id) { return str_starts_with((string)get_post_mime_type($id),'image/'); }
function wp_get_attachment_image_src($id,$size='thumbnail',$icon=false) { return $GLOBALS['hero_fixture']['images'][$id] ?? false; }
function wp_get_attachment_url($id) { return $GLOBALS['hero_fixture']['images'][$id][0] ?? false; }
function wp_get_attachment_metadata($id) { $image=$GLOBALS['hero_fixture']['images'][$id] ?? null;return $image?array('width'=>$image[1],'height'=>$image[2]):false; }
function wp_strip_all_tags($value,$breaks=false) { return strip_tags($value); }
function sanitize_text_field($value) { return trim(strip_tags((string)$value)); }
function wp_parse_url($url,$component=-1) { return parse_url($url,$component); }
function esc_url_raw($url,$protocols=null) { return is_string($url)&&preg_match('~\Ahttps?://~i',$url)?$url:''; }
function esc_url($url,$protocols=null,$context='display') { return htmlspecialchars(esc_url_raw($url),ENT_QUOTES,'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function __($text,$domain='') { return $text; }
function esc_html__($text,$domain='') { return esc_html($text); }
function esc_attr__($text,$domain='') { return esc_attr($text); }
function absint($value) { return abs((int)$value); }
function home_url($path='/') { return 'https://petish.example'.$path; }
function get_stylesheet_directory_uri() { return 'https://petish.example/wp-content/themes/dt-the7-child'; }
function get_stylesheet_directory() { return __DIR__.'/../wordpress/wp-content/themes/dt-the7-child'; }
function add_action(...$args) { $GLOBALS['hero_fixture']['actions'][]=$args; }
function add_filter(...$args) { $GLOBALS['hero_fixture']['filters'][]=$args; }
function add_settings_error(...$args) { $GLOBALS['hero_fixture']['errors'][]=$args; }
function register_setting(...$args) { $GLOBALS['hero_fixture']['registered'][]=$args; }
function add_menu_page(...$args) { $GLOBALS['hero_fixture']['menus'][]=$args;return 'toplevel_page_petish-hero-images'; }
function add_submenu_page(...$args) { $GLOBALS['hero_fixture']['submenus'][]=$args;return 'toplevel_page_petish-hero-images'; }
function wp_enqueue_media(...$args) { $GLOBALS['hero_fixture']['media']++; }
function wp_enqueue_script(...$args) { $GLOBALS['hero_fixture']['scripts'][]=$args; }
function wp_enqueue_style(...$args) { $GLOBALS['hero_fixture']['styles'][]=$args; }
function wp_add_inline_script(...$args) { $GLOBALS['hero_fixture']['inline_scripts'][]=$args; }
function wp_add_inline_style(...$args) { $GLOBALS['hero_fixture']['inline_styles'][]=$args; }
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
function wp_die(...$args) { throw new RuntimeException('Access denied.'); }
class WP_Error { function __construct(public $code='',public $message='') {} }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Post {
    public $ID; public $post_type; public $post_status; public $post_parent; public $post_password; public $post_mime_type;
    function __construct($data) { foreach($data as$key=>$value)$this->$key=$value; }
}

function fixture_image($id,$mime='image/jpeg',$parent=0,$status='inherit') {
    $GLOBALS['hero_fixture']['posts'][$id]=new WP_Post(array('ID'=>$id,'post_type'=>'attachment','post_status'=>$status,'post_parent'=>$parent,'post_password'=>'','post_mime_type'=>$mime));
    $GLOBALS['hero_fixture']['images'][$id]=array('https://petish.example/wp-content/uploads/hero-'.$id.'.jpg',1800,1000,false);
    $GLOBALS['hero_fixture']['meta'][$id]['_wp_attachment_image_alt']='Custom photo '.$id;
}
function defaults() {
    $result=array();
    foreach(array('rabbit','guinea-pig','hamster','chinchilla') as $slug) $result[]=array('slug'=>$slug,'image'=>$slug.'-fallback.webp','width'=>2400,'height'=>1600,'name'=>ucfirst($slug),'alt'=>'Default '.$slug,'author'=>'ORIGINAL-CREDIT-'.$slug,'source'=>'https://photos.example/'.$slug,'license_name'=>'Original license','license_url'=>'https://photos.example/license','changes'=>'Original fallback transformation');
    return $result;
}
require __DIR__.'/../wordpress/wp-content/themes/dt-the7-child/includes/hero-images.php';
$checks=array();
function check($name,$value) { global$checks;$checks[$name]=$value===true;if(!$value)throw new RuntimeException($name); }
$f=&$GLOBALS['hero_fixture'];$class=Petish_Hero_Images::class;
$slots=array('rabbit','guinea-pig','hamster','chinchilla');$empty=array_fill_keys($slots,0);
check('include_inert',$f['actions']===array()&&$f['filters']===array()&&$f['registered']===array());
$class::boot();
check('boot_hooks_only',count($f['actions'])>=3&&$f['options']===array());
$class::register();
$registration=$f['registered'][0];
check('native_setting_registered',$registration[0]===$class::GROUP&&$registration[1]===$class::OPTION&&$registration[2]['sanitize_callback']===array($class,'sanitize')&&$registration[2]['show_in_rest']===false&&$registration[2]['default']===$empty);
check('settings_capability_matches_page',$f['filters'][0][0]==='option_page_capability_'.$class::GROUP&&$f['filters'][0][1]()==='edit_theme_options');
$class::menu();
check('native_homepage_menu_and_hero_submenu',$f['menus'][0][0]==='Homepage'&&$f['menus'][0][2]==='edit_theme_options'&&$f['menus'][0][3]===$class::PAGE&&$f['submenus'][0][2]==='Hero images'&&$f['submenus'][0][3]==='edit_theme_options');
$class::admin_assets('unrelated-screen');
check('media_tools_scoped_to_settings',$f['media']===0&&$f['scripts']===array());
$class::admin_assets('toplevel_page_'.$class::PAGE);
check('native_media_picker_enqueued',$f['media']===1&&count($f['scripts'])===1&&str_ends_with($f['scripts'][0][1],'/assets/js/hero-images-admin.js')&&in_array('media-editor',$f['scripts'][0][2],true));
check('media_script_has_content_version',$f['scripts'][0][3]===substr(hash_file('sha256',get_stylesheet_directory().'/assets/js/hero-images-admin.js'),0,16));
$f['manager']=false;$class::admin_assets('toplevel_page_'.$class::PAGE);$f['manager']=true;
check('unauthorized_screen_no_media_enqueue',$f['media']===1&&count($f['scripts'])===1);
check('empty_settings_are_four_resets',$class::settings()===$empty);
check('invalid_option_shape_defaults',($f['options'][$class::OPTION]='not-an-array')&&$class::settings()===$empty);
$f['options']=array();
foreach(array(11=>'image/jpeg',12=>'image/png',13=>'image/webp',14=>'image/avif',15=>'image/gif') as$id=>$mime) {
    fixture_image($id,$mime);
    $image=$class::image($id);
    check('native_mime_'.$id,is_array($image)&&$image['url']===$f['images'][$id][0]&&$image['width']===1800&&$image['height']===1000&&$image['alt']==='Custom photo '.$id);
}
foreach(array(0,-11,true,11.5,'011','11.0','11e0','11abc',' 11',array(),999) as$index=>$id) check('malformed_or_missing_id_'.$index,$class::image($id)===false);
fixture_image(21,'image/svg+xml');check('svg_not_public_hero',$class::image(21)===false);
fixture_image(22,'text/html');check('non_image_rejected',$class::image(22)===false);
fixture_image(23,'image/jpeg',0,'private');check('private_attachment_rejected',$class::image(23)===false);
fixture_image(24);$f['posts'][24]->post_type='post';check('wrong_post_type_rejected',$class::image(24)===false);
fixture_image(25);$f['posts'][25]->post_password='secret';check('password_attachment_rejected',$class::image(25)===false);
$f['posts'][201]=new WP_Post(array('ID'=>201,'post_type'=>'page','post_status'=>'publish','post_password'=>''));
fixture_image(26,'image/webp',201);check('public_parent_accepted',is_array($class::image(26)));
$f['posts'][201]->post_status='private';check('private_parent_rejected',$class::image(26)===false);
$f['posts'][201]->post_status='draft';check('draft_parent_rejected',$class::image(26)===false);
$f['posts'][201]->post_status='publish';$f['posts'][201]->post_password='secret';check('password_parent_rejected',$class::image(26)===false);
fixture_image(27,'image/jpeg',999);check('missing_parent_rejected',$class::image(27)===false);
foreach(array('javascript:alert(1)','data:image/png;base64,AA==','file:///private/photo.jpg','https://user:pass@petish.example/a.jpg','https://petish.example/a.jpg#fragment','https://petish.example/a%250a.jpg')as$index=>$url){fixture_image(30);$f['images'][30][0]=$url;check('unsafe_url_'.$index,$class::image(30)===false);}
fixture_image(30);$f['images'][30][1]=0;check('zero_width_rejected',$class::image(30)===false);
$f['images'][30][1]=1800;$f['images'][30][2]=-2;check('negative_height_rejected',$class::image(30)===false);
$f['meta'][11]['_wp_attachment_image_alt']='<b>Bright bunny</b> & grass';
check('alt_text_is_plain',$class::image(11)['alt']==='Bright bunny & grass');
$f['options'][$class::OPTION]=array('rabbit'=>11,'hamster'=>12,'alien'=>13);
check('unknown_stored_keys_dropped',$class::settings()===array('rabbit'=>11,'guinea-pig'=>0,'hamster'=>12,'chinchilla'=>0));
$before=$f['options'];$f['manager']=false;$denied=$class::sanitize(array('rabbit'=>13));
check('unauthorized_sanitizer_keeps_current',$denied===$class::settings()&&$f['options']===$before);
$f['manager']=true;$updated=$class::sanitize(array('rabbit'=>'13','hamster'=>'0'));
check('valid_selection_and_explicit_reset',$updated['rabbit']===13&&$updated['hamster']===0);
check('sanitize_does_not_write',$f['options']===$before);
check('malformed_input_preserves_current',$class::sanitize('not-an-image-map')===$class::settings());
$errors=count($f['errors']);$invalid=$class::sanitize(array('rabbit'=>'999','hamster'=>'12abc'));
check('invalid_input_preserves_previous',$invalid['rabbit']===11&&$invalid['hamster']===12&&count($f['errors'])>$errors);
$partial=$class::sanitize(array('rabbit'=>'0'));
check('partial_save_preserves_other_slots',$partial['rabbit']===0&&$partial['hamster']===12&&array_keys($partial)===$slots);
$foreign=$class::sanitize(array('rabbit'=>'11','unrecognised'=>'12'));
check('unknown_input_not_persisted',array_keys($foreign)===$slots&&!isset($foreign['unrecognised']));
$f['options'][$class::OPTION]=array('rabbit'=>999,'guinea-pig'=>23,'hamster'=>21,'chinchilla'=>array());
check('stale_or_private_stored_values_reset',$class::settings()===$empty);
$fallback=$class::resolve(defaults());
check('fallback_four_order_preserved',array_column($fallback,'slug')===$slots);
foreach(defaults()as$index=>$row)check('fallback_credits_unchanged_'.$index,$fallback[$index]['image']===$row['image']&&$fallback[$index]['author']===$row['author']&&$fallback[$index]['alt']===$row['alt']&&empty($fallback[$index]['custom']));
$f['options'][$class::OPTION]=array('rabbit'=>11,'hamster'=>12);
$resolved=$class::resolve(defaults());
check('custom_url_and_native_dimensions',$resolved[0]['custom']===true&&$resolved[0]['url']===$f['images'][11][0]&&$resolved[0]['width']===1800&&$resolved[0]['height']===1000);
check('custom_alt_uses_attachment',$resolved[0]['alt']==='Bright bunny & grass');
check('custom_has_no_original_credits',!str_contains(json_encode($resolved[0]),'ORIGINAL-CREDIT')&&empty($resolved[0]['author'])&&empty($resolved[0]['source'])&&empty($resolved[0]['license_name'])&&empty($resolved[0]['license_url'])&&empty($resolved[0]['changes']));
check('custom_hamster_has_no_old_backdrop_credit',$resolved[2]['custom']===true&&$resolved[2]['url']===$f['images'][12][0]&&empty($resolved[2]['changes']));
check('unselected_slots_remain_defaults',$resolved[1]===defaults()[1]&&$resolved[3]===defaults()[3]);
$f['meta'][11]['_wp_attachment_image_alt']='';$resolved=$class::resolve(defaults());
check('missing_custom_alt_uses_slot_description',$resolved[0]['alt']==='Default rabbit');
unset($f['posts'][11]);$resolved=$class::resolve(defaults());
check('deleted_attachment_falls_back',$resolved[0]===defaults()[0]);
check('no_options_mutated_by_resolve',$f['options'][$class::OPTION]===array('rabbit'=>11,'hamster'=>12));
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
