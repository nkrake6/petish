<?php
/** Read-only native homepage rendering after the narrowly scoped storefront release. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
$report=['status'=>'failure','category'=>'input','checks'=>[]];
ob_start(static function($ignored,$phase)use(&$report){
    if(!($phase&PHP_OUTPUT_HANDLER_FINAL))return '';
    $public=$report;$public['checks']=(object)$public['checks'];
    return json_encode($public,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
},0,0);

const PETISH_HOMEPAGE_PREVIOUS_FILES = [
    'wp-content/themes/dt-the7-child/inc/petish-email-branding.php'=>'c4d6fcbb325dacb6c3778b2bac6cfa0b007ad01972599131a5d8f5aa532c70e3',
    'wp-content/themes/dt-the7-child/inc/petish-account-email-branding.php'=>'8a347cbf2fb77b10f6ce0b5ae31a5ef639a2334990f6f8f2927d48e3b012b0fd',
    'wp-content/themes/dt-the7-child/woocommerce/emails/email-header.php'=>'f60dc3839daf6282aa533897f1ee82d21331d89e029fb4257e16f3f35279b2bd',
    'wp-content/themes/dt-the7-child/woocommerce/emails/email-footer.php'=>'a156a16e09b8a2db0257ff2a3a8514a3d3095b4d222f6f657f62f3a6e0dc6f8c',
    'wp-content/themes/dt-the7-child/woocommerce/emails/email-button.php'=>'d913f0696089c47252dfb04f51f4d6988305f6702924675092af2d8f7d09d116',
    'wp-content/plugins/petish-passport/assets/account-navigation.js'=>'5885712bde19288a62d9660ced39f98d48c27067fea7179e42b2bad48f311e75',
    'wp-content/plugins/petish-passport/assets/passport.css'=>'58ae61debf37356af35c47abc0881e2d393ae5f6330fa79afff1eb9e1b6732fd',
    'wp-content/plugins/petish-passport/includes/class-petish-passport-ui.php'=>'f3b878854d38a6a4fc83c5b5908a2429a84da2a7d1155609f83fae55a37dceac',
    'wp-content/themes/dt-the7-child/assets/brand/repaired/combined-light-v2.png'=>'45b8ab50ef5fd0b4bc98fb7994d3a4583d0d013db1b253bf7f870496cd5cf615',
];
const PETISH_HOMEPAGE_HOT_RULE_SHA256 = '159367b9eaf4e4ec6173568ad612182ab2800b70df1fd37185ebe4488d30b09c';
const PETISH_HOMEPAGE_BADGE_SHA256 = '4a731487daec6eda89a4e08aeec2bab86bd4f7a79e1828ad8bb77069600464af';

function petish_homepage_verify_fail() { throw new RuntimeException(); }
function petish_homepage_verify_path($path,$directory=false) {
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
function petish_homepage_verify_mark($name,$value) {
    global $report;
    if(!is_string($name)||!preg_match('/\A[a-z_]+\z/D',$name)||!is_bool($value))petish_homepage_verify_fail();
    $report['checks'][$name]=$value;
    if(!$value)petish_homepage_verify_fail();
}
/** State only; no stock values, private settings or account digests enter the report. */
function petish_homepage_verify_state() {
    global $wpdb,$report;
    $previous_category=$report['category'];$report['category']='snapshot_stock';
    if(!is_object($wpdb)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->prefix)
        ||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->postmeta))petish_homepage_verify_fail();
    $stock=$wpdb->get_results("SELECT meta_id,post_id,meta_key,SHA2(CONCAT(IF(meta_value IS NULL,'null:','value:'),COALESCE(meta_value,'')),256) AS value_state FROM `{$wpdb->postmeta}`"
        ." WHERE meta_key IN ('_stock','_stock_status') ORDER BY meta_id",ARRAY_A);
    if(!is_array($stock))petish_homepage_verify_fail();
    foreach($stock as$row)if(!is_array($row)||!preg_match('/\A[a-f0-9]{64}\z/Di',$row['value_state']??''))petish_homepage_verify_fail();
    $report['category']='snapshot_orders';$orders=petish_email_preview_order_counts();
    $report['category']='snapshot_accounts';$accounts=petish_email_preview_account_state();
    $report['category']='snapshot_shipping';$shipping=[];
    foreach(['petish_shipping_mode','petish_automatic_shipping_rates','petish_supplier_shipping_quotes',
        'woocommerce_currency','woocommerce_shipping_tax_class','woocommerce_shipping_cost_requires_address',
        'woocommerce_ship_to_destination','woocommerce_ship_to_countries','woocommerce_specific_ship_to_countries',
        'woocommerce_calc_taxes','woocommerce_prices_include_tax','woocommerce_tax_based_on',
        'woocommerce_dimension_unit','woocommerce_weight_unit']as$name)$shipping[$name]=get_option($name,null);
    $evidence=$wpdb->get_results("SELECT meta_id,post_id,meta_key,SHA2(CONCAT(IF(meta_value IS NULL,'null:','value:'),COALESCE(meta_value,'')),256) AS value_state FROM `{$wpdb->postmeta}`"
        ." WHERE meta_key IN ('_petish_supplier_free_delivery_evidence','_petish_supplier_product_url','_petish_supplier_order_count','_petish_supplier_order_observed') ORDER BY meta_id",ARRAY_A);
    if(!is_array($evidence))petish_homepage_verify_fail();
    foreach($evidence as$row)if(!is_array($row)||!preg_match('/\A[a-f0-9]{64}\z/Di',$row['value_state']??''))petish_homepage_verify_fail();
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
        'woocommerce_myaccount_edit_address_endpoint','woocommerce_logout_endpoint','show_on_front','page_on_front','woocommerce_hide_out_of_stock_items']as$name)$options[$name]=get_option($name,null);
    $report['category']=$previous_category;
    return ['orders'=>$orders,'accounts'=>$accounts,
        'stock'=>hash('sha256',json_encode($stock,JSON_THROW_ON_ERROR)),
        'shipping_policy'=>hash('sha256',serialize($shipping)),
        'supplier_evidence'=>hash('sha256',json_encode($evidence,JSON_THROW_ON_ERROR)),
        'settings'=>hash('sha256',serialize($options))];
}

