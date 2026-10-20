<?php
/** Functions only: native WooCommerce previews using unsaved synthetic data. */

function petish_email_preview_fail() { throw new RuntimeException('Email preview validation failed.'); }

function petish_email_preview_css($css) {
    if (!is_string($css) || str_contains($css, '\\')) petish_email_preview_fail();
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    $css = preg_replace('~@import\b[^;]*(?:;|$)~is', '', $css);
    $css = preg_replace('~url\s*\([^)]*\)~is', 'none', $css);
    if (!is_string($css) || preg_match('~(?:url\s*\(|@import\b|expression\s*\(|behavior\s*:|-moz-binding|https?:|data:|//)~i', $css)) petish_email_preview_fail();
    return $css;
}

/** Final public-content gate; independent of the native HTML parser. */
function petish_email_preview_public_content($html) {
    if (!is_string($html)) petish_email_preview_fail();
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~(?:staging\.petish\.biz|/home/|\.petish-staging|wc_order_|_wpnonce|nonce=|PETISH_PREVIEW_NO_AUTH_TOKEN|<script\b|<iframe\b|<form\b|\son[a-z]+\s*=)~i', $decoded)) petish_email_preview_fail();
    $matched = preg_match_all('~[A-Z0-9.!#$%&\'*+/=?^_`{|}\~-]+@[A-Z0-9.-]+\.[A-Z]{2,63}~i', $decoded, $addresses);
    if ($matched === false) petish_email_preview_fail();
    foreach ($addresses[0] as $address) if (!in_array(strtolower($address), ['orders@petish.biz','alex@petish.invalid'],true)) petish_email_preview_fail();
    return $html;
}

/** Canonical browser wrapper for the sanitized copy, independent of mail clients. */
function petish_email_preview_document($html) {
    if (!is_string($html)) petish_email_preview_fail();
    $html = preg_replace('~<!doctype\b[^>]*>~is', '', $html);
    $html = preg_replace('~<meta\b[^>]*>~is', '', $html);
    $head = '<meta charset="utf-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
        . '<meta name="format-detection" content="telephone=no">';
    $html = preg_replace('~(<head\b[^>]*>)~i', '$1' . "\n" . $head, $html, -1, $count);
    if (!is_string($html) || $count!==1) petish_email_preview_fail();
    return '<!doctype html>' . "\n" . ltrim($html);
}

/** Finish the approved root-relative sentinel after WordPress URL escaping. */
function petish_email_preview_local_images($html,$expected) {
    if (!is_string($html) || !is_int($expected) || $expected<1) petish_email_preview_fail();
    $html = str_replace('src="/petish-logo-v2.png"','src="petish-logo-v2.png"',$html,$count);
    if ($count!==$expected) petish_email_preview_fail();
    return $html;
}

