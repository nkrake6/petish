#!/usr/bin/env bash
# Read only fixed payment settings; never bootstrap WordPress or contact a payment API.
set +x
set -euo pipefail
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
export LC_ALL=C
site_path=$HOME/domains/petish.biz/public_html
unavailable() {
    printf '{"status":"unavailable","category":"%s"}\n' "$1"
    exit 1
}
[[ -f $site_path/wp-config.php ]] || unavailable site_unavailable
command -v wp >/dev/null 2>&1 || unavailable wpcli_unavailable
command -v php >/dev/null 2>&1 || unavailable php_unavailable
if ! prefix=$(wp --path="$site_path" --no-color config get table_prefix --type=variable 2>/dev/null); then
    unavailable database_configuration
fi
[[ $prefix =~ ^[A-Za-z0-9_]+$ ]] || unavailable database_configuration
for constant in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
    if ! connection_value=$(wp --path="$site_path" --no-color config get "$constant" 2>/dev/null); then
        unavailable database_configuration
    fi
    printf -v "PETISH_$constant" '%s' "$connection_value"
    export "PETISH_$constant"
done
unset connection_value
export PETISH_DB_PREFIX=$prefix
PETISH_DB_CLIENT_FLAGS=0
if connection_flags=$(wp --path="$site_path" --no-color config get MYSQL_CLIENT_FLAGS 2>/dev/null); then
    PETISH_DB_CLIENT_FLAGS=$connection_flags
fi
export PETISH_DB_CLIENT_FLAGS
unset connection_flags

export PETISH_SITE_PATH=$site_path

