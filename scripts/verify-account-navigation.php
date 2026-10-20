<?php
/** Read-only native account-menu validation after the three-file Passport release. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
$report=['status'=>'failure','category'=>'input','checks'=>[]];
ob_start(static function($ignored,$phase)use(&$report){
    if(!($phase&PHP_OUTPUT_HANDLER_FINAL))return '';
    $public=$report;$public['checks']=(object)$public['checks'];
    return json_encode($public,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
},0,0);

function petish_navigation_verify_fail() { throw new RuntimeException(); }
function petish_navigation_verify_path($path,$directory=false) {
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
function petish_navigation_verify_mark($name,$value) {
    global $report;
    if(!is_string($name)||!preg_match('/\A[a-z_]+\z/D',$name)||!is_bool($value))petish_navigation_verify_fail();
    $report['checks'][$name]=$value;
    if(!$value)petish_navigation_verify_fail();
}
/** State only; no stock values, private settings or account digests enter the report. */
function petish_navigation_verify_state() {
    global $wpdb,$report;
    $previous_category=$report['category'];$report['category']='snapshot_stock';
    if(!is_object($wpdb)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->prefix)
        ||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->postmeta))petish_navigation_verify_fail();
    $stock=$wpdb->get_results("SELECT meta_id,post_id,meta_key,SHA2(CONCAT(IF(meta_value IS NULL,'null:','value:'),COALESCE(meta_value,'')),256) AS value_state FROM `{$wpdb->postmeta}`"
        ." WHERE meta_key IN ('_stock','_stock_status') ORDER BY meta_id",ARRAY_A);
    if(!is_array($stock))petish_navigation_verify_fail();
    foreach($stock as$row)if(!is_array($row)||!preg_match('/\A[a-f0-9]{64}\z/Di',$row['value_state']??''))petish_navigation_verify_fail();
    $report['category']='snapshot_orders';$orders=petish_email_preview_order_counts();
    $report['category']='snapshot_accounts';$accounts=petish_email_preview_account_state();
    $report['category']='snapshot_settings';$options=[];
    foreach(['active_plugins','stylesheet','template','woocommerce_enable_guest_checkout',
        'woocommerce_enable_myaccount_registration','woocommerce_enable_signup_and_login_from_checkout',
        'woocommerce_registration_generate_username','woocommerce_registration_generate_password',
        'woocommerce_customer_processing_order_settings','woocommerce_customer_completed_order_settings',
        'woocommerce_customer_refunded_order_settings','woocommerce_customer_note_settings',
        'woocommerce_customer_new_account_settings','woocommerce_customer_verify_email_settings',
        'woocommerce_customer_reset_password_settings','woocommerce_email_from_name','woocommerce_email_from_address',
        'woocommerce_email_footer_text','woocommerce_myaccount_page_id','woocommerce_myaccount_orders_endpoint',
        'woocommerce_myaccount_downloads_endpoint','woocommerce_myaccount_edit_account_endpoint',
        'woocommerce_myaccount_edit_address_endpoint','woocommerce_logout_endpoint']as$name)$options[$name]=get_option($name,null);
    $report['category']=$previous_category;
    return ['orders'=>$orders,'accounts'=>$accounts,
        'stock'=>hash('sha256',json_encode($stock,JSON_THROW_ON_ERROR)),
        'settings'=>hash('sha256',serialize($options))];
}

/** Check native menu rows; no URL, nonce, label or customer data is published. */
function petish_navigation_verify_menu_html($html,$items,$current) {
    if(!is_string($html)||strlen($html)>1048576||!is_array($items)||!isset($items[$current]))return false;
    $processor=new WP_HTML_Tag_Processor($html);$navigation=false;$seen=[];$active=[];$row=null;
    while($processor->next_tag()){
        $tag=$processor->get_tag();$classes=$processor->get_attribute('class');
        $classes=is_string($classes)?preg_split('/\s+/',trim($classes)):[];
        if($tag==='NAV'&&in_array('woocommerce-MyAccount-navigation',$classes,true))$navigation=true;
        if($tag==='LI'){
            $row=null;
            foreach(array_keys($items)as$endpoint)if(in_array('woocommerce-MyAccount-navigation-link--'.$endpoint,$classes,true)){
                $row=$endpoint;
                if(in_array('is-active',$classes,true))$active[]=$endpoint;
                break;
            }
        }
        if($tag==='A'&&$row!==null){
            $href=$processor->get_attribute('href');$parsed=is_string($href)?parse_url($href):false;
            if(!is_array($parsed)||($parsed['scheme']??'')!=='https'||($parsed['host']??'')!=='petish.biz')return false;
            $expected=parse_url(wc_get_account_endpoint_url($row));
            if(!is_array($expected)||($parsed['path']??'/')!==($expected['path']??'/'))return false;
            if(!str_contains($html,esc_html($items[$row])))return false;
            $seen[$row]=true;$row=null;
        }
    }
    return $navigation&&array_keys($seen)===array_keys($items)&&$active===[$current];
}

