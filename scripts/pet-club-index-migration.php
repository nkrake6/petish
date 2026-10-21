<?php
/** Explicit, reversible migration of three search fields from already-public snapshots. */
if (PHP_SAPI !== 'cli') exit(1);
if (!defined('PETISH_CLUB_VERIFY_LIBRARY')) define('PETISH_CLUB_VERIFY_LIBRARY', true);
require_once __DIR__ . '/verify-pet-club.php';

final class Petish_Club_Index_Migration {
    const KEYS = ['_petish_club_find_name', '_petish_club_find_species', '_petish_club_find_variety'];
    const SOURCE_KEYS = ['_petish_club_public', '_petish_club_token', '_petish_club_revoked', '_petish_club_moderated'];
    const MAX_PETS = 100;
    private static $allowed_sql = null;
    private static $gate_depth = 0;
    private static $strict = false;
    private static $isolated = false;
    private static $maintenance = false;

    private static function fail() { throw new RuntimeException(); }
    private static function hash($value) { return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
    private static function id($value) {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $value));
    }
    private static function table($value) {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9_]+\z/D', $value)) self::fail();
        return '`' . $value . '`';
    }
    public static function query_guard($sql) {
        if (is_string($sql) && self::$allowed_sql !== null && hash_equals(self::$allowed_sql, $sql)) { self::$allowed_sql = null; return $sql; }
        if (is_string($sql) && strlen($sql) <= 8388608 && !str_contains($sql, "\0") && !preg_match('~/\*|--|\#~', $sql)
            && preg_match('/\A\s*(?:SELECT|SHOW|DESCRIBE|DESC)\b/i', $sql)
            && !preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $sql) && !str_contains(rtrim($sql, "; \r\n\t"), ';')) return $sql;
        if (self::$strict) self::fail();
        return 'SELECT 0 WHERE 1=0';
    }
    public static function last_guard($hook) {
        if ($hook === 'query') { remove_filter('query', [__CLASS__, 'query_guard'], PHP_INT_MAX); add_filter('query', [__CLASS__, 'query_guard'], PHP_INT_MAX); }
        if (self::$isolated && (in_array($hook, ['enable_loading_object_cache_dropin','enable_loading_advanced_cache_dropin'], true)
            || (self::$maintenance && $hook === 'enable_maintenance_mode'))) {
            remove_filter($hook, [__CLASS__, 'disabled'], PHP_INT_MAX); add_filter($hook, [__CLASS__, 'disabled'], PHP_INT_MAX);
        }
    }
    public static function disabled() { return false; }
    public static function isolate_cache($maintenance = false) {
        if (function_exists('wp_cache_init') || class_exists('WP_Object_Cache', false) || isset($GLOBALS['wp_object_cache'])) self::fail();
        self::$isolated = true; self::$maintenance = $maintenance;
        foreach (['enable_loading_object_cache_dropin','enable_loading_advanced_cache_dropin'] as $hook) add_filter($hook, [__CLASS__, 'disabled'], PHP_INT_MAX);
        if ($maintenance) add_filter('enable_maintenance_mode', [__CLASS__, 'disabled'], PHP_INT_MAX);
    }
    public static function runtime_cache($root) {
        try {
            if (!petish_club_verify_path($root, true) || !function_exists('wp_using_ext_object_cache') || !in_array(wp_using_ext_object_cache(), [false,null], true)
                || !isset($GLOBALS['wp_object_cache']) || !is_object($GLOBALS['wp_object_cache']) || get_class($GLOBALS['wp_object_cache']) !== 'WP_Object_Cache') return false;
            $class = $root . '/wp-includes/class-wp-object-cache.php'; $api = $root . '/wp-includes/cache.php';
            if (!petish_club_verify_path($class) || !petish_club_verify_path($api) || (new ReflectionClass($GLOBALS['wp_object_cache']))->getFileName() !== $class) return false;
            foreach (['init','get','get_multiple','set','set_multiple','add','add_multiple','replace','delete','delete_multiple','incr','decr','flush','flush_runtime','flush_group','supports','close','switch_to_blog','add_global_groups','add_non_persistent_groups','reset'] as $suffix) {
                $name = 'wp_cache_' . $suffix;
                if (!function_exists($name) || (new ReflectionFunction($name))->getFileName() !== $api) return false;
            }
            foreach (['enable_loading_object_cache_dropin','enable_loading_advanced_cache_dropin'] as $hook) if (apply_filters($hook, true) !== false) return false;
            $content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : $root . '/wp-content';
            foreach (['object-cache.php','advanced-cache.php'] as $file) if (in_array($content . '/' . $file, get_included_files(), true)) return false;
            return true;
        } catch (Throwable $ignored) { return false; }
    }
    private static function runtime($root) { if (!self::$gate_depth || !self::runtime_cache($root)) self::fail(); }
    /** CLI retains this outer gate through WordPress shutdown callbacks. */
    public static function enable_gate() {
        if (self::$gate_depth++) return;
        add_filter('query', [__CLASS__, 'query_guard'], PHP_INT_MAX);
        add_action('all', [__CLASS__, 'last_guard'], PHP_INT_MAX, 1);
    }
    public static function with_gate($callback) {
        if (!is_callable($callback)) self::fail();
        if (self::$gate_depth++) { try { return $callback(); } finally { self::$gate_depth--; } }
        add_filter('query', [__CLASS__, 'query_guard'], PHP_INT_MAX);
        add_action('all', [__CLASS__, 'last_guard'], PHP_INT_MAX, 1);
        try { return $callback(); }
        finally { self::$gate_depth--; remove_filter('query', [__CLASS__, 'query_guard'], PHP_INT_MAX); remove_action('all', [__CLASS__, 'last_guard'], PHP_INT_MAX); self::$allowed_sql = null; self::$strict = false; }
    }
    private static function execute($sql) {
        global $wpdb;
        if (!self::$gate_depth || self::$allowed_sql !== null) self::fail();
        self::$allowed_sql = $sql;
        try { $result = $wpdb->query($sql); if ($result === false || $wpdb->last_error !== '' || self::$allowed_sql !== null) self::fail(); return $result; }
        finally { self::$allowed_sql = null; }
    }
    private static function rows($sql) {
        global $wpdb; $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') self::fail(); return $rows;
    }
    private static function metadata($id, $keys) {
        global $wpdb; if (!self::id($id)) self::fail();
        $sql = $wpdb->prepare('SELECT meta_id,post_id,meta_key,meta_value FROM ' . self::table($wpdb->postmeta)
            . ' WHERE post_id=%d AND meta_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ') ORDER BY meta_id', $id, ...$keys);
        $rows = self::rows($sql); $result = [];
        foreach ($rows as $row) {
            if (!self::id($row['meta_id'] ?? null) || (int) ($row['post_id'] ?? 0) !== (int) $id
                || !in_array($row['meta_key'] ?? null, $keys, true) || !is_string($row['meta_value'] ?? null)) self::fail();
            $result[$row['meta_key']][] = ['meta_id' => (int) $row['meta_id'], 'post_id' => (int) $id, 'meta_key' => $row['meta_key'], 'meta_value' => $row['meta_value']];
        }
        return $result;
    }
    /** No private name, bio, variety, photo or moment field is queried here. */
    private static function source($id) {
        global $wpdb;
        $rows = self::rows($wpdb->prepare('SELECT p.ID,p.post_author,p.post_type,p.post_status FROM ' . self::table($wpdb->posts)
            . ' p INNER JOIN ' . self::table($wpdb->users) . " u ON u.ID=p.post_author WHERE p.ID=%d AND p.post_type='petish_pet' AND p.post_status='private'", $id));
        if (count($rows) !== 1 || !self::id($rows[0]['post_author'] ?? null)) self::fail();
        $meta = self::metadata($id, self::SOURCE_KEYS);
        if (count($meta['_petish_club_public'] ?? []) !== 1 || count($meta['_petish_club_token'] ?? []) !== 1
            || isset($meta['_petish_club_revoked']) || isset($meta['_petish_club_moderated'])) self::fail();
        $raw = $meta['_petish_club_public'][0]['meta_value']; $token = $meta['_petish_club_token'][0]['meta_value'];
        if (strlen($raw) > 1048576 || !preg_match('/\A[a-f0-9]{32}\z/D', $token)) self::fail();
        $snapshot = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($snapshot) || ($snapshot['token'] ?? null) !== $token) self::fail();
        $plain = static function($value, $depth = 0) use (&$plain) {
            if ($depth > 8 || is_object($value) || is_resource($value)) return false;
            if (is_array($value)) { if (count($value) > 100) return false; foreach ($value as $child) if (!$plain($child, $depth + 1)) return false; }
            return true;
        };
        if (!$plain($snapshot)) self::fail();
        // The application's public projection validates the frozen record's full
        // schema and exact token lookup; it never substitutes current owner edits.
        wp_cache_delete((int) $id, 'post_meta');
        $public = Petish_Passport_Community::public_profile($token);
        if (is_wp_error($public) || !is_array($public)) self::fail();
        foreach (['name', 'species'] as $key) if (!is_string($snapshot[$key] ?? null) || ($public[$key] ?? null) !== $snapshot[$key]) self::fail();
        $variety = $snapshot['variety'] ?? '';
        if (!is_string($variety) || ($public['variety'] ?? '') !== $variety || strlen($snapshot['name']) > 1000 || strlen($variety) > 1000) self::fail();
        $normalise = static function($value) { $value = remove_accents($value, 'en_US'); return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value); };
        return ['owner' => (int) $rows[0]['post_author'], 'source_hash' => self::hash([$rows[0], $meta]),
            'values' => array_combine(self::KEYS, [$normalise($snapshot['name']), $snapshot['species'], $normalise($variety)])];
    }
    private static function candidates() {
        global $wpdb; $postmeta = self::table($wpdb->postmeta); $missing = [];
        foreach (self::KEYS as $key) $missing[] = $wpdb->prepare("NOT EXISTS (SELECT 1 FROM $postmeta m WHERE m.post_id=p.ID AND m.meta_key=%s)", $key);
        $sql = 'SELECT p.ID FROM ' . self::table($wpdb->posts) . ' p INNER JOIN ' . self::table($wpdb->users) . ' u ON u.ID=p.post_author'
            . " WHERE p.post_type='petish_pet' AND p.post_status='private'"
            . " AND EXISTS (SELECT 1 FROM $postmeta t WHERE t.post_id=p.ID AND t.meta_key='_petish_club_token')"
            . " AND NOT EXISTS (SELECT 1 FROM $postmeta v WHERE v.post_id=p.ID AND v.meta_key IN ('_petish_club_revoked','_petish_club_moderated'))"
            . ' AND (' . implode(' OR ', $missing) . ') ORDER BY p.ID ASC LIMIT ' . (self::MAX_PETS + 1);
        $rows = self::rows($sql); if (count($rows) > self::MAX_PETS) self::fail();
        $ids = []; foreach ($rows as $row) { if (!self::id($row['ID'] ?? null)) self::fail(); $ids[] = (int) $row['ID']; } return $ids;
    }
    private static function locks($ids, $callback, $offset = 0) {
        if ($offset >= count($ids)) return $callback();
        $result = Petish_Passport_Store::with_pet_lock($ids[$offset], static function() use ($ids, $callback, $offset) { return self::locks($ids, $callback, $offset + 1); });
        if (is_wp_error($result)) self::fail(); return $result;
    }
    public static function plan($root, $run, $pet_ids = null) {
        if (!petish_club_verify_run($run) || !petish_club_verify_path($root, true)) self::fail();
        self::runtime($root);
        self::$strict = true; $scope = $pet_ids === null ? 'production' : 'fixture';
        if ($scope === 'fixture' && wp_get_environment_type() !== 'staging') self::fail();
        if ($pet_ids === null) $pet_ids = self::candidates();
        if (!is_array($pet_ids) || count($pet_ids) > self::MAX_PETS) self::fail();
        foreach ($pet_ids as $id) if (!is_int($id) || $id < 1) self::fail();
        $pet_ids = array_values(array_unique($pet_ids)); sort($pet_ids, SORT_NUMERIC);
        $entries = self::locks($pet_ids, static function() use ($pet_ids) {
            $entries = [];
            foreach ($pet_ids as $id) {
                $source = self::source($id); $existing = self::metadata($id, self::KEYS); $fields = []; $missing = false;
                foreach (self::KEYS as $key) {
                    $preimage = $existing[$key] ?? [];
                    if (count($preimage) > 1 || ($preimage && $preimage[0]['meta_value'] !== $source['values'][$key])) self::fail();
                    $fields[$key] = ['before' => $preimage, 'value' => $source['values'][$key]];
                    if (!$preimage) $missing = true;
                }
                if (!$missing) self::fail();
                $entries[] = ['pet_id' => $id, 'owner' => $source['owner'], 'source_hash' => $source['source_hash'], 'fields' => $fields];
            }
            return $entries;
        });
        return ['format' => 'petish-club-index-migration-v1', 'run' => $run, 'scope' => $scope, 'status' => 'planned',
            'entries' => $entries, 'plan_hash' => self::hash([$run, $scope, $entries]), 'before' => null, 'inserted' => []];
    }
    public static function validate($journal) {
        if (!is_array($journal) || array_keys($journal) !== ['format','run','scope','status','entries','plan_hash','before','inserted']
            || $journal['format'] !== 'petish-club-index-migration-v1' || !petish_club_verify_run($journal['run'])
            || !in_array($journal['scope'], ['production','fixture'], true) || !in_array($journal['status'], ['planned','prepared','applied','rolled_back'], true)
            || !is_array($journal['entries']) || count($journal['entries']) > self::MAX_PETS || !is_array($journal['inserted'])
            || count($journal['inserted']) > self::MAX_PETS * 3 || !is_string($journal['plan_hash'])
            || !hash_equals(self::hash([$journal['run'], $journal['scope'], $journal['entries']]), $journal['plan_hash'])) self::fail();
        $last = 0; $allowed = [];
        foreach ($journal['entries'] as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['pet_id','owner','source_hash','fields'] || !is_int($entry['pet_id']) || $entry['pet_id'] <= $last
                || !is_int($entry['owner']) || $entry['owner'] < 1 || !is_string($entry['source_hash']) || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['source_hash'])
                || !is_array($entry['fields']) || array_keys($entry['fields']) !== self::KEYS) self::fail();
            $last = $entry['pet_id']; $missing = false;
            foreach ($entry['fields'] as $key => $field) {
                if (!is_array($field) || array_keys($field) !== ['before','value'] || !is_string($field['value']) || strlen($field['value']) > 1000
                    || !is_array($field['before']) || count($field['before']) > 1) self::fail();
                if ($field['before']) {
                    $row = $field['before'][0];
                    if (!self::valid_row($row) || $row['post_id'] !== $entry['pet_id'] || $row['meta_key'] !== $key || $row['meta_value'] !== $field['value']) self::fail();
                } else { $missing = true; $allowed[$entry['pet_id'] . '|' . $key] = $field['value']; }
            }
            if (!$missing) self::fail();
        }
        $seen = [];
        foreach ($journal['inserted'] as $row) {
            if (!self::valid_row($row) || isset($seen[$row['meta_id']]) || !array_key_exists($row['post_id'] . '|' . $row['meta_key'], $allowed)
                || $allowed[$row['post_id'] . '|' . $row['meta_key']] !== $row['meta_value']) self::fail();
            $seen[$row['meta_id']] = true; unset($allowed[$row['post_id'] . '|' . $row['meta_key']]);
        }
        if ($journal['status'] !== 'planned' && ($allowed || !is_array($journal['before']))) self::fail();
        if ($journal['status'] === 'planned' && ($journal['inserted'] || $journal['before'] !== null)) self::fail();
        return true;
    }
    private static function valid_row($row) {
        return is_array($row) && array_keys($row) === ['meta_id','post_id','meta_key','meta_value'] && is_int($row['meta_id']) && $row['meta_id'] > 0
            && is_int($row['post_id']) && $row['post_id'] > 0 && in_array($row['meta_key'], self::KEYS, true) && is_string($row['meta_value']) && strlen($row['meta_value']) <= 1000;
    }
    public static function write($path, $journal) {
        self::validate($journal); $directory = dirname($path);
        if (!petish_club_verify_path($directory, true) || (fileperms($directory) & 07777) !== 0700) self::fail();
        if ((file_exists($path) || is_link($path)) && (!petish_club_verify_path($path) || (fileperms($path) & 07777) !== 0600)) self::fail();
        $raw = json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($raw) > 1048576) self::fail();
        $temporary = $directory . '/.index-journal-' . bin2hex(random_bytes(12)); $handle = fopen($temporary, 'xb');
        if (!$handle) self::fail();
        try {
            if (!chmod($temporary, 0600) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) self::fail();
            fclose($handle); $handle = null;
            if (!rename($temporary, $path)) self::fail();
            // The renamed directory entry must be durable before database COMMIT.
            $parent = fopen($directory, 'r');
            if (!$parent) self::fail();
            try { if (!function_exists('fsync') || !fsync($parent)) self::fail(); } finally { fclose($parent); }
        } finally { if (is_resource($handle)) fclose($handle); if (is_file($temporary)) unlink($temporary); }
    }
    private static function database_tables() {
        global $wpdb;
        self::table($wpdb->prefix);
        $rows = self::rows($wpdb->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', $wpdb->esc_like($wpdb->prefix) . '%'));
        if (!$rows || count($rows) > 500) self::fail(); $tables = []; $transactional = false;
        foreach ($rows as $row) { self::table($row['TABLE_NAME'] ?? null); $tables[] = $row['TABLE_NAME']; if ($row['TABLE_NAME'] === $wpdb->postmeta) $transactional = strtoupper($row['ENGINE'] ?? '') === 'INNODB'; }
        if (!$transactional) self::fail();
        foreach (self::rows('SHOW TRIGGERS') as $trigger) if (($trigger['Table'] ?? null) === $wpdb->postmeta) self::fail();
        $mode = $wpdb->get_var('SELECT @@SESSION.sql_mode');
        if (!is_string($mode) || str_contains($mode, 'NO_BACKSLASH_ESCAPES') || $wpdb->last_error !== '') self::fail();
        return $tables;
    }
    private static function state($root, $omit = []) {
        global $wpdb; $tables = []; $ids = [];
        foreach ($omit as $row) { if (!self::valid_row($row)) self::fail(); $ids[] = $row['meta_id']; }
        foreach (self::database_tables() as $table) {
            $where = $table === $wpdb->postmeta && $ids ? ' WHERE meta_id NOT IN (' . implode(',', $ids) . ')' : '';
            $tables[$table] = petish_club_verify_table_state($table, $where);
        }
        return ['tables' => $tables, 'photos' => petish_club_verify_photo_state($root)];
    }
    private static function current_row($id) {
        global $wpdb; $rows = self::rows($wpdb->prepare('SELECT meta_id,post_id,meta_key,meta_value FROM ' . self::table($wpdb->postmeta) . ' WHERE meta_id=%d', $id));
        if (count($rows) > 1) self::fail(); if (!$rows) return null;
        $row = $rows[0]; $row['meta_id'] = (int) $row['meta_id']; $row['post_id'] = (int) $row['post_id']; return $row;
    }
    private static function check_sources($journal, $with_inserted) {
        $inserted = []; foreach ($journal['inserted'] as $row) $inserted[$row['post_id']][$row['meta_key']] = [$row];
        foreach ($journal['entries'] as $entry) {
            $source = self::source($entry['pet_id']);
            if ($source['owner'] !== $entry['owner'] || !hash_equals($entry['source_hash'], $source['source_hash'])) self::fail();
            $current = self::metadata($entry['pet_id'], self::KEYS);
            foreach ($entry['fields'] as $key => $field) {
                $expected = $field['before'];
                if (!$expected && $with_inserted) $expected = $inserted[$entry['pet_id']][$key] ?? [];
                if ($source['values'][$key] !== $field['value'] || ($current[$key] ?? []) !== $expected) self::fail();
            }
        }
    }
    public static function verify($root, $journal) {
        self::validate($journal); self::runtime($root); self::$strict = true;
        if (!in_array($journal['status'], ['prepared','applied','rolled_back'], true)) self::fail();
        return self::locks(array_column($journal['entries'], 'pet_id'), static function() use ($root, $journal) {
            $applied = $journal['status'] !== 'rolled_back';
            self::check_sources($journal, $applied);
            foreach ($journal['inserted'] as $row) if (self::current_row($row['meta_id']) !== ($applied ? $row : null)) self::fail();
            if (self::state($root, $applied ? $journal['inserted'] : []) !== $journal['before']) self::fail();
            return true;
        });
    }
    public static function apply($root, &$journal, $path) {
        global $wpdb; self::validate($journal); self::runtime($root); self::$strict = true;
        if ($journal['status'] === 'applied' || $journal['status'] === 'prepared') {
            self::verify($root, $journal); $journal['status'] = 'applied'; self::write($path, $journal); return true;
        }
        if ($journal['status'] !== 'planned' || (string) $wpdb->get_var('SELECT @@SESSION.autocommit') !== '1' || $wpdb->last_error !== ''
            || (string) $wpdb->get_var('SELECT @@SESSION.in_transaction') !== '0' || $wpdb->last_error !== '') self::fail();
        self::database_tables();
        return self::locks(array_column($journal['entries'], 'pet_id'), static function() use ($root, &$journal, $path, $wpdb) {
            if ($journal['scope'] === 'production' && self::candidates() !== array_column($journal['entries'], 'pet_id')) self::fail();
            self::check_sources($journal, false);
            $before = self::state($root); $inserted = []; $active = false;
            try {
                self::execute('START TRANSACTION'); $active = true;
                self::check_sources($journal, false);
                foreach ($journal['entries'] as $entry) foreach ($entry['fields'] as $key => $field) {
                    if ($field['before']) continue;
                    $sql = $wpdb->prepare('INSERT INTO ' . self::table($wpdb->postmeta) . ' (post_id,meta_key,meta_value) VALUES (%d,%s,%s)', $entry['pet_id'], $key, $field['value']);
                    if (self::execute($sql) !== 1 || !self::id($wpdb->insert_id)) self::fail();
                    $inserted[] = ['meta_id' => (int) $wpdb->insert_id, 'post_id' => $entry['pet_id'], 'meta_key' => $key, 'meta_value' => $field['value']];
                    wp_cache_delete($entry['pet_id'], 'post_meta');
                }
                $journal['before'] = $before; $journal['inserted'] = $inserted; $journal['status'] = 'prepared';
                self::verify($root, $journal);
                // IDs and exact values reach stable private storage before COMMIT.
                // A crash before this point rolls back; after commit it remains owned.
                self::write($path, $journal);
                self::execute('COMMIT'); $active = false;
                $journal['status'] = 'applied'; self::write($path, $journal); self::verify($root, $journal);
                return true;
            } finally { if ($active) self::execute('ROLLBACK'); foreach ($journal['entries'] as $entry) wp_cache_delete($entry['pet_id'], 'post_meta'); }
        });
    }
    public static function rollback($root, &$journal, $path) {
        global $wpdb; self::validate($journal); self::runtime($root); self::$strict = true;
        if ($journal['status'] === 'planned') return true;
        if ($journal['status'] === 'rolled_back') return self::verify($root, $journal);
        self::database_tables();
        if ((string) $wpdb->get_var('SELECT @@SESSION.autocommit') !== '1' || $wpdb->last_error !== ''
            || (string) $wpdb->get_var('SELECT @@SESSION.in_transaction') !== '0' || $wpdb->last_error !== '') self::fail();
        return self::locks(array_column($journal['entries'], 'pet_id'), static function() use ($root, &$journal, $path, $wpdb) {
            // Never adopt another request's row, even when its value is identical.
            foreach ($journal['inserted'] as $row) { $current = self::current_row($row['meta_id']); if ($current !== null && $current !== $row) self::fail(); }
            $active = false;
            try {
                self::execute('START TRANSACTION'); $active = true;
                foreach ($journal['inserted'] as $row) {
                    $current = self::current_row($row['meta_id']); if ($current === null) continue; if ($current !== $row) self::fail();
                    $sql = $wpdb->prepare('DELETE FROM ' . self::table($wpdb->postmeta) . ' WHERE meta_id=%d AND post_id=%d AND BINARY meta_key=%s AND BINARY meta_value=%s',
                        $row['meta_id'], $row['post_id'], $row['meta_key'], $row['meta_value']);
                    if (self::execute($sql) !== 1) self::fail(); wp_cache_delete($row['post_id'], 'post_meta');
                }
                $restored = $journal; $restored['status'] = 'rolled_back'; self::verify($root, $restored);
                self::execute('COMMIT'); $active = false; $journal = $restored; self::write($path, $journal); return true;
            } finally { if ($active) self::execute('ROLLBACK'); foreach ($journal['entries'] as $entry) wp_cache_delete($entry['pet_id'], 'post_meta'); }
        });
    }
}

