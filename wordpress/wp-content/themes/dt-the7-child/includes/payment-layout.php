<?php
/** Product control placement and product/Cart native message appearance. */
defined( 'ABSPATH' ) || exit;

final class Petish_Product_Payment_Layout {
    const PAYPAL_HOOK = 'petish_product_paypal_controls';

    public static function boot() {
        // PayPal Payments 4.1.3 uses these filters for both SDK v5 and v6.
        add_filter( 'woocommerce_paypal_payments_single_product_renderer_hook', array( __CLASS__, 'paypal_hook' ) );
        add_filter( 'woocommerce_paypal_payments_product_messages_renderer_hook', array( __CLASS__, 'paypal_hook' ) );
        add_filter( 'woocommerce_paypal_payments_product_messages_renderer_priority', array( __CLASS__, 'message_priority' ) );
        // The existing WooPayments native group renders at priority 1.
        add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'render_paypal' ), 2 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 41 );
        add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
    }

    public static function supported_product() {
        if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
            return false;
        }
        $product = wc_get_product( get_queried_object_id() );
        return $product && $product->is_type( array( 'simple', 'variable' ) ) && $product->is_purchasable() && $product->is_in_stock();
    }

    public static function paypal_hook( $hook ) {
        return self::supported_product() ? self::PAYPAL_HOOK : $hook;
    }

    public static function message_priority( $priority ) {
        // Buttons stay at the provider's native priority 31; its message follows.
        return self::supported_product() ? 40 : $priority;
    }

    public static function body_class( $classes ) {
        if ( self::supported_product() ) {
            $classes[] = 'petish-product-payment-groups';
        }
        return $classes;
    }

    public static function render_paypal() {
        if ( ! self::supported_product() || false === has_action( self::PAYPAL_HOOK ) ) {
            return;
        }
        // Execute exactly the callbacks registered by PayPal, with their own
        // availability checks. No replacement markup, SDK calls, or DOM moves.
        ob_start();
        do_action( self::PAYPAL_HOOK );
        $native = ob_get_clean();
        if ( '' === trim( $native ) ) {
            return;
        }
        echo '<section class="petish-product-paypal" aria-label="' . esc_attr__( 'PayPal payment options', 'petish' ) . '">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Native provider output, unmodified.
        echo $native;
        echo '</section>';
    }

    public static function assets() {
        $cart = function_exists( 'is_cart' ) && is_cart();
        if ( ! self::supported_product() && ! $cart ) {
            return;
        }
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        $dependencies = $cart ? array( 'petish-design' ) : array( 'petish-product-purchase' );
        wp_enqueue_style( 'petish-product-payment-groups', $uri . '/assets/css/payment-layout.css', $dependencies, substr( hash_file( 'sha256', $dir . '/assets/css/payment-layout.css' ), 0, 16 ) );
        if ( defined( 'WCPAY_PLUGIN_FILE' ) && is_readable( dirname( WCPAY_PLUGIN_FILE ) . '/assets/images/payment-methods/afterpay-badge.svg' ) ) {
            // Use the unchanged badge shipped by the active provider. A missing
            // asset adds no heading; no copied artwork or remote SDK is needed.
            $badge_url = plugins_url( 'assets/images/payment-methods/afterpay-badge.svg', WCPAY_PLUGIN_FILE );
            wp_add_inline_style( 'petish-product-payment-groups', ':root{--petish-afterpay-badge-image:url(' . wp_json_encode( esc_url_raw( $badge_url ) ) . ')}' );
        }
        // Restore native appearance before WooPayments initializes its message.
        // The script never handles provider payment/disclosure actions.
        wp_enqueue_script( 'petish-product-payment-groups', $uri . '/assets/js/payment-layout.js', array(), substr( hash_file( 'sha256', $dir . '/assets/js/payment-layout.js' ), 0, 16 ), false );
        // Cart React reads cached appearance in its initial useState. Supply
        // only the page scope so this head script can expire it before mount.
        wp_add_inline_script( 'petish-product-payment-groups', 'window.petishPaymentMessagePage = ' . wp_json_encode( $cart ? 'cart' : 'product' ) . ';', 'before' );
    }
}
