<?php
/** Request-local AST evaluation; never activates a plugin in the database. */
function petish_tracking_bootstrap($root, $private, $run, $mark, $definitions) {
    if (PHP_SAPI !== 'cli' || ini_get('allow_url_fopen') || !is_callable($mark)) rehearsal_fail();
    $plugin = $root . '/wp-content/plugins/woo-advanced-shipment-tracking';
    $marker = $plugin . '/.petish-tracking-rehearsal-owned';
    $manifest_path = __DIR__ . '/ast-manifest.json';
    if (!rehearsal_path($plugin, true) || !rehearsal_path($marker)
        || (fileperms($marker) & 07777) !== 0600 || trim(file_get_contents($marker)) !== $run) rehearsal_fail();
    $manifest = rehearsal_private_json($manifest_path);
    if (count($manifest) < 10 || count($manifest) > 10000) rehearsal_fail();
    $seen = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if ($path === $marker) continue;
        if (!rehearsal_path($path) || $file->getSize() > 10485760) rehearsal_fail();
        $name = substr($path, strlen($plugin) + 1);
        if (!isset($manifest[$name]) || !is_string($manifest[$name])
            || !preg_match('/\A[a-f0-9]{64}\z/D', $manifest[$name])
            || !hash_equals($manifest[$name], hash_file('sha256', $path))) rehearsal_fail();
        $seen[] = $name;
    }
    sort($seen); $names = array_keys($manifest); sort($names);
    if ($seen !== $names || ($manifest['woocommerce-advanced-shipment-tracking.php'] ?? '')
        !== 'e642a8751e25fef8986abb9fd2441a514313dd003c54afc69ccdd48fc83f506c') rehearsal_fail();
    $mark('tracking_reviewed_source_verified', true);
    $mark('tracking_direct_remote_files_blocked', true);
    $baseline = ['petish-fulfilment/petish-fulfilment.php','petish-passport/petish-passport.php',
        'woocommerce/woocommerce.php','woocommerce-payments/woocommerce-payments.php']; sort($baseline);
    $basename = 'woo-advanced-shipment-tracking/woocommerce-advanced-shipment-tracking.php';
    add_filter('option_active_plugins', static function($plugins) use ($baseline, $basename) {
        if (!is_array($plugins)) rehearsal_fail();
        $actual = $plugins; sort($actual);
        if ($actual !== $baseline) rehearsal_fail();
        $plugins[] = $basename;
        return $plugins;
    }, PHP_INT_MAX);
    // These are per-request option values. No activation, upgrades, carrier
    // downloads, telemetry, or migration of cloned order data is necessary.
    $options = [
        'wc_advanced_shipment_tracking'=>'4.5', 'ast_option_migrated'=>true,
        'ast_general_settings'=>[
            'wc_ast_status_partial_shipped'=>1, 'wcast_enable_partial_shipped_email'=>1,
            'wc_ast_status_updated_tracking'=>0, 'wc_ast_status_shipped'=>0, 'wc_ast_enable_log'=>0,
            'wc_ast_unclude_tracking_info'=>['completed'=>1,'partial-shipped'=>1],
        ],
        'woocommerce_customer_partial_shipped_order_settings'=>['enabled'=>'yes','email_type'=>'html'],
        'customizer_partial_shipped_order_settings_enabled'=>1,
        'tracking_info_settings'=>['fluid_hide_provider_image'=>1],
        'ast_enable_usage_data'=>0, 'ast_optin_email_notification'=>0, 'ast_usage_data_selector'=>1,
    ];
    foreach ($options as $name=>$value) add_filter('pre_option_'.$name, static function() use ($value) { return $value; }, PHP_INT_MAX);
    $owned_table = null; $fixture_id = null;
    $mark('tracking_carrier_fixture_removed', false);
    $cleanup = static function() use (&$owned_table, &$fixture_id, $mark) {
        if ($owned_table === null) return;
        global $wpdb;
        // Drop only the newly created fixture table, and only if its complete
        // contents are still the one owned synthetic row.
        $rows = $wpdb->get_results("SELECT id,ts_slug,provider_url FROM `{$owned_table}`", ARRAY_A);
        if (!is_array($rows) || !(count($rows) === 0 && $fixture_id === null)
            && !(count($rows) === 1 && (int)$rows[0]['id'] === $fixture_id
                && $rows[0]['ts_slug'] === 'petish-rehearsal'
                && $rows[0]['provider_url'] === 'https://tracking.petish.invalid/parcel/%number%')) rehearsal_fail();
        if ($wpdb->query("DROP TABLE `{$owned_table}`") === false) rehearsal_fail();
        $owned_table = null;
        $mark('tracking_carrier_fixture_removed', true);
    };
    register_shutdown_function(static function() use ($cleanup) { try { $cleanup(); } catch (Throwable $ignored) {} });
    add_action('plugins_loaded', static function() use (&$owned_table, &$fixture_id, $mark, $definitions) {
        global $wpdb;
        if (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'staging'
            || !defined('PETISH_STAGING_GUARD_ACTIVE') || PETISH_STAGING_GUARD_ACTIVE !== true
            || !defined('DB_NAME') || DB_NAME !== $definitions['DB_NAME']
            || !defined('DB_HOST') || DB_HOST !== $definitions['DB_HOST']
            || !class_exists('WC_Advanced_Shipment_Tracking_Install')) rehearsal_fail();
        $install = WC_Advanced_Shipment_Tracking_Install::get_instance();
        remove_action('init', [$install,'update_database_check']);
        remove_all_actions('ast_insert_shipping_provider');
        remove_all_actions('zorem_usage_data_ast');
        $table = $wpdb->prefix.'woo_shippment_provider';
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) rehearsal_fail();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== null) rehearsal_fail();
        // Schema creation only: the normal installer also schedules downloads
        // and writes a legacy migration marker, so it is deliberately unused.
        $owned_table = $table;
        $install->create_shippment_tracking_table();
        if ($wpdb->insert($table, [
            'provider_name'=>'Petish staging carrier','ts_slug'=>'petish-rehearsal',
            'provider_url'=>'https://tracking.petish.invalid/parcel/%number%',
            'shipping_country'=>'Global','shipping_country_name'=>'Global',
            'shipping_default'=>1,'display_in_order'=>1,'trackship_supported'=>0,
        ]) !== 1) rehearsal_fail();
        $fixture_id = (int)$wpdb->insert_id;
        $mark('tracking_carrier_fixture_created', $fixture_id > 0);
        $mark('tracking_migrations_and_downloads_disabled', true);
    }, PHP_INT_MIN + 1);
    return $cleanup;
}
