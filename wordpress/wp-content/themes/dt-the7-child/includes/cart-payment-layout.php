<?php
/** Cart Block presentation and request-local Google Pay display provider. */
defined( 'ABSPATH' ) || exit;

final class Petish_Cart_Payment_Layout {
    private static $wcpay_google_pay_selected = false;

    public static function boot() {
        add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 45 );
        // Query/cart are ready; precede PayPal SDK v6's native wp10 style gate.
        add_action( 'wp', array( __CLASS__, 'select_google_pay_provider' ), 1 );
        add_filter( 'woocommerce_paypal_payments_googlepay_button_styles', array( __CLASS__, 'paypal_google_pay_styles' ), 30 );
    }

    private static function applies() {
        return function_exists( 'is_cart' ) && is_cart()
            && function_exists( 'has_block' ) && has_block( 'woocommerce/cart' );
    }

    public static function body_class( $classes ) {
        if ( self::applies() ) {
            $classes[] = 'petish-cart-payment-layout';
        }
        return $classes;
    }

    /**
     * Use WooPayments for Cart Google Pay when that merchant integration is
     * configured and available. The native provider still checks the browser.
     * This removes one Blocks display integration from this HTML request only;
     * no saved option, Woo gateway or payment-processing endpoint is changed.
     */
    public static function select_google_pay_provider() {
        self::$wcpay_google_pay_selected = false;
        if ( ! self::applies() || is_admin() || wp_doing_ajax()
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ( defined( 'WP_CLI' ) && WP_CLI )
            || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true )
            || ! class_exists( 'WC_Payments' )
            || ! is_callable( array( 'WC_Payments', 'get_express_checkout_helper' ) )
            || ! is_callable( array( 'WC_Payments', 'get_payment_gateway_by_id' ) )
            || ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
            return;
        }
        try {
            $helper = WC_Payments::get_express_checkout_helper();
            $google_pay = WC_Payments::get_payment_gateway_by_id( 'google_pay' );
            if ( ! is_object( $helper ) || ! is_callable( array( $helper, 'get_enabled_express_checkout_methods_for_context' ) )
                || ! in_array( 'payment_request', $helper->get_enabled_express_checkout_methods_for_context(), true )
                || ! is_object( $google_pay ) || ! is_callable( array( $google_pay, 'is_available_for_express_checkout' ) )
                || ! $google_pay->is_available_for_express_checkout() ) {
                return;
            }
            $container = \Automattic\WooCommerce\Blocks\Package::container();
            $registry = $container->get( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class );
            $assets = $container->get( \Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry::class );
            $wcpay = $registry->get_registered( 'woocommerce_payments' );
            if ( ! $wcpay || ! $wcpay->is_active()
                || $assets->exists( 'paymentMethodData' )
                || wp_script_is( 'ppcp-googlepay-block', 'enqueued' )
                || wp_script_is( 'ppcp-googlepay-block', 'done' )
                || wp_script_is( 'wc-cart-block-frontend', 'enqueued' )
                || wp_script_is( 'wc-cart-block-frontend', 'done' ) ) {
                // Unusual early rendering: preserve the native configuration.
                return;
            }
            if ( $registry->is_registered( 'ppcp-googlepay' ) ) {
                $registry->unregister( 'ppcp-googlepay' );
            }
            self::$wcpay_google_pay_selected = true;
        } catch ( \Throwable $error ) {
            // Unknown provider/API versions preserve native payment availability.
            return;
        }
    }

    /** SDK v6 reads this native Cart-only display DTO; leave other locations. */
    public static function paypal_google_pay_styles( $styles ) {
        if ( ! self::$wcpay_google_pay_selected || ! is_object( $styles )
            || ! in_array( $styles->location ?? '', array( 'cart', 'cart-block' ), true )
            || ! isset( $styles->methods ) || ! is_array( $styles->methods ) ) {
            return $styles;
        }
        $cart_styles = clone $styles;
        $cart_styles->methods = array_values( array_diff( $cart_styles->methods, array( 'ppcp-googlepay' ) ) );
        return $cart_styles;
    }

    public static function enqueue() {
        if ( ! self::applies() ) {
            return;
        }
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        foreach ( array( 'cart-payment-layout', 'cart-typography' ) as $name ) {
            $path = '/assets/css/' . $name . '.css';
            wp_enqueue_style( 'petish-' . $name, $uri . $path, array( 'petish-design' ), substr( hash_file( 'sha256', $dir . $path ), 0, 16 ) );
        }
        $path = '/assets/js/cart-payment-layout.js';
        wp_enqueue_script( 'petish-cart-payment-layout', $uri . $path, array( 'wp-data' ), substr( hash_file( 'sha256', $dir . $path ), 0, 16 ), array( 'strategy' => 'defer', 'in_footer' => true ) );
    }
}
