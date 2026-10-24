<?php
/** Native Media Library and Settings API proof; fresh disconnected fixtures only. */
if (PHP_SAPI !== 'cli') exit(2);
umask(0077); ob_start();
require '/srv/petish-native/wp-load.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/user.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require '/checks/native-hero/includes/hero-images.php';

$checks=array(); $failure=null; $owner=bin2hex(random_bytes(16)); $posts=array(); $users=array(); $files=array(); $options=array(); $mail_calls=0;
function hero_check($name,$value){global$checks;$checks[$name]=$value===true;if(!$checks[$name])throw new RuntimeException($name);}
function hero_ok($value,$name){hero_check($name,!is_wp_error($value));return$value;}
function hero_capture($callback){ob_start();try{$callback();return ob_get_contents();}finally{ob_end_clean();}}
function hero_option($name){global$options;if(!array_key_exists($name,$options))$options[$name]=get_option($name,null);}
function hero_snapshot($except=array()){
    global$wpdb; $result=array();
    foreach(array($wpdb->posts,$wpdb->postmeta,$wpdb->users,$wpdb->usermeta)as$table)
        $result[$table]=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$table` ORDER BY 1",ARRAY_A)));
    $values=$wpdb->get_results("SELECT option_name,option_value,autoload FROM `{$wpdb->options}` ORDER BY option_name",ARRAY_A);
    $result['options']=hash('sha256',serialize(array_values(array_filter($values,static fn($v)=>!in_array($v['option_name'],$except,true)))));
    return$result;
}
function hero_post($type,$status,$parent=0,$mime=''){
    global$owner,$posts;
    $id=hero_ok(wp_insert_post(array('post_type'=>$type,'post_status'=>$status,'post_parent'=>$parent,'post_title'=>'Owned hero fixture '.$owner,
        'post_mime_type'=>$mime,'meta_input'=>array('_petish_native_owner'=>$owner)),true),'owned_post_created');
    $posts[]=(int)$id;return(int)$id;
}
function hero_png($parent=0,$status='inherit'){
    global$owner,$files,$posts;
    $pixel=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lL8AAAAASUVORK5CYII=',true);
    $upload=wp_upload_bits('petish-hero-'.$owner.'-'.count($files).'.png',null,$pixel);
    hero_check('owned_png_uploaded',empty($upload['error'])&&is_file($upload['file']));$files[]=$upload['file'];
    $id=hero_ok(wp_insert_attachment(array('post_mime_type'=>'image/png','post_title'=>'Owned hero PNG','post_status'=>$status,
        'post_parent'=>$parent,'meta_input'=>array('_petish_native_owner'=>$owner)),$upload['file'],$parent,true),'owned_attachment_created');
    $posts[]=(int)$id;$metadata=wp_generate_attachment_metadata($id,$upload['file']);wp_update_attachment_metadata($id,$metadata);
    return(int)$id;
}
function hero_home(){return hero_capture(static function(){include '/checks/native-hero/front-page.php';});}
function hero_die($message='',$title='',$args=array()){throw new RuntimeException('native_wp_die');}
function hero_nonce($html){preg_match('/name="_wpnonce" value="([^"]+)"/',$html,$m);return html_entity_decode($m[1]??'',ENT_QUOTES,'UTF-8');}

try{
    hero_check('disconnected_origin',defined('PETISH_FULFILMENT_NATIVE_OWNER')&&wp_get_environment_type()==='local'&&home_url()==='http://petish-fulfilment.invalid');
    hero_check('exact_native_versions',get_bloginfo('version')==='7.1.3'&&WC_VERSION==='11.2.1');
    hero_check('http_blocked',is_wp_error(wp_remote_get('https://example.invalid/')));
    hero_check('cron_disabled',defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON);
    $capture=static function($return)use(&$mail_calls){$mail_calls++;return false;};add_filter('pre_wp_mail',$capture,PHP_INT_MAX);
    add_filter('stylesheet_directory',static fn()=>'/checks/native-hero');
    add_filter('stylesheet_directory_uri',static fn()=>home_url('/wp-content/themes/dt-the7-child'));
    wp_set_template_globals();
    add_filter('theme_file_path',static function($path,$file){return in_array($file,array('header.php','footer.php'),true)?'/checks/native-hero/'.$file:$path;},PHP_INT_MAX,2);
    $admin=get_users(array('role'=>'administrator','number'=>1))[0];wp_set_current_user($admin->ID);
    $customer=hero_ok(wp_insert_user(array('user_login'=>'hero-'.$owner,'user_pass'=>bin2hex(random_bytes(24)),
        'user_email'=>'hero-'.$owner.'@example.invalid','role'=>'customer')),'owned_customer_created');$users[]=(int)$customer;
    Petish_Hero_Images::boot();Petish_Hero_Images::register();
    $empty=array_fill_keys(Petish_Hero_Images::SLUGS,0);hero_option(Petish_Hero_Images::OPTION);
    hero_check('default_setting_not_persisted',get_option(Petish_Hero_Images::OPTION)===$empty
        &&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name=%s",Petish_Hero_Images::OPTION))===0);
    $registered=get_registered_settings()[Petish_Hero_Images::OPTION]??array();
    hero_check('native_settings_api_registration',($registered['group']??'')===Petish_Hero_Images::GROUP&&($registered['type']??'')==='array'
        &&($registered['sanitize_callback']??null)===array('Petish_Hero_Images','sanitize')&&($registered['show_in_rest']??null)===false);
    $allowed=option_update_filter(array());hero_check('native_options_whitelist',in_array(Petish_Hero_Images::OPTION,$allowed[Petish_Hero_Images::GROUP]??array(),true));
    hero_check('settings_group_theme_capability',apply_filters('option_page_capability_'.Petish_Hero_Images::GROUP,'manage_options')==='edit_theme_options');
    $GLOBALS['menu']=array();$GLOBALS['submenu']=array();$GLOBALS['admin_page_hooks']=array();
    Petish_Hero_Images::menu();
    hero_check('native_menu_registered',isset($GLOBALS['submenu'][Petish_Hero_Images::PAGE])&&get_plugin_page_hookname(Petish_Hero_Images::PAGE,'')==='toplevel_page_'.Petish_Hero_Images::PAGE);
    $form=hero_capture(array('Petish_Hero_Images','render'));
    hero_check('settings_form_native_action_and_nonce',str_contains($form,'action="options.php"')&&wp_verify_nonce(hero_nonce($form),Petish_Hero_Images::GROUP.'-options')!==false);
    hero_check('settings_form_four_controls',substr_count($form,'data-petish-hero-choose')===4&&substr_count($form,'data-petish-hero-reset')===4);
    Petish_Hero_Images::admin_assets('unrelated_screen');hero_check('unrelated_screen_no_media_script',!wp_script_is('petish-hero-images-admin','enqueued'));
    wp_set_current_user($customer);Petish_Hero_Images::admin_assets('toplevel_page_'.Petish_Hero_Images::PAGE);
    hero_check('customer_no_media_enqueue',!wp_script_is('petish-hero-images-admin','enqueued'));
    foreach(array(0,$customer)as$actor){wp_set_current_user($actor);$denied=false;add_filter('wp_die_handler',static fn()=>'hero_die');
        try{Petish_Hero_Images::render();}catch(RuntimeException $e){$denied=$e->getMessage()==='native_wp_die';}
        remove_all_filters('wp_die_handler');hero_check('actor_'.$actor.'_cannot_render_settings',$denied&&!current_user_can('edit_theme_options'));
    }
    wp_set_current_user($admin->ID);Petish_Hero_Images::admin_assets('toplevel_page_'.Petish_Hero_Images::PAGE);
    $script=wp_scripts()->registered['petish-hero-images-admin']??null;
    hero_check('native_media_library_enqueued',wp_script_is('media-editor','enqueued')&&wp_script_is('media-views','enqueued')&&wp_script_is('petish-hero-images-admin','enqueued'));
    hero_check('admin_script_real_content_hash',$script&&$script->ver===substr(hash_file('sha256','/checks/native-hero/assets/js/hero-images-admin.js'),0,16));
    $public=hero_post('page','publish');$private=hero_post('page','private');$password=hero_post('page','publish');
    $private_type=hero_post(Petish_Order_Cancellation_Requests::POST_TYPE,'publish');
    wp_update_post(array('ID'=>$password,'post_password'=>'owned-private-password'));
    $png=hero_png();$parent_png=hero_png($public);$private_png=hero_png($private);$password_png=hero_png($password);
    $avatar=hero_png(0,'private');$reviewer=hero_png($private);$nonpublic_png=hero_png($private_type);$no_meta=hero_png();delete_post_meta($no_meta,'_wp_attachment_metadata');
    $svg=hero_post('attachment','inherit',0,'image/svg+xml');$pdf=hero_post('attachment','inherit',0,'application/pdf');
    update_post_meta($png,'_wp_attachment_image_alt','Bright <b>bunny</b> & grass');
    $native=wp_get_attachment_image_src($png,'full');$image=Petish_Hero_Images::image($png);
    hero_check('native_real_png_metadata',wp_get_image_mime(get_attached_file($png))==='image/png'&&is_array($native)&&$native[1]===1&&$native[2]===1);
    hero_check('native_attachment_resolver',$image===array('url'=>$native[0],'width'=>1,'height'=>1,'alt'=>'Bright bunny & grass'));
    hero_check('public_parent_accepted',is_array(Petish_Hero_Images::image($parent_png)));
    hero_check('private_parent_and_avatar_rejected',Petish_Hero_Images::image($private_png)===false&&Petish_Hero_Images::image($avatar)===false&&Petish_Hero_Images::image($reviewer)===false);
    hero_check('published_nonpublic_type_parent_rejected',!is_post_publicly_viewable($private_type)&&Petish_Hero_Images::image($nonpublic_png)===false);
    hero_check('password_parent_rejected',Petish_Hero_Images::image($password_png)===false);
    hero_check('svg_and_pdf_rejected',Petish_Hero_Images::image($svg)===false&&Petish_Hero_Images::image($pdf)===false);
    hero_check('missing_native_dimensions_rejected',Petish_Hero_Images::image($no_meta)===false);
    hero_check('stale_id_rejected',Petish_Hero_Images::image(PHP_INT_MAX)===false);
    $before=hero_snapshot(array(Petish_Hero_Images::OPTION));$defaults_html=hero_home();
    $source=file_get_contents('/checks/native-hero/front-page.php');$start=strpos($source,'$petish_home_photos   = array(');$end=strpos($source,"\nif ( class_exists( 'Petish_Hero_Images' ) )",$start);
    hero_check('real_default_array_located',$start!==false&&$end!==false);eval(substr($source,$start,$end-$start));$defaults=$petish_home_photos;
    hero_check('default_photo_array_byte_exact',serialize(Petish_Hero_Images::resolve($defaults))===serialize($defaults));
    hero_check('default_home_four_photographs',substr_count($defaults_html,'data-petish-hero-slide')===4&&str_contains($defaults_html,'rabbit-v6.webp')&&!str_contains($defaults_html,'ph-hero-slide-custom'));
    update_option(Petish_Hero_Images::OPTION,array('rabbit'=>(string)$png,'guinea-pig'=>'0','hamster'=>'0','chinchilla'=>'0'));
    hero_check('native_sanitize_option_roundtrip',Petish_Hero_Images::settings()===array('rabbit'=>$png,'guinea-pig'=>0,'hamster'=>0,'chinchilla'=>0));
    hero_check('hero_change_only_hero_option',hero_snapshot(array(Petish_Hero_Images::OPTION))===$before);
    $resolved=Petish_Hero_Images::resolve($defaults);$custom_html=hero_home();
    hero_check('custom_native_url_dimensions_alt',$resolved[0]['url']===$native[0]&&$resolved[0]['width']===1&&$resolved[0]['height']===1&&$resolved[0]['alt']==='Bright bunny & grass');
    hero_check('custom_original_photo_credits_removed',array_intersect(array_keys($resolved[0]),array('author','source','license_name','license_url','changes'))===array());
    hero_check('other_photo_rows_byte_exact',array_slice($resolved,1)===array_slice($defaults,1));
    hero_check('real_home_uses_custom_native_image',str_contains($custom_html,'ph-hero-slide-custom')&&str_contains($custom_html,'src="'.esc_url($native[0]).'" alt="Bright bunny &amp; grass" width="1" height="1"'));
    foreach(array(0,$customer)as$actor){wp_set_current_user($actor);$old=get_option(Petish_Hero_Images::OPTION);update_option(Petish_Hero_Images::OPTION,array('rabbit'=>$parent_png));
        hero_check('actor_'.$actor.'_native_sanitizer_preserves_selection',get_option(Petish_Hero_Images::OPTION)===$old);}
    wp_set_current_user($admin->ID);update_option(Petish_Hero_Images::OPTION,array('rabbit'=>$private_png,'hamster'=>$svg));
    hero_check('invalid_save_retains_prior_choice',Petish_Hero_Images::settings()===array('rabbit'=>$png,'guinea-pig'=>0,'hamster'=>0,'chinchilla'=>0));
    $attached=get_attached_file($png);$away=$attached.'.proof-away';rename($attached,$away);$files[]=$away;
    hero_check('offloaded_media_metadata_does_not_require_local_file',Petish_Hero_Images::image($png)===$image);rename($away,$attached);
    wp_update_post(array('ID'=>$png,'post_status'=>'trash'));
    hero_check('trashed_saved_attachment_falls_back',Petish_Hero_Images::resolve($defaults)===$defaults);
    wp_update_post(array('ID'=>$png,'post_status'=>'inherit'));
    update_option(Petish_Hero_Images::OPTION,$empty);
    hero_check('reset_restores_exact_defaults',Petish_Hero_Images::settings()===$empty&&Petish_Hero_Images::resolve($defaults)===$defaults&&hero_home()===$defaults_html);
    $read_before=hero_snapshot();Petish_Hero_Images::settings();Petish_Hero_Images::resolve($defaults);hero_home();
    hero_check('hero_reads_do_not_mutate_data',hero_snapshot()===$read_before);

    // New staff activation controls change only their two explicit options.
    $request_option=Petish_Order_Cancellation_Requests::OPTION;$email_option=Petish_Order_Cancellation_Emails::OPTION;
    hero_option($request_option);hero_option($email_option);
    hero_check('cancellation_flags_default_off',!Petish_Order_Cancellation_Requests::enabled()&&!Petish_Order_Cancellation_Emails::enabled());
    $settings=array('_wpnonce'=>wp_create_nonce(Petish_Order_Cancellation_Admin::NONCE.':settings'),'accept_requests'=>'yes','send_emails'=>'yes');
    $_SERVER['REQUEST_METHOD']='POST';wp_set_current_user($customer);
    hero_check('customer_cannot_enable_cancellation',is_wp_error(Petish_Order_Cancellation_Admin::settings_request($settings)));
    wp_set_current_user($admin->ID);$settings['_wpnonce']=wp_create_nonce(Petish_Order_Cancellation_Admin::NONCE.':settings');
    $bad=$settings;$bad['_wpnonce']='invalid';hero_check('cancellation_settings_nonce_required',is_wp_error(Petish_Order_Cancellation_Admin::settings_request($bad)));
    $bad=$settings;$bad['send_emails']=true;hero_check('cancellation_settings_strict_values',is_wp_error(Petish_Order_Cancellation_Admin::settings_request($bad)));
    $_SERVER['REQUEST_METHOD']='GET';hero_check('cancellation_settings_post_required',is_wp_error(Petish_Order_Cancellation_Admin::settings_request($settings)));$_SERVER['REQUEST_METHOD']='POST';
    $settings_before=hero_snapshot(array($request_option,$email_option));$mail_before=$mail_calls;
    $saved=Petish_Order_Cancellation_Admin::settings_request($settings);
    hero_check('cancellation_settings_roundtrip',$saved===array('accept_requests'=>'yes','send_emails'=>'yes')&&Petish_Order_Cancellation_Requests::enabled()&&Petish_Order_Cancellation_Emails::enabled());
    hero_check('cancellation_enable_only_two_options',hero_snapshot(array($request_option,$email_option))===$settings_before);
    hero_check('cancellation_enable_never_dispatches',$mail_calls===$mail_before);
    $off=Petish_Order_Cancellation_Admin::settings_request(array('_wpnonce'=>$settings['_wpnonce']));
    hero_check('cancellation_unchecked_disables_both',$off===array('accept_requests'=>'no','send_emails'=>'no')&&!Petish_Order_Cancellation_Requests::enabled()&&!Petish_Order_Cancellation_Emails::enabled());
    hero_check('no_mail_calls_in_hero_proof',$mail_calls===0);
}catch(Throwable $error){$failure=$error->getMessage();}
finally{
    wp_set_current_user(1);
    foreach($options as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}
    foreach(array_reverse($posts)as$id)if(get_post_meta($id,'_petish_native_owner',true)===$owner){if(get_post_type($id)==='attachment')wp_delete_attachment($id,true);else wp_delete_post($id,true);}
    foreach($users as$id){$u=get_userdata($id);if($u&&str_contains($u->user_login,$owner))wp_delete_user($id);}
    foreach($files as$file)if(is_file($file)&&str_contains(basename($file),$owner))unlink($file);
}
ob_end_clean();
echo json_encode(array('status'=>$failure===null?'success':'failure','checks'=>$checks,'failed_check'=>$failure),JSON_PRETTY_PRINT)."\n";
exit($failure===null?0:1);
