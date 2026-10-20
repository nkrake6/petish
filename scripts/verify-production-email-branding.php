<?php
/** Read-only native rendering after the narrowly scoped production email release. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
$report=['status'=>'failure','category'=>'input','checks'=>[]];
ob_start(static function($ignored,$phase)use(&$report){
    if(!($phase&PHP_OUTPUT_HANDLER_FINAL))return '';
    $public=$report;$public['checks']=(object)$public['checks'];
    return json_encode($public,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
},0,0);

function petish_production_verify_fail() { throw new RuntimeException(); }
function petish_production_verify_path($path,$directory=false) {
    if(!is_string($path)||$path===''||$path[0]!=='/'||str_contains($path,"\0"))return false;
    $cursor='';
    foreach(explode('/',substr($path,1))as$part){
        if($part===''||$part==='.'||$part==='..')return false;
        $cursor.='/'.$part;
        if(is_link($cursor)||realpath($cursor)!==$cursor)return false;
    }
    $info=@lstat($path);
    return $info&&($directory?is_dir($path):(is_file($path)&&$info['nlink']===1));
}
function petish_production_verify_mark($name,$value) {
    global $report;
    if(!is_string($name)||!preg_match('/\A[a-z_]+\z/D',$name)||!is_bool($value))petish_production_verify_fail();
    $report['checks'][$name]=$value;
    if(!$value)petish_production_verify_fail();
}
/** State only; no stock values, private settings or account digests enter the report. */
function petish_production_verify_state() {
    global $wpdb;
    if(!is_object($wpdb)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->prefix)
        ||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->postmeta))petish_production_verify_fail();
    $stock=$wpdb->get_results("SELECT meta_id,post_id,meta_key,SHA2(meta_value,256) AS value_state FROM `{$wpdb->postmeta}`"
        ." WHERE meta_key IN ('_stock','_stock_status') ORDER BY meta_id",ARRAY_A);
    if(!is_array($stock))petish_production_verify_fail();
    foreach($stock as$row)if(!is_array($row)||!preg_match('/\A[a-f0-9]{64}\z/Di',$row['value_state']??''))petish_production_verify_fail();
    $options=[];
    foreach(['active_plugins','stylesheet','template','woocommerce_enable_guest_checkout',
        'woocommerce_enable_myaccount_registration','woocommerce_enable_signup_and_login_from_checkout',
        'woocommerce_registration_generate_username','woocommerce_registration_generate_password',
        'woocommerce_customer_processing_order_settings','woocommerce_customer_completed_order_settings',
        'woocommerce_customer_refunded_order_settings','woocommerce_customer_note_settings',
        'woocommerce_customer_new_account_settings','woocommerce_customer_verify_email_settings',
        'woocommerce_customer_reset_password_settings','woocommerce_email_from_name','woocommerce_email_from_address',
        'woocommerce_email_footer_text']as$name)$options[$name]=get_option($name,null);
    return ['orders'=>petish_email_preview_order_counts(),'accounts'=>petish_email_preview_account_state(),
        'stock'=>hash('sha256',json_encode($stock,JSON_THROW_ON_ERROR)),
        'settings'=>hash('sha256',serialize($options))];
}

function petish_production_verify_html($html,$order_type=false) {
    if(!is_string($html)||strlen($html)<100||strlen($html)>1048576
        ||!str_contains($html,'/assets/brand/repaired/combined-light-v2.png')
        ||stripos($html,'#6532a1')===false||stripos($html,'#ede7ff')===false)return false;
    $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    if(!str_contains($text,'Alex')||!str_contains($text,'Petish'))return false;
    if($order_type&&(!str_contains($text,'P-1042')||!str_contains($text,'Corner & Gap Cleaning Brush')
        ||!(str_contains($text,'14.60')||str_contains($text,'14,60'))))return false;
    $processor=new WP_HTML_Tag_Processor($html);$logo=false;
    while($processor->next_tag(['tag_name'=>'IMG'])){
        $url=$processor->get_attribute('src');$parsed=is_string($url)?parse_url($url):false;
        if(is_array($parsed)&&($parsed['scheme']??null)==='https'&&($parsed['host']??null)==='petish.biz'
            &&($parsed['path']??null)==='/wp-content/themes/dt-the7-child/assets/brand/repaired/combined-light-v2.png'
            &&(string)$processor->get_attribute('width')==='240'&&(string)$processor->get_attribute('height')==='160')$logo=true;
    }
    return $logo;
}
function petish_production_verify_button($html,$url) {
    $processor=new WP_HTML_Tag_Processor($html);
    while($processor->next_tag(['tag_name'=>'A'])){
        $classes=$processor->get_attribute('class');$style=$processor->get_attribute('style');
        if(!is_string($classes)||!preg_match('/(?:\A|\s)button(?:\s|\z)/D',$classes)
            ||$processor->get_attribute('href')!==$url||!is_string($style))continue;
        if(preg_match('/(?:\A|;)\s*color\s*:\s*#(?:ffffff|fff)(?:\s*!important)?\s*(?:;|\z)/iD',$style)
            &&preg_match('/(?:\A|;)\s*background-color\s*:\s*#6532a1(?:\s*!important)?\s*(?:;|\z)/iD',$style))return true;
    }
    return false;
}

