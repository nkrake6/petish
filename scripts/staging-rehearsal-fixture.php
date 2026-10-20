<?php
/** Transient, exact-customer staging fixture. Never deploy to production. */
defined('ABSPATH') || exit;
define('PETISH_REHEARSAL_CONFIG_FILE', '__PETISH_REHEARSAL_CONFIG_PATH__');

function petish_rehearsal_fixture_config() {
    static $loaded = false, $config = false;
    if ($loaded) return $config;
    $loaded = true;
    if (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'staging'
        || !defined('PETISH_STAGING_GUARD_ACTIVE') || !PETISH_STAGING_GUARD_ACTIVE) return false;
    $path = PETISH_REHEARSAL_CONFIG_FILE;
    if (!is_string($path) || !str_starts_with($path, '/') || is_link($path) || realpath($path) !== $path) return false;
    $stat = @lstat($path);
    if (!$stat || !is_file($path) || ($stat['mode'] & 07777) !== 0600 || $stat['nlink'] !== 1
        || (fileperms(dirname($path)) & 07777) !== 0700) return false;
    $value = json_decode((string)@file_get_contents($path), true);
    if (!is_array($value) || !is_string($value['run_id'] ?? null)
        || !preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D', $value['run_id'])
        || !is_int($value['customer_id'] ?? null) || $value['customer_id'] < 1
        || ($value['variation_id'] ?? null) !== 246 || ($value['quantity'] ?? null) !== 1
        || ($value['country'] ?? null) !== 'AU' || ($value['postcode'] ?? null) !== '3083'
        || ($value['currency'] ?? null) !== 'AUD' || ($value['shipping_ex_tax'] ?? null) !== 6
        || !is_int($value['started'] ?? null) || !is_int($value['expires'] ?? null)
        || $value['started'] > time() || $value['expires'] <= time()) return false;
    foreach (['email_events', 'stock_events'] as $key) {
        $file = $value[$key] ?? null;
        if (!is_string($file) || dirname($file) !== dirname($path) || is_link($file)
            || realpath($file) !== $file || !is_file($file) || (fileperms($file) & 07777) !== 0600) return false;
    }
    $config = $value;
    return $config;
}

function petish_rehearsal_fixture_identity($config) {
    if (!$config || !class_exists('WC_Payments') || !WC_Payments::mode()->is_dev()
        || !WC_Payments::mode()->is_test() || get_woocommerce_currency() !== 'AUD') return false;
    $user_id = get_current_user_id();
    if ($user_id === $config['customer_id']) return true;
    // Store API cookie authentication can leave the WordPress principal unset
    // while WooCommerce has resolved its native signed cart-session customer.
    // This fallback never overrides a different authenticated WordPress user.
    if ($user_id !== 0 || !function_exists('WC')) return false;
    $woocommerce = WC();
    $customer = is_object($woocommerce) ? ($woocommerce->customer ?? null) : null;
    return is_a($customer, 'WC_Customer') && (int)$customer->get_id() === $config['customer_id'];
}

function petish_rehearsal_fixture_package($package, $config) {
    if (!is_array($package['contents'] ?? null) || count($package['contents']) !== 1
        || ($package['destination']['country'] ?? '') !== 'AU'
        || preg_replace('/\s+/', '', (string)($package['destination']['postcode'] ?? '')) !== '3083') return false;
    $line = reset($package['contents']);
    return is_array($line) && (int)($line['variation_id'] ?? 0) === 246
        && (int)($line['product_id'] ?? 0) === 242 && (float)($line['quantity'] ?? 0) === 1.0;
}

function petish_rehearsal_fixture_label($config) {
    return 'STAGING SIMULATION [' . $config['run_id'] . '] — no actual carrier';
}

function petish_rehearsal_fixture_rates($rates, $package) {
    $config = petish_rehearsal_fixture_config();
    if (!petish_rehearsal_fixture_identity($config) || !petish_rehearsal_fixture_package($package, $config)) return $rates;
    $id = 'petish_rehearsal_shipping:' . $config['run_id'];
    $tax = WC_Tax::calc_shipping_tax(6, WC_Tax::get_shipping_tax_rates());
    $rate = new WC_Shipping_Rate($id, petish_rehearsal_fixture_label($config), 6, $tax, 'petish_rehearsal_shipping', 0);
    $rate->add_meta_data('_petish_rehearsal_run', $config['run_id']);
    return [$id => $rate];
}
add_filter('woocommerce_package_rates', 'petish_rehearsal_fixture_rates', PHP_INT_MAX, 2);

function petish_rehearsal_fixture_package_cache($packages) {
    $config = petish_rehearsal_fixture_config();
    if (!petish_rehearsal_fixture_identity($config)) return $packages;
    foreach ($packages as &$package) {
        if (petish_rehearsal_fixture_package($package, $config)) $package['petish_rehearsal_run'] = $config['run_id'];
    }
    unset($package);
    return $packages;
}
add_filter('woocommerce_cart_shipping_packages', 'petish_rehearsal_fixture_package_cache', PHP_INT_MAX, 1);

