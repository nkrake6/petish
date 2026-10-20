<?php
/** Native, read-only verification of the private delivery cost-sheet preview. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
$report=['status'=>'failure','category'=>'input','checks'=>[]];
ob_start(static function($ignored,$phase)use(&$report){
    if(!($phase&PHP_OUTPUT_HANDLER_FINAL))return '';
    $public=$report;$public['checks']=(object)$public['checks'];
    return json_encode($public,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
},0,0);

const PETISH_COST_SHEET_VERIFY_CHECKS = [
    'target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
    'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled',
    'cron_and_async_runner_disabled','cost_sheet_classes_loaded','admin_hooks_registered',
    'anonymous_access_denied','customer_access_denied','manager_access_verified',
    'invalid_nonce_rejected','valid_nonce_accepted','catalogue_template_verified',
    'csv_formula_injection_prevented','csv_limits_enforced','exact_identity_and_quantity_verified',
    'unknown_cost_not_zero','estimate_not_verified_rate','known_calculation_verified',
    'admin_preview_rendered','orders_unchanged','users_and_authentication_unchanged',
    'product_prices_and_stock_unchanged','shipping_policy_and_settings_unchanged',
];

function petish_cost_sheet_verify_fail() { throw new RuntimeException(); }
function petish_cost_sheet_verify_path($path,$directory=false) {
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
function petish_cost_sheet_verify_mark($name,$value) {
    global $report;
    if(!in_array($name,PETISH_COST_SHEET_VERIFY_CHECKS,true)||!is_bool($value))petish_cost_sheet_verify_fail();
    $report['checks'][$name]=$value;
    if(!$value)petish_cost_sheet_verify_fail();
}
/** Read only private digests. No cost, product, account or setting values are reported. */
function petish_cost_sheet_verify_state() {
    global $wpdb;
    foreach(['prefix','posts','postmeta','options']as$name)
        if(!isset($wpdb->$name)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->$name))petish_cost_sheet_verify_fail();
    $products=$wpdb->get_results("SELECT m.meta_id,m.post_id,m.meta_key,"
        ."SHA2(CONCAT(IF(m.meta_value IS NULL,'null:','value:'),COALESCE(m.meta_value,'')),256) AS value_state"
        ." FROM `{$wpdb->postmeta}` m INNER JOIN `{$wpdb->posts}` p ON p.ID=m.post_id"
        ." WHERE p.post_type IN ('product','product_variation') ORDER BY m.meta_id",ARRAY_A);
    $statuses=$wpdb->get_results("SELECT ID,post_type,post_parent,post_status FROM `{$wpdb->posts}`"
        ." WHERE post_type IN ('product','product_variation') ORDER BY ID",ARRAY_A);
    $settings=$wpdb->get_results("SELECT option_id,option_name,SHA2(CONCAT(IF(option_value IS NULL,'null:','value:'),"
        ."COALESCE(option_value,'')),256) AS value_state FROM `{$wpdb->options}`"
        ." WHERE option_name NOT LIKE '\\\\_transient\\\\_%' AND option_name NOT LIKE '\\\\_site\\\\_transient\\\\_%'"
        ." AND option_name NOT IN ('cron','rewrite_rules') ORDER BY option_id",ARRAY_A);
    if(!is_array($products)||!is_array($statuses)||!is_array($settings))petish_cost_sheet_verify_fail();
    foreach(array_merge($products,$settings)as$row)
        if(!is_array($row)||!preg_match('/\A[a-f0-9]{64}\z/Di',$row['value_state']??''))petish_cost_sheet_verify_fail();
    return [
        'orders'=>petish_email_preview_order_counts(),
        'accounts'=>petish_email_preview_account_state(),
        'products'=>hash('sha256',json_encode([$products,$statuses],JSON_THROW_ON_ERROR)),
        'settings'=>hash('sha256',json_encode($settings,JSON_THROW_ON_ERROR)),
    ];
}
/** During the preview only, suppress and record attempted persistent SQL mutations. */
function petish_cost_sheet_verify_query_guard($sql) {
    if(!is_string($sql))petish_cost_sheet_verify_fail();
    $probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
    if(preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)
        &&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)
        &&!preg_match('/;\s*\S/s',rtrim($probe)))return $sql;
    $GLOBALS['petish_cost_sheet_write_attempted']=true;
    return 'SELECT 0 WHERE 1=0';
}
/** wp_die is caught only inside this private CLI proof; no HTTP request is altered. */
function petish_cost_sheet_verify_die_handler() {
    return static function(){throw new RuntimeException('petish_cost_sheet_denied');};
}
function petish_cost_sheet_verify_rejected($callback) {
    try{$callback();}catch(InvalidArgumentException$ignored){return true;}
    return false;
}
function petish_cost_sheet_verify_denied($callback) {
    try{$callback();}catch(RuntimeException$error){return $error->getMessage()==='petish_cost_sheet_denied';}
    return false;
}
function petish_cost_sheet_verify_capture($callback) {
    ob_start();
    try{$callback();return ob_get_contents();}
    finally{ob_end_clean();}
}
function petish_cost_sheet_verify_private($method,...$args) {
    $reflection=new ReflectionMethod('Petish_Delivery_Cost_Sheet_Admin',$method);
    $reflection->setAccessible(true);
    return $reflection->invoke(null,...$args);
}
/** Synthetic inputs never become products, orders, rates, uploads or stored options. */
function petish_cost_sheet_verify_fixture($now) {
    $row=array_replace(array_fill_keys(Petish_Delivery_Cost_Sheet::COLUMNS,''),[
        'product_id'=>'1','variation_id'=>'0','expected_sku'=>'SKU-1','expected_parent_sku'=>'SKU-1',
        'supplier_product_url'=>'https://supplier.invalid/item/1','shipping_fingerprint'=>str_repeat('a',64),
        'country'=>'AU','state'=>'VIC','postcode'=>'3083','quantity'=>'2','service'=>'Tracked',
        'evidence_kind'=>'estimate','evidence_reference'=>'Private synthetic fixture',
        'observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-3600),'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600),
        'supplier_currency'=>'USD','supplier_to_aud_rate'=>'1.5','fx_observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-60),
        'supplier_unit_cost'=>'3','supplier_freight_total'=>'4','retail_unit_ex_tax_aud'=>'15',
        'customer_shipping_ex_tax_aud'=>'5','customer_tax_total_aud'=>'3.5','other_cost_aud'=>'1',
        'payment_fee_percent'=>'2','payment_fee_fixed_aud'=>'0.3','freight_buffer_percent'=>'10','target_margin_percent'=>'30',
    ]);
    $catalogue=[1=>['product_id'=>1,'variation_id'=>0,'product_label'=>'Private verification product','expected_sku'=>'SKU-1',
        'expected_parent_sku'=>'SKU-1','supplier_product_url'=>$row['supplier_product_url'],
        'shipping_fingerprint'=>$row['shipping_fingerprint'],'available'=>true,'maximum_quantity'=>10]];
    $countries=['AU'=>['states'=>['VIC'=>'Victoria'],'state_required'=>true,'postcode_required'=>true]];
    $currencies=['AUD'=>'Australian dollar','USD'=>'US dollar'];
    return [$row,$catalogue,$countries,$currencies];
}
/** Exact, independently computed economics and conservative unknown/identity handling. */
function petish_cost_sheet_verify_calculations($now) {
    [$row,$catalogue,$countries,$currencies]=petish_cost_sheet_verify_fixture($now);
    $preview=static function($rows,$facts=null)use($catalogue,$countries,$currencies,$now){
        return Petish_Delivery_Cost_Sheet::preview($rows,$facts??$catalogue,$countries,$currencies,$now);
    };
    $results=$preview([$row]);$known=$results[0]??[];
    $expected=['supplier_goods_aud'=>'9','supplier_freight_aud'=>'6','freight_buffer_aud'=>'0.6',
        'revenue_ex_tax_aud'=>'35','customer_total_aud'=>'38.5','payment_fees_aud'=>'1.07',
        'modelled_cost_aud'=>'17.67','contribution_aud'=>'17.33','customer_shipping_minus_supplier_freight_aud'=>'-1'];
    petish_cost_sheet_verify_mark('known_calculation_verified',($known['status']??null)==='complete'
        &&($known['totals']??null)===$expected&&($known['margin_percent']??null)==='49.51'&&($known['target_met']??null)===true);

    $invalid=[];
    foreach([['quantity'=>'0'],['quantity'=>'11'],['variation_id'=>'2'],['product_id'=>'2'],
        ['expected_sku'=>'CHANGED'],['expected_parent_sku'=>'CHANGED'],['supplier_product_url'=>'https://supplier.invalid/changed'],
        ['shipping_fingerprint'=>str_repeat('b',64)],['country'=>'ZZ'],['state'=>'NSW'],['postcode'=>''],
        ['expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-1)]]as$change)$invalid[]=array_replace($row,$change);
    $invalid_results=$preview($invalid);
    $exact=count($invalid_results)===count($invalid);
    foreach($invalid_results as$result)$exact=$exact&&($result['status']??null)==='invalid';
    $unavailable=$catalogue;$unavailable[1]['available']=false;
    $exact=$exact&&($preview([$row],$unavailable)[0]['status']??null)==='invalid';
    petish_cost_sheet_verify_mark('exact_identity_and_quantity_verified',$exact);

    $unknown=$preview([array_replace($row,['supplier_freight_total'=>''])])[0]??[];
    $zero=$preview([array_replace($row,['supplier_freight_total'=>'0'])])[0]??[];
    petish_cost_sheet_verify_mark('unknown_cost_not_zero',($unknown['status']??null)==='incomplete'
        &&in_array('supplier_freight_total',$unknown['missing']??[],true)
        &&array_key_exists('supplier_freight_aud',$unknown['totals']??[])&&$unknown['totals']['supplier_freight_aud']===null
        &&array_key_exists('contribution_aud',$unknown['totals']??[])&&$unknown['totals']['contribution_aud']===null
        &&($unknown['supplier_zero_freight_entered']??null)===false&&($zero['status']??null)==='complete'
        &&($zero['totals']['supplier_freight_aud']??null)==='0'&&($zero['supplier_zero_freight_entered']??null)===true);

    $safe=true;
    foreach(['=1+1','+SUM(1,2)','-1+2','@SUM(1,2)',"\t=1+1",'  =1+1']as$payload){
        $dangerous=array_replace($row,['expected_sku'=>$payload,'expected_parent_sku'=>$payload,'promotion_note'=>$payload]);
        $decoded=Petish_Delivery_Cost_Sheet::parse(Petish_Delivery_Cost_Sheet::csv([$dangerous]));
        $safe=$safe&&count($decoded)===1&&$decoded[0]['expected_sku']==="'".$payload
            &&$decoded[0]['expected_parent_sku']==="'".$payload&&$decoded[0]['promotion_note']==="'".$payload;
        $facts=$catalogue;$facts[1]['expected_sku']=$payload;$facts[1]['expected_parent_sku']=$payload;
        $roundtrip=$preview($decoded,$facts)[0]??[];
        $safe=$safe&&($roundtrip['status']??null)==='complete'&&($roundtrip['input']['expected_sku']??null)===$payload;
    }
    petish_cost_sheet_verify_mark('csv_formula_injection_prevented',$safe);
    $csv=Petish_Delivery_Cost_Sheet::csv([$row]);$lines=explode("\n",rtrim($csv,"\n"));
    $too_many=$lines[0]."\n".str_repeat($lines[1]."\n",Petish_Delivery_Cost_Sheet::MAX_ROWS+1);
    $limits=petish_cost_sheet_verify_rejected(static fn()=>Petish_Delivery_Cost_Sheet::parse(str_repeat('x',Petish_Delivery_Cost_Sheet::MAX_BYTES+1)))
        &&petish_cost_sheet_verify_rejected(static fn()=>Petish_Delivery_Cost_Sheet::parse($too_many))
        &&petish_cost_sheet_verify_rejected(static fn()=>Petish_Delivery_Cost_Sheet::csv(array_fill(0,Petish_Delivery_Cost_Sheet::MAX_ROWS+1,$row)))
        &&petish_cost_sheet_verify_rejected(static fn()=>Petish_Delivery_Cost_Sheet::parse("product_id\n1\n"))
        &&petish_cost_sheet_verify_rejected(static fn()=>Petish_Delivery_Cost_Sheet::parse($csv."\0"));
    petish_cost_sheet_verify_mark('csv_limits_enforced',$limits);
    return $results;
}
/** Request-local native principals; these objects never save a user or modify a role. */
function petish_cost_sheet_verify_principal($role,$caps) {
    $user=new WP_User(0);
    $user->roles=$role===null?[]:[$role];$user->caps=$caps;$user->allcaps=$caps;
    $GLOBALS['current_user']=$user;
}
function petish_cost_sheet_verify_native($root) {
    global $report;
    petish_cost_sheet_verify_mark('cost_sheet_classes_loaded',class_exists('Petish_Delivery_Cost_Sheet')
        &&class_exists('Petish_Delivery_Cost_Sheet_Admin'));
    require_once$root.'/wp-admin/includes/template.php';
    require_once$root.'/wp-admin/includes/plugin.php';
    $saved=['user'=>wp_get_current_user(),'get'=>$_GET,'post'=>$_POST,'files'=>$_FILES,
        'method'=>$_SERVER['REQUEST_METHOD'],'submenu'=>$GLOBALS['submenu']??null,
        'pages'=>$GLOBALS['_registered_pages']??null,'parents'=>$GLOBALS['_parent_pages']??null];
    $GLOBALS['petish_cost_sheet_write_attempted']=false;
    add_filter('query','petish_cost_sheet_verify_query_guard',PHP_INT_MAX);
    add_filter('wp_die_handler','petish_cost_sheet_verify_die_handler',PHP_INT_MAX);
    try{
        $_GET=[];$_POST=[];$_FILES=[];$_SERVER['REQUEST_METHOD']='GET';
        petish_cost_sheet_verify_principal(null,[]);
        petish_cost_sheet_verify_mark('anonymous_access_denied',!Petish_Delivery_Cost_Sheet_Admin::authorized()
            &&petish_cost_sheet_verify_denied(static fn()=>Petish_Delivery_Cost_Sheet_Admin::render())
            &&petish_cost_sheet_verify_denied(static fn()=>Petish_Delivery_Cost_Sheet_Admin::download()));
        petish_cost_sheet_verify_principal('customer',['read'=>true]);
        petish_cost_sheet_verify_mark('customer_access_denied',!Petish_Delivery_Cost_Sheet_Admin::authorized()
            &&petish_cost_sheet_verify_denied(static fn()=>Petish_Delivery_Cost_Sheet_Admin::render())
            &&petish_cost_sheet_verify_denied(static fn()=>Petish_Delivery_Cost_Sheet_Admin::download()));
        petish_cost_sheet_verify_principal('shop_manager',['read'=>true,'manage_woocommerce'=>true]);
        petish_cost_sheet_verify_mark('manager_access_verified',Petish_Delivery_Cost_Sheet_Admin::authorized()===true
            &&current_user_can('manage_woocommerce')&&!current_user_can('manage_options'));
        Petish_Delivery_Cost_Sheet_Admin::menu();
        $menu=false;
        foreach($GLOBALS['submenu']['woocommerce']??[]as$item)
            if(($item[2]??null)===Petish_Delivery_Cost_Sheet_Admin::SLUG&&($item[1]??null)==='manage_woocommerce')$menu=true;
        petish_cost_sheet_verify_mark('admin_hooks_registered',$menu
            &&has_action('admin_menu',['Petish_Delivery_Cost_Sheet_Admin','menu'])!==false
            &&has_action('admin_post_'.Petish_Delivery_Cost_Sheet_Admin::ACTION,['Petish_Delivery_Cost_Sheet_Admin','download'])!==false
            &&has_action('admin_post_nopriv_'.Petish_Delivery_Cost_Sheet_Admin::ACTION)===false);
        $_GET['_wpnonce']='not-a-valid-nonce';
        petish_cost_sheet_verify_mark('invalid_nonce_rejected',!Petish_Delivery_Cost_Sheet_Admin::authorized($_GET['_wpnonce'])
            &&!Petish_Delivery_Cost_Sheet_Admin::authorized([])
            &&petish_cost_sheet_verify_denied(static fn()=>Petish_Delivery_Cost_Sheet_Admin::download()));
        $nonce=wp_create_nonce(Petish_Delivery_Cost_Sheet_Admin::NONCE);
        petish_cost_sheet_verify_mark('valid_nonce_accepted',is_string($nonce)&&$nonce!==''
            &&Petish_Delivery_Cost_Sheet_Admin::authorized($nonce)===true);
        $_GET=[];

        // Same bounded catalogue selection and identity adapter as the download endpoint.
        $query=new WP_Query(['post_type'=>['product','product_variation'],'post_status'=>'publish',
            'posts_per_page'=>Petish_Delivery_Cost_Sheet::MAX_ROWS,'paged'=>1,'orderby'=>'ID','order'=>'ASC',
            'fields'=>'ids','no_found_rows'=>true,'update_post_term_cache'=>false]);
        $rows=[];$identities=[];
        foreach($query->posts as$id){
            $identity=petish_cost_sheet_verify_private('identity',wc_get_product($id));
            if(!$identity)continue;
            $rows[]=array_intersect_key($identity,array_flip(Petish_Delivery_Cost_Sheet::COLUMNS));
            $identities[(int)$id]=$identity;
        }
        if(!$rows)petish_cost_sheet_verify_fail();
        $decoded=Petish_Delivery_Cost_Sheet::parse(Petish_Delivery_Cost_Sheet::csv($rows));
        $catalogue=petish_cost_sheet_verify_private('catalogue',$decoded);
        $template=count($decoded)===count($rows)&&$catalogue===$identities;
        foreach($decoded as$i=>$row)foreach(Petish_Delivery_Cost_Sheet::COLUMNS as$column)
            $template=$template&&$row[$column]===Petish_Delivery_Cost_Sheet::csv_cell($rows[$i][$column]??'');
        $countries=petish_cost_sheet_verify_private('countries');$currencies=get_woocommerce_currencies();
        $template=$template&&isset($countries['AU']['states']['VIC'],$currencies['AUD'],$currencies['USD'])
            &&WC_Validation::is_postcode('3083','AU');
        [$scenario]=petish_cost_sheet_verify_fixture(time());
        $actual=array_replace($scenario,$decoded[0],array_diff_key($scenario,$rows[0]),['quantity'=>'1']);
        $actual_result=Petish_Delivery_Cost_Sheet::preview([$actual],$catalogue,$countries,$currencies)[0]??[];
        petish_cost_sheet_verify_mark('catalogue_template_verified',$template&&($actual_result['status']??null)==='complete');
        $results=petish_cost_sheet_verify_calculations(time());

        $report['category']='renderer';
        $html=petish_cost_sheet_verify_capture(static fn()=>Petish_Delivery_Cost_Sheet_Admin::render());
        $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
        $form=$xpath->query('//form[@method="post" and @enctype="multipart/form-data"]');
        $download=$xpath->query('//form[@method="get"]//input[@name="action" and @value="'.Petish_Delivery_Cost_Sheet_Admin::ACTION.'"]');
        $nonce_fields=$xpath->query('//form//input[@name="_wpnonce"]');
        $rendered=$form->length===1&&$download->length===1&&$nonce_fields->length===2
            &&$xpath->query('//input[@type="file" and @name="petish_cost_csv"]')->length===1
            &&str_contains($html,'Live delivery settings are unchanged.');
        foreach($nonce_fields as$field)$rendered=$rendered&&Petish_Delivery_Cost_Sheet_Admin::authorized($field->getAttribute('value'))===true;
        $_SERVER['REQUEST_METHOD']='POST';$_POST=['_wpnonce'=>'not-a-valid-nonce'];
        $invalid_html=petish_cost_sheet_verify_capture(static fn()=>Petish_Delivery_Cost_Sheet_Admin::render());
        $rendered=$rendered&&str_contains($invalid_html,'Your form expired or you do not have access.')
            &&!str_contains($invalid_html,'<h2>Cost preview</h2>');
        $results[0]['input']['service']='<script>private-fixture</script>';
        $preview_html=petish_cost_sheet_verify_capture(static fn()=>petish_cost_sheet_verify_private('results',$results));
        $rendered=$rendered&&str_contains($preview_html,'Cost preview')&&str_contains($preview_html,'A$17.33')
            &&str_contains($preview_html,'49.51%')&&!str_contains($preview_html,'<script>')
            &&str_contains($preview_html,'&lt;script&gt;private-fixture&lt;/script&gt;');
        petish_cost_sheet_verify_mark('estimate_not_verified_rate',($results[0]['input']['evidence_kind']??null)==='estimate'
            &&str_contains($preview_html,'Estimate only')&&str_contains($preview_html,'Preview only — not activated')
            &&!array_key_exists('verified',$results[0])&&!array_key_exists('activated',$results[0]));
        petish_cost_sheet_verify_mark('admin_preview_rendered',$rendered);
    }finally{
        remove_filter('query','petish_cost_sheet_verify_query_guard',PHP_INT_MAX);
        remove_filter('wp_die_handler','petish_cost_sheet_verify_die_handler',PHP_INT_MAX);
        $GLOBALS['current_user']=$saved['user'];$_GET=$saved['get'];$_POST=$saved['post'];$_FILES=$saved['files'];
        $_SERVER['REQUEST_METHOD']=$saved['method'];
        foreach(['submenu'=>'submenu','pages'=>'_registered_pages','parents'=>'_parent_pages']as$key=>$global)
            if($saved[$key]===null)unset($GLOBALS[$global]);else$GLOBALS[$global]=$saved[$key];
    }
}

try {
    if(!in_array($argc,[2,3],true)||($argc===3&&!in_array($argv[2],['production','staging'],true))
        ||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[1])||ini_get('allow_url_fopen'))petish_cost_sheet_verify_fail();
    $target=$argc===3?$argv[2]:'production';$host=$target==='staging'?'staging.petish.biz':'petish.biz';
    $report['category']='paths';$home=getenv('HOME');
    if(!petish_cost_sheet_verify_path($home,true))petish_cost_sheet_verify_fail();
    $root=$home.'/domains/'.$host.'/public_html';
    foreach([$root,$root.'/wp-includes']as$path)if(!petish_cost_sheet_verify_path($path,true))petish_cost_sheet_verify_fail();
    foreach([$root.'/wp-config.php',$root.'/wp-load.php',$root.'/wp-includes/plugin.php',
        $root.'/wp-admin/includes/template.php',$root.'/wp-admin/includes/plugin.php',
        __DIR__.'/email-branding-preview-remote.php',
        $root.'/wp-content/plugins/petish-fulfilment/petish-fulfilment.php',
        $root.'/wp-content/plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet.php',
        $root.'/wp-content/plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet-admin.php']as$path)
        if(!petish_cost_sheet_verify_path($path))petish_cost_sheet_verify_fail();
    petish_cost_sheet_verify_mark('target_paths_verified',true);
    $report['category']='bootstrap';
    foreach(['WP_HTTP_BLOCK_EXTERNAL'=>true,'WP_ACCESSIBLE_HOSTS'=>'','DISABLE_WP_CRON'=>true,
        'AUTOMATIC_UPDATER_DISABLED'=>true,'DISALLOW_FILE_MODS'=>true,'WP_USE_THEMES'=>false,'WP_ADMIN'=>true]as$name=>$value){
        if(defined($name))petish_cost_sheet_verify_fail();define($name,$value);
    }
    $_SERVER['HTTP_HOST']=$host;$_SERVER['SERVER_NAME']=$host;$_SERVER['SERVER_PORT']='443';
    $_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/wp-admin/admin.php';
    $_SERVER['PHP_SELF']='/wp-admin/admin.php';$_SERVER['SCRIPT_NAME']='/wp-admin/admin.php';
    $_SERVER['SERVER_PROTOCOL']='HTTP/1.1';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    require_once$root.'/wp-includes/plugin.php';
    $block_http=static function(){return new WP_Error('petish_cost_sheet_verify_http_blocked','Cost-sheet verification blocks outbound HTTP.');};
    $block_mail=static function(){return true;};
    $stop_delivery=static function(){throw new \PHPMailer\PHPMailer\Exception('Cost-sheet verification blocks delivery.');};
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
    petish_cost_sheet_verify_mark('target_environment_verified',wp_get_environment_type()===$target
        &&parse_url(home_url('/'),PHP_URL_HOST)===$host&&parse_url(site_url('/'),PHP_URL_HOST)===$host
        &&($target==='staging'?(defined('PETISH_STAGING_GUARD_ACTIVE')&&PETISH_STAGING_GUARD_ACTIVE===true
            &&defined('WCPAY_DEV_MODE')&&WCPAY_DEV_MODE===true):(!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true)));
    petish_cost_sheet_verify_mark('woocommerce_runtime_verified',defined('WC_VERSION')&&WC_VERSION==='11.2.1'
        &&class_exists('WC_Product')&&class_exists('Petish_Supplier_Quotes'));
    $blocked=apply_filters('pre_http_request',false,['method'=>'POST'],'https://www.dsers.com/petish-cost-sheet-verification');
    petish_cost_sheet_verify_mark('all_outbound_http_blocked',is_wp_error($blocked)&&$blocked->get_error_code()==='petish_cost_sheet_verify_http_blocked');
    petish_cost_sheet_verify_mark('mail_calls_blocked',apply_filters('pre_wp_mail',null,[])===true);
    petish_cost_sheet_verify_mark('remote_file_access_disabled',!ini_get('allow_url_fopen'));
    petish_cost_sheet_verify_mark('cron_and_async_runner_disabled',defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON===true
        &&apply_filters('action_scheduler_allow_async_request_runner',true)===false
        &&apply_filters('action_scheduler_queue_runner_concurrent_batches',1)===0);
    require_once __DIR__.'/email-branding-preview-remote.php';
    $report['category']='snapshot';$before=petish_cost_sheet_verify_state();
    $report['category']='verification';
    petish_cost_sheet_verify_native($root);
    $report['category']='snapshot';
    $after=petish_cost_sheet_verify_state();
    petish_cost_sheet_verify_mark('orders_unchanged',$after['orders']===$before['orders']&&!$GLOBALS['petish_cost_sheet_write_attempted']);
    petish_cost_sheet_verify_mark('users_and_authentication_unchanged',$after['accounts']===$before['accounts']);
    petish_cost_sheet_verify_mark('product_prices_and_stock_unchanged',hash_equals($after['products'],$before['products']));
    petish_cost_sheet_verify_mark('shipping_policy_and_settings_unchanged',hash_equals($after['settings'],$before['settings']));
    if(array_diff(PETISH_COST_SHEET_VERIFY_CHECKS,array_keys($report['checks'])))petish_cost_sheet_verify_fail();
    $report['status']='success';$report['category']='complete';
}catch(Throwable$ignored){$report['status']='failure';}
exit($report['status']==='success'?0:1);
