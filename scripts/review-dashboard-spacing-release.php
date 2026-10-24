<?php
/** Read-only library for the three-file Passport dashboard layout release. */
if (PHP_SAPI !== 'cli') exit(1);

const PETISH_REVIEW_SPACING_FILES = array(
    'wp-content/plugins/petish-passport/includes/class-petish-customer-reviews-ui.php' => '932c47ed580dca1e0db77dfa5e6b64f63d1cc08eb5988dd1d8ef418776818dc1',
    'wp-content/plugins/petish-passport/templates/customer-reviews-dashboard.php' => '1e42b1f98d7cbf16550720183a232b13625884ca8ade08e37467a6e01cc1cef0',
    'wp-content/plugins/petish-passport/assets/customer-reviews.css' => '8de4b0253b926cccf7f7170b144ea35844f4fcd51a65d7a797dcbffe508e97c3',
);
const PETISH_REVIEW_SPACING_BASELINES = array(
    'wp-content/plugins/petish-passport/includes/class-petish-customer-reviews-ui.php' => '262968f8272b3307568946ee72a0418e29d3a7a0990603705058a2d1fbed3d43',
    'wp-content/plugins/petish-passport/templates/customer-reviews-dashboard.php' => 'ed0702f68e94bb7ac6c102629b6c6b63d3ab570a2b13040906954893fece54d7',
    'wp-content/plugins/petish-passport/assets/customer-reviews.css' => 'b4159fb54cd1776b0cb9dad644b4d8655c02d01c2c9354bf4a633ed2ead23668',
);

function petish_review_spacing_files() { return PETISH_REVIEW_SPACING_FILES; }
function petish_review_spacing_baselines() { return PETISH_REVIEW_SPACING_BASELINES; }

/** Canonical ordinary paths only; never follow a link to select source. */
function petish_review_spacing_path($path, $directory = false) {
    if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) return false;
    $cursor = '';
    foreach (explode('/', substr($path, 1)) as $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
        $cursor .= '/' . $part;
        if (is_link($cursor) || realpath($cursor) !== $cursor) return false;
    }
    $info = @lstat($path);
    return $info && ($directory ? is_dir($path) : is_file($path) && $info['nlink'] === 1);
}

/** Checks exact candidate files without changing source, options or review flags. */
function petish_review_spacing_source($root) {
    if (!petish_review_spacing_path($root, true)) return false;
    foreach (PETISH_REVIEW_SPACING_FILES as $name => $hash) {
        $path = $root . '/' . $name;
        if (!petish_review_spacing_path($path) || filesize($path) > 1048576 || !hash_equals($hash, hash_file('sha256', $path))) return false;
        if (str_ends_with($name, '.php')) {
            try { token_get_all(file_get_contents($path), TOKEN_PARSE); }
            catch (Throwable $ignored) { return false; }
        }
    }
    return true;
}

/** Verify the actual empty dashboard, never real customer metrics or records. */
function petish_review_spacing_native_render($root) {
    if (!class_exists('Petish_Customer_Reviews_UI', false) || !class_exists('Petish_Customer_Reviews', false)
        || !class_exists('WP_HTML_Processor', false)
        || (new ReflectionClass('Petish_Customer_Reviews_UI'))->getFileName() !== $root . '/wp-content/plugins/petish-passport/includes/class-petish-customer-reviews-ui.php') return false;
    $data = array(
        'metrics' => array('published'=>0,'pending'=>0,'withdrawn'=>0,'helpful'=>0,'not_helpful'=>0,'qualifying'=>0,'tier'=>Petish_Customer_Reviews::tier(0)),
        'reviews' => array('items'=>array(),'page'=>1,'pages'=>1),
        'product' => false, 'search' => '', 'products' => array(), 'status' => 'published', 'values' => array(),
    );
    ob_start();
    try { Petish_Customer_Reviews_UI::template('dashboard', $data); $html = ob_get_contents(); }
    finally { ob_end_clean(); }
    if (!is_string($html) || $html === '' || strlen($html) > 131072) return false;
    $processor = WP_HTML_Processor::create_fragment($html);
    if (!$processor) return false;
    $main_depth = null; $composer = false; $reviews = false;
    while ($processor->next_tag()) {
        if ($processor->has_class('pcr-studio-main')) $main_depth = $processor->get_current_depth();
        if ($processor->get_attribute('id') === 'pcr-composer') {
            if ($main_depth === null || $processor->get_current_depth() !== $main_depth + 1) return false;
            $composer = true;
        }
        if ($processor->get_attribute('id') === 'pcr-my-reviews') {
            if (!$composer || $processor->get_current_depth() !== $main_depth + 1) return false;
            $reviews = true;
        }
        if ($processor->has_class('pcr-sidebar')) return $composer && $reviews && $processor->get_current_depth() === $main_depth;
    }
    return false;
}

/** Use a private request-local registry; do not pollute other verification assets. */
function petish_review_spacing_asset_version($root) {
    if (!class_exists('WP_Styles', false) || !class_exists('WP_Scripts', false)
        || !class_exists('Petish_Customer_Reviews_UI', false)) return false;
    $had_styles = array_key_exists('wp_styles', $GLOBALS); $styles = $GLOBALS['wp_styles'] ?? null;
    $had_scripts = array_key_exists('wp_scripts', $GLOBALS); $scripts = $GLOBALS['wp_scripts'] ?? null;
    $account = static fn() => true;
    add_filter('woocommerce_is_account_page', $account, PHP_INT_MAX);
    try {
        $GLOBALS['wp_styles'] = new WP_Styles(); $GLOBALS['wp_scripts'] = new WP_Scripts();
        Petish_Customer_Reviews_UI::enqueue();
        $asset = $GLOBALS['wp_styles']->registered['petish-customer-reviews'] ?? null;
        return is_object($asset) && $asset->ver === substr(hash_file('sha256', $root . '/wp-content/plugins/petish-passport/assets/customer-reviews.css'), 0, 16);
    } finally {
        remove_filter('woocommerce_is_account_page', $account, PHP_INT_MAX);
        if ($had_styles) $GLOBALS['wp_styles'] = $styles; else unset($GLOBALS['wp_styles']);
        if ($had_scripts) $GLOBALS['wp_scripts'] = $scripts; else unset($GLOBALS['wp_scripts']);
    }
}

/** The caller owns the isolated WordPress bootstrap and before/after DB snapshots. */
function petish_review_spacing_verify($root) {
    $checks = array('review_spacing_source_verified'=>false,'review_spacing_native_render_verified'=>false,'review_spacing_asset_version_verified'=>false);
    try {
        $checks['review_spacing_source_verified'] = petish_review_spacing_source($root);
        if (!$checks['review_spacing_source_verified']) return $checks;
        $checks['review_spacing_native_render_verified'] = petish_review_spacing_native_render($root);
        $checks['review_spacing_asset_version_verified'] = petish_review_spacing_asset_version($root);
    } catch (Throwable $ignored) { /* Only bounded booleans leave this library. */ }
    return $checks;
}