/** The factories below never call save(), trigger(), send(), or key generators. */
function petish_production_verify_render() {
    $order=petish_email_preview_order();$user=petish_email_preview_user();
    petish_production_verify_mark('synthetic_unsaved_order_and_user',(int)$order->get_id()===0&&(int)$user->ID===0&&!$user->exists());
    $definitions=['customer_processing_order'=>'processing','customer_completed_order'=>'completed',
        'customer_refunded_order'=>'refunded','customer_note'=>'processing'];
    $accounts=['customer_new_account','customer_verify_email','customer_reset_password'];
    $ids=array_merge(array_keys($definitions),$accounts);$found=[];
    foreach(WC()->mailer()->get_emails()as$email)if(is_object($email)&&in_array($email->id??'',$ids,true))$found[$email->id]=$email;
    petish_production_verify_mark('seven_native_email_types_available',count($found)===7
        &&is_a($found['customer_verify_email']??null,'Automattic\\WooCommerce\\Internal\\CustomerEmailVerification\\Emails\\CustomerVerifyEmail'));
    // Remove optional external image lookups for this display-only item. Every
    // filter here is request-local and removed after rendering.
    $item_args=static function($args){$args['show_image']=false;return $args;};
    add_filter('woocommerce_email_order_items_args',$item_args,PHP_INT_MAX);
    try{
        foreach($definitions as$id=>$status){
            $email=clone$found[$id];$sample=clone$order;$sample->set_status($status);
            if($status==='completed')$sample->set_date_completed('2026-10-21T10:00:00+11:00');
            $sample->preview_refunded=$status==='refunded';$email->object=$sample;$email->recipient='alex@petish.invalid';
            $email->placeholders['{site_title}']='Petish';$email->placeholders['{order_number}']='P-1042';
            $email->placeholders['{order_date}']='20 October 2026';
            $email->settings['heading']=$email->get_default_heading();$email->settings['heading_full']=$email->get_default_heading();
            if($id==='customer_refunded_order'){
                $email->partial_refund=false;$refund=new WC_Order_Refund();$refund->set_amount('14.60');
                $refund->set_reason('Synthetic display verification.');$email->refund=$refund;
                if((int)$refund->get_id()!==0)petish_production_verify_fail();
            }
            if($id==='customer_note')$email->customer_note='Synthetic display verification. No shipment or live tracking is represented.';
            $html=$email->style_inline($email->get_content_html());
            petish_production_verify_mark('order_email_html_branded',petish_production_verify_html($html,true));
            unset($html);
        }
        petish_production_verify_mark('four_order_types_rendered',true);
        foreach($accounts as$id){
            $email=petish_email_preview_account_email($found[$id],$user);
            $html=$email->style_inline($email->get_content_html());
            petish_production_verify_mark('account_email_html_branded',petish_production_verify_html($html,false)
                &&(int)$email->object->ID===0&&!$email->object->exists());
            if($id==='customer_verify_email')petish_production_verify_mark('verification_button_readable_and_native_url_preserved',
                petish_production_verify_button($html,$email->verify_url));
            unset($html);
        }
        petish_production_verify_mark('three_account_types_rendered',true);
        petish_production_verify_mark('core_welcome_and_reset_filters_verified',petish_email_preview_core_account_branding($user));
    }finally{remove_filter('woocommerce_email_order_items_args',$item_args,PHP_INT_MAX);}
}