const PETISH_CLUB_INDEX_CHECKS = ['index_plan_verified','index_journal_verified','index_maintenance_owned','index_lock_owned',
    'index_apply_verified','index_exact_delta_verified','index_rollback_verified','index_read_only_verified','index_private_runtime_cache_verified'];
const PETISH_CLUB_INDEX_COMMON = ['target_paths_verified','target_environment_verified','woocommerce_runtime_verified','all_outbound_http_blocked',
    'mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled','index_private_runtime_cache_verified'];
const PETISH_CLUB_INDEX_REQUIRED = [
    'plan' => ['index_plan_verified','index_read_only_verified'],
    'apply' => ['index_journal_verified','index_maintenance_owned','index_lock_owned','index_apply_verified','index_exact_delta_verified'],
    'verify' => ['index_journal_verified','index_maintenance_owned','index_lock_owned','index_exact_delta_verified','index_read_only_verified'],
    'rollback' => ['index_journal_verified','index_maintenance_owned','index_lock_owned','index_rollback_verified'],
];

function petish_club_index_boundary($run, $mode) {
    $proof_home = getenv('HOME'); $tools = $proof_home . '/.petish-pet-club-deploy-' . $run; $release = $proof_home . '/.petish-pet-club-releases/' . $run;
    $root = $proof_home . '/domains/petish.biz/public_html';
    if (!petish_club_verify_run($run) || __DIR__ !== $tools || !petish_club_verify_path($tools, true) || (fileperms($tools) & 07777) !== 0700) petish_club_verify_fail();
    $marker = $tools . '/.pet-club-owned';
    if (!petish_club_verify_path($marker) || (fileperms($marker) & 07777) !== 0600 || file_get_contents($marker) !== $run . "\n") petish_club_verify_fail();
    if ($mode !== 'plan') {
        $lock = dirname($release) . '/deploy.lock'; $owner = $lock . '/owner';
        if (!petish_club_verify_path($lock, true) || (fileperms($lock) & 07777) !== 0700 || !petish_club_verify_path($owner)
            || (fileperms($owner) & 07777) !== 0600 || file_get_contents($owner) !== $run . "\n") petish_club_verify_fail();
        $receipt = petish_club_verify_read_json($release . '/maintenance.json'); $guard = $root . '/.maintenance';
        if (array_keys($receipt) !== ['run','sha256'] || $receipt['run'] !== $run || !preg_match('/\A[a-f0-9]{64}\z/D', $receipt['sha256'])
            || !petish_club_verify_path($guard) || (fileperms($guard) & 07777) !== 0600 || !hash_equals($receipt['sha256'], hash_file('sha256', $guard))) petish_club_verify_fail();
    }
    return [$root, $tools . '/pet-club-index-migration.json'];
}
function petish_club_index_migration_main($argv) {
    global $report; petish_club_verify_report_start();
    try {
        if (count($argv) !== 3 || !in_array($argv[2], ['plan','apply','verify','rollback'], true)) petish_club_verify_fail();
        [$run, $mode] = [$argv[1], $argv[2]]; [$expected_root, $path] = petish_club_index_boundary($run, $mode);
        $plugin = $expected_root . '/wp-includes/plugin.php'; if (!petish_club_verify_path($plugin)) petish_club_verify_fail(); require_once $plugin;
        Petish_Club_Index_Migration::isolate_cache($mode !== 'plan');
        Petish_Club_Index_Migration::enable_gate();
        Petish_Club_Index_Migration::with_gate(static function() use ($run, $mode, $path, $expected_root) {
            global $report;
            $root = petish_club_verify_bootstrap('production', false); if ($root !== $expected_root) petish_club_verify_fail();
            petish_club_index_boundary($run, $mode);
            if (!Petish_Club_Index_Migration::runtime_cache($root)) petish_club_verify_fail();
            $report['checks']['index_private_runtime_cache_verified'] = true;
            $report['category'] = 'index_' . $mode;
            if ($mode === 'plan') {
                if (file_exists($path) || is_link($path)) petish_club_verify_fail();
                $journal = Petish_Club_Index_Migration::plan($root, $run); Petish_Club_Index_Migration::write($path, $journal);
                $report['checks']['index_plan_verified'] = true; $report['checks']['index_read_only_verified'] = true;
            } else {
                $journal = petish_club_verify_read_json($path); Petish_Club_Index_Migration::validate($journal);
                if ($journal['run'] !== $run || $journal['scope'] !== 'production') petish_club_verify_fail();
                $report['checks']['index_journal_verified'] = true; $report['checks']['index_maintenance_owned'] = true; $report['checks']['index_lock_owned'] = true;
                Petish_Club_Index_Migration::$mode($root, $journal, $path);
                $report['checks']['index_' . ($mode === 'verify' ? 'exact_delta' : $mode) . '_verified'] = true;
                if ($mode === 'apply') $report['checks']['index_exact_delta_verified'] = true;
                if ($mode === 'verify') $report['checks']['index_read_only_verified'] = true;
            }
        });
        if (array_diff(array_merge(PETISH_CLUB_INDEX_COMMON, PETISH_CLUB_INDEX_REQUIRED[$mode]), array_keys($report['checks']))) petish_club_verify_fail();
        $report['status'] = 'success'; $report['category'] = 'complete'; return 0;
    } catch (Throwable $ignored) { $report['status'] = 'failure'; return 1; }
}
if (!defined('PETISH_CLUB_INDEX_LIBRARY')) exit(petish_club_index_migration_main($argv));
