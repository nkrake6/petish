<?php
/** Explicit staging-only channel provisioning; preserves original discussions. */
if (PHP_SAPI !== 'cli') exit(2);
define('PETISH_CLUB_VERIFY_LIBRARY', true);
require_once __DIR__ . '/verify-pet-club.php';
umask(0077); ini_set('display_errors', '0'); ini_set('log_errors', '0'); error_reporting(0);
$report = array('status' => 'failure', 'category' => 'bootstrap', 'checks' => array());
ob_start();
$phase = 'bootstrap'; $next = null; $transaction = false; $flag_override = null;
function stream_setup_check($name, $value) { global $report; $report['checks'][$name] = $value === true; if (!$value) throw new RuntimeException($name); }
function stream_setup_query($sql) {
    global $phase, $next, $wpdb;
    if ($next !== null && $sql === $next) { $next = null; return $sql; }
    if (preg_match('/\A\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql) && !preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql) && !preg_match('/;\s*\S/', rtrim($sql))) return $sql;
    $tables = isset($wpdb) ? array($wpdb->prefix . 'petish_chat_stream_messages_v1', $wpdb->prefix . 'petish_chat_stream_reactions_v1') : array();
    foreach ($tables as $table) {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) throw new RuntimeException('table');
        if ($phase === 'schema' && preg_match('/\ACREATE TABLE `?' . preg_quote($table, '/') . '`?\s*\(/i', $sql)) return $sql;
        if ($phase === 'import' && preg_match('/\AINSERT INTO `' . preg_quote($table, '/') . '`\s*\(/i', $sql)) return $sql;
    }
    if ($phase === 'bootstrap') return 'SELECT 0 WHERE 1=0';
    throw new RuntimeException('unexpected_write');
}
function stream_setup_once($sql) { global $wpdb, $next; $next = $sql; $out = $wpdb->query($sql); if ($out === false || $next !== null || $wpdb->last_error !== '') throw new RuntimeException('sql'); return $out; }
try {
    require_once getenv('HOME') . '/domains/staging.petish.biz/public_html/wp-includes/plugin.php';
    add_filter('query', 'stream_setup_query', PHP_INT_MAX);
    add_action('all', static function($hook) { if ($hook === 'query') { remove_filter('query', 'stream_setup_query', PHP_INT_MAX); add_filter('query', 'stream_setup_query', PHP_INT_MAX); } }, PHP_INT_MAX);
    $root = petish_club_verify_bootstrap('staging', false);
    $phase = 'read';
    stream_setup_check('staging_guard_and_test_payments', defined('PETISH_STAGING_GUARD_ACTIVE') && PETISH_STAGING_GUARD_ACTIVE && defined('WCPAY_DEV_MODE') && WCPAY_DEV_MODE);
    $manifest = petish_club_verify_read_json(__DIR__ . '/source-manifest.json');
    $plugin = $root . '/wp-content/plugins/petish-passport'; $actual = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin, FilesystemIterator::SKIP_DOTS)) as $file) { if (!petish_club_verify_path($file->getPathname())) throw new RuntimeException('source'); $actual[substr($file->getPathname(), strlen($plugin) + 1)] = hash_file('sha256', $file->getPathname()); }
    ksort($manifest); ksort($actual); stream_setup_check('exact_reviewed_plugin_source', $manifest === $actual);
    stream_setup_check('complete_stream_module_loaded', class_exists('Petish_Chat_Stream_UI') && class_exists('Petish_Chat_Stream_Media') && class_exists('Petish_Chat_Stream'));
    stream_setup_check('legacy_chat_already_enabled', get_option('petish_chat_enabled_v1', 'no') === 'yes');
    $before = petish_club_verify_state($root);
    $old_option = get_option(Petish_Chat_Stream::OPTION_ENABLED, null);
    stream_setup_check('expected_flag_state', in_array($old_option, array(null, 'no', 'yes'), true));
    $staff = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 1));
    stream_setup_check('existing_staff_principal_found', count($staff) === 1); wp_set_current_user((int) $staff[0]);
    $phase = 'schema'; stream_setup_check('explicit_schema_installed', Petish_Chat_Stream::install_schema() === true); $phase = 'read';
    foreach (array($wpdb->options, $wpdb->prefix . 'petish_chat_stream_messages_v1', $wpdb->prefix . 'petish_chat_stream_reactions_v1') as $table) {
        stream_setup_check('transactional_' . substr(hash('sha256', $table), 0, 8), $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $table)) === 'InnoDB');
        stream_setup_check('no_triggers_' . substr(hash('sha256', $table), 0, 8), (string) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s AND EVENT_OBJECT_TABLE=%s', DB_NAME, $table)) === '0');
    }
    stream_setup_once('START TRANSACTION'); $transaction = true;
    $flag = Petish_Chat_Stream::OPTION_ENABLED;
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_id,option_value FROM `{$wpdb->options}` WHERE option_name=%s FOR UPDATE", $flag), ARRAY_A);
    if (!$row) stream_setup_once($wpdb->prepare("INSERT INTO `{$wpdb->options}` (`option_name`,`option_value`,`autoload`) VALUES (%s,%s,%s)", $flag, 'yes', 'off'));
    elseif ($row['option_value'] === 'no') stream_setup_once($wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value=%s WHERE option_id=%d AND option_value=%s", 'yes', $row['option_id'], 'no'));
    // Do not place an uncommitted flag in any cache. Service checks use this
    // process-only override until the transaction has been committed.
    $flag_override = static fn() => 'yes';
    add_filter('pre_option_' . $flag, $flag_override, PHP_INT_MAX);
    stream_setup_check('stream_enabled_on_staging', Petish_Chat_Stream::enabled());
    $legacy = Petish_Chat_Stream::inspect_legacy((int) $staff[0]); stream_setup_check('legacy_snapshot_reviewed', !is_wp_error($legacy));
    $phase = 'import'; $import = Petish_Chat_Stream::import_legacy((int) $staff[0], $legacy['source_sha256']); $phase = 'read';
    stream_setup_check('legacy_import_conflict_free', !is_wp_error($import) && !$import['conflicts']);
    stream_setup_check('legacy_source_unchanged', Petish_Chat_Stream::inspect_legacy((int) $staff[0])['source_sha256'] === $legacy['source_sha256']);
    $after = petish_club_verify_state($root);
    foreach (array('orders', 'accounts', 'products', 'passports', 'photos') as $key) stream_setup_check($key . '_unchanged', $before[$key] === $after[$key]);
    $omit = static fn($rows) => array_values(array_filter($rows, static fn($row) => $row['option_name'] !== Petish_Chat_Stream::OPTION_ENABLED));
    stream_setup_check('other_settings_unchanged', $omit($before['settings_rows']) === $omit($after['settings_rows']));
    wp_set_current_user(0); $guest = Petish_Chat_Stream::state('general');
    stream_setup_check('guest_projection_is_public_only', !is_wp_error($guest) && !array_filter($guest['messages'], static fn($row) => $row['status'] !== 'published'));
    stream_setup_once('COMMIT'); $transaction = false;
    $report['checks']['transaction_committed'] = true;
    remove_filter('pre_option_' . $flag, $flag_override, PHP_INT_MAX); $flag_override = null;
    wp_cache_delete($flag, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
    stream_setup_check('committed_flag_read_back', Petish_Chat_Stream::enabled());
    $report['status'] = 'success'; $report['category'] = 'complete';
} catch (Throwable $error) {
    if ($transaction) { try { stream_setup_once('ROLLBACK'); } catch (Throwable $ignored) {} }
    if ($flag_override !== null) remove_filter('pre_option_' . Petish_Chat_Stream::OPTION_ENABLED, $flag_override, PHP_INT_MAX);
    if (class_exists('Petish_Chat_Stream')) { wp_cache_delete(Petish_Chat_Stream::OPTION_ENABLED, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options'); }
    $report['category'] = $phase;
}
ob_end_clean(); echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
exit($report['status'] === 'success' ? 0 : 1);
