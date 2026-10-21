<?php
/** Small cart quantity badge, hydrated separately from cacheable headers. */
defined( 'ABSPATH' ) || exit;

final class Petish_Cart_Badge {
    public static function boot() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 45 );
        add_action( 'wc_ajax_petish_cart_count', array( __CLASS__, 'count' ) );
    }

    public static function assets() {
        if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_AJAX' ) || is_admin() ) {
            return;
        }
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        wp_enqueue_style( 'petish-cart-badge', $uri . '/assets/css/cart-badge.css', array( 'petish-design' ), filemtime( $dir . '/assets/css/cart-badge.css' ) );
        wp_enqueue_script( 'petish-cart-badge', $uri . '/assets/js/cart-badge.js', array( 'jquery' ), filemtime( $dir . '/assets/js/cart-badge.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
        // Shared configuration only: no cart count, customer details, or nonce.
        wp_add_inline_script( 'petish-cart-badge', 'window.petishCartBadge = ' . wp_json_encode( array(
            'endpoint' => WC_AJAX::get_endpoint( 'petish_cart_count' ),
            'cartUrl'  => wc_get_cart_url(),
        ) ) . ';', 'before' );
    }

    private static function same_origin() {
        if ( 'cross-site' === strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) ) ) {
            return false;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( ! is_string( $origin ) || '' === $origin ) {
            return '' === $origin;
        }
        $actual = wp_parse_url( $origin );
        $expected = wp_parse_url( home_url() );
        return is_array( $actual ) && isset( $actual['scheme'], $actual['host'] )
            && strtolower( $actual['scheme'] ) === strtolower( $expected['scheme'] )
            && strtolower( $actual['host'] ) === strtolower( $expected['host'] )
            && (int) ( $actual['port'] ?? ( 'https' === $actual['scheme'] ? 443 : 80 ) ) === (int) ( $expected['port'] ?? ( 'https' === $expected['scheme'] ? 443 : 80 ) );
    }

    /** Read the current Woo session's quantity; never perform a cart mutation. */
    public static function count() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'Vary: Cookie', false );
        header( 'X-Content-Type-Options: nosniff' );
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::same_origin() ) {
            wp_send_json_error( array( 'message' => 'Cart quantity could not be loaded.' ), 403 );
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => 'Cart quantity is unavailable.' ), 503 );
        }
        // WooCommerce has already initialized this visitor's cart for wc-ajax.
        // Its native session reconciliation also applies, as with mini-cart reads.
        $count = max( 0, (int) WC()->cart->get_cart_contents_count() );
        wp_send_json_success( array(
            'count' => $count,
            'label' => sprintf( _n( '%s item in cart', '%s items in cart', $count, 'petish' ), number_format_i18n( $count ) ),
        ) );
    }
}

Petish_Cart_Badge::boot();