function petish_rehearsal_fixture_order($order, $config, $require_tag = true) {
    if (!$config || !is_a($order, 'WC_Order') || (int)$order->get_customer_id() !== $config['customer_id']
        || !$order->get_date_created() || $order->get_date_created()->getTimestamp() < $config['started']) return false;
    if ($order->get_shipping_country() !== 'AU'
        || preg_replace('/\s+/', '', (string)$order->get_shipping_postcode()) !== '3083'
        || abs((float)$order->get_shipping_total() - 6.0) > 0.001
        || count($order->get_items('fee')) !== 0 || count($order->get_coupon_codes()) !== 0) return false;
    $items = $order->get_items('line_item');
    if (count($items) !== 1) return false;
    $item = reset($items);
    if ((int)$item->get_variation_id() !== 246 || (int)$item->get_product_id() !== 242
        || (float)$item->get_quantity() !== 1.0) return false;
    $shipping = $order->get_items('shipping');
    if (count($shipping) !== 1) return false;
    $method = reset($shipping);
    if ($method->get_method_id() !== 'petish_rehearsal_shipping'
        || $method->get_name() !== petish_rehearsal_fixture_label($config)) return false;
    return !$require_tag || $order->get_meta('_petish_rehearsal_run', true) === $config['run_id'];
}

function petish_rehearsal_fixture_tag($order) {
    $config = petish_rehearsal_fixture_config();
    if (petish_rehearsal_fixture_order($order, $config, false)) {
        $order->update_meta_data('_petish_rehearsal_run', $config['run_id']);
        $order->save();
    }
}
add_action('woocommerce_checkout_create_order', 'petish_rehearsal_fixture_tag', PHP_INT_MAX, 1);
add_action('woocommerce_checkout_order_created', 'petish_rehearsal_fixture_tag', PHP_INT_MAX, 1);
add_action('woocommerce_store_api_checkout_update_order_from_request', 'petish_rehearsal_fixture_tag', PHP_INT_MAX, 1);
add_action('woocommerce_store_api_checkout_order_processed', 'petish_rehearsal_fixture_tag', PHP_INT_MAX, 1);

function petish_rehearsal_fixture_append($path, $record) {
    if (is_link($path) || !is_file($path) || (fileperms($path) & 07777) !== 0600) return;
    @file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
}

function petish_rehearsal_fixture_email($headers, $email_id, $order) {
    $config = petish_rehearsal_fixture_config();
    if (!petish_rehearsal_fixture_order($order, $config)
        || !in_array($email_id, ['new_order', 'failed_order', 'customer_failed_order', 'customer_processing_order',
            'customer_note', 'customer_completed_order', 'customer_refunded_order'], true)) return $headers;
    petish_rehearsal_fixture_append($config['email_events'], ['run_id' => $config['run_id'],
        'order_id' => (int)$order->get_id(), 'email_id' => $email_id, 'at' => time()]);
    $extra = ['X-Petish-Rehearsal: ' . $config['run_id'], 'X-Petish-Rehearsal-Order: ' . $order->get_id(),
        'X-Petish-Rehearsal-Email: ' . $email_id];
    if (is_array($headers)) return array_merge($headers, $extra);
    return rtrim((string)$headers, "\r\n") . "\r\n" . implode("\r\n", $extra) . "\r\n";
}
add_filter('woocommerce_email_headers', 'petish_rehearsal_fixture_email', PHP_INT_MAX, 3);

function petish_rehearsal_fixture_snapshot($order, $event) {
    $config = petish_rehearsal_fixture_config();
    if (!petish_rehearsal_fixture_order($order, $config)) return;
    $product = wc_get_product(246);
    $reduced = null;
    if (is_callable([$order->get_data_store(), 'get_stock_reduced'])) $reduced = (bool)$order->get_data_store()->get_stock_reduced($order);
    petish_rehearsal_fixture_append($config['stock_events'], ['run_id' => $config['run_id'],
        'order_id' => (int)$order->get_id(), 'event' => $event,
        'stock' => $product ? $product->get_stock_quantity() : null, 'stock_reduced' => $reduced, 'at' => time()]);
}
function petish_rehearsal_fixture_status($order_id, $old, $new, $order) {
    if (in_array($new, ['pending', 'failed', 'processing', 'completed', 'refunded'], true)) petish_rehearsal_fixture_snapshot($order, $new);
}
add_action('woocommerce_order_status_changed', 'petish_rehearsal_fixture_status', PHP_INT_MAX, 4);
function petish_rehearsal_fixture_paid($order_id) {
    petish_rehearsal_fixture_snapshot(wc_get_order($order_id), 'payment_complete');
}
add_action('woocommerce_payment_complete', 'petish_rehearsal_fixture_paid', PHP_INT_MAX, 1);