function petish_navigation_verify_account() {
    global $wp,$report;
    if(!class_exists('Petish_Passport_UI')||!class_exists('Petish_Catalogue')
        ||!is_callable(['Petish_Passport_UI','enqueue'])||!is_object($wp))petish_navigation_verify_fail();
    petish_navigation_verify_mark('anonymous_render_context_verified',get_current_user_id()===0&&!is_user_logged_in());
    $query_vars=$wp->query_vars;
    $account=static function(){return true;};
    add_filter('woocommerce_is_account_page',$account,PHP_INT_MAX);
    try{
        $wp->query_vars=[];
        if(!is_account_page())petish_navigation_verify_fail();
        Petish_Passport_UI::enqueue();
        petish_navigation_verify_mark('account_assets_enqueued',wp_script_is('petish-account-navigation','enqueued')
            &&wp_style_is('petish-passport','enqueued'));
        $scripts=wp_scripts();$styles=wp_styles();
        $script=$scripts->registered['petish-account-navigation']??null;$style=$styles->registered['petish-passport']??null;
        $base=WP_PLUGIN_DIR.'/petish-passport/assets/';
        $script_path=is_object($script)?parse_url($script->src,PHP_URL_PATH):null;
        $style_path=is_object($style)?parse_url($style->src,PHP_URL_PATH):null;
        petish_navigation_verify_mark('asset_versions_match_files',is_object($script)&&is_object($style)
            &&$script_path==='/wp-content/plugins/petish-passport/assets/account-navigation.js'
            &&$style_path==='/wp-content/plugins/petish-passport/assets/passport.css'
            &&(string)$script->ver===(string)filemtime($base.'account-navigation.js')
            &&(string)$style->ver===(string)filemtime($base.'passport.css')
            &&(int)$scripts->get_data('petish-account-navigation','group')===1);
        $data=$scripts->get_data('petish-account-navigation','data');$strings=null;
        if(is_string($data)&&preg_match('/(?:\A|\n)var petishAccountNavigation = (.+);(?:\n|\z)/D',$data,$match))$strings=json_decode($match[1],true);
        petish_navigation_verify_mark('navigation_strings_localized',is_array($strings)
            &&is_string($strings['menuLabel']??null)&&trim($strings['menuLabel'])!==''
            &&is_string($strings['currentSection']??null)&&str_contains($strings['currentSection'],'%s'));
        $report['category']='navigation';$items=wc_get_account_menu_items();
        $required=['dashboard','customer-logout'];
        foreach(['orders'=>'woocommerce_myaccount_orders_endpoint','downloads'=>'woocommerce_myaccount_downloads_endpoint',
            'edit-account'=>'woocommerce_myaccount_edit_account_endpoint','edit-address'=>'woocommerce_myaccount_edit_address_endpoint']as$endpoint=>$option)
            if(get_option($option,$endpoint)!=='')$required[]=$endpoint;
        petish_navigation_verify_mark('native_account_menu_items_preserved',is_array($items)
            &&count(array_intersect($required,array_keys($items)))===count($required));
        petish_navigation_verify_mark('passport_menu_items_present',isset($items['my-pets'],$items['for-my-pet'])
            &&trim((string)$items['my-pets'])!==''&&trim((string)$items['for-my-pet'])!=='');
        $cases=['dashboard','orders','my-pets','for-my-pet'];
        foreach($cases as$endpoint){
            if(!isset($items[$endpoint]))petish_navigation_verify_fail();
            $wp->query_vars=$endpoint==='dashboard'?[]:[$endpoint=>''];
            $html=wc_get_template_html('myaccount/navigation.php');
            if(!petish_navigation_verify_menu_html($html,$items,$endpoint))petish_navigation_verify_fail();
            unset($html);
        }
        petish_navigation_verify_mark('native_navigation_rendered',true);
        petish_navigation_verify_mark('current_endpoint_labels_verified',true);
        petish_navigation_verify_mark('logout_link_preserved',isset($items['customer-logout']));
    }finally{$wp->query_vars=$query_vars;remove_filter('woocommerce_is_account_page',$account,PHP_INT_MAX);}
}