/** Sanitize the shareable copy; real email templates are never rewritten here. */
function petish_email_preview_sanitize($html) {
    if (!is_string($html) || strlen($html) > 1048576 || !class_exists('WP_HTML_Tag_Processor')
        || !function_exists('wp_kses')) petish_email_preview_fail();
    $html = preg_replace('~<!--.*?-->~s', '', $html);
    // Email structured data is not useful in an openly shareable browser preview.
    $html = preg_replace('~<script\b[^>]*>.*?</script\s*>~is', '', $html);
    $html = preg_replace('~<!doctype\b[^>]*>~is', '', $html);
    $html = preg_replace_callback('~<style\b[^>]*>(.*?)</style\s*>~is', static function($match) {
        return '<style>' . petish_email_preview_css($match[1]) . '</style>';
    }, $html);
    $global = array_fill_keys(['class','id','style','align','valign','width','height','border','cellpadding',
        'cellspacing','bgcolor','role','dir','lang','colspan','rowspan','scope','aria-label','aria-hidden'], true);
    $allowed = [];
    foreach (['html','head','body','title','style','table','tbody','thead','tfoot','tr','td','th','p','div',
        'span','h1','h2','h3','h4','h5','h6','small','strong','b','i','em','br','hr','a','img','ul','ol',
        'li','blockquote','address','del','ins','center','sup','sub'] as $tag) $allowed[$tag] = $global;
    $allowed['a']['href'] = true;
    $allowed['img'] += ['src'=>true,'alt'=>true];
    $allowed['meta'] = ['charset'=>true,'name'=>true,'content'=>true];
    $html = wp_kses($html, $allowed, ['https','mailto']);
    $processor = new WP_HTML_Tag_Processor($html);
    $images = 0;
    while ($processor->next_tag()) {
        $tag = $processor->get_tag();
        if ($processor->get_attribute('style') !== null) {
            $processor->set_attribute('style', petish_email_preview_css((string)$processor->get_attribute('style')));
        }
        if ($tag === 'A') {
            $href = (string)$processor->get_attribute('href');
            if (strtolower($href) === 'mailto:orders@petish.biz') {
                $processor->set_attribute('href','mailto:orders@petish.biz');
            } else {
                $parsed = parse_url($href);
                $path = is_array($parsed) ? ($parsed['path'] ?? '/') : '/my-account/';
                $public = ['/', '/shop/', '/my-account/', '/delivery-shipping/', '/contact/', '/privacy-policy/', '/terms-conditions/'];
                $safe = is_array($parsed) && ($parsed['scheme'] ?? '') === 'https'
                    && in_array($parsed['host'] ?? '', ['petish.biz','staging.petish.biz'], true)
                    && !isset($parsed['user']) && !isset($parsed['pass']) && in_array($path,$public,true);
                $processor->set_attribute('href','https://petish.biz' . ($safe ? $path : '/my-account/'));
            }
        }
        if ($tag === 'IMG') {
            $src = (string)$processor->get_attribute('src');
            $parsed = parse_url($src);
            $expected = '/wp-content/themes/dt-the7-child/assets/brand/repaired/combined-light-v2.png';
            if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https'
                || !in_array($parsed['host'] ?? '', ['petish.biz','staging.petish.biz'],true)
                || ($parsed['path'] ?? '') !== $expected || isset($parsed['query']) || isset($parsed['fragment'])
                || isset($parsed['user']) || isset($parsed['pass'])) petish_email_preview_fail();
            // esc_url() adds http:// to a bare filename; a leading slash survives.
            $processor->set_attribute('src','/petish-logo-v2.png');
            ++$images;
        }
    }
    $html = petish_email_preview_local_images($processor->get_updated_html(),$images);
    return petish_email_preview_public_content(petish_email_preview_document($html));
}

function petish_email_preview_write($work,$name,$html) {
    if (!in_array($name,['processing.html','completed.html','refunded.html','note.html',
        'new-account.html','verify-email.html','reset-password.html'],true)
        || realpath($work) !== $work || is_link($work) || !is_dir($work) || (fileperms($work)&07777)!==0700) petish_email_preview_fail();
    $path = $work . '/' . $name;
    if (file_exists($path) || is_link($path)) petish_email_preview_fail();
    $file = fopen($path,'xb');
    if (!$file) petish_email_preview_fail();
    try {
        if (!chmod($path,0600) || fwrite($file,$html)!==strlen($html) || !fflush($file)) petish_email_preview_fail();
    } finally { fclose($file); }
}

/** Build a complete display order entirely in memory. No save/trigger/send. */
function petish_email_preview_order() {
    if (!class_exists('Petish_Synthetic_Email_Order',false)) {
        class Petish_Synthetic_Email_Order extends WC_Order {
            public $preview_refunded = false;
            public function __construct() {
                parent::__construct();
                // Prevent the lazy data store from reading orphan order_id=0 items.
                foreach (array_keys($this->items) as $type) $this->items[$type] = [];
            }
            public function get_order_number() { return 'P-1042'; }
            public function get_view_order_url() { return 'https://petish.biz/my-account/'; }
            public function get_refunds() { return []; }
            public function get_total_refunded() { return $this->preview_refunded ? 14.60 : 0.0; }
        }
    }
    $order = new Petish_Synthetic_Email_Order();
    $order->set_currency('AUD');
    $order->set_prices_include_tax(true);
    $order->set_status('processing');
    $order->set_date_created('2026-10-20T12:00:00+11:00');
    $order->set_date_paid('2026-10-20T12:02:00+11:00');
    $order->set_payment_method('woocommerce_payments');
    $order->set_payment_method_title('Card payment');
    $address = ['first_name'=>'Alex','last_name'=>'Taylor','company'=>'','address_1'=>'12 Example Lane',
        'address_2'=>'','city'=>'Bundoora','state'=>'VIC','postcode'=>'3083','country'=>'AU'];
    $order->set_address($address,'shipping');
    $order->set_address($address + ['email'=>'alex@petish.invalid','phone'=>'0400000000'],'billing');
    $item = new WC_Order_Item_Product();
    $item->set_name('Corner & Gap Cleaning Brush');
    $item->set_quantity(1);
    $item->set_subtotal('7.27'); $item->set_total('7.27');
    $item->set_taxes(['total'=>[0=>'0.73'],'subtotal'=>[0=>'0.73']]);
    $item->add_meta_data('Colour','Blue',true);
    $order->add_item($item);
    $shipping = new WC_Order_Item_Shipping();
    $shipping->set_method_title('Standard delivery — sample');
    $shipping->set_method_id('flat_rate');
    $shipping->set_total('6.00');
    $shipping->set_taxes(['total'=>[0=>'0.60']]);
    $order->add_item($shipping);
    $tax = new WC_Order_Item_Tax();
    $tax->set_label('GST'); $tax->set_rate_id(0); $tax->set_rate_percent(10);
    $tax->set_tax_total('0.73'); $tax->set_shipping_tax_total('0.60');
    $order->add_item($tax);
    $order->set_shipping_total('6.00');
    $order->set_cart_tax('0.73'); $order->set_shipping_tax('0.60'); $order->set_total('14.60');
    if ((int)$order->get_id() !== 0 || $order->get_customer_id() || $order->get_order_key()) petish_email_preview_fail();
    return $order;
}

