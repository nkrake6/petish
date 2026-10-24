<?php
/** Render the uploaded native The7 template at its WordPress/Woo boundaries. */
define( 'ABSPATH', '/offline-fixture/' );
define( 'DAY_IN_SECONDS', 86400 );
$vendor = getenv( 'PETISH_THE7_ROOT' ) ?: '/tmp/petish-related-vendor/dt-the7';
$woo = getenv( 'PETISH_WOOCOMMERCE_ROOT' ) ?: '/tmp/petish-fyp-repro/WordPress-7.1.3/wp-content/plugins/woocommerce';
if ( ! is_file( $vendor . '/woocommerce/single-product/related.php' ) || ! is_file( $woo . '/templates/loop/add-to-cart.php' ) ) {
    fwrite( STDERR, "Native The7 and WooCommerce sources are required to regenerate this fixture.\n" );
    exit( 2 );
}
function add_filter() {}
function add_action() {}
function is_admin() { return false; }
function get_stylesheet_directory_uri() { return '/theme'; }
function esc_attr( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function esc_html( $v ) { return htmlspecialchars( $v ); }
function esc_url( $v ) { return $v; }
function __( $v, $domain = '' ) { return $v; }
function number_format_i18n( $v ) { return number_format( $v ); }
function apply_filters( $name, $v, ...$unused ) { return $v; }
function wp_reset_postdata() {}
function wc_review_ratings_enabled() { return false; }
function wc_implode_html_attributes( $values ) {
    $output = array();
    foreach ( $values as $name => $value ) { $output[] = esc_attr( $name ) . '="' . esc_attr( $value ) . '"'; }
    return implode( ' ', $output );
}
function presscore_config() { static $config; return $config ?: ( $config = new class {
    function get( $name ) { return true; }
    function set( $name, $value ) {}
} ); }
require __DIR__ . '/../wordpress/wp-content/themes/dt-the7-child/includes/supplier-popularity.php';
class WC_Product {
    public $id;
    function __construct( $id ) { $this->id = $id; }
    function get_id() { return $this->id; }
    function get_name() { return array( 'Hay feeder', 'Woodland Rabbit Hideaway', 'Soft nest', 'Travel bowl', 'Enrichment Woodland Tunnel', 'Treat ball' )[ $this->id - 1 ]; }
    function get_permalink() { return '/product/fixture-' . $this->id . '/'; }
    function get_type() { return $this->id % 2 ? 'variable' : 'simple'; }
    function is_purchasable() { return true; }
    function is_in_stock() { return true; }
    function supports( $feature ) { return $this->get_type() === 'simple'; }
    function is_on_sale() { return false; }
    function get_meta( $name, ...$unused ) { return $name === '_petish_supplier_order_count' ? ( $this->id === 6 ? '0' : '900' ) : gmdate( 'Y-m-d' ); }
    function get_image() { return '<img class="attachment-woocommerce_thumbnail" src="/neutral-product.svg" width="100" height="100" alt="Neutral fixture image">' . petish_supplier_hot_badge( $this ); }
    function get_price_html() {
        if ( $this->id === 3 ) { return '<span class="woocommerce-Price-amount amount"><bdi>A$99,999.00</bdi></span> – <span class="woocommerce-Price-amount amount"><bdi>A$199,999.00</bdi></span>'; }
        if ( $this->id === 4 ) { return '<del><span class="amount"><bdi>A$40.00</bdi></span></del> <ins><span class="amount"><bdi>A$25.00</bdi></span></ins>'; }
        return '<span class="woocommerce-Price-amount amount"><bdi>A$12.00</bdi></span>';
    }
    function add_to_cart_url() { return $this->get_type() === 'simple' ? '/?add-to-cart=' . $this->id : $this->get_permalink(); }
    function add_to_cart_text() { return $this->get_type() === 'simple' ? 'Add to cart' : 'Select options'; }
}
function wc_get_product( $id ) { return new WC_Product( $id ); }
function woocommerce_template_loop_add_to_cart( $args ) {
    global $product;
    $args += array( 'quantity' => 1, 'attributes' => array(), 'aria-describedby_text' => '' );
    include $GLOBALS['woo'] . '/templates/loop/add-to-cart.php';
}
// Evaluate only this real, self-contained native helper, without theme bootstrap.
$source = file_get_contents( $vendor . '/inc/mods/compatibility/woocommerce/front/mod-wc-template-functions.php' );
$start = strpos( $source, 'function dt_woocommerce_get_product_add_to_cart_icon()' );
$brace = strpos( $source, '{', $start );
$depth = 1;
for ( $end = $brace + 1; $depth; $end++ ) { if ( $source[ $end ] === '{' ) $depth++; elseif ( $source[ $end ] === '}' ) $depth--; }
eval( substr( $source, $start, $end - $start ) );
$related_products = array_map( static function ( $id ) { return new WC_Product( $id ); }, range( 1, 6 ) );
include $vendor . '/woocommerce/single-product/related.php';
