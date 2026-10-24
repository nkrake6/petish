<?php
/** Read-only installed Cart/provider runtime diagnosis. No application writes. */
if (PHP_SAPI !== 'cli') exit(1);
const PETISH_STOREFRONT_PAYMENT_CHECKS = [
    'target_paths_verified',
    'target_environment_verified',
    'woocommerce_runtime_verified',
    'all_outbound_http_blocked',
    'mail_calls_blocked',
    'remote_file_access_disabled',
    'cron_and_async_runner_disabled',
    'cart_query_context_verified',
    'native_probe_error_observed',
    'cart_hook_observed',
    'orders_unchanged',
    'users_and_authentication_unchanged',
    'product_prices_and_stock_unchanged',
    'settings_unchanged',
    'existing_passports_unchanged',
    'private_photo_files_unchanged',
    'readonly_sql_barrier_attached',
    'runtime_write_attempt_blocked',
    'wp_zero_is_cart',
    'wp_zero_has_cart_block',
    'wp_zero_is_admin',
    'wp_zero_is_ajax',
    'wp_zero_is_rest',
    'wp_zero_is_cli_constant',
    'wp_zero_is_get_head',
    'wp_zero_wp_loaded_seen',
    'wp_zero_registry_registration_seen',
    'wp_zero_selector_at_wp_one',
    'wp_zero_selected_flag',
    'wp_zero_woocommerce_payments_registered',
    'wp_zero_ppcp_googlepay_registered',
    'wp_zero_ppcp_sdk_v6_registered',
    'wp_zero_ppcp_gateway_registered',
    'wp_zero_payment_data_captured',
    'wp_zero_ppcp_googlepay_block_registered',
    'wp_zero_ppcp_googlepay_block_enqueued',
    'wp_zero_ppcp_googlepay_block_done',
    'wp_zero_wc_ppcp_sdk_v6_blocks_registered',
    'wp_zero_wc_ppcp_sdk_v6_blocks_enqueued',
    'wp_zero_wc_ppcp_sdk_v6_blocks_done',
    'wp_zero_wc_cart_block_frontend_registered',
    'wp_zero_wc_cart_block_frontend_enqueued',
    'wp_zero_wc_cart_block_frontend_done',
    'wp_zero_wcpay_blocks_checkout_registered',
    'wp_zero_wcpay_blocks_checkout_enqueued',
    'wp_zero_wcpay_blocks_checkout_done',
    'wp_two_is_cart',
    'wp_two_has_cart_block',
    'wp_two_is_admin',
    'wp_two_is_ajax',
    'wp_two_is_rest',
    'wp_two_is_cli_constant',
    'wp_two_is_get_head',
    'wp_two_wp_loaded_seen',
    'wp_two_registry_registration_seen',
    'wp_two_selector_at_wp_one',
    'wp_two_selected_flag',
    'wp_two_woocommerce_payments_registered',
    'wp_two_ppcp_googlepay_registered',
    'wp_two_ppcp_sdk_v6_registered',
    'wp_two_ppcp_gateway_registered',
    'wp_two_payment_data_captured',
    'wp_two_wcpay_blocks_active',
    'wp_two_google_gateway_exists',
    'wp_two_google_gateway_enabled',
    'wp_two_google_split_express_available',
    'wp_two_google_in_enabled_checkout_ids',
    'wp_two_main_gateway_enabled',
    'wp_two_main_payment_request_enabled',
    'wp_two_cart_payment_request_location_enabled',
    'wp_two_native_context_payment_request_enabled',
    'wp_two_ppcp_googlepay_block_registered',
    'wp_two_ppcp_googlepay_block_enqueued',
    'wp_two_ppcp_googlepay_block_done',
    'wp_two_wc_ppcp_sdk_v6_blocks_registered',
    'wp_two_wc_ppcp_sdk_v6_blocks_enqueued',
    'wp_two_wc_ppcp_sdk_v6_blocks_done',
    'wp_two_wc_cart_block_frontend_registered',
    'wp_two_wc_cart_block_frontend_enqueued',
    'wp_two_wc_cart_block_frontend_done',
    'wp_two_wcpay_blocks_checkout_registered',
    'wp_two_wcpay_blocks_checkout_enqueued',
    'wp_two_wcpay_blocks_checkout_done',
    'enqueue_four_is_cart',
    'enqueue_four_has_cart_block',
    'enqueue_four_is_admin',
    'enqueue_four_is_ajax',
    'enqueue_four_is_rest',
    'enqueue_four_is_cli_constant',
    'enqueue_four_is_get_head',
    'enqueue_four_wp_loaded_seen',
    'enqueue_four_registry_registration_seen',
    'enqueue_four_selector_at_wp_one',
    'enqueue_four_selected_flag',
    'enqueue_four_woocommerce_payments_registered',
    'enqueue_four_ppcp_googlepay_registered',
    'enqueue_four_ppcp_sdk_v6_registered',
    'enqueue_four_ppcp_gateway_registered',
    'enqueue_four_payment_data_captured',
    'cart_data_before_is_cart',
    'cart_data_before_has_cart_block',
    'cart_data_before_is_admin',
    'cart_data_before_is_ajax',
    'cart_data_before_is_rest',
    'cart_data_before_is_cli_constant',
    'cart_data_before_is_get_head',
    'cart_data_before_wp_loaded_seen',
    'cart_data_before_registry_registration_seen',
    'cart_data_before_selector_at_wp_one',
    'cart_data_before_selected_flag',
    'cart_data_before_woocommerce_payments_registered',
    'cart_data_before_ppcp_googlepay_registered',
    'cart_data_before_ppcp_sdk_v6_registered',
    'cart_data_before_ppcp_gateway_registered',
    'cart_data_before_payment_data_captured',
    'cart_data_after_is_cart',
    'cart_data_after_has_cart_block',
    'cart_data_after_is_admin',
    'cart_data_after_is_ajax',
    'cart_data_after_is_rest',
    'cart_data_after_is_cli_constant',
    'cart_data_after_is_get_head',
    'cart_data_after_wp_loaded_seen',
    'cart_data_after_registry_registration_seen',
    'cart_data_after_selector_at_wp_one',
    'cart_data_after_selected_flag',
    'cart_data_after_woocommerce_payments_registered',
    'cart_data_after_ppcp_googlepay_registered',
    'cart_data_after_ppcp_sdk_v6_registered',
    'cart_data_after_ppcp_gateway_registered',
    'cart_data_after_payment_data_captured',
    'cart_data_after_ppcp_googlepay_block_registered',
    'cart_data_after_ppcp_googlepay_block_enqueued',
    'cart_data_after_ppcp_googlepay_block_done',
    'cart_data_after_wc_ppcp_sdk_v6_blocks_registered',
    'cart_data_after_wc_ppcp_sdk_v6_blocks_enqueued',
    'cart_data_after_wc_ppcp_sdk_v6_blocks_done',
    'cart_data_after_wc_cart_block_frontend_registered',
    'cart_data_after_wc_cart_block_frontend_enqueued',
    'cart_data_after_wc_cart_block_frontend_done',
    'cart_data_after_wcpay_blocks_checkout_registered',
    'cart_data_after_wcpay_blocks_checkout_enqueued',
    'cart_data_after_wcpay_blocks_checkout_done',
    'cart_data_after_paypal_googlepay_payload_present',
    'cart_data_after_paypal_googlepay_payload_enabled',
    'cart_data_after_paypal_googlepay_payload_script_enabled',
    'cart_data_after_sdk_v6_payload_present',
    'cart_data_after_sdk_v6_googlepay_cart_style_present',
    'cart_data_after_sdk_v6_googlepay_minicart_style_present',
    'dto_cart_before_filter_seen',
    'dto_cart_before_selected_flag',
    'dto_cart_before_wp_seen',
    'dto_cart_before_enabled',
    'dto_cart_before_contains_googlepay',
    'dto_cart_before_contains_paypal',
    'dto_cart_after_filter_seen',
    'dto_cart_after_selected_flag',
    'dto_cart_after_wp_seen',
    'dto_cart_after_enabled',
    'dto_cart_after_contains_googlepay',
    'dto_cart_after_contains_paypal',
    'dto_cart_block_before_filter_seen',
    'dto_cart_block_before_selected_flag',
    'dto_cart_block_before_wp_seen',
    'dto_cart_block_before_enabled',
    'dto_cart_block_before_contains_googlepay',
    'dto_cart_block_before_contains_paypal',
    'dto_cart_block_after_filter_seen',
    'dto_cart_block_after_selected_flag',
    'dto_cart_block_after_wp_seen',
    'dto_cart_block_after_enabled',
    'dto_cart_block_after_contains_googlepay',
    'dto_cart_block_after_contains_paypal',
    'dto_product_before_filter_seen',
    'dto_product_before_selected_flag',
    'dto_product_before_wp_seen',
    'dto_product_before_enabled',
    'dto_product_before_contains_googlepay',
    'dto_product_before_contains_paypal',
    'dto_product_after_filter_seen',
    'dto_product_after_selected_flag',
    'dto_product_after_wp_seen',
    'dto_product_after_enabled',
    'dto_product_after_contains_googlepay',
    'dto_product_after_contains_paypal',
    'dto_mini_cart_before_filter_seen',
    'dto_mini_cart_before_selected_flag',
    'dto_mini_cart_before_wp_seen',
    'dto_mini_cart_before_enabled',
    'dto_mini_cart_before_contains_googlepay',
    'dto_mini_cart_before_contains_paypal',
    'dto_mini_cart_after_filter_seen',
    'dto_mini_cart_after_selected_flag',
    'dto_mini_cart_after_wp_seen',
    'dto_mini_cart_after_enabled',
    'dto_mini_cart_after_contains_googlepay',
    'dto_mini_cart_after_contains_paypal',
    'dto_classic_checkout_before_filter_seen',
    'dto_classic_checkout_before_selected_flag',
    'dto_classic_checkout_before_wp_seen',
    'dto_classic_checkout_before_enabled',
    'dto_classic_checkout_before_contains_googlepay',
    'dto_classic_checkout_before_contains_paypal',
    'dto_classic_checkout_after_filter_seen',
    'dto_classic_checkout_after_selected_flag',
    'dto_classic_checkout_after_wp_seen',
    'dto_classic_checkout_after_enabled',
    'dto_classic_checkout_after_contains_googlepay',
    'dto_classic_checkout_after_contains_paypal',
    'dto_express_checkout_before_filter_seen',
    'dto_express_checkout_before_selected_flag',
    'dto_express_checkout_before_wp_seen',
    'dto_express_checkout_before_enabled',
    'dto_express_checkout_before_contains_googlepay',
    'dto_express_checkout_before_contains_paypal',
    'dto_express_checkout_after_filter_seen',
    'dto_express_checkout_after_selected_flag',
    'dto_express_checkout_after_wp_seen',
    'dto_express_checkout_after_enabled',
    'dto_express_checkout_after_contains_googlepay',
    'dto_express_checkout_after_contains_paypal',
    'dto_other_before_filter_seen',
    'dto_other_before_selected_flag',
    'dto_other_before_wp_seen',
    'dto_other_before_enabled',
    'dto_other_before_contains_googlepay',
    'dto_other_before_contains_paypal',
    'dto_other_after_filter_seen',
    'dto_other_after_selected_flag',
    'dto_other_after_wp_seen',
    'dto_other_after_enabled',
    'dto_other_after_contains_googlepay',
    'dto_other_after_contains_paypal',
];
const PETISH_STOREFRONT_PAYMENT_RUNTIME_FIELDS = PETISH_STOREFRONT_PAYMENT_CHECKS;
final class Petish_Cart_Live_Diagnostic {
    private static $checks = array();
    private static $locations = array();
    private static $seen_stages = array();
    private static $dto_calls = 0;

