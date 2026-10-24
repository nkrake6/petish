<?php
define( 'ABSPATH', '/fixture/' );
// Minimal priority-aware hooks; app callbacks and native fixture output remain real.
$GLOBALS['payment_hooks'] = [];
function add_filter($hook,$callback,$priority=10,$accepted=1){$GLOBALS['payment_hooks'][$hook][$priority][]=[$callback,$accepted];return true;}
function add_action($hook,$callback,$priority=10,$accepted=1){return add_filter($hook,$callback,$priority,$accepted);}
function apply_filters($hook,$value,...$args){$priorities=$GLOBALS['payment_hooks'][$hook]??[];ksort($priorities);foreach($priorities as $callbacks)foreach($callbacks as [$callback,$accepted])$value=$callback(...array_slice([$value,...$args],0,$accepted));return $value;}
function do_action($hook,...$args){$priorities=$GLOBALS['payment_hooks'][$hook]??[];ksort($priorities);foreach($priorities as $callbacks)foreach($callbacks as [$callback,$accepted])$callback(...array_slice($args,0,$accepted));}
function has_action($hook){$priorities=$GLOBALS['payment_hooks'][$hook]??[];ksort($priorities);return $priorities?array_key_first($priorities):false;}
function get_stylesheet_directory(){return __DIR__.'/../wordpress/wp-content/themes/dt-the7-child';}
function get_stylesheet_directory_uri(){return 'https://offline.invalid/theme';}
$GLOBALS['payment_assets']=[];
$GLOBALS['payment_inline']=[];
function wp_enqueue_style(...$args){$GLOBALS['payment_assets'][]=['style',$args];}
function wp_enqueue_script(...$args){$GLOBALS['payment_assets'][]=['script',$args];}
function wp_add_inline_script(...$args){$GLOBALS['payment_inline'][]=$args;}
function wp_json_encode($value){return json_encode($value);}
$context = $argv[1] ?? 'product';
function is_product() { return $GLOBALS['context'] !== 'cart'; }
function is_cart() { return $GLOBALS['context'] === 'cart'; }
function get_queried_object_id() { return 1; }
function wc_get_product( $id = null ) { return new class {
    function is_type( $types ) { return in_array( 'variable', $types, true ); }
    function is_purchasable() { return true; }
    function is_in_stock() { return $GLOBALS['context'] !== 'out-of-stock'; }
}; }
function esc_attr__( $text, $domain ) { return htmlspecialchars( $text ); }
require get_stylesheet_directory() . '/includes/payment-layout.php';
Petish_Product_Payment_Layout::boot();

// These providers' callbacks remain the owners of their native wrappers. The
// fixture uses neutral iframe labels, never substitute branded payment controls.
$buttons_hook = apply_filters( 'woocommerce_paypal_payments_single_product_renderer_hook', 'woocommerce_single_product_summary' );
$messages_hook = apply_filters( 'woocommerce_paypal_payments_product_messages_renderer_hook', 'woocommerce_single_product_summary' );
$message_priority = apply_filters( 'woocommerce_paypal_payments_product_messages_renderer_priority', 30 );
add_action( $buttons_hook, function () {
    if ( $GLOBALS['context'] !== 'provider-unavailable' ) {
        echo '<div class="ppc-button-wrapper"><div id="ppc-button-ppcp-gateway"><iframe title="Native PayPal control fixture"></iframe><iframe title="Native Pay Later control fixture"></iframe></div></div>';
    }
}, 31 );
add_action( $messages_hook, function () {
    if ( $GLOBALS['context'] !== 'provider-unavailable' ) {
        echo '<div class="ppcp-messages"><iframe title="Native PayPal message fixture"></iframe></div>';
    }
}, $message_priority );
add_action( 'woocommerce_after_add_to_cart_form', function () {
    echo '<section class="petish-product-payments" aria-label="Payment options"><div class="wcpay-express-checkout-wrapper"><div id="wcpay-express-checkout-element"><iframe title="Native wallet control fixture"></iframe></div></div><div id="payment-method-message"><iframe title="Native installment message fixture"></iframe></div></section>';
}, 1 );
add_action( 'woocommerce_single_product_summary', function () {
    echo '<form class="cart variations_form"><div class="quantity"><input value="1" aria-label="Quantity"></div><button type="button">Add to cart fixture</button></form>';
    do_action( 'woocommerce_after_add_to_cart_form' );
}, 30 );

ob_start();
do_action( 'woocommerce_single_product_summary' );
$html = ob_get_clean();
Petish_Product_Payment_Layout::assets();
echo json_encode( array( 'assets'=>$GLOBALS['payment_assets'], 'inline'=>$GLOBALS['payment_inline'], 'html' => $html, 'hook' => $buttons_hook, 'message_hook' => $messages_hook, 'message_priority' => $message_priority, 'classes' => apply_filters( 'body_class', array() ) ), JSON_THROW_ON_ERROR );