try {
    if($argc!==2||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[1])||ini_get('allow_url_fopen'))petish_production_verify_fail();
    $report['category']='paths';
    $home=getenv('HOME');if(!petish_production_verify_path($home,true))petish_production_verify_fail();
    $root=$home.'/domains/petish.biz/public_html';
    foreach([$root,$root.'/wp-includes']as$path)if(!petish_production_verify_path($path,true))petish_production_verify_fail();
    foreach([$root.'/wp-config.php',$root.'/wp-load.php',$root.'/wp-includes/plugin.php',__DIR__.'/email-branding-preview-remote.php']as$path)
        if(!petish_production_verify_path($path))petish_production_verify_fail();
    petish_production_verify_mark('production_paths_verified',true);
    $logo=$root.'/wp-content/themes/dt-the7-child/assets/brand/repaired/combined-light-v2.png';
    if(!petish_production_verify_path($logo)||!hash_equals('45b8ab50ef5fd0b4bc98fb7994d3a4583d0d013db1b253bf7f870496cd5cf615',hash_file('sha256',$logo)))petish_production_verify_fail();
    $dimensions=getimagesize($logo);
    petish_production_verify_mark('approved_full_animal_logo_verified',is_array($dimensions)&&$dimensions[0]===1536&&$dimensions[1]===1024);
    $report['category']='bootstrap';
    foreach(['WP_HTTP_BLOCK_EXTERNAL'=>true,'WP_ACCESSIBLE_HOSTS'=>'','DISABLE_WP_CRON'=>true,
        'AUTOMATIC_UPDATER_DISABLED'=>true,'DISALLOW_FILE_MODS'=>true,'WP_USE_THEMES'=>false]as$name=>$value){
        if(defined($name))petish_production_verify_fail();define($name,$value);
    }
    $_SERVER['HTTP_HOST']='petish.biz';$_SERVER['SERVER_NAME']='petish.biz';$_SERVER['SERVER_PORT']='443';
    $_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/';
    $_SERVER['SERVER_PROTOCOL']='HTTP/1.1';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    require_once$root.'/wp-includes/plugin.php';
    $block_http=static function(){return new WP_Error('petish_production_verify_http_blocked','Production render verification blocks outbound HTTP.');};
    $block_mail=static function(){return true;};
    $stop_delivery=static function(){throw new \PHPMailer\PHPMailer\Exception('Production render verification blocks delivery.');};
    $false=static function(){return false;};$zero=static function(){return 0;};
    add_filter('pre_http_request',$block_http,PHP_INT_MAX,3);add_filter('pre_wp_mail',$block_mail,PHP_INT_MAX,2);
    add_action('phpmailer_init',$stop_delivery,PHP_INT_MAX);
    add_filter('action_scheduler_allow_async_request_runner',$false,PHP_INT_MAX);
    add_filter('action_scheduler_queue_runner_concurrent_batches',$zero,PHP_INT_MAX);
    add_action('wp_loaded',static function(){remove_all_actions('action_scheduler_run_queue');},PHP_INT_MAX);
    add_action('all',static function($hook)use($block_http,$block_mail,$stop_delivery,$false,$zero){
        if($hook==='pre_http_request'){remove_filter($hook,$block_http,PHP_INT_MAX);add_filter($hook,$block_http,PHP_INT_MAX,3);}
        if($hook==='pre_wp_mail'){remove_filter($hook,$block_mail,PHP_INT_MAX);add_filter($hook,$block_mail,PHP_INT_MAX,2);}
        if($hook==='phpmailer_init'){remove_action($hook,$stop_delivery,PHP_INT_MAX);add_action($hook,$stop_delivery,PHP_INT_MAX);}
        if($hook==='action_scheduler_allow_async_request_runner'){remove_filter($hook,$false,PHP_INT_MAX);add_filter($hook,$false,PHP_INT_MAX);}
        if($hook==='action_scheduler_queue_runner_concurrent_batches'){remove_filter($hook,$zero,PHP_INT_MAX);add_filter($hook,$zero,PHP_INT_MAX);}
    },PHP_INT_MAX,1);
    require$root.'/wp-load.php';
    $report['category']='runtime';
    petish_production_verify_mark('production_environment_verified',wp_get_environment_type()==='production'
        &&parse_url(home_url('/'),PHP_URL_HOST)==='petish.biz'&&parse_url(site_url('/'),PHP_URL_HOST)==='petish.biz'
        &&(!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true));
    petish_production_verify_mark('tested_theme_and_woocommerce_verified',get_stylesheet()==='dt-the7-child'
        &&get_template()==='dt-the7'&&defined('WC_VERSION')&&WC_VERSION==='11.2.1');
    $blocked=apply_filters('pre_http_request',false,['method'=>'POST'],'https://www.dsers.com/petish-email-verification');
    petish_production_verify_mark('all_outbound_http_blocked',is_wp_error($blocked)&&$blocked->get_error_code()==='petish_production_verify_http_blocked');
    petish_production_verify_mark('mail_calls_blocked',apply_filters('pre_wp_mail',null,[])===true);
    petish_production_verify_mark('remote_file_access_disabled',!ini_get('allow_url_fopen'));
    petish_production_verify_mark('cron_and_async_runner_disabled',defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON===true
        &&apply_filters('action_scheduler_allow_async_request_runner',true)===false
        &&apply_filters('action_scheduler_queue_runner_concurrent_batches',1)===0);
    require_once __DIR__.'/email-branding-preview-remote.php';
    $report['category']='snapshot';$before=petish_production_verify_state();
    $report['category']='renderer';petish_production_verify_render();
    $report['category']='verification';$after=petish_production_verify_state();
    petish_production_verify_mark('order_counts_unchanged',$after['orders']===$before['orders']);
    petish_production_verify_mark('user_counts_unchanged',$after['accounts']['users']===$before['accounts']['users']);
    petish_production_verify_mark('authentication_state_unchanged',hash_equals($after['accounts']['auth_state'],$before['accounts']['auth_state']));
    petish_production_verify_mark('product_stock_unchanged',hash_equals($after['stock'],$before['stock']));
    petish_production_verify_mark('email_and_account_settings_unchanged',hash_equals($after['settings'],$before['settings']));
    petish_production_verify_mark('seven_native_emails_rendered_without_dispatch',true);
    $report['status']='success';$report['category']='complete';
}catch(Throwable$ignored){$report['status']='failure';}
exit($report['status']==='success'?0:1);