    private static function mark( $stage, $key, $value ) {
        self::$checks[ $stage . '_' . $key ] = (bool) $value;
    }

    private static function safe_bool( $stage, $key, $callback ) {
        try { self::mark( $stage, $key, $callback() ); }
        catch ( \Throwable $ignored ) { self::mark( $stage, $key . '_probe_error', true ); }
    }

    private static function selected() {
        if ( ! class_exists( 'Petish_Cart_Payment_Layout', false ) ) return false;
        $property = new \ReflectionProperty( 'Petish_Cart_Payment_Layout', 'wcpay_google_pay_selected' );
        $property->setAccessible( true );
        return true === $property->getValue();
    }

    public static function capture( $stage, $full = false ) {
        if ( isset( self::$seen_stages[ $stage ] ) ) return;
        self::$seen_stages[ $stage ] = true;
        foreach ( array(
            'is_cart' => function () { return function_exists( 'is_cart' ) && is_cart(); },
            'has_cart_block' => function () { return function_exists( 'has_block' ) && has_block( 'woocommerce/cart' ); },
            'is_admin' => function () { return is_admin(); },
            'is_ajax' => function () { return wp_doing_ajax(); },
            'is_rest' => function () { return defined( 'REST_REQUEST' ) && REST_REQUEST; },
            'is_cli_constant' => function () { return defined( 'WP_CLI' ) && WP_CLI; },
            'is_get_head' => function () { return in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ); },
            'wp_loaded_seen' => function () { return did_action( 'wp_loaded' ) > 0; },
            'registry_registration_seen' => function () { return did_action( 'woocommerce_blocks_payment_method_type_registration' ) > 0; },
            'selector_at_wp_one' => function () { return has_action( 'wp', array( 'Petish_Cart_Payment_Layout', 'select_google_pay_provider' ) ) === 1; },
            'selected_flag' => function () { return self::selected(); },
        ) as $key => $callback ) self::safe_bool( $stage, $key, $callback );