php <<'PETISH_AFTERPAY'
<?php
// All option arrays remain in memory; only this fixed projection can leave SSH.
function petish_option_array($raw) {
    if (!is_string($raw) || strlen($raw) > 2097152) return [];
    $value = @unserialize($raw, ['allowed_classes' => false, 'max_depth' => 32]);
    return is_array($value) ? $value : [];
}
function petish_switch($value) {
    return in_array($value, ['yes', 'no'], true) ? $value : 'unknown';
}
function petish_code($value, $length) {
    return is_string($value) && preg_match('/\A[A-Za-z]{' . $length . '}\z/D', $value)
        ? strtoupper($value) : 'unknown';
}
function petish_project_afterpay($options) {
    $settings = petish_option_array($options['woocommerce_woocommerce_payments_settings'] ?? null);
    $split = petish_option_array($options['woocommerce_woocommerce_payments_afterpay_clearpay_settings'] ?? null);
    $plugins = petish_option_array($options['active_plugins'] ?? null);
    $cache = petish_option_array($options['wcpay_account_data'] ?? null);
    $account = isset($cache['data']) && is_array($cache['data']) ? $cache['data'] : [];
    $allowed = ['alipay','amazon_pay','apple_pay','sofort','giropay','google_pay','bancontact','eps','ideal','p24','card','sepa_debit','au_becs_debit','link','affirm','afterpay_clearpay','klarna','grabpay','jcb','multibanco','wechat_pay'];
    $methods = $settings['upe_enabled_payment_method_ids'] ?? [];
    $known = []; $unknown = false;
    if (is_array($methods)) {
        foreach ($methods as $method) {
            if (is_string($method) && in_array($method, $allowed, true)) $known[$method] = true;
            else $unknown = true;
        }
    } else $unknown = true;
    $known = array_keys($known); sort($known);
    $capability = $account['capabilities']['afterpay_clearpay_payments'] ?? 'missing';
    if (!in_array($capability, ['active','inactive','pending','unrequested','missing'], true)) $capability = 'unknown';
    $country = $options['woocommerce_default_country'] ?? '';
    $country = is_string($country) ? explode(':', $country, 2)[0] : '';
    $fetched = $cache['fetched'] ?? null;
    $age = is_int($fetched) && $fetched > 0 && $fetched <= time() ? min(315360000, time() - $fetched) : -1;
    $afterpayPlugin = false;
    foreach ($plugins as $plugin) {
        if (is_string($plugin) && preg_match('~\A(?:afterpay|clearpay)[a-z0-9_-]*/[a-z0-9_.-]+\.php\z~D', $plugin)) $afterpayPlugin = true;
    }
    return [
        'status' => 'success',
        'store_country' => petish_code($country, 2),
        'store_currency' => petish_code($options['woocommerce_currency'] ?? null, 3),
        'checkout_page_content' => 'missing',
        'active_plugins' => [
            'woocommerce_payments' => in_array('woocommerce-payments/woocommerce-payments.php', $plugins, true),
            'woocommerce_stripe' => in_array('woocommerce-gateway-stripe/woocommerce-gateway-stripe.php', $plugins, true),
            'afterpay_plugin' => $afterpayPlugin,
            'woocommerce_paypal_payments' => in_array('woocommerce-paypal-payments/woocommerce-paypal-payments.php', $plugins, true),
        ],
        'wcpay' => [
            'installed_version' => 'unknown',
            'settings_present' => array_key_exists('woocommerce_woocommerce_payments_settings', $options),
            'enabled' => petish_switch($settings['enabled'] ?? null),
            'test_mode' => petish_switch($settings['test_mode'] ?? null),
            'manual_capture' => petish_switch($settings['manual_capture'] ?? null),
            'enabled_methods' => $known,
            'enabled_methods_setting_present' => array_key_exists('upe_enabled_payment_method_ids', $settings),
            'unknown_method_present' => $unknown,
            'afterpay_gateway_setting_present' => array_key_exists('woocommerce_woocommerce_payments_afterpay_clearpay_settings', $options),
            'afterpay_gateway_enabled' => petish_switch($split['enabled'] ?? null),
            'afterpay_gateway_method_selected' => isset($split['upe_enabled_payment_method_ids']) && is_array($split['upe_enabled_payment_method_ids'])
                ? (in_array('afterpay_clearpay', $split['upe_enabled_payment_method_ids'], true) ? 'yes' : 'no') : 'unknown',
        ],
        'cached_account' => [
            'present' => !empty($account),
            'country' => petish_code($account['country'] ?? null, 2),
            'default_currency' => petish_code($account['store_currencies']['default'] ?? null, 3),
            'payments_enabled' => is_bool($account['payments_enabled'] ?? null) ? $account['payments_enabled'] : null,
            'afterpay_capability' => $capability,
            'afterpay_requirements_present' => !empty($account['capability_requirements']['afterpay_clearpay_payments']),
            'age_seconds' => $age,
            'errored' => is_bool($cache['errored'] ?? null) ? $cache['errored'] : null,
        ],
        'source_matches_11_2_0' => ['definition'=>false,'gateway'=>false,'method'=>false,'messaging'=>false],
    ];
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);
$category = 'database_driver';
try {
    if (!function_exists('mysqli_init')) throw new RuntimeException();
    $category = 'database_configuration';
    $prefix = getenv('PETISH_DB_PREFIX');
    if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix)) throw new RuntimeException();
    $host = getenv('PETISH_DB_HOST'); $socket = null; $port = 3306;
    if (!is_string($host) || $host === '' || preg_match('/[\x00-\x20]/', $host)) throw new RuntimeException();
    $offset = strpos($host, ':/');
    if ($offset !== false) { $socket = substr($host, $offset + 1); $host = substr($host, 0, $offset); }
    if (preg_match('/\A\[([^\]]+)\](?::([0-9]+))?\z/D', $host, $parts)) {
        if (!filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) throw new RuntimeException();
        $host = $parts[1]; $port = isset($parts[2]) ? (int)$parts[2] : 3306;
    } elseif (preg_match('/\A([A-Za-z0-9_.-]+):([0-9]+)\z/D', $host, $parts)) {
        $host = $parts[1]; $port = (int)$parts[2];
    } elseif (!preg_match('/\A[A-Za-z0-9_.-]+\z/D', $host) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) throw new RuntimeException();
    if ($port < 1 || $port > 65535) throw new RuntimeException();
    $setting = trim((string)getenv('PETISH_DB_CLIENT_FLAGS'));
    if (preg_match('/\A[0-9]+\z/D', $setting)) $flags = (int)$setting;
    elseif ($setting === 'MYSQLI_CLIENT_SSL' && defined('MYSQLI_CLIENT_SSL')) $flags = MYSQLI_CLIENT_SSL;
    else throw new RuntimeException();
    if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT') && ($flags & MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT)) throw new RuntimeException();
    $category = 'database_connection';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = mysqli_init();
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (defined('MYSQLI_CLIENT_SSL') && ($flags & MYSQLI_CLIENT_SSL)) $database->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    $database->real_connect($host, getenv('PETISH_DB_USER'), getenv('PETISH_DB_PASSWORD'), getenv('PETISH_DB_NAME'), $port, $socket, $flags);
    $database->set_charset('utf8mb4');
    $category = 'settings_lookup';
    // Fixed keys only. Never select customers, orders, account IDs or API keys separately.
    // The projection suppresses every unlisted field inside serialized payment options.
    $names = [
        'active_plugins', 'woocommerce_default_country', 'woocommerce_currency',
        'woocommerce_woocommerce_payments_settings',
        'woocommerce_woocommerce_payments_afterpay_clearpay_settings', 'wcpay_account_data',
        'woocommerce_checkout_page_id',
    ];
    $quoted = "'" . implode("','", $names) . "'";
    $rows = $database->query("SELECT option_name, option_value FROM `{$prefix}options` WHERE option_name IN ($quoted)");
    $options = []; $bytes = 0;
    while ($row = $rows->fetch_assoc()) {
        $bytes += strlen($row['option_value']);
        if ($bytes > 4194304 || strlen($row['option_value']) > 2097152 || !in_array($row['option_name'], $names, true)) throw new RuntimeException();
        $options[$row['option_name']] = $row['option_value'];
    }
    $rows->free();
    $category = 'safe_projection';
    $report = petish_project_afterpay($options);
    // Only the configured, published checkout page is inspected; its text never leaves SSH.
    $checkoutId = $options['woocommerce_checkout_page_id'] ?? '';
    if (is_string($checkoutId) && preg_match('/\A[1-9][0-9]{0,14}\z/D', $checkoutId)) {
        $checkout = $database->query("SELECT post_content FROM `{$prefix}posts` WHERE ID=" . (int)$checkoutId
            . " AND post_type='page' AND post_status='publish' AND post_password='' LIMIT 1");
        $page = $checkout->fetch_assoc();
        if (is_array($page) && is_string($page['post_content']) && strlen($page['post_content']) <= 131072) {
            $block = preg_match('/<!--\s+wp:woocommerce\/checkout(?:\s|\/|-->)/', $page['post_content']) === 1;
            $classic = preg_match('/\[woocommerce_checkout(?:\s|\])/', $page['post_content']) === 1;
            $report['checkout_page_content'] = $block ? ($classic ? 'mixed' : 'block') : ($classic ? 'classic' : 'other');
        }
        $checkout->free();
    }
    $database->close();
    unset($options);
    $root = (string)getenv('PETISH_SITE_PATH') . '/wp-content/plugins/woocommerce-payments';
    $rootReal = realpath($root);
    if (is_string($rootReal) && is_dir($rootReal) && !is_link($root)) {
        $headerPath = $root . '/woocommerce-payments.php';
        if (is_file($headerPath) && !is_link($headerPath) && str_starts_with((string)realpath($headerPath), $rootReal . '/')) {
            $header = file_get_contents($headerPath, false, null, 0, 8192);
            if (is_string($header) && preg_match('/^[ \t*]*Version:[ \t]*([0-9]{1,3}(?:\.[0-9]{1,3}){1,3})[ \t]*$/mi', $header, $match)) $report['wcpay']['installed_version'] = $match[1];
        }
        $references = [
            'definition' => ['includes/payment-methods/Configs/Definitions/AfterpayDefinition.php', '9c53c3bacbd304188a68205ba40ff4be5e242cca74bd108e323bbbfaf8e86fd8'],
            'gateway' => ['includes/class-wc-payment-gateway-wcpay.php', '823e002f2a9c5dc85f72ec70a2ac8099daf95bc749717073423234479b560493'],
            'method' => ['includes/payment-methods/class-upe-payment-method.php', '633a7fd43accb670be9fba0e91b674bb01f209c0aeafbf1b29e7821c3c4534ea'],
            'messaging' => ['includes/class-wc-payments-payment-method-messaging-element.php', '1bcea29323f87efb80959f9ca4be43df7e0efb621a9b2209aab24275a415ce57'],
        ];
        foreach ($references as $key => [$relative, $expected]) {
            $file = $root . '/' . $relative;
            if (is_file($file) && !is_link($file) && str_starts_with((string)realpath($file), $rootReal . '/') && filesize($file) < 1048576) {
                $report['source_matches_11_2_0'][$key] = hash_equals($expected, hash_file('sha256', $file));
            }
        }
    }
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    echo json_encode(['status'=>'unavailable', 'category'=>$category], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
PETISH_AFTERPAY
