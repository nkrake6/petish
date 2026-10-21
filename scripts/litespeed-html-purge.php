<?php
/** A bounded, explicit public HTML cache purge, separate from read-only feature checks. */
if (PHP_SAPI !== 'cli') exit(1);

final class Petish_HTML_Purge {
    const QUEUE = 'litespeed.purge.queue';
    const QUEUE2 = 'litespeed.purge.queue2';
    private static $next_sql = null;
    private static $strict = false;
    private static $expected_header = null;
    private static $captured_header = null;
    private static function fail() { throw new RuntimeException('HTML cache purge check failed.'); }
    private static function mark($name, $valid) {
        global $report;
        $report['checks'][$name] = $valid === true;
        if ($valid !== true) self::fail();
    }
    private static function table() {
        global $wpdb;
        if (!is_string($wpdb->options) || !preg_match('/\A[A-Za-z0-9_]+\z/D', $wpdb->options)) self::fail();
        return '`' . $wpdb->options . '`';
    }
    /** The sole write permit is one exact statement, consumed once. */
    public static function guard($sql) {
        if (is_string($sql) && self::$next_sql !== null && hash_equals(self::$next_sql, $sql)) {
            self::$next_sql = null;
            return $sql;
        }
        if (is_string($sql) && strlen($sql) <= 8388608 && !str_contains($sql, "\0")
            && !preg_match('~/\*|--|\#~', $sql)
            && preg_match('/\A\s*(?:SELECT|SHOW|DESCRIBE|DESC)\b/i', $sql)
            && !preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $sql)
            && !str_contains(rtrim($sql, "; \r\n\t"), ';')) return $sql;
        if (self::$strict) self::fail();
        return 'SELECT 0 WHERE 1=0';
    }
    public static function no() { return false; }
    public static function yes() { return true; }
    /** Keep the outer write gate installed during shutdown without throwing from lazy hooks. */
    public static function finish() { self::$strict = false; self::$next_sql = null; }
    public static function last_guard($hook) {
        $filters = ['query' => [__CLASS__, 'guard'],
            'jetpack_sync_listener_should_load' => [__CLASS__, 'no'],
            'jetpack_sync_sender_should_load' => [__CLASS__, 'no'],
            'pre_option_jetpack_sync_settings_disable' => [__CLASS__, 'yes']];
        if (isset($filters[$hook])) {
            remove_filter($hook, $filters[$hook], PHP_INT_MAX);
            add_filter($hook, $filters[$hook], PHP_INT_MAX);
        }
        if ($hook === 'pre_update_option' && self::$expected_header !== null) {
            remove_filter($hook, [__CLASS__, 'capture'], PHP_INT_MAX);
            add_filter($hook, [__CLASS__, 'capture'], PHP_INT_MAX, 3);
        }
    }
    private static function execute($sql) {
        global $wpdb;
        if (self::$next_sql !== null) self::fail();
        self::$next_sql = $sql;
        try {
            $result = $wpdb->query($sql);
            if ($result === false || $wpdb->last_error !== '' || self::$next_sql !== null) self::fail();
            return $result;
        } finally { self::$next_sql = null; }
    }
    private static function rows($lock = false) {
        global $wpdb;
        $sql = $wpdb->prepare('SELECT option_id,option_name,option_value,autoload FROM ' . self::table()
            . ' WHERE option_name IN (%s,%s) ORDER BY option_name' . ($lock ? ' FOR UPDATE' : ''), self::QUEUE, self::QUEUE2);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '' || count($rows) !== 2) self::fail();
        $result = [];
        foreach ($rows as $row) {
            if (array_keys($row) !== ['option_id','option_name','option_value','autoload']
                || !is_string($row['option_id']) || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $row['option_id'])
                || !in_array($row['option_name'], [self::QUEUE, self::QUEUE2], true)
                || isset($result[$row['option_name']]) || !is_string($row['option_value'])
                || strlen($row['option_value']) > 16384 || !is_string($row['autoload'])
                || !in_array($row['autoload'], ['yes','no','on','off','auto','auto-on','auto-off'], true)) self::fail();
            $result[$row['option_name']] = $row;
        }
        return $result;
    }
    private static function idle($rows) {
        // Do not drain, discard, merge, or overwrite someone else's queued purge.
        self::mark('purge_queue_idle', $rows[self::QUEUE]['option_value'] === '-1');
        self::mark('purge_queue2_idle', $rows[self::QUEUE2]['option_value'] === '-1');
    }
    private static function transactional() {
        global $wpdb;
        $table = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->options)), ARRAY_A);
        $triggers = $wpdb->get_results($wpdb->prepare('SHOW TRIGGERS WHERE `Table` = %s', $wpdb->options), ARRAY_A);
        self::mark('purge_transactional_options', is_array($table) && strtoupper($table['Engine'] ?? '') === 'INNODB'
            && $triggers === [] && $wpdb->last_error === '');
    }
    /** Capture the native API's exact header without letting option hooks write anything. */
    public static function capture($value, $option, $old) {
        if ($option !== self::QUEUE || $old !== '-1' || self::$captured_header !== null
            || !is_string($value) || self::$expected_header === null || !hash_equals(self::$expected_header, $value)) self::fail();
        self::$captured_header = $value;
        return $old;
    }
    private static function native_header($scope) {
        if (!in_array($scope, ['site','delivery'], true) || !defined('LSWCP_TAG_PREFIX')
            || !is_string(LSWCP_TAG_PREFIX) || !preg_match('/\A[a-f0-9]{3}\z/D', LSWCP_TAG_PREFIX)) self::fail();
        $tag = $scope === 'site' ? '' : \LiteSpeed\Tag::get_uri_tag('/delivery-shipping/');
        if ($scope === 'delivery' && (!is_string($tag) || !preg_match('/\AURL\.[a-f0-9]{32}\z/D', $tag))) self::fail();
        self::$expected_header = 'X-LiteSpeed-Purge: public,' . LSWCP_TAG_PREFIX . '_' . $tag;
        self::$captured_header = null;
        // The request has no business executing site-specific purge hooks or changing scope.
        remove_all_filters('litespeed_purge_tags');
        remove_all_actions('litespeed_purged_link');
        add_filter('pre_update_option', [__CLASS__, 'capture'], PHP_INT_MAX, 3);
        try {
            if ($scope === 'site') {
                // Empty tag is the site's shared public-cache tag. It yields the same header
                // as add('*') on this single site, without '*' resetting the crawler queue.
                // It never calls purge_all(), CSS/JS deletion, object-cache or OPcache APIs.
                \LiteSpeed\Purge::add('');
            } else \LiteSpeed\Purge::cls()->purge_url('/delivery-shipping/', false, true);
            self::mark('purge_native_scope_verified', self::$captured_header === self::$expected_header);
            return self::$captured_header;
        } finally {
            remove_filter('pre_update_option', [__CLASS__, 'capture'], PHP_INT_MAX);
            self::$expected_header = null;
            self::$captured_header = null;
        }
    }
    private static function bootstrap() {
        $home = getenv('HOME');
        if (!petish_club_verify_path($home, true)) self::fail();
        $plugin = $home . '/domains/petish.biz/public_html/wp-includes/plugin.php';
        if (!petish_club_verify_path($plugin)) self::fail();
        require_once $plugin;
        foreach (['query' => 'guard', 'jetpack_sync_listener_should_load' => 'no',
            'jetpack_sync_sender_should_load' => 'no', 'pre_option_jetpack_sync_settings_disable' => 'yes'] as $hook => $method) {
            add_filter($hook, [__CLASS__, $method], PHP_INT_MAX);
        }
        add_action('all', [__CLASS__, 'last_guard'], PHP_INT_MAX);
        $root = petish_club_verify_bootstrap('production', false);
        $sync = 'Automattic\\Jetpack\\Sync\\Actions';
        self::mark('jetpack_sync_isolated', apply_filters('jetpack_sync_listener_should_load', true) === false
            && apply_filters('jetpack_sync_sender_should_load', true) === false
            && (!class_exists($sync) || ($sync::$listener === null && $sync::$sender === null)));
        global $report;
        $report['category'] = 'cache_runtime';
        self::mark('purge_runtime_verified', defined('LSCWP_V') && LSCWP_V === '7.9.1'
            && defined('LITESPEED_CLI') && LITESPEED_CLI === true && !is_multisite()
            && class_exists('LiteSpeed\\Purge') && class_exists('LiteSpeed\\Tag')
            && \LiteSpeed\Purge::name(\LiteSpeed\Purge::DB_QUEUE) === self::QUEUE
            && \LiteSpeed\Purge::name(\LiteSpeed\Purge::DB_QUEUE2) === self::QUEUE2
            && (new ReflectionClass('LiteSpeed\\Purge'))->getFileName() === $root . '/wp-content/plugins/litespeed-cache/src/purge.cls.php'
            // The queue CAS deliberately avoids native option hooks. Refuse a site where
            // HTTP could keep an old queue value in a persistent cache we did not invalidate.
            && !file_exists($root . '/wp-content/object-cache.php') && !is_link($root . '/wp-content/object-cache.php')
            && !file_exists($root . '/wp-content/advanced-cache.php') && !is_link($root . '/wp-content/advanced-cache.php')
            && (!defined('LSWCP_EMPTYCACHE') || LSWCP_EMPTYCACHE !== true));
        self::$strict = true;
    }
    private static function receipt_path($run, $scope, $operation, $create) {
        if (!petish_club_verify_run($run) || !in_array($scope, ['site','delivery'], true)
            || !in_array($operation, ['apply','rollback'], true)) self::fail();
        $dir = getenv('HOME') . '/.petish-html-purge';
        if (!file_exists($dir) && $create && !mkdir($dir, 0700)) self::fail();
        if (!petish_club_verify_path($dir, true) || (fileperms($dir) & 07777) !== 0700) self::fail();
        return $dir . '/' . $run . '-' . $scope . '-' . $operation . '.json';
    }
    private static function receipt($scope, $run, $operation, $allow_prepared = false) {
        $receipt = petish_club_verify_read_json(self::receipt_path($run, $scope, $operation, false));
        $expected = 'X-LiteSpeed-Purge: public,' . LSWCP_TAG_PREFIX . '_'
            . ($scope === 'delivery' ? 'URL.' . md5('/delivery-shipping/') : '');
        if (array_keys($receipt) !== ['run_id','scope','operation','state','before','queued_header']
            || $receipt['run_id'] !== $run || $receipt['scope'] !== $scope || $receipt['operation'] !== $operation
            || !in_array($receipt['state'], $allow_prepared ? ['prepared','committed'] : ['committed'], true)
            || $receipt['queued_header'] !== $expected
            || !is_array($receipt['before']) || array_keys($receipt['before']) !== [self::QUEUE, self::QUEUE2]
            || ($receipt['before'][self::QUEUE]['option_value'] ?? null) !== '-1'
            || ($receipt['before'][self::QUEUE2]['option_value'] ?? null) !== '-1') self::fail();
        return $receipt;
    }
    private static function save_receipt($path, $receipt, $replace = false) {
        petish_club_verify_write_json($path, $receipt, $replace);
        $handle = fopen($path, 'r+b');
        if (!$handle) self::fail();
        try {
            if (!flock($handle, LOCK_EX) || !fflush($handle) || !function_exists('fsync') || !fsync($handle)) self::fail();
        } finally { fclose($handle); }
        if (petish_club_verify_read_json($path) !== $receipt) self::fail();
    }
    public static function inspect() {
        self::bootstrap();
        self::transactional();
        self::idle(self::rows());
    }
    public static function queue($scope, $run, $operation) {
        global $wpdb, $report;
        self::bootstrap();
        self::transactional();
        $path = self::receipt_path($run, $scope, $operation, true);
        if (file_exists($path) || is_link($path)) self::fail();
        $report['category'] = 'cache_queue';
        $transaction = false;
        try {
            self::execute('START TRANSACTION'); $transaction = true;
            $before = self::rows(true);
            $reused = false;
            if ($before[self::QUEUE]['option_value'] !== '-1' && $operation === 'rollback') {
                // If the HTTP consumer failed, the rollback may reuse only this run's
                // committed apply request. It must not erase or merge a concurrent purge.
                // A prepared receipt is usable only here: an exact pending row proves
                // the intended queue exists even if transport/journal finalization failed.
                $apply = self::receipt($scope, $run, 'apply', true);
                $pending = $apply['before'];
                $pending[self::QUEUE]['option_value'] = $apply['queued_header'];
                if ($before !== $pending) self::fail();
                $header = $apply['queued_header'];
                $reused = true;
            }
            self::mark('purge_queue_available', $before[self::QUEUE]['option_value'] === '-1' || $reused);
            self::mark('purge_queue2_idle', $before[self::QUEUE2]['option_value'] === '-1');
            // Bypass stale request-local alloptions while keeping persistent caches isolated.
            wp_cache_delete(self::QUEUE, 'options'); wp_cache_delete(self::QUEUE2, 'options');
            wp_cache_delete('alloptions', 'options');
            if (get_option(self::QUEUE) !== $before[self::QUEUE]['option_value'] || get_option(self::QUEUE2) !== '-1') self::fail();
            if ($reused) self::mark('purge_native_scope_verified', true);
            else {
                $header = self::native_header($scope);
                $row = $before[self::QUEUE];
                $sql = $wpdb->prepare('UPDATE ' . self::table()
                    . ' SET option_value=%s WHERE option_id=%s AND option_name=%s AND option_value=%s AND autoload=%s',
                    $header, $row['option_id'], self::QUEUE, '-1', $row['autoload']);
                if (self::execute($sql) !== 1) self::fail();
            }
            $after = self::rows();
            $expected = $before; $expected[self::QUEUE]['option_value'] = $header;
            self::mark('purge_exact_queue_write_verified', $after === $expected);
            self::mark('purge_other_queue_unchanged', $after[self::QUEUE2] === $before[self::QUEUE2]);
            $idle = $before; $idle[self::QUEUE]['option_value'] = '-1';
            $receipt = ['run_id' => $run, 'scope' => $scope, 'operation' => $operation,
                'state' => 'prepared', 'before' => $idle, 'queued_header' => $header];
            self::save_receipt($path, $receipt);
            self::execute('COMMIT'); $transaction = false;
            $receipt['state'] = 'committed';
            self::save_receipt($path, $receipt, true);
            self::mark('purge_receipt_saved', true);
        } finally {
            self::$next_sql = null;
            if ($transaction) self::execute('ROLLBACK');
        }
    }
    public static function verify($scope, $run, $operation) {
        global $report;
        self::bootstrap();
        $report['category'] = 'cache_receipt';
        $receipt = self::receipt($scope, $run, $operation);
        self::mark('purge_receipt_verified', true);
        $now = self::rows();
        // A normal PHP HTTP request consumes the native queue. No write occurs here.
        self::mark('purge_queue_consumed', $now[self::QUEUE] === $receipt['before'][self::QUEUE]);
        self::mark('purge_other_queue_unchanged', $now[self::QUEUE2] === $receipt['before'][self::QUEUE2]);
    }
}

if (!defined('PETISH_HTML_PURGE_LIBRARY')) {
    define('PETISH_CLUB_VERIFY_LIBRARY', true);
    require __DIR__ . '/verify-pet-club.php';
    petish_club_verify_report_start();
    try {
        if (count($argv) !== 5 || !in_array($argv[1], ['inspect','queue','verify'], true)
            || !in_array($argv[2], ['site','delivery'], true) || !petish_club_verify_run($argv[3])
            || !in_array($argv[4], ['apply','rollback'], true)) petish_club_verify_fail();
        if ($argv[1] === 'inspect') Petish_HTML_Purge::inspect();
        elseif ($argv[1] === 'queue') Petish_HTML_Purge::queue($argv[2], $argv[3], $argv[4]);
        else Petish_HTML_Purge::verify($argv[2], $argv[3], $argv[4]);
        $report['status'] = 'success'; $report['category'] = 'complete';
    } catch (Throwable $ignored) { $report['status'] = 'failure'; }
    finally { Petish_HTML_Purge::finish(); }
    exit($report['status'] === 'success' ? 0 : 1);
}