        foreach ( array( 'ppcp-googlepay-block', 'wc-ppcp-sdk-v6-blocks', 'wc-cart-block-frontend', 'WCPAY_BLOCKS_CHECKOUT' ) as $handle ) {
            $key = str_replace( '-', '_', strtolower( $handle ) );
            foreach ( array( 'registered', 'enqueued', 'done' ) as $state ) {
                self::safe_bool( $stage, $key . '_' . $state, function () use ( $handle, $state ) { return wp_script_is( $handle, $state ); } );
            }
        }
        try {
            $container = \Automattic\WooCommerce\Blocks\Package::container();
            $registry = $container->get( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class );
            foreach ( array( 'woocommerce_payments', 'ppcp-googlepay', 'ppcp-sdk-v6', 'ppcp-gateway' ) as $name ) {
                self::mark( $stage, str_replace( '-', '_', $name ) . '_registered', $registry->is_registered( $name ) );
            }
            $assets = $container->get( \Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry::class );
            self::mark( $stage, 'payment_data_captured', $assets->exists( 'paymentMethodData' ) );
            if ( $assets->exists( 'paymentMethodData' ) ) {
                $get = new \ReflectionMethod( $assets, 'get' );
                $get->setAccessible( true );
                $data = $get->invoke( $assets )['paymentMethodData'] ?? array();
                self::mark( $stage, 'paypal_googlepay_payload_present', isset( $data['ppcp-googlepay'] ) );
                self::mark( $stage, 'paypal_googlepay_payload_enabled', ! empty( $data['ppcp-googlepay']['enabled'] ) );
                self::mark( $stage, 'paypal_googlepay_payload_script_enabled', ! empty( $data['ppcp-googlepay']['scriptData']['is_enabled'] ) );
                self::mark( $stage, 'sdk_v6_payload_present', isset( $data['ppcp-sdk-v6'] ) );
                self::mark( $stage, 'sdk_v6_googlepay_cart_style_present', isset( $data['ppcp-sdk-v6']['google_pay']['styles']['cart-block'] ) || isset( $data['ppcp-sdk-v6']['google_pay']['styles']['cart'] ) );
                self::mark( $stage, 'sdk_v6_googlepay_minicart_style_present', isset( $data['ppcp-sdk-v6']['google_pay']['styles']['mini-cart'] ) );
                unset( $data );
            }
            if ( $full ) {
                $wcpay = $registry->get_registered( 'woocommerce_payments' );
                self::safe_bool( $stage, 'wcpay_blocks_active', function () use ( $wcpay ) { return $wcpay && $wcpay->is_active(); } );
            }
        } catch ( \Throwable $ignored ) { self::mark( $stage, 'registry_probe_error', true ); }
        if ( ! $full ) return;

