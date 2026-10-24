<?php
/* Actual WooCommerce public registry; isolated provider/config test doubles. */
namespace Automattic\WooCommerce\Blocks {
    final class Package {
        public static $fixture_container;
        public static function container() { return self::$fixture_container; }
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $woo = false;
    foreach ( array( getenv('PETISH_WOOCOMMERCE_ROOT'), __DIR__.'/../wordpress/wp-content/plugins/woocommerce', '/tmp/petish-fyp-repro/WordPress-7.1.3/wp-content/plugins/woocommerce' ) as $candidate ) {
        if ( is_string($candidate) && is_file($candidate.'/src/Blocks/Payments/PaymentMethodRegistry.php') ) { $woo=$candidate.'/src/Blocks/'; break; }
    }
    if ( false === $woo ) { fwrite(STDERR, "Set PETISH_WOOCOMMERCE_ROOT to native WooCommerce source.\n"); exit(2); }
    require $woo . 'Integrations/IntegrationInterface.php';
    require $woo . 'Integrations/IntegrationRegistry.php';
    require $woo . 'Payments/PaymentMethodTypeInterface.php';
    require $woo . 'Payments/PaymentMethodRegistry.php';
    $paypal = false;
    foreach ( array( getenv('PETISH_PAYPAL_PAYMENTS_ROOT'), __DIR__.'/../wordpress/wp-content/plugins/woocommerce-paypal-payments', '/tmp/petish-payment-layout-draft/paypal-source' ) as $candidate ) {
        if ( is_string($candidate) && is_file($candidate.'/modules/ppcp-settings/src/DTO/LocationStylingDTO.php') ) { $paypal=$candidate; break; }
    }
    if ( false === $paypal ) { fwrite(STDERR, "Set PETISH_PAYPAL_PAYMENTS_ROOT to native PayPal Payments source.\n"); exit(2); }
    require $paypal.'/modules/ppcp-settings/src/DTO/LocationStylingDTO.php';
    require __DIR__ . '/../wordpress/wp-content/themes/dt-the7-child/includes/cart-payment-layout.php';

    function is_cart() { global $case; return $case['cart']; }
    function has_block( $name ) { global $case; return 'woocommerce/cart' === $name && $case['block']; }
    function is_admin() { global $case; return $case['admin']; }
    function wp_doing_ajax() { global $case; return $case['ajax']; }
    function wp_script_is( $handle, $status ) { global $case; return in_array( "$handle:$status", $case['scripts'], true ); }
    function update_option() { throw new \RuntimeException( 'Saved settings must not be changed.' ); }
    function _doing_it_wrong() { throw new \RuntimeException( 'Incorrect native registry use.' ); }
    function check( $result, $message ) { if ( ! $result ) throw new \RuntimeException( $message ); }

