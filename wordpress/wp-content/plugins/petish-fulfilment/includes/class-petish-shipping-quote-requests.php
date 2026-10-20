<?php
/** Private, customer-owned delivery requests. No orders, payments or mail are created. */
defined('ABSPATH') || exit;

final class Petish_Shipping_Quote_Requests {
    const POST_TYPE = 'petish_ship_quote';
    const META = '_petish_shipping_request';
    const ENDPOINT = 'shipping-quotes';
    const MAX_AGE = 604800;
    private static $errors = array();
    private static $values = array();

    public static function automatic_mode() { return 'automatic' === get_option('petish_shipping_mode', 'quote'); }

    public static function init() {
        add_action('init', array(__CLASS__, 'register'));
        add_shortcode('petish_shipping_quote', array(__CLASS__, 'shortcode'));
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'account_menu'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'account_view'));
        add_action('template_redirect', array(__CLASS__, 'submit_request'), 15);
        add_action('template_redirect', array(__CLASS__, 'cache_guard'), 0);
        add_filter('woocommerce_login_redirect', array(__CLASS__, 'login_redirect'), 20, 2);
        add_filter('woocommerce_get_query_vars', array(__CLASS__, 'query_vars'));
        add_action('add_meta_boxes_' . self::POST_TYPE, array(__CLASS__, 'admin_boxes'));
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'admin_save'), 10, 3);
        add_filter('wp_insert_post_data', array(__CLASS__, 'force_private'), 10, 2);
        add_filter('render_block', array(__CLASS__, 'block_cta'), 20, 2);
        add_action('woocommerce_after_cart_totals', array(__CLASS__, 'classic_cta'));
        add_action('woocommerce_review_order_after_shipping', array(__CLASS__, 'classic_checkout_cta'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function register() {
        register_post_type(self::POST_TYPE, array(
            'label' => 'Delivery quote requests', 'public' => false, 'publicly_queryable' => false,
            'exclude_from_search' => true, 'show_in_rest' => false, 'query_var' => false, 'rewrite' => false,
            'show_ui' => true, 'show_in_menu' => 'woocommerce', 'supports' => array('title'),
            'map_meta_cap' => false,
            'capabilities' => array('edit_post'=>'manage_woocommerce','read_post'=>'manage_woocommerce','delete_post'=>'manage_woocommerce','edit_posts'=>'manage_woocommerce','edit_others_posts'=>'manage_woocommerce','publish_posts'=>'manage_woocommerce','read_private_posts'=>'manage_woocommerce','delete_posts'=>'manage_woocommerce','delete_private_posts'=>'manage_woocommerce','delete_published_posts'=>'manage_woocommerce','delete_others_posts'=>'manage_woocommerce','edit_private_posts'=>'manage_woocommerce','edit_published_posts'=>'manage_woocommerce','create_posts'=>'do_not_allow'),
        ));
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function force_private($data, $posted) {
        if (($data['post_type'] ?? '') === self::POST_TYPE && !in_array($data['post_status'], array('trash','auto-draft'), true)) $data['post_status'] = 'private';
        return $data;
    }

    public static function query_vars($vars) { $vars[self::ENDPOINT]=self::ENDPOINT; return $vars; }

    private static function error($code, $message) { return new WP_Error('petish_quote_' . $code, $message); }
    private static function currency_ok() { return !is_wp_error(Petish_Quote_Currency::context()); }
    private static function lower($value) { return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : wc_strtolower($value); }
    private static function clean($value) { return is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', wc_clean((string)$value))) : ''; }
    private static function utc_timestamp($text) {
        if (!is_string($text) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$text)) return false;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$text,new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d\TH:i:s\Z') === $text ? $date->getTimestamp() : false;
    }

    public static function normalize_destination($address) {
        $result = array();
        foreach (array('country','state','city','postcode','address_1','address_2') as $key) {
            $value = self::clean($address[$key] ?? ('address_1' === $key ? ($address['address'] ?? '') : ''));
            if (in_array($key, array('country','state'), true)) $value = wc_strtoupper($value);
            elseif ('postcode' === $key) $value = wc_normalize_postcode($value);
            else $value = self::lower($value);
            $result[$key] = $value;
        }
        return $result;
    }

    private static function stock_fingerprint($product) {
        $manager = wc_get_product($product->get_stock_managed_by_id());
        // Available quantities are checked afresh; unrelated sales need not void freight.
        return hash('sha256', wp_json_encode(array($product->get_stock_status('edit'), $product->get_backorders('edit'), $product->get_manage_stock('edit'), $manager ? $manager->get_id() : 0, $manager ? $manager->get_stock_status('edit') : null)));
    }

    /** Canonical server cart identity; prices and client-supplied owner/amount are excluded. */
    public static function snapshot_lines($contents, $require_public = true) {
        if (!is_array($contents) || !$contents) return self::error('cart', 'Your cart is empty.');
        if (count($contents) > 100) return self::error('cart_size', 'Please request delivery for a cart of at most 100 different items.');
        $groups = array();
        foreach ($contents as $line) {
            $product = is_array($line) ? ($line['data'] ?? null) : null;
            if (!$product instanceof WC_Product || !in_array($product->get_type(), array('simple','variation'), true)) return self::error('product', 'One cart item cannot receive a delivery quote.');
            $quantity = $line['quantity'] ?? null;
            if ((!is_int($quantity) && !is_string($quantity)) || !preg_match('/^[1-9][0-9]{0,4}$/D', (string)$quantity) || (int)$quantity > 10000) return self::error('quantity', 'Check your cart quantities.');
            $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
            $parent_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $variation_id = $product->is_type('variation') ? $product->get_id() : 0;
            if (!$parent || (int)($line['product_id'] ?? 0) !== $parent_id || (int)($line['variation_id'] ?? 0) !== $variation_id) return self::error('identity', 'A cart item has changed. Please update your cart.');
            if ($require_public && ('publish' !== $parent->get_status() || 'publish' !== $product->get_status() || !$product->is_purchasable())) return self::error('unavailable', 'One cart item is not currently available to buy.');
            $fingerprint = Petish_Supplier_Quotes::fingerprint($product);
            if (is_wp_error($fingerprint) || !$product->get_sku('edit') || !$parent->get_sku('edit')) return self::error('supplier', 'One cart item is awaiting delivery information.');
            $key = $parent_id . ':' . $variation_id;
            $identity = array('product_id'=>$parent_id,'variation_id'=>$variation_id,'expected_sku'=>$product->get_sku('edit'),'expected_parent_sku'=>$parent->get_sku('edit'),'shipping_fingerprint'=>$fingerprint,'stock_fingerprint'=>self::stock_fingerprint($product),'shippable'=>$product->needs_shipping());
            if (isset($groups[$key]) && $groups[$key]['identity'] !== $identity) return self::error('identity', 'A cart item has changed.');
            if (!isset($groups[$key])) $groups[$key] = array('identity'=>$identity,'quantity'=>0,'product'=>$product);
            $groups[$key]['quantity'] += (int)$quantity;
        }
        ksort($groups, SORT_STRING);
        $result = array(); $physical = false;
        foreach ($groups as $group) {
            if ($group['quantity'] > 10000 || ($require_public && (!$group['product']->is_in_stock() || !$group['product']->has_enough_stock($group['quantity'])))) return self::error('stock', 'One cart item is no longer available in the requested quantity.');
            $result[] = array_merge($group['identity'], array('quantity'=>$group['quantity']));
            $physical = $physical || $group['identity']['shippable'];
        }
        return $physical ? $result : self::error('physical', 'Your cart has no items requiring delivery.');
    }

    private static function validate_address($address) {
        $country = wc_strtoupper(self::clean($address['country'] ?? ''));
        if (!WC()->countries || !isset(WC()->countries->get_shipping_countries()[$country])) return self::error('country', 'Choose an available delivery country.');
        $fields = WC()->countries->get_address_fields($country, 'quote_');
        foreach (array('country','state','city','postcode','address_1','address_2') as $key) {
            $value = self::clean($address[$key] ?? '');
            if (strlen($value) > 300) return self::error('address', 'Check the length of your delivery address.');
            if (!empty($fields['quote_'.$key]['required']) && '' === $value) return self::error('address', 'Complete all required delivery address fields.');
        }
        $states = WC()->countries->get_states($country);
        if (is_array($states) && $states && !isset($states[wc_strtoupper(self::clean($address['state'] ?? ''))])) return self::error('state', 'Choose a valid state or region.');
        if (!empty($address['postcode']) && !WC_Validation::is_postcode(self::clean($address['postcode']), $country)) return self::error('postcode', 'Enter a valid delivery postcode.');
        return self::normalize_destination($address);
    }

    /** Pure request preparation; persistence and authentication belong to the POST handler. */
    public static function create_request_record($contents, $address, $owner_id, $now = null) {
        if ((int)$owner_id < 1 || !self::currency_ok()) return self::error('account', 'Sign in and choose a supported checkout currency to request a quote.');
        $lines = self::snapshot_lines($contents, true);
        if (is_wp_error($lines)) return $lines;
        $destination = self::validate_address($address);
        if (is_wp_error($destination)) return $destination;
        return array('schema_version'=>1,'owner_id'=>(int)$owner_id,'status'=>'requested','currency'=>'AUD','requested_at_utc'=>gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),'lines'=>$lines,'destination'=>$destination,'approval'=>null);
    }

    public static function validate_approval_fields($fields, $record, $now = null) {
        $now = $now ?? time();
        if (!is_array($record) || ($record['schema_version'] ?? null) !== 1 || ($record['currency'] ?? '') !== 'AUD' || empty($record['lines']) || empty($record['destination']) || !self::currency_ok()) return self::error('record', 'The request is invalid.');
        $amount = is_string($fields['customer_shipping_ex_tax_aud'] ?? null) ? trim($fields['customer_shipping_ex_tax_aud']) : '';
        if (!preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,6})?$/D', $amount)) return self::error('amount', 'Enter a non-negative AUD delivery charge excluding tax.');
        $result = array('customer_shipping_ex_tax_aud'=>rtrim(rtrim(sprintf('%.6F',(float)$amount),'0'),'.'),'approved_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now));
        foreach (array('carrier','reference','delivery_window') as $key) {
            $value = self::clean($fields[$key] ?? '');
            if ('' === $value || strlen($value) > 500) return self::error('evidence', 'Complete carrier, private freight evidence/reference and estimated delivery window.');
            if ('reference' !== $key && preg_match('~(?:https?://|www\.)~i',$value)) return self::error('evidence','Put links in the private evidence field; carrier and delivery window are shown to the customer.');
            $result[$key] = $value;
        }
        $notes = self::clean($fields['customer_notes'] ?? '');
        if (strlen($notes) > 1000) return self::error('notes', 'Customer delivery notes must be at most 1,000 characters.');
        if (preg_match('~(?:https?://|www\.)~i', $notes)) return self::error('notes', 'Keep supplier links and URLs in the private evidence field, not customer notes.');
        $result['customer_notes'] = $notes;
        $text = is_string($fields['expires_utc'] ?? null) ? trim($fields['expires_utc']) : '';
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',$text)) return self::error('expiry', 'Use an exact UTC expiry: YYYY-MM-DDTHH:MM:SSZ.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$text,new DateTimeZone('UTC'));
        $expiry = $date ? $date->getTimestamp() : 0;
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $text || $expiry <= $now || $expiry > $now+self::MAX_AGE) return self::error('expiry', 'Expiry must be in the future and no more than seven days from approval.');
        $result['expires_utc'] = $text;
        return $result;
    }

    /** Pure owner/cart/address/expiry matcher for independent, non-persistent tests. */
    public static function rate_from_record($record, $owner_id, $lines, $destination, $now = null) {
        $now = $now ?? time();
        if (!is_array($record) || ($record['schema_version'] ?? null) !== 1 || ($record['status'] ?? '') !== 'approved' || (int)($record['owner_id'] ?? 0) !== (int)$owner_id || (int)$owner_id < 1 || ($record['currency'] ?? '') !== 'AUD' || !self::currency_ok()) return self::error('owner', 'No approved quote matches this account.');
        if (!is_array($lines) || !$lines || $record['lines'] !== $lines || $record['destination'] !== self::normalize_destination($destination)) return self::error('changed', 'Your cart or delivery address has changed.');
        $approval = $record['approval'] ?? null;
        if (!is_array($approval)) return self::error('approval', 'The delivery quote is not approved.');
        $approved_at = self::utc_timestamp($approval['approved_at_utc'] ?? '');
        $expiry = self::utc_timestamp($approval['expires_utc'] ?? '');
        if (!$approved_at || !$expiry || $approved_at > $now || $expiry <= $now || $expiry <= $approved_at || $expiry-$approved_at > self::MAX_AGE) return self::error('expired', 'Your delivery quote has expired.');
        $amount = $approval['customer_shipping_ex_tax_aud'] ?? null;
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,6})?$/D',$amount)) return self::error('amount', 'The approved delivery amount is invalid.');
        return array('cost'=>$amount,'quotes'=>array(array('source'=>'customer_request','request_id'=>(int)($record['request_id'] ?? 0),'owner_id'=>(int)$owner_id,'approved_at_utc'=>$approval['approved_at_utc'],'expires_utc'=>$approval['expires_utc'],'cart_hash'=>hash('sha256',wp_json_encode($lines)),'destination_hash'=>hash('sha256',wp_json_encode($record['destination'])))),'separate_parcels'=>null);
    }

    private static function stored_contents($lines) {
        $contents = array();
        foreach ((array)$lines as $line) {
            if (!is_array($line) || !isset($line['product_id'],$line['variation_id'],$line['quantity'])) return self::error('record','The request contains an invalid item.');
            $product = wc_get_product((int)($line['variation_id'] ?: $line['product_id']));
            if (!$product) return self::error('product', 'A requested product no longer exists.');
            $contents[] = array('data'=>$product,'product_id'=>(int)$line['product_id'],'variation_id'=>(int)$line['variation_id'],'quantity'=>(int)$line['quantity']);
        }
        return $contents;
    }

    private static function fresh_record($record) {
        $contents = self::stored_contents($record['lines'] ?? array());
        if (is_wp_error($contents)) return $contents;
        $lines = self::snapshot_lines($contents,true);
        return is_wp_error($lines) || $lines !== ($record['lines'] ?? null) ? self::error('changed','The request’s products, stock or specifications changed. Ask the customer to submit a new request.') : $lines;
    }

    private static function requests($owner_id, $status = null) {
        $posts = get_posts(array('post_type'=>self::POST_TYPE,'post_status'=>'private','author'=>(int)$owner_id,'numberposts'=>-1,'orderby'=>'ID','order'=>'DESC','suppress_filters'=>false));
        $records = array();
        foreach ($posts as $post) {
            $record = get_post_meta($post->ID,self::META,true);
            if (!is_array($record) || (int)($record['owner_id'] ?? 0) !== (int)$owner_id || ($status && ($record['status'] ?? '') !== $status)) continue;
            $record['request_id'] = $post->ID; $records[] = $record;
        }
        return $records;
    }

    public static function get_owned_request($id, $owner_id) {
        $post = get_post((int)$id);
        if (!$post || $post->post_type !== self::POST_TYPE || $post->post_status !== 'private' || (int)$owner_id < 1 || (int)$post->post_author !== (int)$owner_id) return self::error('owner','This request is unavailable.');
        $record = get_post_meta($post->ID,self::META,true);
        if (!is_array($record) || (int)($record['owner_id'] ?? 0) !== (int)$owner_id) return self::error('owner','This request is unavailable.');
        $record['request_id'] = $post->ID;
        return $record;
    }

    public static function quote_package($package, $now = null) {
        if (self::automatic_mode()) return self::error('retired','Manual delivery quotes are no longer used at checkout.');
        if (!is_user_logged_in() || !WC()->cart || !self::currency_ok()) return self::error('account','No customer quote is available.');
        $lines = self::snapshot_lines(WC()->cart->get_cart(),true);
        $package_lines = self::snapshot_lines($package['contents'] ?? array(),true);
        if (is_wp_error($lines) || is_wp_error($package_lines)) return self::error('cart','The cart is not eligible for an approved quote.');
        $physical = array_values(array_filter($lines, static function($line){return !empty($line['shippable']);}));
        if ($physical !== $package_lines) return self::error('package','The delivery package differs from the approved cart.');
        $matches = array(); $owner = get_current_user_id();
        foreach (self::requests($owner,'approved') as $record) {
            $rate = self::rate_from_record($record,$owner,$lines,$package['destination'] ?? array(),$now);
            if (!is_wp_error($rate)) $matches[] = $rate;
        }
        return count($matches) === 1 ? $matches[0] : self::error('unquoted','No single approved quote matches this cart and address.');
    }

    public static function account_menu($items) {
        if (self::automatic_mode()) { unset($items[self::ENDPOINT]); return $items; }
        $result = array();
        foreach ($items as $key=>$label) { if ('customer-logout' === $key) $result[self::ENDPOINT] = 'Shipping quotes'; $result[$key]=$label; }
        if (!isset($result[self::ENDPOINT])) $result[self::ENDPOINT]='Shipping quotes';
        return $result;
    }

    public static function quote_url() { return home_url('/shipping-quote/'); }
    private static function account_url() { return wc_get_account_endpoint_url(self::ENDPOINT); }
    private static function no_cache() { if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true); nocache_headers(); }

    public static function cache_guard() {
        $post=get_post();
        if (is_cart() || is_checkout() || (is_account_page() && is_wc_endpoint_url(self::ENDPOINT)) || ($post && has_shortcode($post->post_content,'petish_shipping_quote'))) self::no_cache();
    }

    public static function login_redirect($redirect, $user) {
        if (self::automatic_mode()) return $redirect;
        $requested = isset($_GET['redirect_to']) && is_scalar($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';
        return untrailingslashit($requested) === untrailingslashit(self::quote_url()) ? self::quote_url() : $redirect;
    }

    private static function use_quote_at_checkout() {
        if (self::automatic_mode()) return;
        if (!is_user_logged_in() || !WC()->cart || !WC()->session) { self::$errors[]='Sign in and load your cart before using a quote.'; return; }
        $id=isset($_POST['request_id']) && is_scalar($_POST['request_id']) ? absint($_POST['request_id']) : 0;
        $nonce=isset($_POST['_petish_quote_use_nonce']) && is_scalar($_POST['_petish_quote_use_nonce']) ? sanitize_text_field(wp_unslash($_POST['_petish_quote_use_nonce'])) : '';
        if (!$id || !wp_verify_nonce($nonce,'petish_shipping_quote_use_'.$id)) { self::$errors[]='Your quote form expired. Refresh and try again.'; return; }
        $record=self::get_owned_request($id,get_current_user_id());
        $lines=self::snapshot_lines(WC()->cart->get_cart(),true);
        if (is_wp_error($record) || is_wp_error($lines) || is_wp_error(self::rate_from_record($record,get_current_user_id(),$lines,$record['destination'] ?? array()))) { self::$errors[]='This approved quote no longer matches your account and cart. Request a new delivery quote.'; return; }
        // Explicit user action updates only the session-backed Woo delivery object.
        $customer=new WC_Customer(get_current_user_id(),true);
        $store=$customer->get_data_store();
        if ((int)$customer->get_id() !== get_current_user_id() || !$store || $store->get_current_class_name() !== 'WC_Customer_Data_Store_Session') { self::$errors[]='The checkout session could not be prepared safely. Enter the quoted delivery address at checkout.'; return; }
        foreach ($record['destination'] as $key=>$value) $customer->{'set_shipping_'.$key}($value);
        $customer->set_calculated_shipping(true);
        // The exact guarded datastore only writes WC()->session. Calling it
        // directly also avoids unrelated generic customer-save integrations.
        $store->update($customer);
        // Woo registered the original object by identity at shutdown. Its stale
        // snapshot must not overwrite the explicit approved-address handoff.
        if (WC()->customer instanceof WC_Customer) remove_action('shutdown',array(WC()->customer,'save'),10);
        WC()->customer=$customer;
        wp_safe_redirect(wc_get_checkout_url()); exit;
    }

    public static function assets() {
        if (self::automatic_mode()) return;
        $post = get_post();
        if (is_cart() || is_checkout() || (is_account_page() && is_wc_endpoint_url(self::ENDPOINT)) || ($post && has_shortcode($post->post_content,'petish_shipping_quote'))) {
            $base = plugin_dir_url(dirname(__FILE__)) . 'assets/';
            wp_enqueue_style('petish-shipping-quotes',$base.'shipping-quotes.css',array(),'0.2.0');
            wp_enqueue_script('petish-shipping-quotes',$base.'shipping-quotes.js',array('jquery','wc-country-select','wc-address-i18n'),'0.2.0',true);
            self::no_cache();
        }
    }

    public static function submit_request() {
        if (self::automatic_mode()) return;
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || !is_scalar($_POST['petish_shipping_quote_action'] ?? null)) return;
        if ('checkout' === $_POST['petish_shipping_quote_action']) { self::no_cache(); self::use_quote_at_checkout(); return; }
        if ('request' !== $_POST['petish_shipping_quote_action']) return;
        self::no_cache();
        if (!is_user_logged_in()) { self::$errors[]='Sign in before requesting a delivery quote.'; return; }
        if (!isset($_POST['_petish_quote_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_petish_quote_nonce'])),'petish_shipping_quote_request')) { self::$errors[]='Your form expired. Refresh the page and try again.'; return; }
        if (!WC()->cart) { self::$errors[]='Your cart could not be loaded. Return to the cart and try again.'; return; }
        foreach (array('country','state','city','postcode','address_1','address_2') as $key) self::$values[$key]=self::clean(wp_unslash($_POST['quote_'.$key] ?? ''));
        $record = self::create_request_record(WC()->cart->get_cart(),self::$values,get_current_user_id());
        if (is_wp_error($record)) { self::$errors[]=$record->get_error_message(); return; }
        // Identical pending requests are reused; client owner/price/status fields are never read.
        foreach (self::requests(get_current_user_id(),'requested') as $existing) {
            if ($existing['lines'] === $record['lines'] && $existing['destination'] === $record['destination']) { wp_safe_redirect(add_query_arg('request',(int)$existing['request_id'],self::account_url())); exit; }
        }
        $recent = self::requests(get_current_user_id());
        if (count(array_filter($recent,static function($r){return strtotime($r['requested_at_utc'] ?? '') > time()-DAY_IN_SECONDS;})) >= 10) { self::$errors[]='You have reached ten delivery requests in 24 hours. Review your Shipping quotes before submitting another.'; return; }
        $id = wp_insert_post(array('post_type'=>self::POST_TYPE,'post_status'=>'private','post_author'=>get_current_user_id(),'post_title'=>'Delivery request — '.gmdate('Y-m-d H:i').' UTC'),true);
        if (is_wp_error($id) || !$id || !add_post_meta($id,self::META,$record,true)) { self::$errors[]='The request could not be saved. Please try again.'; return; }
        wp_safe_redirect(add_query_arg(array('request'=>(int)$id,'created'=>1),self::account_url())); exit;
    }

    private static function line_list($lines) {
        echo '<ul class="petish-quote-items">';
        foreach ((array)$lines as $line) { $product=wc_get_product((int)($line['variation_id'] ?: $line['product_id'])); echo '<li class="petish-quote-item">'.esc_html(($product?$product->get_name():'Product #'.$line['product_id']).' × '.(int)$line['quantity']).'</li>'; }
        echo '</ul>';
    }

    public static function shortcode() {
        self::no_cache(); ob_start();
        if (self::automatic_mode()) {
            echo '<section class="petish-card"><h1>Delivery &amp; shipping</h1><p>Enter your delivery address at checkout to check available delivery options and charges. Review the full order total before paying.</p><p><a class="button" href="'.esc_url(wc_get_cart_url()).'">View cart</a> <a href="'.esc_url(home_url('/delivery-shipping/')).'">Delivery information</a></p></section>';
            return ob_get_clean();
        }
        echo '<section class="petish-shipping-quote"><h1>Request a shipping quote</h1>';
        if (!is_user_logged_in()) {
            $login=add_query_arg('redirect_to',self::quote_url(),wc_get_page_permalink('myaccount'));
            echo '<p>Sign in to request delivery pricing for your cart before paying.</p><div class="petish-quote-actions"><a class="button" href="'.esc_url($login).'">Sign in to request a quote</a></div></section>';
            return ob_get_clean();
        }
        $contents=WC()->cart ? WC()->cart->get_cart() : array(); $lines=self::snapshot_lines($contents,true);
        foreach (self::$errors as $error) echo '<div class="petish-quote-notice woocommerce-error" role="alert">'.esc_html($error).'</div>';
        if (is_wp_error($lines)) { echo '<p class="petish-quote-empty">'.esc_html($lines->get_error_message()).'</p><a class="button" href="'.esc_url(wc_get_page_permalink('shop')).'">Browse products</a></section>'; return ob_get_clean(); }
        echo '<p>We’ll review tracked delivery for these items and your address. You can review the full checkout total before making any payment.</p><div class="petish-quote-layout"><form method="post" id="petish-shipping-quote-form" class="petish-quote-form">';
        wp_nonce_field('petish_shipping_quote_request','_petish_quote_nonce');
        echo '<input type="hidden" name="petish_shipping_quote_action" value="request"><div class="woocommerce-shipping-fields"><div class="woocommerce-shipping-fields__field-wrapper petish-quote-fields">';
        $country=self::$values['country'] ?? (WC()->customer ? WC()->customer->get_shipping_country() : WC()->countries->get_base_country());
        if (!$country || !isset(WC()->countries->get_shipping_countries()[$country])) $country=WC()->countries->get_base_country();
        $fields=WC()->countries->get_address_fields($country,'quote_');
        foreach (array('country','address_1','address_2','city','state','postcode') as $key) {
            $field=$fields['quote_'.$key] ?? null; if (!$field) continue;
            $field['id']='shipping_'.$key; $field['country']=$country;
            $value=self::$values[$key] ?? ($key==='country'?$country:(WC()->customer ? WC()->customer->{'get_shipping_'.$key}() : ''));
            woocommerce_form_field('quote_'.$key,$field,$value);
        }
        echo '</div></div><p>Your address is used for this request. It does not update your saved account address.</p><div class="petish-quote-actions"><button type="submit" class="button">Request shipping quote</button><span data-petish-quote-submit-status class="petish-quote-submit-status" role="status" aria-live="polite"></span></div></form><aside class="petish-quote-summary petish-quote-card"><h2>Your cart</h2>';
        self::line_list($lines);
        echo '<p>Changing items, quantities, specifications, stock or delivery address may require a new quote.</p><a href="'.esc_url(self::account_url()).'">View my shipping quotes</a></aside></div></section>';
        return ob_get_clean();
    }

    public static function account_view() {
        self::no_cache(); if (!is_user_logged_in()) return;
        echo '<section class="petish-shipping-quote"><h2>Shipping quotes</h2><p>'.(self::automatic_mode() ? 'Previous requests are kept for reference. Manual quotes are no longer used at checkout.' : 'Request and review delivery pricing before payment.').'</p>';
        foreach (self::$errors as $error) echo '<div class="petish-quote-notice woocommerce-error" role="alert">'.esc_html($error).'</div>';
        $records=self::requests(get_current_user_id());
        $selected=absint($_GET['request'] ?? 0);
        if ($selected) { $record=self::get_owned_request($selected,get_current_user_id()); if (!is_wp_error($record)) { self::render_record($record); echo '</section>'; return; } }
        if (!$records) echo '<p class="petish-quote-empty">You have no shipping quote requests yet.</p>';
        else {
            echo '<table class="shop_table shop_table_responsive petish-quote-table"><thead><tr><th>Request</th><th>Destination</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            foreach (array_slice($records,0,50) as $record) echo '<tr><td data-title="Request">#'.(int)$record['request_id'].'</td><td data-title="Destination">'.esc_html($record['destination']['city'].', '.$record['destination']['country']).'</td><td data-title="Status">'.esc_html(self::display_status($record)).'</td><td data-title="Actions"><a href="'.esc_url(add_query_arg('request',(int)$record['request_id'],self::account_url())).'">View request</a></td></tr>';
            echo '</tbody></table>';
        }
        echo '<div class="petish-quote-actions">';
        if (!self::automatic_mode()) echo '<a class="button" href="'.esc_url(self::quote_url()).'">Request a shipping quote</a>';
        echo '<a href="'.esc_url(wc_get_cart_url()).'">Return to cart</a></div></section>';
    }

    private static function display_status($record) {
        if (self::automatic_mode()) return 'Historical — '.ucfirst($record['status'] ?? 'requested');
        if (($record['status'] ?? '') === 'approved') {
            if (strtotime($record['approval']['expires_utc'] ?? '') <= time()) return 'Expired';
            if (is_wp_error(self::fresh_record($record))) return 'Changed — new request needed';
        }
        return ucfirst($record['status'] ?? 'requested');
    }

    private static function render_record($record) {
        $status=self::display_status($record);
        echo '<article class="petish-quote-card"><h3>Request #'.(int)$record['request_id'].'</h3><p class="petish-quote-status" data-status="'.esc_attr(strtolower(strtok($status,' '))).'">'.esc_html($status).'</p>';
        self::line_list($record['lines']);
        echo '<p class="petish-quote-meta">'.esc_html(implode(', ',array_filter($record['destination']))).'</p>';
        if (self::automatic_mode()) {
            echo '<p>This request is saved for reference. Check current delivery options and charges at checkout.</p>';
            if (!empty($record['approval']['carrier']) && !empty($record['approval']['delivery_window'])) echo '<p>Recorded carrier: '.esc_html($record['approval']['carrier']).'. Recorded estimated delivery: '.esc_html($record['approval']['delivery_window']).'.</p>';
            if (!empty($record['approval']['customer_notes'])) echo '<p>'.esc_html($record['approval']['customer_notes']).'</p>';
            echo '<div class="petish-quote-actions"><a href="'.esc_url(self::account_url()).'">All shipping quotes</a><a href="'.esc_url(wc_get_cart_url()).'">Return to cart</a></div></article>';
            return;
        }
        if ($status === 'Approved') {
            echo '<p>Review your approved delivery charge and full total at checkout before paying. Use the same cart. The button below copies this delivery address to your checkout session, without updating your saved profile.</p><p>Carrier: '.esc_html($record['approval']['carrier']).'. Estimated delivery: '.esc_html($record['approval']['delivery_window']).'. Quote expires '.esc_html(wp_date('j M Y, g:ia T',strtotime($record['approval']['expires_utc']))).'.</p>';
            if (!empty($record['approval']['customer_notes'])) echo '<p>'.esc_html($record['approval']['customer_notes']).'</p>';
            echo '<form method="post" class="petish-quote-actions">';
            wp_nonce_field('petish_shipping_quote_use_'.$record['request_id'],'_petish_quote_use_nonce');
            echo '<input type="hidden" name="petish_shipping_quote_action" value="checkout"><input type="hidden" name="request_id" value="'.(int)$record['request_id'].'"><button type="submit" class="button">Use quote at checkout</button></form>';
        } elseif ($status === 'Requested') echo '<p>Your request is waiting for review. No payment has been taken.</p>';
        else echo '<p>A current approved delivery quote is required before checkout can offer this delivery method. You can submit a new request.</p>';
        echo '<div class="petish-quote-actions"><a href="'.esc_url(self::quote_url()).'">Request another quote</a><a href="'.esc_url(self::account_url()).'">All shipping quotes</a></div></article>';
    }

    public static function admin_boxes() { add_meta_box('petish-delivery-request','Private delivery request',array(__CLASS__,'admin_box'),self::POST_TYPE,'normal','high'); }
    public static function authorized_admin_action($post_id,$nonce) { return current_user_can('manage_woocommerce') && current_user_can('edit_post',$post_id) && get_post_type($post_id) === self::POST_TYPE && wp_verify_nonce($nonce,'petish_quote_admin_'.$post_id); }

    public static function admin_box($post) {
        if (!current_user_can('manage_woocommerce')) return;
        $record=get_post_meta($post->ID,self::META,true);
        if (!is_array($record)) { echo '<p>No valid request record.</p>'; return; }
        wp_nonce_field('petish_quote_admin_'.$post->ID,'_petish_quote_admin_nonce');
        echo '<p>Owner user #'.(int)$record['owner_id'].' · Status: '.esc_html($record['status']).' · '.esc_html($record['requested_at_utc']).'</p>';
        self::line_list($record['lines']);
        echo '<p><strong>Private destination:</strong> '.esc_html(implode(', ',array_filter($record['destination']))).'</p><p>Review the exact variants, grouped quantities, address, tracked service and supplier evidence. No order, supplier booking, email or payment is created by approval.</p>';
        $approval=is_array($record['approval'] ?? null)?$record['approval']:array();
        $fields=array('customer_shipping_ex_tax_aud'=>'Total customer delivery charge EXCLUDING TAX (AUD) — WooCommerce adds applicable destination tax','carrier'=>'Tracked carrier / service (shown to customer)','reference'=>'PRIVATE supplier freight evidence and reference (include currency, conversion and route evidence)','delivery_window'=>'Estimated delivery window shown to customer','customer_notes'=>'Optional customer delivery notes, plain text with no links (shown to customer)','expires_utc'=>'Expiry UTC, maximum seven days: YYYY-MM-DDTHH:MM:SSZ');
        foreach ($fields as $key=>$label) echo '<p><label for="petish_'.esc_attr($key).'"><strong>'.esc_html($label).'</strong></label><br><input class="widefat" id="petish_'.esc_attr($key).'" name="petish_'.esc_attr($key).'" type="text" value="'.esc_attr($approval[$key] ?? ('expires_utc' === $key ? gmdate('Y-m-d\TH:i:s\Z',time()+3*DAY_IN_SECONDS) : '')).'"></p>';
        echo '<p>A new quote starts with a three-day expiry. Shorten it when the supplier price or availability needs an earlier review.</p>';
        echo '<p><label><input type="checkbox" name="petish_quote_reviewed" value="1"> I checked this exact cart, quantity and destination against current supplier evidence; the entered customer charge is ex-tax AUD.</label></p><p><button type="submit" class="button button-primary" name="petish_quote_decision" value="approve">Approve delivery quote</button> <button type="submit" class="button" name="petish_quote_decision" value="decline">Decline request</button></p>';
        $error=get_post_meta($post->ID,'_petish_quote_admin_error',true); if ($error) echo '<p><strong>'.esc_html($error).'</strong></p>';
    }

    public static function admin_save($post_id,$post,$update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || !isset($_POST['petish_quote_decision'],$_POST['_petish_quote_admin_nonce']) || !self::authorized_admin_action($post_id,sanitize_text_field(wp_unslash($_POST['_petish_quote_admin_nonce'])))) return;
        $record=get_post_meta($post_id,self::META,true); if (!is_array($record)) return;
        $original=$record;
        $decision=sanitize_key(wp_unslash($_POST['petish_quote_decision']));
        if ($decision === 'decline') { $record['status']='declined'; $record['approval']=null; }
        elseif ($decision === 'approve') {
            $fresh=self::fresh_record($record);
            $fields=array(); foreach (array('customer_shipping_ex_tax_aud','carrier','reference','delivery_window','customer_notes','expires_utc') as $key) $fields[$key]=is_scalar($_POST['petish_'.$key] ?? null)?wp_unslash($_POST['petish_'.$key]):'';
            $approval=self::validate_approval_fields($fields,$record);
            if (empty($_POST['petish_quote_reviewed']) || is_wp_error($fresh) || is_wp_error($approval)) { update_post_meta($post_id,'_petish_quote_admin_error',is_wp_error($fresh)?$fresh->get_error_message():(is_wp_error($approval)?$approval->get_error_message():'Confirm the evidence review before approving.')); return; }
            // One current approval for the same owner/cart/address prevents ambiguous checkout matches.
            foreach (self::requests((int)$record['owner_id'],'approved') as $other) {
                if ((int)$other['request_id'] !== (int)$post_id && $other['lines'] === $record['lines'] && $other['destination'] === $record['destination'] && strtotime($other['approval']['expires_utc'] ?? '') > time()) { update_post_meta($post_id,'_petish_quote_admin_error','Another current approval already matches this cart and address. Decline it before approving this request.'); return; }
            }
            $record['status']='approved'; $record['approval']=$approval;
        } else return;
        $record['reviewed_by']=get_current_user_id(); $record['reviewed_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
        if (update_post_meta($post_id,self::META,$record,$original)) delete_post_meta($post_id,'_petish_quote_admin_error');
    }

    private static function cta() {
        if (self::automatic_mode()) return '';
        if (!WC()->cart || !WC()->cart->needs_shipping()) return '';
        $packages=WC()->cart->get_shipping_packages(); if (!$packages) return '';
        $unquoted=false;
        foreach ($packages as $package) if (is_wp_error(Petish_Supplier_Quotes::quote_package($package))) $unquoted=true;
        if (!$unquoted) return '';
        self::no_cache();
        return '<section class="petish-shipping-quote petish-quote-notice"><p>Delivery pricing needs a quote for this cart and address. Request a shipping quote before payment.</p><a class="button" href="'.esc_url(self::quote_url()).'">Request a shipping quote</a></section>';
    }
    public static function block_cta($html,$block) { return in_array($block['blockName'] ?? '',array('woocommerce/cart','woocommerce/checkout'),true) ? $html.self::cta() : $html; }
    public static function classic_cta() { echo self::cta(); }
    public static function classic_checkout_cta() { $cta=self::cta(); if ($cta) echo '<tr><td colspan="2">'.$cta.'</td></tr>'; }
}
