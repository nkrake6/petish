<?php
/* Dependency-free WordPress hook/enqueue boundary fixture. */
define( 'ABSPATH', __DIR__ );
$hooks = array();
$hook_priorities = array();
$assets = array();
$is_cart_page = true;
$is_cart_block = true;
function add_filter( $hook, $callback, $priority = 10 ) { global $hooks, $hook_priorities; $hooks[ $hook ] = $callback; $hook_priorities[ $hook ] = $priority; }
function add_action( $hook, $callback, $priority ) { add_filter( $hook, $callback, $priority ); }
function is_cart() { global $is_cart_page; return $is_cart_page; }
function has_block( $block ) { global $is_cart_block; return 'woocommerce/cart' === $block && $is_cart_block; }
function get_stylesheet_directory() { return __DIR__ . '/../wordpress/wp-content/themes/dt-the7-child'; }
function get_stylesheet_directory_uri() { return 'https://offline.invalid/theme'; }
function wp_enqueue_style( ...$args ) { global $assets; $assets[] = array( 'style', $args ); }
function wp_enqueue_script( ...$args ) { global $assets; $assets[] = array( 'script', $args ); }
function check( $result, $message ) { if ( ! $result ) { throw new RuntimeException( $message ); } }
require get_stylesheet_directory() . '/includes/cart-payment-layout.php';
Petish_Cart_Payment_Layout::boot();
check( array_keys( $hooks ) === array( 'body_class', 'wp_enqueue_scripts', 'wp', 'woocommerce_paypal_payments_googlepay_button_styles' ), 'Only presentation and native request-local display selection hooks registered.' );
check( $hook_priorities['wp'] === 1 && $hook_priorities['wp_enqueue_scripts'] === 45 && $hook_priorities['woocommerce_paypal_payments_googlepay_button_styles'] === 30, 'Select native provider after query and before either SDK registers its product renderer.' );
foreach ( array( array( true, true, true ), array( true, false, false ), array( false, true, false ), array( false, false, false ) ) as $case ) {
    list( $is_cart_page, $is_cart_block, $expected ) = $case;
    $assets = array();
    $classes = Petish_Cart_Payment_Layout::body_class( array( 'native' ) );
    Petish_Cart_Payment_Layout::enqueue();
    check( in_array( 'petish-cart-payment-layout', $classes, true ) === $expected, 'Body class only on Cart Block.' );
    check( count( $assets ) === ( $expected ? 3 : 0 ), 'Assets only on Cart Block.' );
    if ( $expected ) {
        check( $assets[2][1][2] === array( 'wp-data' ), 'Only public data API dependency; no native gateway dependencies changed.' );
        foreach ( $assets as $asset ) {
            $arguments = $asset[1];
            $relative = substr( $arguments[1], strlen( get_stylesheet_directory_uri() ) );
            $expected_version = substr( hash_file( 'sha256', get_stylesheet_directory() . $relative ), 0, 16 );
            check( is_string( $arguments[3] ) && preg_match( '/\A[a-f0-9]{16}\z/D', $arguments[3] ) === 1, 'Cart asset version is a finite content digest.' );
            check( $arguments[3] === $expected_version, 'Every Cart asset uses a content hash despite normalized archive timestamps.' );
        }
    }
}
echo "PASS: Cart Block-only body/assets; native request-local display selection; classic cart and other pages unchanged.\n";