        try {
            $main = WC_Payments::get_gateway();
            $google = WC_Payments::get_payment_gateway_by_id( 'google_pay' );
            $helper = WC_Payments::get_express_checkout_helper();
            self::mark( $stage, 'google_gateway_exists', is_object( $google ) );
            self::safe_bool( $stage, 'google_gateway_enabled', function () use ( $google ) { return $google && $google->is_enabled(); } );
            self::safe_bool( $stage, 'google_split_express_available', function () use ( $google ) { return $google && $google->is_available_for_express_checkout(); } );
            self::safe_bool( $stage, 'google_in_enabled_checkout_ids', function () use ( $google ) { return $google && in_array( $google->get_stripe_id(), $google->get_payment_method_ids_enabled_at_checkout(), true ); } );
            self::safe_bool( $stage, 'main_gateway_enabled', function () use ( $main ) { return $main && $main->is_enabled(); } );
            self::safe_bool( $stage, 'main_payment_request_enabled', function () use ( $main ) { return $main && $main->is_payment_request_enabled(); } );
            self::safe_bool( $stage, 'cart_payment_request_location_enabled', function () use ( $helper ) { return $helper && $helper->is_express_checkout_method_enabled_at( 'cart', 'payment_request' ); } );
            self::safe_bool( $stage, 'native_context_payment_request_enabled', function () use ( $helper ) { return $helper && in_array( 'payment_request', $helper->get_enabled_express_checkout_methods_for_context(), true ); } );
        } catch ( \Throwable $ignored ) { self::mark( $stage, 'wcpay_probe_error', true ); }
    }

    public static function dto( $styles, $after ) {
        if ( ++self::$dto_calls > 40 ) return $styles;
        $location = is_object( $styles ) ? ( $styles->location ?? '' ) : '';
        if ( ! in_array( $location, array( 'cart', 'cart-block', 'product', 'mini_cart', 'mini-cart', 'classic_checkout', 'express_checkout' ), true ) ) $location = 'other';
        self::$locations[ $location ] = true;
        $stage = 'dto_' . str_replace( '-', '_', $location ) . ( $after ? '_after' : '_before' );
        self::mark( $stage, 'filter_seen', true );
        self::mark( $stage, 'selected_flag', self::selected() );
        self::mark( $stage, 'wp_seen', did_action( 'wp' ) > 0 );
        self::mark( $stage, 'enabled', is_object( $styles ) && ! empty( $styles->enabled ) );
        self::mark( $stage, 'contains_googlepay', is_object( $styles ) && in_array( 'ppcp-googlepay', $styles->methods ?? array(), true ) );
        self::mark( $stage, 'contains_paypal', is_object( $styles ) && in_array( 'ppcp-gateway', $styles->methods ?? array(), true ) );
        return $styles;
    }

    public static function install() {
        add_action( 'wp', function () { self::capture( 'wp_zero' ); }, 0 );
        add_action( 'wp', function () { self::capture( 'wp_two', true ); }, 2 );
        add_action( 'wp_enqueue_scripts', function () { self::capture( 'enqueue_four' ); }, 4 );
        add_action( 'woocommerce_blocks_cart_enqueue_data', function () { self::capture( 'cart_data_before' ); }, 1 );
        add_action( 'woocommerce_blocks_cart_enqueue_data', function () { self::capture( 'cart_data_after' ); }, 99 );
        add_filter( 'woocommerce_paypal_payments_googlepay_button_styles', function ( $styles ) { return self::dto( $styles, false ); }, 29 );
        add_filter( 'woocommerce_paypal_payments_googlepay_button_styles', function ( $styles ) { return self::dto( $styles, true ); }, 31 );
    }

    public static function report() {
        return array( 'format' => 'petish-cart-native-policy-diagnostic-v1', 'checks' => self::$checks, 'locations' => array_keys( self::$locations ) );
    }
}

