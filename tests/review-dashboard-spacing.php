<?php
/** Actual dashboard and WordPress HTML parser, with isolated presentation registries. */
$core = $argv[1] ?? '';
if (!is_file($core . '/wp-includes/html-api/class-wp-html-processor.php')) exit(2);
define('ABSPATH', rtrim($core, '/') . '/');
spl_autoload_register(static function($name) use ($core) {
    if (!str_starts_with($name, 'WP_HTML_')) return;
    $file = $core . '/wp-includes/html-api/class-' . strtolower(str_replace('_', '-', $name)) . '.php';
    if (is_file($file)) require_once $file;
});
class_exists('WP_HTML_Processor');
function __($value, $domain = '') { return $value; }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function number_format_i18n($value) { return number_format($value); }
function _doing_it_wrong() { throw new RuntimeException('Unexpected HTML parser usage.'); }
function wc_get_page_permalink($name) { return 'https://example.invalid/my-account/'; }
function add_query_arg($args, $base) { return $base . '?' . http_build_query($args); }
function plugin_dir_url($file) { return 'https://example.invalid/wp-content/plugins/petish-passport/'; }
function is_product() { return false; }
function is_account_page() { return apply_filters('woocommerce_is_account_page', false); }
$GLOBALS['spacing_filters'] = array();
function add_filter($name, $callback, $priority = 10) { $GLOBALS['spacing_filters'][$name][$priority][] = $callback; }
function remove_filter($name, $callback, $priority = 10) {
    foreach ($GLOBALS['spacing_filters'][$name][$priority] ?? array() as $key=>$value) if ($value === $callback) unset($GLOBALS['spacing_filters'][$name][$priority][$key]);
    if (empty($GLOBALS['spacing_filters'][$name][$priority])) unset($GLOBALS['spacing_filters'][$name][$priority]);
    if (empty($GLOBALS['spacing_filters'][$name])) unset($GLOBALS['spacing_filters'][$name]);
}
function apply_filters($name, $value) {
    foreach ($GLOBALS['spacing_filters'][$name] ?? array() as $callbacks) foreach ($callbacks as $callback) $value = $callback($value);
    return $value;
}
class WP_Styles { public $registered = array(); }
class WP_Scripts { public $registered = array(); }
function wp_style_is($name, $state) { return false; }
function wp_enqueue_style($name, $source, $deps, $version) { $GLOBALS['wp_styles']->registered[$name] = (object)array('ver'=>$version); }
function wp_enqueue_script($name, $source, $deps, $version, $footer) { $GLOBALS['wp_scripts']->registered[$name] = (object)array('ver'=>$version); }
function get_option() { throw new RuntimeException('No settings reads required by this fixture.'); }
function update_option() { throw new RuntimeException('No feature flag or settings write allowed.'); }
function delete_option() { throw new RuntimeException('No settings write allowed.'); }
function wp_insert_post() { throw new RuntimeException('No records may be created.'); }
function wc_create_refund() { throw new RuntimeException('No payment operation permitted.'); }
function wp_mail() { throw new RuntimeException('No email permitted.'); }
$root = dirname(__DIR__) . '/wordpress';
require $root . '/wp-content/plugins/petish-passport/includes/class-petish-customer-reviews.php';
require $root . '/wp-content/plugins/petish-passport/includes/class-petish-customer-reviews-ui.php';
require dirname(__DIR__) . '/scripts/review-dashboard-spacing-release.php';
$styles = new WP_Styles(); $scripts = new WP_Scripts();
$GLOBALS['wp_styles'] = $styles; $GLOBALS['wp_scripts'] = $scripts;
$result = petish_review_spacing_verify($root);
$result['presentation_registries_restored'] = $GLOBALS['wp_styles'] === $styles && $GLOBALS['wp_scripts'] === $scripts;
$result['request_filters_restored'] = $GLOBALS['spacing_filters'] === array();
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
exit(in_array(false, $result, true) ? 1 : 0);