function petish_email_preview_order_counts() {
    global $wpdb;
    if (!is_object($wpdb) || !preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->prefix)) petish_email_preview_fail();
    $posts = $wpdb->prefix.'posts'; $orders = $wpdb->prefix.'wc_orders';
    $counts = ['posts'=>$wpdb->get_var("SELECT COUNT(*) FROM `{$posts}` WHERE post_type IN ('shop_order','shop_order_refund')")];
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($orders)));
    if ($exists === $orders) $counts['hpos'] = $wpdb->get_var("SELECT COUNT(*) FROM `{$orders}`");
    foreach ($counts as $count) if (!is_numeric($count)) petish_email_preview_fail();
    return $counts;
}

/** Synthetic account data follows WooCommerce's own unsaved EmailPreview contract. */
function petish_email_preview_user() {
    $user = new WP_User(0);
    $user->data = (object)['ID'=>0,'user_login'=>'alex','user_email'=>'alex@petish.invalid',
        'display_name'=>'Alex Taylor','first_name'=>'Alex','last_name'=>'Taylor',
        'user_pass'=>'','user_activation_key'=>''];
    if ((int)$user->ID!==0 || $user->exists()) petish_email_preview_fail();
    return $user;
}

/** Populate only display fields. No account creation or authentication generators. */
function petish_email_preview_account_email($native,$user) {
    if (!$user instanceof WP_User || (int)$user->ID!==0 || $user->exists()) petish_email_preview_fail();
    $email = clone $native;
    if (!in_array($email->id??'', ['customer_new_account','customer_verify_email','customer_reset_password'],true)
        || !is_callable([$email,'get_content_html']) || !is_callable([$email,'style_inline'])) petish_email_preview_fail();
    $email->object = clone $user;
    $email->recipient = 'alex@petish.invalid';
    $email->user_login = 'alex';
    $email->user_email = 'alex@petish.invalid';
    $email->user_display_name = 'Alex';
    $email->placeholders['{site_title}'] = 'Petish';
    $email->settings['heading'] = $email->get_default_heading();
    if ($email->id==='customer_new_account') {
        $email->user_pass = '';
        $email->password_generated = true;
        $email->set_password_url = 'https://petish.biz/my-account/';
    } elseif ($email->id==='customer_reset_password') {
        $email->user_id = 0;
        // The native template requires a key argument. This fixed marker is not
        // generated, stored or usable; the public-copy link sanitizer removes it.
        $email->reset_key = 'PETISH_PREVIEW_NO_AUTH_TOKEN';
    } else {
        $email->verify_url = 'https://petish.biz/my-account/';
    }
    return $email;
}