    final class FixtureMethod implements \Automattic\WooCommerce\Blocks\Payments\PaymentMethodTypeInterface {
        private $name;
        private $handle;
        private $active;
        public function __construct( $name, $handle, $active = true ) { $this->name = $name; $this->handle = $handle; $this->active = $active; }
        public function get_name() { return $this->name; }
        public function initialize() {}
        public function is_active() { return $this->active; }
        public function get_payment_method_script_handles() { return array( $this->handle ); }
        public function get_payment_method_script_handles_for_admin() { return array( $this->handle ); }
        public function get_payment_method_data() { return array( 'id' => $this->name, 'native' => true ); }
        public function get_supported_features() { return array( 'products' ); }
        public function get_script_handles() { return $this->get_payment_method_script_handles(); }
        public function get_editor_script_handles() { return $this->get_payment_method_script_handles_for_admin(); }
        public function get_script_data() { return array(); }
    }
    final class WC_Payments {
        public static function get_express_checkout_helper() {
            global $case;
            return $case['helper'] ? new class {
                public function get_enabled_express_checkout_methods_for_context() { global $case; return $case['methods']; }
            } : null;
        }
        public static function get_gateway() {
            global $case;
            return $case['gateway'] ? new class {
                public function is_payment_request_enabled() { global $case; return $case['main_payment_request']; }
            } : false;
        }
        public static function get_payment_gateway_by_id( $id ) {
            global $split_lookups;
            $split_lookups++;
            return new class {
                public function is_available_for_express_checkout() { return false; }
            };
        }
    }
    $defaults = array( 'cart' => true, 'block' => true, 'admin' => false, 'ajax' => false, 'scripts' => array(), 'method' => 'GET', 'helper' => true, 'methods' => array( 'payment_request' ), 'gateway' => true, 'main_payment_request' => true, 'wcpay_active' => true, 'wcpay_registered' => true, 'late_data' => false, 'paypal_registered' => true );
    $cases = array(
        'cart-configured' => array( array(), true ),
        'head' => array( array( 'method' => 'HEAD' ), true ),
        'checkout-or-product' => array( array( 'cart' => false ), false ),
        'classic-cart' => array( array( 'block' => false ), false ),
        'admin' => array( array( 'admin' => true ), false ),
        'ajax' => array( array( 'ajax' => true ), false ),
        'post' => array( array( 'method' => 'POST' ), false ),
        'payment-request-not-enabled-on-cart' => array( array( 'methods' => array( 'amazon_pay' ) ), false ),
        'main-native-wallet-enabled-split-lookup-unavailable' => array( array(), true ),
        'main-native-wallet-disabled' => array( array( 'main_payment_request' => false ), false ),
        'main-gateway-unavailable' => array( array( 'gateway' => false ), false ),
        'helper-unavailable' => array( array( 'helper' => false ), false ),
        'native-express-enabled-card-block-inactive' => array( array( 'wcpay_active' => false ), true ),
        'native-express-enabled-card-registry-key-absent' => array( array( 'wcpay_registered' => false ), true ),
        'already-enqueued' => array( array( 'scripts' => array( 'ppcp-googlepay-block:enqueued' ) ), false ),
        'already-printed' => array( array( 'scripts' => array( 'ppcp-googlepay-block:done' ) ), false ),
        'frontend-assets-registered-only' => array( array( 'scripts' => array( 'wc-cart-block-frontend:registered' ) ), true ),
        'frontend-assets-already-enqueued' => array( array( 'scripts' => array( 'wc-cart-block-frontend:enqueued' ) ), false ),
        'frontend-assets-already-printed' => array( array( 'scripts' => array( 'wc-cart-block-frontend:done' ) ), false ),
        'payment-data-already-collected' => array( array( 'late_data' => true ), false ),
        'v6-without-v5-paypal-integration' => array( array( 'paypal_registered' => false ), true ),
    );
    $mode=$argv[1]??'normal';
    if ( in_array($mode,array('rest','cli'),true) ) {
        define($mode==='rest'?'REST_REQUEST':'WP_CLI',true);
        $cases=array($mode=>array(array(),false));
    }
    foreach ( $cases as $label => $spec ) {
        $case = array_replace( $defaults, $spec[0] );
        $split_lookups = 0;
        $_SERVER['REQUEST_METHOD'] = $case['method'];
        $registry = new \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry();
        $paypal = new FixtureMethod( 'ppcp-gateway', 'ppcp-checkout-block' );
        $wcpay = new FixtureMethod( 'woocommerce_payments', 'WCPAY_BLOCKS_CHECKOUT', $case['wcpay_active'] );
        $other = new FixtureMethod( 'unrelated', 'unrelated-native' );
        $registry->register( $paypal );
        if ( $case['wcpay_registered'] ) $registry->register( $wcpay );
        $registry->register( $other );
        if ( $case['paypal_registered'] ) $registry->register( new FixtureMethod( 'ppcp-googlepay', 'ppcp-googlepay-block' ) );
        \Automattic\WooCommerce\Blocks\Package::$fixture_container = new class( $registry ) {
            private $registry;
            public function __construct( $registry ) { $this->registry = $registry; }
            public function get( $name ) {
                if ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class === $name ) return $this->registry;
                return new class { public function exists( $key ) { global $case; check( 'paymentMethodData' === $key, 'Native data timing guard.' ); return $case['late_data']; } };
            }
        };
        Petish_Cart_Payment_Layout::select_google_pay_provider();
        check( $split_lookups === 0, $label . ': native express selection ignores split regular-checkout availability.' );
        $expected = $case['paypal_registered'] && ! $spec[1];
        check( $registry->is_registered( 'ppcp-googlepay' ) === $expected, $label . ': request-local provider selection.' );
        check( $registry->get_registered( 'ppcp-gateway' ) === $paypal && $registry->get_registered( 'woocommerce_payments' ) === ( $case['wcpay_registered'] ? $wcpay : null ) && $registry->get_registered( 'unrelated' ) === $other, $label . ': other native integration identity unchanged.' );
        $deps = $registry->get_all_active_payment_method_script_dependencies();
        $data = $registry->get_all_registered_script_data()['paymentMethodData'];
        check( in_array( 'ppcp-googlepay-block', $deps, true ) === $expected && isset( $data['ppcp-googlepay'] ) === $expected, $label . ': native dependency and data collected together.' );
        check( isset( $data['ppcp-gateway'] ) && in_array( 'ppcp-checkout-block', $deps, true ), $label . ': PayPal/Pay in 4 stay native.' );
        foreach ( array( 'cart', 'cart-block', 'product', 'mini_cart', 'express_checkout', 'classic_checkout' ) as $location ) {
            $styles = new \WooCommerce\PayPalCommerce\Settings\DTO\LocationStylingDTO( $location, true, array( 'ppcp-gateway', 'ppcp-googlepay', 'ppcp-applepay' ) );
            $filtered = Petish_Cart_Payment_Layout::paypal_google_pay_styles( $styles );
            $cart_selected = $spec[1] && in_array($location,array('cart','cart-block'),true);
            check( in_array( 'ppcp-googlepay', $filtered->methods, true ) === ! $cart_selected, $label . ': only Cart Google Pay display DTO changes.' );
            check( in_array( 'ppcp-googlepay', $styles->methods, true ), $label . ': original native DTO remains untouched.' );
            check( $filtered->enabled && in_array( 'ppcp-gateway', $filtered->methods, true ) && in_array( 'ppcp-applepay', $filtered->methods, true ), $label . ': PayPal, Apple Pay and location-enabled state unchanged.' );
            if ( ! $cart_selected ) check( $styles === $filtered, $label . ': other locations preserve DTO identity.' );
        }
    }
    echo 'PASS: ' . count( $cases ) . " native registry/provider cases; only Cart display integration selected; dependencies/data consistent; no saved settings or gateway changes.\n";
}