try {
    if($argc!==2||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[1])||ini_get('allow_url_fopen'))petish_navigation_verify_fail();
    $report['category']='paths';$home=getenv('HOME');
    if(!petish_navigation_verify_path($home,true))petish_navigation_verify_fail();
    $root=$home.'/domains/petish.biz/public_html';
    foreach([$root,$root.'/wp-includes']as$path)if(!petish_navigation_verify_path($path,true))petish_navigation_verify_fail();
    foreach([$root.'/wp-config.php',$root.'/wp-load.php',$root.'/wp-includes/plugin.php',__DIR__.'/email-branding-preview-remote.php']as$path)
        if(!petish_navigation_verify_path($path))petish_navigation_verify_fail();
    petish_navigation_verify_mark('production_paths_verified',true);
    $report['category']='assets';$plugin=$root.'/wp-content/plugins/petish-passport';
    foreach(['assets/account-navigation.js','assets/passport.css','includes/class-petish-passport-ui.php']as$file)
        if(!petish_navigation_verify_path($plugin.'/'.$file))petish_navigation_verify_fail();
    $js=file_get_contents($plugin.'/assets/account-navigation.js');$css=file_get_contents($plugin.'/assets/passport.css');
    petish_navigation_verify_mark('navigation_assets_present',is_string($js)&&strlen($js)<1048576
        &&str_contains($js,'pp-account-navigation-toggle')&&str_contains($js,'li.is-active a')
        &&is_string($css)&&strlen($css)<1048576&&str_contains($css,'pp-account-navigation-toggle')
        &&str_contains($css,'max-width:991px'));
    unset($js,$css);
    $report['category']='bootstrap';
    foreach(['WP_HTTP_BLOCK_EXTERNAL'=>true,'WP_ACCESSIBLE_HOSTS'=>'','DISABLE_WP_CRON'=>true,
        'AUTOMATIC_UPDATER_DISABLED'=>true,'DISALLOW_FILE_MODS'=>true,'WP_USE_THEMES'=>false]as$name=>$value){
        if(defined($name))petish_navigation_verify_fail();define($name,$value);
    }
    $_SERVER['HTTP_HOST']='petish.biz';$_SERVER['SERVER_NAME']='petish.biz';$_SERVER['SERVER_PORT']='443';
    $_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/my-account/';
    $_SERVER['SERVER_PROTOCOL']='HTTP/1.1';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    require_once$root.'/wp-includes/plugin.php';
    $block_http=static function(){return new WP_Error('petish_navigation_verify_http_blocked','Account navigation verification blocks outbound HTTP.');};
    $block_mail=static function(){return true;};
    $stop_delivery=static function(){throw new \PHPMailer\PHPMailer\Exception('Account navigation verification blocks delivery.');};
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
    petish_navigation_verify_mark('production_environment_verified',wp_get_environment_type()==='production'
        &&parse_url(home_url('/'),PHP_URL_HOST)==='petish.biz'&&parse_url(site_url('/'),PHP_URL_HOST)==='petish.biz'
        &&(!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true));
    petish_navigation_verify_mark('tested_theme_and_woocommerce_verified',get_stylesheet()==='dt-the7-child'
        &&get_template()==='dt-the7'&&defined('WC_VERSION')&&WC_VERSION==='11.2.1');
    $blocked=apply_filters('pre_http_request',false,['method'=>'POST'],'https://www.dsers.com/petish-account-navigation-verification');
    petish_navigation_verify_mark('all_outbound_http_blocked',is_wp_error($blocked)&&$blocked->get_error_code()==='petish_navigation_verify_http_blocked');
    petish_navigation_verify_mark('mail_calls_blocked',apply_filters('pre_wp_mail',null,[])===true);
    petish_navigation_verify_mark('remote_file_access_disabled',!ini_get('allow_url_fopen'));
    petish_navigation_verify_mark('cron_and_async_runner_disabled',defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON===true
        &&apply_filters('action_scheduler_allow_async_request_runner',true)===false
        &&apply_filters('action_scheduler_queue_runner_concurrent_batches',1)===0);
    require_once __DIR__.'/email-branding-preview-remote.php';
    $report['category']='snapshot';$before=petish_navigation_verify_state();
    petish_navigation_verify_mark('production_data_snapshot_verified',true);
    $report['category']='enqueue';petish_navigation_verify_account();
    $report['category']='verification';$after=petish_navigation_verify_state();
    petish_navigation_verify_mark('order_counts_unchanged',$after['orders']===$before['orders']);
    petish_navigation_verify_mark('user_counts_unchanged',$after['accounts']['users']===$before['accounts']['users']);
    petish_navigation_verify_mark('authentication_state_unchanged',hash_equals($after['accounts']['auth_state'],$before['accounts']['auth_state']));
    petish_navigation_verify_mark('product_stock_unchanged',hash_equals($after['stock'],$before['stock']));
    petish_navigation_verify_mark('account_settings_unchanged',hash_equals($after['settings'],$before['settings']));
    $report['status']='success';$report['category']='complete';
}catch(Throwable$ignored){$report['status']='failure';}
exit($report['status']==='success'?0:1);