function petish_storefront_payment_runtime_main($argv) {
    global $report,$wp;
    define('PETISH_CLUB_VERIFY_LIBRARY',true);
    require_once __DIR__.'/verify-pet-club.php';
    petish_club_verify_report_start();
    try {
        if(count($argv)!==2 || !petish_club_verify_run($argv[1])) throw new RuntimeException();
        $report['category']='bootstrap';
        $root=petish_club_verify_bootstrap('production',true);
        $report['category']='diagnostics';
        $before=petish_club_verify_state($root);
        $blocked_before=(int)($GLOBALS['petish_club_blocked_write_count']??0);
        Petish_Cart_Live_Diagnostic::install();
        // Re-enter only the normal anonymous GET Cart query/render lifecycle.
        // Do not create a session, add cart items, authenticate or invoke checkout.
        $_SERVER['REQUEST_URI']='/cart/';
        $_SERVER['REQUEST_METHOD']='GET';
        $_SERVER['PHP_SELF']='/index.php';
        $_SERVER['SCRIPT_NAME']='/index.php';
        $report['category']='diagnostics';
        $wp->main();
        $report['checks']['cart_query_context_verified']=is_cart()&&has_block('woocommerce/cart');
        if(!$report['checks']['cart_query_context_verified']) throw new RuntimeException();
        $report['category']='diagnostics';
        do_action('wp_enqueue_scripts');
        $page=get_post();
        ob_start();
        try { apply_filters('the_content',$page ? $page->post_content : ''); }
        finally { ob_end_clean(); }
        $native=Petish_Cart_Live_Diagnostic::report()['checks'];
        foreach(PETISH_STOREFRONT_PAYMENT_CHECKS as $key) if(!array_key_exists($key,$report['checks'])) $report['checks'][$key]=(bool)($native[$key]??false);
        $report['checks']['native_probe_error_observed']=false;
        foreach($native as $key=>$value) if(str_ends_with($key,'_probe_error')&&$value) $report['checks']['native_probe_error_observed']=true;
        $report['checks']['cart_hook_observed']=array_key_exists('wp_two_is_cart',$native);
        $report['category']='diagnostics';
        petish_club_verify_state_equal($before,petish_club_verify_state($root));
        $report['checks']['readonly_sql_barrier_attached']=has_filter('query','petish_club_verify_query_guard')===PHP_INT_MAX;
        $report['checks']['runtime_write_attempt_blocked']=((int)($GLOBALS['petish_club_blocked_write_count']??0))>$blocked_before;
        if(!$report['checks']['readonly_sql_barrier_attached']||!$report['checks']['cart_hook_observed']||$report['checks']['native_probe_error_observed']) throw new RuntimeException();
        $report['status']='success';
        $report['category']='complete';
    } catch(Throwable $ignored) { $report['status']='failure'; }
    return $report['status']==='success'?0:1;
}
if(!defined('PETISH_STOREFRONT_PAYMENT_RUNTIME_LIBRARY'))exit(petish_storefront_payment_runtime_main($argv));
