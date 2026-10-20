<?php
/**
 * Plugin Name: Petish Fulfilment
 * Description: Automatic destination delivery, reviewed supplier quotes and private delivery cost previews.
 * Version: 0.5.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: petish-fulfilment
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-petish-quote-currency.php';
require_once __DIR__ . '/includes/class-petish-supplier-quotes.php';
require_once __DIR__ . '/includes/class-petish-shipping-quote-requests.php';
Petish_Shipping_Quote_Requests::init();
require_once __DIR__ . '/includes/class-petish-delivery-cost-sheet.php';
require_once __DIR__ . '/includes/class-petish-delivery-cost-sheet-admin.php';
Petish_Delivery_Cost_Sheet_Admin::init();

// WooCommerce's method base class is autoloadable after its plugins_loaded setup.
add_action('plugins_loaded', static function () {
    if (!class_exists('WC_Shipping_Method')) return;
    require_once __DIR__ . '/includes/class-petish-automatic-shipping.php';
    Petish_Automatic_Shipping::init();
}, 20);

add_action('woocommerce_shipping_init', static function () {
    require_once __DIR__ . '/includes/class-petish-supplier-shipping.php';
    require_once __DIR__ . '/includes/class-petish-automatic-shipping.php';
    Petish_Automatic_Shipping::init();
});
add_filter('woocommerce_shipping_methods', static function ($methods) {
    $methods['petish_supplier_shipping'] = 'Petish_Supplier_Shipping_Method';
    $methods['petish_automatic_shipping'] = 'Petish_Automatic_Shipping_Method';
    return $methods;
});

// Quote expiry and specification changes must invalidate Woo's session rate cache.
add_filter('woocommerce_cart_shipping_packages', array('Petish_Supplier_Quotes', 'cache_packages'), PHP_INT_MAX);
add_filter('woocommerce_shipping_packages', array('Petish_Supplier_Quotes', 'guard_cached_packages'), PHP_INT_MAX);
// Our native rate already contains the reviewed amount converted without retail rounding.
add_filter('wcpay_multi_currency_should_convert_shipping_amount', array('Petish_Quote_Currency', 'skip_native_shipping_conversion'), PHP_INT_MAX, 2);
add_filter('woocommerce_shipping_method_add_rate_args', array('Petish_Quote_Currency', 'rate_precision'), 901, 2);