/** Read only SQL digests: raw credentials and tokens never enter the report. */
function petish_email_preview_account_state() {
    global $wpdb;
    foreach (['users','usermeta'] as $table) if (!isset($wpdb->$table)
        || !preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->$table)) petish_email_preview_fail();
    $users = $wpdb->users; $meta = $wpdb->usermeta;
    $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$users}`");
    $keys = $wpdb->get_results("SELECT ID, SHA2(user_activation_key,256) AS token_state FROM `{$users}` ORDER BY ID",ARRAY_A);
    $query = $wpdb->prepare("SELECT umeta_id,user_id,meta_key,SHA2(meta_value,256) AS token_state FROM `{$meta}`"
        . " WHERE meta_key IN ('session_tokens','default_password_nag') OR meta_key LIKE %s OR meta_key LIKE %s ORDER BY umeta_id",
        $wpdb->esc_like('_wc_email_verification_key').'%', $wpdb->esc_like('_wc_email_verified').'%');
    $metadata = $wpdb->get_results($query,ARRAY_A);
    if (!is_numeric($count) || !is_array($keys) || !is_array($metadata) || (int)$count!==count($keys)) petish_email_preview_fail();
    foreach (array_merge($keys,$metadata) as $row) if (!is_array($row)
        || !preg_match('/\A[0-9a-f]{64}\z/Di',$row['token_state']??'')) petish_email_preview_fail();
    return ['users'=>(int)$count,'auth_state'=>hash('sha256',json_encode([$keys,$metadata],JSON_THROW_ON_ERROR))];
}

/** Exercise the deployed core notification filters without dispatch or token creation. */
function petish_email_preview_core_account_branding($user) {
    $url = 'https://petish.invalid/account-preview/?action=rp&key=PETISH_PREVIEW_NO_AUTH_TOKEN&login=alex';
    $mail = ['to'=>'alex@petish.invalid','subject'=>'[Petish] Sample account message',
        'message'=>"Hi Alex,\n\nYour Petish sample account details.\n".$url,'headers'=>''];
    $results = [
        apply_filters('wp_new_user_notification_email',$mail,$user,'Petish'),
        apply_filters('retrieve_password_notification_email',$mail,'PETISH_PREVIEW_NO_AUTH_TOKEN','alex',$user),
    ];
    foreach ($results as $result) {
        if (!is_array($result) || ($result['to']??null)!==$mail['to'] || ($result['subject']??null)!==$mail['subject']
            || !is_string($result['message']??null)) return false;
        $body = html_entity_decode($result['message'],ENT_QUOTES | ENT_HTML5,'UTF-8');
        $headers = $result['headers']??'';
        if (is_array($headers)) $headers = implode("\n",$headers);
        $links = new WP_HTML_Tag_Processor($result['message']);
        $native_link = false;
        while ($links->next_tag(['tag_name'=>'A'])) if ($links->get_attribute('href')===$url) $native_link = true;
        if (!is_string($headers) || stripos($headers,'Content-Type: text/html; charset=UTF-8')===false
            || !$native_link || !str_contains($body,$url) || !str_contains($body,'Your Petish sample account details.')
            || !str_contains($body,'<html') || !str_contains($body,'<table')
            || !str_contains($body,'petish-core-account-message')
            || !str_contains($body,'/assets/brand/repaired/combined-light-v2.png')
            || stripos($body,'#6532a1')===false || stripos($body,'#ede7ff')===false) return false;
    }
    return true;
}

/** Caller has already verified the fixed staging paths, DB and guard runtime. */
function petish_email_preview_render($context) {
    $checks = array_fill_keys(['email_preview_rendered','email_preview_native_renderer',
        'email_preview_unsaved_synthetic_order','email_preview_synthetic_content_only',
        'email_preview_no_order_records_created','email_preview_stock_unchanged',
        'email_preview_html_sanitized','email_preview_branding_logo_present','email_preview_branding_palette_present',
        'email_preview_native_order_details_present','email_preview_four_types_rendered',
        'email_preview_account_types_rendered','email_preview_no_user_records_created',
        'email_preview_no_auth_tokens_generated','email_preview_verification_available',
        'email_preview_seven_types_rendered','email_preview_core_account_branding','email_preview_verification_enabled',
        'email_preview_guest_checkout_enabled'],false);
    $mark = static function($name,$value) use (&$checks,$context) {
        if (!array_key_exists($name,$checks) || !is_bool($value)) petish_email_preview_fail();
        $checks[$name] = $value;
        if (isset($context['mark']) && is_callable($context['mark'])) ($context['mark'])($name,$value);
    };
    if (!is_array($context) || wp_get_environment_type()!=='staging'
        || !defined('PETISH_STAGING_GUARD_ACTIVE') || PETISH_STAGING_GUARD_ACTIVE!==true
        || !defined('WCPAY_DEV_MODE') || WCPAY_DEV_MODE!==true
        || !is_string($context['root']??null) || realpath(ABSPATH)!==$context['root']
        || parse_url(home_url(),PHP_URL_HOST)!=='staging.petish.biz'
        || !is_string($context['work']??null) || realpath($context['work'])!==$context['work']
        || (fileperms($context['work'])&07777)!==0700 || !function_exists('WC')) petish_email_preview_fail();
    foreach (['WC_Order','WC_Order_Item_Product','WC_Order_Item_Shipping','WC_Order_Item_Tax','WC_Order_Refund','WP_User','WP_HTML_Tag_Processor'] as $class) if (!class_exists($class)) petish_email_preview_fail();
    $counts = petish_email_preview_order_counts();
    $account_state = petish_email_preview_account_state();
    $settings = get_option('woocommerce_customer_verify_email_settings',[]);
    if (!is_array($settings)) petish_email_preview_fail();
    $mark('email_preview_verification_enabled',($settings['enabled']??'yes')==='yes');
    $mark('email_preview_guest_checkout_enabled',wc_string_to_bool(get_option('woocommerce_enable_guest_checkout')));
    $product = wc_get_product(246);
    if (!$product || !$product->managing_stock()) petish_email_preview_fail();
    $stock = $product->get_stock_quantity();
    $order = petish_email_preview_order();
    $user = petish_email_preview_user();
    $mark('email_preview_unsaved_synthetic_order',(int)$order->get_id()===0);
    $footer = static function() { return '© Petish.'; };
    $additional = static function() { return 'Thank you for choosing Petish.'; };
    // The fictional item has no product photo. Use the native no-image option
    // so its preview does not reserve a blank thumbnail column.
    $item_args = static function($args) { $args['show_image']=false; return $args; };
    $html_type = static function() { return 'text/html'; };
    $site_title = static function() { return 'Petish'; };
    $account_url = static function() { return 'https://petish.biz/my-account/'; };
    add_filter('pre_option_blogname',$site_title,PHP_INT_MAX);
    add_filter('pre_option_woocommerce_email_footer_text',$footer,PHP_INT_MAX);
    add_filter('woocommerce_email_footer_text',$footer,PHP_INT_MAX);
    add_filter('woocommerce_email_content_type',$html_type,PHP_INT_MAX);
    add_filter('woocommerce_email_order_items_args',$item_args,PHP_INT_MAX);
    add_filter('woocommerce_get_myaccount_page_permalink',$account_url,PHP_INT_MAX);
    $definitions = [
        'customer_processing_order'=>['processing.html','processing'],
        'customer_completed_order'=>['completed.html','completed'],
        'customer_refunded_order'=>['refunded.html','refunded'],
        'customer_note'=>['note.html','processing'],
    ];
    $account_definitions = ['customer_new_account'=>'new-account.html',
        'customer_verify_email'=>'verify-email.html','customer_reset_password'=>'reset-password.html'];
    $all_ids = array_merge(array_keys($definitions),array_keys($account_definitions));
    foreach ($all_ids as $id) add_filter('woocommerce_email_additional_content_'.$id,$additional,PHP_INT_MAX);
    try {
        $emails = WC()->mailer()->get_emails();
        $found = [];
        foreach ($emails as $native) if (is_object($native) && in_array($native->id??'',$all_ids,true)) $found[$native->id] = $native;
        $verify_class = 'Automattic\\WooCommerce\\Internal\\CustomerEmailVerification\\Emails\\CustomerVerifyEmail';
        $available = isset($found['customer_verify_email']) && is_a($found['customer_verify_email'],$verify_class);
        $mark('email_preview_verification_available',$available);
        if (count($found)!==7 || !$available) petish_email_preview_fail();
        foreach ($definitions as $id=>[$name,$status]) {
            $email = clone $found[$id];
            if (!is_callable([$email,'get_content_html']) || !is_callable([$email,'style_inline'])) petish_email_preview_fail();
            $sample = clone $order; $sample->set_status($status);
            if ($status==='completed') $sample->set_date_completed('2026-10-21T10:00:00+11:00');
            $sample->preview_refunded = $status==='refunded';
            $email->object = $sample;
            $email->recipient = 'alex@petish.invalid';
            $email->placeholders['{site_title}'] = 'Petish';
            $email->placeholders['{order_number}'] = 'P-1042';
            $email->placeholders['{order_date}'] = '20 October 2026';
            $default_heading = $email->get_default_heading();
            $email->settings['heading'] = $default_heading;
            $email->settings['heading_full'] = $default_heading;
            if ($id==='customer_refunded_order') {
                $email->partial_refund = false;
                $refund = new WC_Order_Refund(); $refund->set_amount('14.60');
                $refund->set_reason('Full refund for this sample preview.'); $email->refund = $refund;
                if ((int)$refund->get_id()!==0) petish_email_preview_fail();
            }
            if ($id==='customer_note') $email->customer_note = 'Thanks for getting in touch. This is a sample order note for your Petish email preview. No shipment or live tracking is represented.';
            $raw = $email->style_inline($email->get_content_html());
            $mark('email_preview_native_renderer',true);
            $safe = petish_email_preview_sanitize($raw);
            $logo = str_contains($safe,'petish-logo-v2.png');
            $palette = stripos($safe,'#6532a1')!==false && stripos($safe,'#ede7ff')!==false;
            $text = html_entity_decode(strip_tags($safe),ENT_QUOTES | ENT_HTML5,'UTF-8');
            $details = str_contains($text,'P-1042') && str_contains($text,'Corner & Gap Cleaning Brush')
                && str_contains($text,'Alex') && (str_contains($text,'14.60') || str_contains($text,'14,60'));
            $mark('email_preview_branding_logo_present',$logo);
            $mark('email_preview_branding_palette_present',$palette);
            $mark('email_preview_native_order_details_present',$details);
            if (!$logo || !$palette || !$details) petish_email_preview_fail();
            petish_email_preview_write($context['work'],$name,$safe);
        }
        $mark('email_preview_four_types_rendered',true);
        foreach ($account_definitions as $id=>$name) {
            $email = petish_email_preview_account_email($found[$id],$user);
            $raw = $email->style_inline($email->get_content_html());
            $safe = petish_email_preview_sanitize($raw);
            $logo = str_contains($safe,'petish-logo-v2.png');
            $palette = stripos($safe,'#6532a1')!==false && stripos($safe,'#ede7ff')!==false;
            $text = html_entity_decode(strip_tags($safe),ENT_QUOTES | ENT_HTML5,'UTF-8');
            if (!$logo || !$palette || !str_contains($text,'Alex') || !str_contains($text,'Petish')
                || ($id==='customer_verify_email' && !str_contains($text,'alex@petish.invalid'))
                || (int)$email->object->ID!==0 || $email->object->exists()) petish_email_preview_fail();
            petish_email_preview_write($context['work'],$name,$safe);
        }
        $mark('email_preview_account_types_rendered',true);
        $mark('email_preview_seven_types_rendered',true);
        $mark('email_preview_core_account_branding',petish_email_preview_core_account_branding($user));
    } finally {
        remove_filter('pre_option_blogname',$site_title,PHP_INT_MAX);
        remove_filter('pre_option_woocommerce_email_footer_text',$footer,PHP_INT_MAX);
        remove_filter('woocommerce_email_footer_text',$footer,PHP_INT_MAX);
        remove_filter('woocommerce_email_content_type',$html_type,PHP_INT_MAX);
        remove_filter('woocommerce_email_order_items_args',$item_args,PHP_INT_MAX);
        remove_filter('woocommerce_get_myaccount_page_permalink',$account_url,PHP_INT_MAX);
        foreach ($all_ids as $id) remove_filter('woocommerce_email_additional_content_'.$id,$additional,PHP_INT_MAX);
    }
    $mark('email_preview_html_sanitized',true);
    $mark('email_preview_synthetic_content_only',true);
    $mark('email_preview_no_order_records_created',petish_email_preview_order_counts()===$counts);
    $mark('email_preview_stock_unchanged',wc_get_product(246)->get_stock_quantity()===$stock);
    $after_accounts = petish_email_preview_account_state();
    $mark('email_preview_no_user_records_created',$after_accounts['users']===$account_state['users']);
    $mark('email_preview_no_auth_tokens_generated',hash_equals($after_accounts['auth_state'],$account_state['auth_state']));
    $descriptive = ['email_preview_verification_enabled','email_preview_guest_checkout_enabled'];
    foreach ($checks as $name=>$value) if ($name!=='email_preview_rendered' && !in_array($name,$descriptive,true) && $value!==true) petish_email_preview_fail();
    $mark('email_preview_rendered',true);
    return $checks;
}