function petish_homepage_verify_previous_files($root) {
    foreach(PETISH_HOMEPAGE_PREVIOUS_FILES as$file=>$expected){
        $path=$root.'/'.$file;
        if(!petish_homepage_verify_path($path)||!hash_equals($expected,hash_file('sha256',$path)))return false;
    }
    return true;
}

function petish_homepage_verify_card_ids($xpath,$node,$products) {
    $ids=[];
    foreach($xpath->query('.//article[contains(concat(" ",normalize-space(@class)," ")," ph-product ")]',$node)as$card){
        $links=$xpath->query('.//a[contains(concat(" ",normalize-space(@class)," ")," ph-product-link ")]',$card);
        if($links->length!==1)petish_homepage_verify_fail();
        $url=$links->item(0)->getAttribute('href');
        if(!isset($products[$url]))petish_homepage_verify_fail();
        $ids[]=(int)$products[$url]->get_id();
    }
    return $ids;
}

/** Native HTML remains private. Report only comparisons with the public product pool. */
function petish_homepage_verify_html($html,$pool,$free) {
    if(!is_string($html)||strlen($html)<100||strlen($html)>8388608||!class_exists('DOMDocument'))petish_homepage_verify_fail();
    $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{$loaded=$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
    finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    if(!$loaded)petish_homepage_verify_fail();
    $xpath=new DOMXPath($dom);$products=[];
    foreach($pool as$product)$products[$product->get_permalink()]=$product;
    $switches=$xpath->query('//*[@data-petish-theme-switch]');$switch_markup=$switches->length>=1&&$switches->length<=8;
    foreach($switches as$wrapper){
        $buttons=$xpath->query('./button[@data-petish-theme-toggle and @type="button" and @role="switch" and @aria-label="Dark mode" and @aria-checked="false"]',$wrapper);
        $previous=$wrapper->previousSibling;$next=$wrapper->nextSibling;
        while($previous&&!$previous instanceof DOMElement)$previous=$previous->previousSibling;
        while($next&&!$next instanceof DOMElement)$next=$next->nextSibling;
        $switch_markup=$switch_markup&&$wrapper->hasAttribute('hidden')&&$buttons->length===1
            &&$previous instanceof DOMElement&&preg_match('/(?:^|\s)mini-search(?:\s|$)/',$previous->getAttribute('class'))===1
            &&$next instanceof DOMElement&&$next->hasAttribute('data-petish-market')
            &&$xpath->query('.//*[local-name()="svg" and @aria-hidden="true"]',$wrapper)->length===2;
    }
    petish_homepage_verify_mark('theme_switch_markup_verified',(bool)$switch_markup);
    petish_homepage_verify_mark('full_homepage_rendered',$xpath->query('//*[@id="petish-home-title"]')->length===1
        &&$xpath->query('//*[@id="petish-collection"]')->length===1);
    $personal=$xpath->query('//*[@id="petish-for-your-pet"]');
    petish_homepage_verify_mark('for_your_pet_cacheable_shell_verified',$personal->length===1
        &&$xpath->query('.//*[@data-petish-for-your-pet-body]',$personal->item(0))->length===1
        &&$xpath->query('.//*[@data-petish-for-your-pet-error and @hidden]',$personal->item(0))->length===1
        &&$xpath->query('.//*[@data-petish-for-your-pet-retry]',$personal->item(0))->length===1
        &&$xpath->query('.//article[contains(concat(" ",normalize-space(@class)," ")," ph-product ")]',$personal->item(0))->length===0
        &&parse_url($personal->item(0)->getAttribute('data-endpoint'),PHP_URL_PATH)==='/wp-admin/admin-ajax.php');
    petish_homepage_verify_mark('hot_subtitle_removed',!str_contains(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'),'Selected by supplier-reported orders.'));
    $sections=$xpath->query('//*[@id="petish-free-delivery"]');
    petish_homepage_verify_mark('free_delivery_section_matches_evidence',$sections->length===(count($free)?1:0));
    $expected=array_map(static function($product){return(int)$product->get_id();},array_slice($free,0,4));
    $actual=$sections->length?petish_homepage_verify_card_ids($xpath,$sections->item(0),$products):[];
    petish_homepage_verify_mark('free_delivery_ordering_verified',$actual===$expected);
    $positions=[];
    foreach($xpath->query('//*[@id="petish-hot-items" or @id="petish-free-delivery" or @id="petish-collection"]')as$section)$positions[]=$section->getAttribute('id');
    $position_ok=!count($free)||(array_search('petish-free-delivery',$positions,true)<array_search('petish-collection',$positions,true)
        &&(!in_array('petish-hot-items',$positions,true)||array_search('petish-hot-items',$positions,true)<array_search('petish-free-delivery',$positions,true)));
    petish_homepage_verify_mark('free_delivery_section_position_verified',$position_ok);
    $free_badges_ok=true;$hot_badges_ok=true;
    foreach($xpath->query('//article[contains(concat(" ",normalize-space(@class)," ")," ph-product ")]')as$card){
        $links=$xpath->query('.//a[contains(concat(" ",normalize-space(@class)," ")," ph-product-link ")]',$card);
        if($links->length!==1||!isset($products[$links->item(0)->getAttribute('href')]))petish_homepage_verify_fail();
        $product=$products[$links->item(0)->getAttribute('href')];
        $badges=$xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," petish-free-delivery-badge ")]',$card);
        $eligible=petish_supplier_free_delivery_eligible($product);
        $free_badges_ok=$free_badges_ok&&$badges->length===($eligible?1:0);
        if($badges->length===1){
            $images=$xpath->query('.//img',$badges->item(0));
            $free_badges_ok=$free_badges_ok&&$images->length===1
                &&parse_url($images->item(0)->getAttribute('src'),PHP_URL_PATH)==='/wp-content/themes/dt-the7-child/assets/images/ui/free-delivery-van.png';
        }
        $hot=$xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," petish-hot-badge ")]',$card);
        $hot_badges_ok=$hot_badges_ok&&$hot->length===(petish_supplier_hot_badge($product)!==''?1:0);
    }
    petish_homepage_verify_mark('product_badges_match_eligibility',$free_badges_ok);
    petish_homepage_verify_mark('hot_badges_match_existing_rule',$hot_badges_ok);
}

function petish_homepage_verify_render($root) {
    global $report;
    petish_homepage_verify_mark('anonymous_render_context_verified',get_current_user_id()===0&&!is_user_logged_in());
    foreach(['petish_supplier_hot_badge','petish_supplier_free_delivery_eligible','petish_supplier_free_delivery_compare',
        'petish_supplier_free_delivery_badge','petish_supplier_free_delivery_destination_label']as$name)
        if(!function_exists($name))petish_homepage_verify_fail();
    petish_homepage_verify_mark('free_delivery_helpers_available',true);
    $reflection=new ReflectionFunction('petish_supplier_hot_badge');$lines=file($reflection->getFileName());
    $hot_source=implode('',array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
    petish_homepage_verify_mark('original_hot_rule_preserved',hash_equals(PETISH_HOMEPAGE_HOT_RULE_SHA256,hash('sha256',$hot_source)));
    $report['category']='query';wp();
    petish_homepage_verify_mark('homepage_query_verified',is_front_page());
    $args=['status'=>'publish','type'=>['simple','variable'],'visibility'=>'catalog','limit'=>-1,'orderby'=>'ID','order'=>'ASC'];
    if(get_option('woocommerce_hide_out_of_stock_items')==='yes')$args['stock_status']=['instock','onbackorder'];
    $pool=[];$free=[];
    foreach(wc_get_products($args)as$product){
        if(!$product instanceof WC_Product||$product->get_status()!=='publish'||!$product->is_visible())continue;
        $pool[]=$product;
        $eligible=petish_supplier_free_delivery_eligible($product);
        if(($eligible?true:false)!==(petish_supplier_free_delivery_badge($product)!==''))petish_homepage_verify_fail();
        if($eligible)$free[]=$product;
    }
    usort($free,'petish_supplier_free_delivery_compare');
    $report['checks']['free_delivery_products_available']=count($free)>0;
    $report['category']='renderer';ob_start();
    // The7 initializes page/header/footer configuration on its native
    // get_header action (presscore_template_config_init at priority 9), which
    // the actual front-page template invokes. No redirect hook is required.
    try{require$root.'/wp-content/themes/dt-the7-child/front-page.php';$html=ob_get_contents();}
    finally{ob_end_clean();}
    $report['category']='products';petish_homepage_verify_html($html,$pool,$free);unset($html);
    $style=wp_styles()->registered['petish-home']??null;
    petish_homepage_verify_mark('homepage_stylesheet_enqueued',wp_style_is('petish-home','enqueued')||wp_style_is('petish-home','done'));
    petish_homepage_verify_mark('stylesheet_version_matches_file',is_object($style)
        &&parse_url($style->src,PHP_URL_PATH)==='/wp-content/themes/dt-the7-child/assets/css/home.css'
        &&(string)$style->ver===(string)filemtime($root.'/wp-content/themes/dt-the7-child/assets/css/home.css'));
    $script=wp_scripts()->registered['petish-for-your-pet']??null;
    petish_homepage_verify_mark('for_your_pet_script_enqueued',(wp_script_is('petish-for-your-pet','enqueued')||wp_script_is('petish-for-your-pet','done'))
        &&is_object($script)&&parse_url($script->src,PHP_URL_PATH)==='/wp-content/themes/dt-the7-child/assets/js/for-your-pet.js'
        &&(string)$script->ver===(string)filemtime($root.'/wp-content/themes/dt-the7-child/assets/js/for-your-pet.js'));
    $theme_script=wp_scripts()->registered['petish-theme']??null;$design_style=wp_styles()->registered['petish-design']??null;
    petish_homepage_verify_mark('theme_switch_assets_enqueued',is_object($theme_script)&&is_object($design_style)
        &&(wp_script_is('petish-theme','enqueued')||wp_script_is('petish-theme','done'))
        &&(wp_style_is('petish-design','enqueued')||wp_style_is('petish-design','done'))
        &&parse_url($theme_script->src,PHP_URL_PATH)==='/wp-content/themes/dt-the7-child/assets/js/theme.js'
        &&parse_url($design_style->src,PHP_URL_PATH)==='/wp-content/themes/dt-the7-child/assets/css/petish.css'
        &&(string)$theme_script->ver===(string)filemtime($root.'/wp-content/themes/dt-the7-child/assets/js/theme.js')
        &&(string)$design_style->ver===(string)filemtime($root.'/wp-content/themes/dt-the7-child/assets/css/petish.css'));
}

try {
    if(!in_array($argc,[2,3],true)||($argc===3&&!in_array($argv[2],['production','staging'],true))
        ||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[1])||ini_get('allow_url_fopen'))petish_homepage_verify_fail();
    $target=$argc===3?$argv[2]:'production';$host=$target==='staging'?'staging.petish.biz':'petish.biz';
    $report['category']='paths';$home=getenv('HOME');
    if(!petish_homepage_verify_path($home,true))petish_homepage_verify_fail();
    $root=$home.'/domains/'.$host.'/public_html';
    foreach([$root,$root.'/wp-includes']as$path)if(!petish_homepage_verify_path($path,true))petish_homepage_verify_fail();
    foreach([$root.'/wp-config.php',$root.'/wp-load.php',$root.'/wp-includes/plugin.php',__DIR__.'/email-branding-preview-remote.php',__DIR__.'/verify-pet-club.php',
        $root.'/wp-content/themes/dt-the7-child/front-page.php',$root.'/wp-content/themes/dt-the7-child/assets/css/home.css',
        $root.'/wp-content/themes/dt-the7-child/includes/supplier-popularity.php',
        $root.'/wp-content/themes/dt-the7-child/includes/for-your-pet.php',
        $root.'/wp-content/themes/dt-the7-child/assets/js/for-your-pet.js']as$path)
        if(!petish_homepage_verify_path($path))petish_homepage_verify_fail();
    petish_homepage_verify_mark('target_paths_verified',true);
    $report['category']='assets';
    petish_homepage_verify_mark('previous_account_and_email_assets_preserved',petish_homepage_verify_previous_files($root));
    $badge=$root.'/wp-content/themes/dt-the7-child/assets/images/ui/free-delivery-van.png';
    if(!petish_homepage_verify_path($badge))petish_homepage_verify_fail();
    $dimensions=getimagesize($badge);
    petish_homepage_verify_mark('free_delivery_badge_asset_verified',is_array($dimensions)&&$dimensions[0]===1600&&$dimensions[1]===592
        &&hash_equals(PETISH_HOMEPAGE_BADGE_SHA256,hash_file('sha256',$badge)));
    $report['category']='bootstrap';
    foreach(['WP_HTTP_BLOCK_EXTERNAL'=>true,'WP_ACCESSIBLE_HOSTS'=>'','DISABLE_WP_CRON'=>true,
        'AUTOMATIC_UPDATER_DISABLED'=>true,'DISALLOW_FILE_MODS'=>true,'WP_USE_THEMES'=>false]as$name=>$value){
        if(defined($name))petish_homepage_verify_fail();define($name,$value);
    }
    $_SERVER['HTTP_HOST']=$host;$_SERVER['SERVER_NAME']=$host;$_SERVER['SERVER_PORT']='443';
    $_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/';$_SERVER['PHP_SELF']='/index.php';
    $_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['SERVER_PROTOCOL']='HTTP/1.1';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    require_once$root.'/wp-includes/plugin.php';
    // Reuse the tested barrier and full state proof. Its CLI entrypoint stays
    // disabled; the barrier remains attached through WordPress shutdown.
    define('PETISH_CLUB_VERIFY_LIBRARY',true);require_once __DIR__.'/verify-pet-club.php';
    $GLOBALS['petish_club_blocked_write_count']=0;
    add_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);
    $block_http=static function(){return new WP_Error('petish_homepage_verify_http_blocked','Homepage verification blocks outbound HTTP.');};
    $block_mail=static function(){return true;};
    $stop_delivery=static function(){throw new \PHPMailer\PHPMailer\Exception('Homepage verification blocks delivery.');};
    $false=static function(){return false;};$zero=static function(){return 0;};
    add_filter('pre_http_request',$block_http,PHP_INT_MAX,3);add_filter('pre_wp_mail',$block_mail,PHP_INT_MAX,2);
    add_action('phpmailer_init',$stop_delivery,PHP_INT_MAX);
    add_filter('action_scheduler_allow_async_request_runner',$false,PHP_INT_MAX);
    add_filter('action_scheduler_queue_runner_concurrent_batches',$zero,PHP_INT_MAX);
    add_filter('jetpack_sync_listener_should_load',$false,PHP_INT_MAX);
    add_filter('jetpack_sync_sender_should_load',$false,PHP_INT_MAX);
    add_filter('pre_option_jetpack_sync_settings_disable',$block_mail,PHP_INT_MAX);
    foreach(['wcpay_account_data','wcpay_multi_currency_cached_currencies','the7_plugins_last_check']as$key)
        add_filter('pre_update_option_'.$key,static function($value,$previous){return $previous;},PHP_INT_MAX,2);
    add_action('wp_loaded',static function(){remove_all_actions('action_scheduler_run_queue');petish_club_verify_disable_async_dispatch();},PHP_INT_MAX);
    add_action('all',static function($hook)use($block_http,$block_mail,$stop_delivery,$false,$zero){
        if($hook==='shutdown')petish_club_verify_disable_async_dispatch();
        if($hook==='query'){remove_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);add_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);}
        foreach(['jetpack_sync_listener_should_load'=>$false,'jetpack_sync_sender_should_load'=>$false,'pre_option_jetpack_sync_settings_disable'=>$block_mail]as$name=>$callback)
            if($hook===$name){remove_filter($hook,$callback,PHP_INT_MAX);add_filter($hook,$callback,PHP_INT_MAX);}
        if($hook==='pre_http_request'){remove_filter($hook,$block_http,PHP_INT_MAX);add_filter($hook,$block_http,PHP_INT_MAX,3);}
        if($hook==='pre_wp_mail'){remove_filter($hook,$block_mail,PHP_INT_MAX);add_filter($hook,$block_mail,PHP_INT_MAX,2);}
        if($hook==='phpmailer_init'){remove_action($hook,$stop_delivery,PHP_INT_MAX);add_action($hook,$stop_delivery,PHP_INT_MAX);}
        if($hook==='action_scheduler_allow_async_request_runner'){remove_filter($hook,$false,PHP_INT_MAX);add_filter($hook,$false,PHP_INT_MAX);}
        if($hook==='action_scheduler_queue_runner_concurrent_batches'){remove_filter($hook,$zero,PHP_INT_MAX);add_filter($hook,$zero,PHP_INT_MAX);}
    },PHP_INT_MAX,1);
    require$root.'/wp-load.php';
    $bootstrap_blocked=$GLOBALS['petish_club_blocked_write_count'];
    $report['category']='runtime';
    petish_homepage_verify_mark('target_environment_verified',wp_get_environment_type()===$target
        &&parse_url(home_url('/'),PHP_URL_HOST)===$host&&parse_url(site_url('/'),PHP_URL_HOST)===$host
        &&($target==='staging'?(defined('PETISH_STAGING_GUARD_ACTIVE')&&PETISH_STAGING_GUARD_ACTIVE===true
            &&defined('WCPAY_DEV_MODE')&&WCPAY_DEV_MODE===true):(!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true)));
    petish_homepage_verify_mark('tested_theme_and_woocommerce_verified',get_stylesheet()==='dt-the7-child'
        &&get_template()==='dt-the7'&&defined('WC_VERSION')&&WC_VERSION==='11.2.1');
    $blocked=apply_filters('pre_http_request',false,['method'=>'POST'],'https://www.dsers.com/petish-homepage-verification');
    petish_homepage_verify_mark('all_outbound_http_blocked',is_wp_error($blocked)&&$blocked->get_error_code()==='petish_homepage_verify_http_blocked');
    petish_homepage_verify_mark('mail_calls_blocked',apply_filters('pre_wp_mail',null,[])===true);
    petish_homepage_verify_mark('remote_file_access_disabled',!ini_get('allow_url_fopen'));
    petish_homepage_verify_mark('cron_and_async_runner_disabled',defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON===true
        &&apply_filters('action_scheduler_allow_async_request_runner',true)===false
        &&apply_filters('action_scheduler_queue_runner_concurrent_batches',1)===0
        &&(!class_exists('ActionScheduler_QueueRunner',false)||has_action('shutdown',[ActionScheduler_QueueRunner::instance(),'maybe_dispatch_async_request'])===false));
    require_once __DIR__.'/email-branding-preview-remote.php';
    $report['category']='snapshot';$before=petish_homepage_verify_state();$full_before=petish_club_verify_state($root);
    petish_homepage_verify_mark('production_data_snapshot_verified',true);
    petish_homepage_verify_render($root);
    $report['category']='verification';$after=petish_homepage_verify_state();
    petish_homepage_verify_mark('order_counts_unchanged',$after['orders']===$before['orders']);
    petish_homepage_verify_mark('user_counts_unchanged',$after['accounts']['users']===$before['accounts']['users']);
    petish_homepage_verify_mark('authentication_state_unchanged',hash_equals($after['accounts']['auth_state'],$before['accounts']['auth_state']));
    petish_homepage_verify_mark('product_stock_unchanged',hash_equals($after['stock'],$before['stock']));
    petish_homepage_verify_mark('account_settings_unchanged',hash_equals($after['settings'],$before['settings']));
    petish_homepage_verify_mark('shipping_policy_and_evidence_unchanged',hash_equals($after['shipping_policy'],$before['shipping_policy'])
        &&hash_equals($after['supplier_evidence'],$before['supplier_evidence']));
    petish_homepage_verify_mark('protected_site_state_unchanged',$full_before===petish_club_verify_state($root));
    petish_homepage_verify_mark('native_read_only_verified',petish_club_verify_read_only_scope($bootstrap_blocked));
    petish_homepage_verify_mark('previous_account_and_email_assets_preserved',petish_homepage_verify_previous_files($root));
    $report['status']='success';$report['category']='complete';
}catch(Throwable$ignored){$report['status']='failure';}
exit($report['status']==='success'?0:1);

