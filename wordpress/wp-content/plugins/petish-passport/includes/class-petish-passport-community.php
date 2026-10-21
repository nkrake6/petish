<?php
/** Explicit public pet snapshots. Private Passport records and originals stay private. */
defined('ABSPATH') || exit;

final class Petish_Passport_Community {
    const META = '_petish_club_public';
    const TOKEN = '_petish_club_token';
    const REVOKED = '_petish_club_revoked';
    const BLOCKED = '_petish_club_moderated';
    const HEART = 'petish_club_heart_';
    const REPORT = 'petish_club_report_';

    public static function boot() {
        add_action('init', array(__CLASS__, 'early_cache'), 0);
        add_action('template_redirect', array(__CLASS__, 'route'), 0);
        add_filter('wp_robots', array(__CLASS__, 'robots'));
        foreach (array('publish', 'unpublish', 'heart', 'report', 'moderate') as $action) {
            add_action('admin_post_petish_club_' . $action, array(__CLASS__, 'handle_' . $action));
            add_action('admin_post_nopriv_petish_club_' . $action, array(__CLASS__, 'guest'));
        }
        add_action('admin_post_petish_club_photo', array(__CLASS__, 'serve_photo'));
        add_action('admin_post_nopriv_petish_club_photo', array(__CLASS__, 'serve_photo'));
        add_action('admin_menu', array(__CLASS__, 'moderation_menu'));
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
        add_action('delete_user', array(__CLASS__, 'mark_deleting'), 0);
        add_action('wpmu_delete_user', array(__CLASS__, 'mark_deleting'), 0);
        add_action('delete_user', array(__CLASS__, 'delete_user'), 2);
        add_action('wpmu_delete_user', array(__CLASS__, 'delete_user'), 2);
    }

    private static function error($code = 'petish_club_unavailable', $message = 'This shared Passport is not available.') {
        return new WP_Error($code, __($message, 'petish-passport'));
    }
    private static function token_valid($token) { return is_string($token) && 1 === preg_match('/\A[a-f0-9]{32}\z/D', $token); }
    private static function variety_valid($value) { return is_string($value) && 1 === preg_match('/\A.{0,60}\z/usD', $value); }
    private static function id_valid($value) { return (is_int($value) && $value > 0) || (is_string($value) && 1 === preg_match('/\A[1-9][0-9]{0,17}\z/D', $value)); }
    private static function file_key_valid($key, $uid) {
        return is_string($key) && 1 === preg_match('#\A' . preg_quote((string) absint($uid), '#') . '/[a-f0-9]{48}\.(?:jpg|png|webp)\z#D', $key);
    }
    public static function url($token = '') {
        return self::token_valid($token) ? add_query_arg('petish_pet', $token, home_url('/')) : add_query_arg('petish_club', '1', home_url('/'));
    }
    public static function is_route() { return isset($_GET['petish_pet']) || isset($_GET['petish_club']); }
    public static function robots($robots) {
        if (self::is_route()) { $robots['noindex'] = true; $robots['nofollow'] = true; unset($robots['index'], $robots['follow']); }
        return $robots;
    }
    public static function early_cache() {
        if (self::is_route() || (isset($_REQUEST['action']) && is_string($_REQUEST['action']) && strpos($_REQUEST['action'], 'petish_club_') === 0)) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            do_action('litespeed_control_set_nocache', 'Petish public visibility and member actions');
        }
    }
    public static function no_cache() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
    }
    /** Canonical lock lives with private mutations so publish and delete cannot race. */
    public static function with_pet_lock($pet_id, $callback) {
        return Petish_Passport_Store::with_pet_lock($pet_id, $callback);
    }
    private static function with_actor_lock($uid, $callback) {
        return Petish_Passport_Store::with_user_lock($uid, static function() use ($uid, $callback) {
            wp_cache_delete($uid, 'user_meta');
            return $callback();
        });
    }
    private static function actor_available($uid) { return get_user_by('id', $uid) && !get_user_meta($uid, '_petish_club_deleting', true); }
    public static function mark_deleting($uid) {
        $result = self::with_actor_lock($uid, static function() use ($uid) {
            update_user_meta($uid, '_petish_club_deleting', '1');
            return '1' === (string) get_user_meta($uid, '_petish_club_deleting', true);
        });
        if (true !== $result) wp_die(esc_html__('Community activity could not be paused. Please retry account deletion.', 'petish-passport'), '', array('response' => 500));
    }
    private static function raw($pet_id) {
        if (get_post_meta($pet_id, self::REVOKED, true) || get_post_meta($pet_id, self::BLOCKED, true)) return false;
        $post = get_post($pet_id);
        if (!$post || Petish_Passport_Store::TYPE !== $post->post_type || 'private' !== $post->post_status || !get_user_by('id', (int) $post->post_author)) return false;
        $value = get_post_meta($pet_id, self::META, true);
        if (!is_array($value) || !self::token_valid($value['token'] ?? null)
            || get_post_meta($pet_id, self::TOKEN, true) !== $value['token']) return false;
        foreach (array('name', 'species', 'age', 'bio', 'photo', 'published') as $key) if (!isset($value[$key]) || !is_string($value[$key])) return false;
        if (array_key_exists('variety', $value) && !self::variety_valid($value['variety'])) return false;
        if (!is_array($value['traits'] ?? null) || count($value['traits']) > 3 || !is_array($value['moments'] ?? null) || count($value['moments']) > 12) return false;
        if (!isset(Petish_Passport_Store::species()[$value['species']]) || ($value['age'] !== '' && !isset(Petish_Passport_Store::ages()[$value['age']]))) return false;
        if ($value['photo'] !== '' && !self::file_key_valid($value['photo'], $post->post_author)) return false;
        foreach ($value['traits'] as $trait) if (!is_string($trait)) return false;
        foreach ($value['moments'] as $moment) {
            if (!is_array($moment) || !self::token_valid($moment['token'] ?? null) || !in_array($moment['kind'] ?? '', array('photo', 'journal'), true)) return false;
            foreach (array('caption', 'photo', 'created') as $key) if (!isset($moment[$key]) || !is_string($moment[$key])) return false;
            if ($moment['photo'] !== '' && !self::file_key_valid($moment['photo'], $post->post_author)) return false;
        }
        return array('pet_id' => (int) $pet_id, 'uid' => (int) $post->post_author, 'snapshot' => $value);
    }
    private static function find($token) {
        if (!self::token_valid($token)) return false;
        $ids = get_posts(array('post_type' => Petish_Passport_Store::TYPE, 'post_status' => 'private', 'posts_per_page' => 2,
            'fields' => 'ids', 'meta_key' => self::TOKEN, 'meta_value' => $token, 'no_found_rows' => true, 'suppress_filters' => true, 'cache_results' => false));
        if (count($ids) !== 1) return false;
        $raw = self::raw((int) $ids[0]);
        return $raw && hash_equals($raw['snapshot']['token'], $token) ? $raw : false;
    }
    private static function keys($snapshot, $uid) {
        if (!is_array($snapshot)) return array();
        $keys = array();
        if (self::file_key_valid($snapshot['photo'] ?? '', $uid)) $keys[] = $snapshot['photo'];
        foreach (is_array($snapshot['moments'] ?? null) ? $snapshot['moments'] : array() as $moment) {
            if (is_array($moment) && self::file_key_valid($moment['photo'] ?? '', $uid)) $keys[] = $moment['photo'];
        }
        return array_values(array_unique($keys));
    }
    private static function cleanup_keys($pet_id, $uid) {
        $keys = get_post_meta($pet_id, '_petish_club_cleanup', true);
        if ($keys === '') return array();
        if (!is_array($keys) || count($keys) > 64) return self::error('petish_club_cleanup', 'Stored shared photos need review before sharing can change.');
        foreach ($keys as $key) if (!self::file_key_valid($key, $uid)) return self::error('petish_club_cleanup', 'Stored shared photos need review before sharing can change.');
        return array_values(array_unique($keys));
    }
    private static function journal($pet_id, $uid, $keys) {
        $pending = self::cleanup_keys($pet_id, $uid); if (is_wp_error($pending)) return $pending;
        foreach ($keys as $key) if (!self::file_key_valid($key, $uid)) return self::error('petish_club_cleanup', 'A shared photo could not be prepared.');
        $pending = array_values(array_unique(array_merge($pending, $keys)));
        if (count($pending) > 64) return self::error('petish_club_cleanup', 'Stored shared photos need removal before you can publish again.');
        update_post_meta($pet_id, '_petish_club_cleanup', $pending);
        return get_post_meta($pet_id, '_petish_club_cleanup', true) === $pending ? true : self::error('petish_club_cleanup', 'The shared photos could not be prepared. Please try again.');
    }
    private static function flush_pending($pet_id, $uid, $keep = array()) {
        $pending = self::cleanup_keys($pet_id, $uid); if (is_wp_error($pending)) return $pending;
        $retained = array();
        foreach ($pending as $key) if (!in_array($key, $keep, true) && !Petish_Passport_Media::remove($key)) $retained[] = $key;
        update_post_meta($pet_id, '_petish_club_cleanup', $retained);
        if (get_post_meta($pet_id, '_petish_club_cleanup', true) !== $retained || $retained) return self::error('petish_club_cleanup', 'Sharing is off or updated, but some old photo copies still need removal. Please try again.');
        return true;
    }
    /** Selected photos become independent copies, never a public alias for originals. */
    public static function publish($pet_id, $uid, $share_age = false, $moment_ids = array()) {
        if (!is_bool($share_age) || !is_array($moment_ids) || count($moment_ids) > 12) return self::error('petish_club_input', 'Check the details and moments you want to share.');
        foreach ($moment_ids as $id) if (!self::id_valid($id)) return self::error('petish_club_input', 'Check the selected moments.');
        $moment_ids = array_values(array_unique(array_map('absint', $moment_ids)));
        return self::with_pet_lock($pet_id, static function() use ($pet_id, $uid, $share_age, $moment_ids) {
            $pet = Petish_Passport_Store::get($pet_id, $uid);
            if (is_wp_error($pet)) return $pet;
            $variety = Petish_Passport_Store::variety_label($pet);
            if (!self::variety_valid($variety)) return self::error('petish_club_input', 'Choose a breed or variety of no more than 60 characters before sharing.');
            wp_cache_delete($uid, 'user_meta');
            if (!self::actor_available($uid)) return self::error('petish_club_signin', 'Your account is not available for sharing.');
            if (get_post_meta($pet_id, self::BLOCKED, true)) return self::error('petish_club_moderated', 'Sharing is paused while this Passport is reviewed.');
            if (get_post_meta($pet_id, self::REVOKED, true)) {
                $cleanup = self::unpublish($pet_id, $uid); if (is_wp_error($cleanup)) return $cleanup;
            }
            $old = self::raw($pet_id);
            $old_keys = $old ? self::keys($old['snapshot'], $uid) : array();
            $cleanup = self::flush_pending($pet_id, $uid, $old_keys); if (is_wp_error($cleanup)) return $cleanup;
            $moments = array();
            foreach ($moment_ids as $id) {
                $moment = Petish_Passport_Moments::get($id, $uid);
                if (is_wp_error($moment) || (int) $moment['pet_id'] !== (int) $pet_id) return self::error('petish_club_input', 'A selected moment is not available in this Passport.');
                $moments[] = $moment;
            }
            // Persist the cleanup key before a derivative file is created.
            $before_copy = static fn($key) => self::journal($pet_id, $uid, array($key));
            try {
                $token = $old ? $old['snapshot']['token'] : bin2hex(random_bytes(16));
                $snapshot = array('token' => $token, 'name' => $pet['name'], 'species' => $pet['species'],
                    'variety' => $variety, 'age' => $share_age ? $pet['age'] : '', 'bio' => $pet['bio'] ?? '', 'traits' => $pet['traits'] ?? array(),
                    'photo' => '', 'moments' => array(), 'published' => gmdate('Y-m-d H:i:s'));
                if ($pet['photo'] !== '') {
                    $copy = Petish_Passport_Media::duplicate($pet['photo'], $uid, $before_copy);
                    if (is_wp_error($copy)) { self::flush_pending($pet_id, $uid, $old_keys); return $copy; }
                    $snapshot['photo'] = $copy;
                }
                foreach ($moments as $moment) {
                    $entry = array('source_id' => (int) $moment['id'], 'token' => bin2hex(random_bytes(16)), 'kind' => $moment['kind'],
                        'caption' => $moment['caption'], 'created' => $moment['created'], 'photo' => '');
                    if ($moment['photo'] !== '') {
                        $copy = Petish_Passport_Media::duplicate($moment['photo'], $uid, $before_copy);
                        if (is_wp_error($copy)) { self::flush_pending($pet_id, $uid, $old_keys); return $copy; }
                        $entry['photo'] = $copy;
                    }
                    $snapshot['moments'][] = $entry;
                }
            } catch (Throwable $error) {
                self::flush_pending($pet_id, $uid, $old_keys);
                return self::error('petish_club_save', 'Your public preview could not be prepared. Please try again.');
            }
            // Keep old and new keys reachable across a partial metadata write.
            $queued = self::journal($pet_id, $uid, $old_keys);
            if (is_wp_error($queued)) { self::flush_pending($pet_id, $uid, $old_keys); return $queued; }
            update_post_meta($pet_id, self::REVOKED, '1');
            if ('1' !== (string) get_post_meta($pet_id, self::REVOKED, true)) {
                self::flush_pending($pet_id, $uid, $old_keys);
                return self::error('petish_club_save', 'Sharing could not be updated. Please try again.');
            }
            update_post_meta($pet_id, self::META, wp_slash($snapshot));
            update_post_meta($pet_id, self::TOKEN, $token);
            if (get_post_meta($pet_id, self::META, true) !== $snapshot || get_post_meta($pet_id, self::TOKEN, true) !== $token) {
                self::unpublish($pet_id, $uid);
                return self::error('petish_club_save', 'Sharing is off because the update did not finish. Please try again.');
            }
            $cleanup = self::flush_pending($pet_id, $uid, self::keys($snapshot, $uid));
            if (is_wp_error($cleanup)) return $cleanup; // Revoked until cleanup succeeds; retry remains possible.
            delete_post_meta($pet_id, self::REVOKED);
            return self::raw($pet_id) ? self::published_for_owner($pet_id, $uid) : self::error('petish_club_save', 'Sharing is off because the update did not finish. Please try again.');
        });
    }

    private static function action_names($pet_id = 0, $uid = 0, $limit = 200, $offset = 0) {
        global $wpdb;
        $patterns = array();
        foreach (array(self::HEART, self::REPORT) as $prefix) {
            $patterns[] = $pet_id ? $wpdb->esc_like($prefix . absint($pet_id) . '_') . '%'
                : $wpdb->esc_like($prefix) . '%' . $wpdb->esc_like('_' . absint($uid));
        }
        return $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_id ASC LIMIT %d OFFSET %d", $patterns[0], $patterns[1], max(1, (int) $limit), max(0, (int) $offset)));
    }
    private static function purge_actions($pet_id = 0, $uid = 0) {
        for ($batch = 0; $batch < 100; $batch++) {
            $names = self::action_names($pet_id, $uid);
            if (!is_array($names)) return false;
            if (!$names) return true;
            foreach ($names as $name) if (!delete_option($name) && false !== get_option($name, false)) return false;
        }
        return false;
    }
    /** Revocation is saved before deleting copies, so failed cleanup cannot keep a pet public. */
    public static function unpublish($pet_id, $uid) {
        return self::with_pet_lock($pet_id, static function() use ($pet_id, $uid) {
            $pet = Petish_Passport_Store::get($pet_id, $uid);
            if (is_wp_error($pet)) return $pet;
            $snapshot = get_post_meta($pet_id, self::META, true);
            $pending = self::cleanup_keys($pet_id, $uid);
            if (is_wp_error($pending)) return $pending;
            $keys = array_unique(array_merge(self::keys($snapshot, $uid), $pending));
            if (!$snapshot && !$keys && !get_post_meta($pet_id, self::TOKEN, true)) {
                if (!self::purge_actions($pet_id)) return self::error('petish_club_cleanup', 'Some community data could not be removed. Please try again.');
                delete_post_meta($pet_id, self::REVOKED); delete_post_meta($pet_id, '_petish_club_cleanup'); return true;
            }
            update_post_meta($pet_id, self::REVOKED, '1');
            if ('1' !== (string) get_post_meta($pet_id, self::REVOKED, true)) return self::error('petish_club_save', 'Sharing could not be turned off. Please try again.');
            $queued = self::journal($pet_id, $uid, $keys);
            if (is_wp_error($queued)) return $queued;
            $cleanup = self::flush_pending($pet_id, $uid);
            if (is_wp_error($cleanup) || !self::purge_actions($pet_id)) return self::error('petish_club_cleanup', 'Sharing is off. Some stored copies still need removal; please try again.');
            delete_post_meta($pet_id, self::TOKEN);
            delete_post_meta($pet_id, self::META);
            delete_post_meta($pet_id, '_petish_club_cleanup');
            if (get_post_meta($pet_id, self::TOKEN, true) || get_post_meta($pet_id, self::META, true)) return self::error('petish_club_cleanup', 'Sharing is off. Please try again to finish removing the shared details.');
            delete_post_meta($pet_id, self::REVOKED);
            return true;
        });
    }
    private static function photo_url($token, $moment = '') {
        $args = array('action' => 'petish_club_photo', 'token' => $token);
        if ($moment !== '') $args['moment'] = $moment;
        return add_query_arg($args, admin_url('admin-post.php'));
    }
    public static function heart_count($pet_id) {
        global $wpdb;
        return max(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::HEART . absint($pet_id) . '_') . '%')));
    }
    private static function project($raw) {
        $snapshot = $raw['snapshot']; $uid = get_current_user_id(); $moments = array();
        foreach ($snapshot['moments'] as $moment) $moments[] = array('token' => $moment['token'], 'kind' => $moment['kind'],
            'caption' => $moment['caption'], 'photo_url' => $moment['photo'] !== '' ? self::photo_url($snapshot['token'], $moment['token']) : '', 'created' => $moment['created']);
        return array('token' => $snapshot['token'], 'url' => self::url($snapshot['token']), 'name' => $snapshot['name'], 'species' => $snapshot['species'],
            'variety' => $snapshot['variety'] ?? '', 'age' => $snapshot['age'], 'bio' => $snapshot['bio'], 'traits' => array_values($snapshot['traits']),
            'photo_url' => $snapshot['photo'] !== '' ? self::photo_url($snapshot['token']) : '', 'moments' => $moments,
            'published' => $snapshot['published'], 'hearts' => self::heart_count($raw['pet_id']),
            'hearted' => $uid > 0 && false !== get_option(self::HEART . $raw['pet_id'] . '_' . $uid, false),
            'is_owner' => $uid > 0 && $uid === $raw['uid'], 'can_heart' => $uid > 0 && $uid !== $raw['uid']);
    }
    public static function public_profile($token) { $raw = self::find($token); return $raw ? self::project($raw) : self::error(); }
    public static function published_for_owner($pet_id, $uid) {
        $pet = Petish_Passport_Store::get($pet_id, $uid);
        if (is_wp_error($pet)) return $pet;
        $raw = self::raw($pet_id); $snapshot = $raw ? $raw['snapshot'] : array();
        return array('shared' => (bool) $raw, 'blocked' => (bool) get_post_meta($pet_id, self::BLOCKED, true),
            'token' => $snapshot['token'] ?? '', 'url' => $raw ? self::url($snapshot['token']) : '', 'published' => $snapshot['published'] ?? '',
            'share_age' => isset($snapshot['age']) && $snapshot['age'] !== '',
            'moment_ids' => $raw ? array_map('absint', array_column($snapshot['moments'], 'source_id')) : array(),
            'hearts' => $raw ? self::heart_count($pet_id) : 0, 'profile' => $raw ? self::project($raw) : null);
    }
    public static function discover($limit = 6, $exclude_token = '') {
        $limit = max(1, min(24, (int) $limit)); $profiles = array();
        $ids = get_posts(array('post_type' => Petish_Passport_Store::TYPE, 'post_status' => 'private', 'posts_per_page' => 100,
            'fields' => 'ids', 'meta_key' => self::TOKEN, 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true, 'suppress_filters' => true, 'cache_results' => false));
        foreach ($ids as $id) { $raw = self::raw($id); if (!$raw || $raw['snapshot']['token'] === $exclude_token) continue; $profiles[] = self::project($raw); if (count($profiles) >= $limit) break; }
        return $profiles;
    }
    public static function set_heart($token, $uid, $desired) {
        if (!is_bool($desired) || !$uid || (int) $uid !== get_current_user_id() || !get_user_by('id', $uid)) return self::error('petish_club_signin', 'Sign in to give a pet a heart.');
        $initial = self::find($token);
        if (!$initial) return self::error();
        return self::with_actor_lock($uid, static function() use ($initial, $token, $uid, $desired) {
            if (!self::actor_available($uid)) return self::error('petish_club_signin', 'Your account is not available for community updates.');
            return self::with_pet_lock($initial['pet_id'], static function() use ($token, $uid, $desired) {
            $raw = self::find($token);
            if (!$raw) return self::error();
            if ((int) $uid === $raw['uid']) return self::error('petish_club_own_heart', 'You can give hearts to other members’ pets.');
            $key = self::HEART . $raw['pet_id'] . '_' . absint($uid);
            if ($desired) add_option($key, gmdate('Y-m-d H:i:s'), '', false);
            else delete_option($key);
            if ((false !== get_option($key, false)) !== $desired) return self::error('petish_club_heart', 'Your heart could not be updated. Please try again.');
            return array('hearted' => $desired, 'hearts' => self::heart_count($raw['pet_id']));
            });
        });
    }
    public static function report($token, $uid, $reason) {
        if (!$uid || (int) $uid !== get_current_user_id() || !get_user_by('id', $uid)) return self::error('petish_club_signin', 'Sign in to report a shared pet profile.');
        if (!in_array($reason, array('spam', 'inappropriate', 'privacy', 'other'), true)) return self::error('petish_club_report', 'Choose a reason for the report.');
        $initial = self::find($token);
        if (!$initial) return self::error();
        return self::with_actor_lock($uid, static function() use ($initial, $token, $uid, $reason) {
            if (!self::actor_available($uid)) return self::error('petish_club_signin', 'Your account is not available for community updates.');
            return self::with_pet_lock($initial['pet_id'], static function() use ($token, $uid, $reason) {
            $raw = self::find($token);
            if (!$raw || $raw['uid'] === (int) $uid) return self::error('petish_club_report', 'This report could not be submitted.');
            $key = self::REPORT . $raw['pet_id'] . '_' . absint($uid);
            if (!add_option($key, array('reason' => $reason, 'created' => gmdate('Y-m-d H:i:s')), '', false) && false === get_option($key, false)) return self::error('petish_club_report', 'This report could not be submitted. Please try again.');
            return true;
            });
        });
    }

    /** Trusted native verification/serving helper; never emit this result as JSON. */
    public static function resolve_photo($token, $moment_token = '') {
        if (!self::token_valid($token) || !is_string($moment_token) || ($moment_token !== '' && !self::token_valid($moment_token))) return self::error();
        $raw = self::find($token); if (!$raw) return self::error();
        return self::with_pet_lock($raw['pet_id'], static function() use ($token, $moment_token) {
            $raw = self::find($token); if (!$raw) return self::error();
            $key = $raw['snapshot']['photo'];
            if ($moment_token !== '') { $key = ''; foreach ($raw['snapshot']['moments'] as $moment) if (hash_equals($moment['token'], $moment_token)) { $key = $moment['photo']; break; } }
            $path = $key !== '' ? Petish_Passport_Media::file_for_owner($key, $raw['uid']) : false;
            if (!$path || is_wp_error($path)) return self::error();
            $types = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            return isset($types[$ext]) ? array('path' => $path, 'mime' => $types[$ext], 'extension' => $ext) : self::error();
        });
    }
    public static function serve_photo() {
        self::no_cache(); header('Cross-Origin-Resource-Policy: same-origin');
        $token = isset($_GET['token']) && is_string($_GET['token']) ? wp_unslash($_GET['token']) : '';
        $moment_token = isset($_GET['moment']) ? (is_string($_GET['moment']) ? wp_unslash($_GET['moment']) : null) : '';
        $raw = self::find($token); $sent = false;
        if ($raw && ($moment_token === '' || self::token_valid($moment_token))) {
            $sent = self::with_pet_lock($raw['pet_id'], static function() use ($token, $moment_token) {
                $resolved = self::resolve_photo($token, $moment_token);
                if (is_wp_error($resolved)) return false;
                $path = $resolved['path']; $ext = $resolved['extension'];
                header('Content-Type: ' . $resolved['mime']); header('Content-Length: ' . filesize($path)); header('Content-Disposition: inline; filename="pet-photo.' . $ext . '"');
                if ('HEAD' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) readfile($path);
                return true;
            });
        }
        if (true !== $sent) { status_header(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found.'; }
        exit;
    }
    public static function route() {
        if (!self::is_route()) return;
        self::no_cache();
        $profile = null;
        if (isset($_GET['petish_pet'])) $profile = self::public_profile(is_string($_GET['petish_pet']) ? wp_unslash($_GET['petish_pet']) : '');
        elseif (!is_string($_GET['petish_club']) || $_GET['petish_club'] !== '1') $profile = self::error();
        if (is_wp_error($profile)) status_header(404); else status_header(200);
        get_header();
        if (is_wp_error($profile)) echo '<main class="petish-passport pp-club-public"><h1>' . esc_html__('Passport not available', 'petish-passport') . '</h1><p>' . esc_html__('This pet may be private or no longer shared.', 'petish-passport') . '</p><a href="' . esc_url(self::url()) . '">' . esc_html__('Meet the Petish community', 'petish-passport') . '</a></main>';
        elseif ($profile) Petish_Passport_UI::render_public($profile);
        else Petish_Passport_UI::render_community(self::discover(24));
        get_footer(); exit;
    }
    private static function input($name) { return isset($_POST[$name]) && is_string($_POST[$name]) ? wp_unslash($_POST[$name]) : ''; }
    public static function authorized($action, $subject, $nonce = null) {
        return is_user_logged_in() && 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && is_string($nonce) && (bool) wp_verify_nonce($nonce, 'petish_club_' . $action . '_' . $subject);
    }
    private static function guard($action, $subject) {
        if (!self::authorized($action, $subject, self::input('petish_club_nonce'))) wp_die(esc_html__('Your session could not be verified. Refresh the page and try again.', 'petish-passport'), '', array('response' => 403));
    }
    private static function finish($result, $url, $success) {
        $error = is_wp_error($result);
        if (self::input('request_mode') === 'json') {
            if ($error) wp_send_json_error(array('message' => $result->get_error_message()), 400);
            wp_send_json_success(array('message' => $success, 'result' => $result));
        }
        if (function_exists('WC') && WC()) {
            if (!WC()->session) WC()->initialize_session();
            if (function_exists('wc_add_notice')) wc_add_notice($error ? $result->get_error_message() : $success, $error ? 'error' : 'success');
        }
        wp_safe_redirect($url, 303); exit;
    }
    public static function guest() { self::no_cache(); wp_safe_redirect(wp_login_url(self::url()), 303); exit; }
    public static function handle_publish() {
        $id = self::input('pet_id'); self::guard('publish', $id);
        $ids = isset($_POST['moment_ids']) && is_array($_POST['moment_ids']) ? wp_unslash($_POST['moment_ids']) : array();
        $result = self::id_valid($id) && self::input('confirm_share') === '1' && (!isset($_POST['moment_ids']) || is_array($_POST['moment_ids']))
            ? self::publish((int) $id, get_current_user_id(), self::input('share_age') === '1', $ids) : self::error('petish_club_consent', 'Choose the details to share and confirm publication.');
        self::finish($result, Petish_Passport_UI::url(), __('Your selected pet details are now shared with the Petish community.', 'petish-passport'));
    }
    public static function handle_unpublish() {
        $id = self::input('pet_id'); self::guard('unpublish', $id);
        self::finish(self::unpublish((int) $id, get_current_user_id()), Petish_Passport_UI::url(), __('Sharing is off. Your Passport is private.', 'petish-passport'));
    }
    public static function handle_heart() {
        $token = self::input('token'); self::guard('heart', $token);
        $desired = self::input('hearted');
        $result = in_array($desired, array('0', '1'), true) ? self::set_heart($token, get_current_user_id(), $desired === '1') : self::error('petish_club_input', 'Choose whether to add or remove your heart.');
        self::finish($result, self::url($token), $desired === '1' ? __('Heart added.', 'petish-passport') : __('Heart removed.', 'petish-passport'));
    }
    public static function handle_report() {
        $token = self::input('token'); self::guard('report', $token);
        self::finish(self::report($token, get_current_user_id(), self::input('reason')), self::url($token), __('Thank you. Your report is available to the Petish team for review.', 'petish-passport'));
    }

    public static function moderation_menu() { add_submenu_page('woocommerce', __('Pet Club moderation', 'petish-passport'), __('Pet Club moderation', 'petish-passport'), 'manage_woocommerce', 'petish-club-moderation', array(__CLASS__, 'moderation_page')); }
    public static function moderate($pet_id, $operation) {
        if (!current_user_can('manage_woocommerce') || !in_array($operation, array('hide', 'unblock'), true)) return self::error('petish_club_permission', 'You cannot moderate this Passport.');
        return self::with_pet_lock($pet_id, static function() use ($pet_id, $operation) {
            $post = get_post($pet_id);
            if (!$post || $post->post_type !== Petish_Passport_Store::TYPE || $post->post_status !== 'private') return self::error();
            if ($operation === 'unblock') { delete_post_meta($pet_id, self::BLOCKED); return !get_post_meta($pet_id, self::BLOCKED, true) ? true : self::error(); }
            update_post_meta($pet_id, self::BLOCKED, '1');
            if ('1' !== (string) get_post_meta($pet_id, self::BLOCKED, true)) return self::error();
            return self::unpublish($pet_id, (int) $post->post_author);
        });
    }
    public static function handle_moderate() {
        $id = self::input('pet_id'); self::guard('moderate', $id);
        self::finish(self::moderate((int) $id, self::input('operation')), admin_url('admin.php?page=petish-club-moderation'), __('The moderation setting was updated.', 'petish-passport'));
    }
    public static function moderation_page() {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('You do not have access to this page.', 'petish-passport'), '', array('response' => 403));
        global $wpdb;
        $reports = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 200", $wpdb->esc_like(self::REPORT) . '%'), ARRAY_A);
        $groups = array();
        foreach (is_array($reports) ? $reports : array() as $row) {
            if (!preg_match('/\Apetish_club_report_([1-9][0-9]*)_[1-9][0-9]*\z/D', $row['option_name'], $match)) continue;
            $value = maybe_unserialize($row['option_value']);
            $reason = is_array($value) && in_array($value['reason'] ?? '', array('spam', 'inappropriate', 'privacy', 'other'), true) ? $value['reason'] : 'other';
            $groups[(int) $match[1]][] = $reason;
        }
        $blocked = get_posts(array('post_type' => Petish_Passport_Store::TYPE, 'post_status' => 'private', 'posts_per_page' => 100, 'fields' => 'ids', 'meta_key' => self::BLOCKED, 'meta_value' => '1', 'no_found_rows' => true, 'suppress_filters' => true, 'cache_results' => false));
        foreach ($blocked as $id) if (!isset($groups[$id])) $groups[$id] = array();
        echo '<div class="wrap"><h1>' . esc_html__('Pet Club moderation', 'petish-passport') . '</h1><p>' . esc_html__('Review reported profiles. Hiding a profile also pauses new sharing until you unblock it. Unblocking keeps the profile private.', 'petish-passport') . '</p>';
        if (!$groups) echo '<p>' . esc_html__('No reported or paused profiles.', 'petish-passport') . '</p>';
        foreach ($groups as $id => $reasons) {
            $post = get_post($id); if (!$post || $post->post_type !== Petish_Passport_Store::TYPE) continue;
            $is_blocked = (bool) get_post_meta($id, self::BLOCKED, true); $raw = self::raw($id);
            echo '<section style="background:#fff;border:1px solid #ddd;padding:18px;margin:16px 0;max-width:800px"><h2>' . esc_html($post->post_title) . '</h2><p>' . esc_html($reasons ? implode(', ', $reasons) : __('Sharing paused', 'petish-passport')) . '</p>';
            if ($raw) echo '<p><a href="' . esc_url(self::url($raw['snapshot']['token'])) . '">' . esc_html__('View shared profile', 'petish-passport') . '</a></p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="petish_club_moderate"><input type="hidden" name="pet_id" value="' . esc_attr($id) . '"><input type="hidden" name="operation" value="' . ($is_blocked ? 'unblock' : 'hide') . '">';
            wp_nonce_field('petish_club_moderate_' . $id, 'petish_club_nonce');
            echo '<button class="button button-secondary" type="submit">' . esc_html($is_blocked ? __('Unblock sharing', 'petish-passport') : __('Hide and pause sharing', 'petish-passport')) . '</button></form></section>';
        }
        echo '</div>';
    }

    public static function exporters($items) { $items['petish-club'] = array('exporter_friendly_name' => 'Petish Club', 'callback' => array(__CLASS__, 'export')); return $items; }
    public static function erasers($items) { $items['petish-club'] = array('eraser_friendly_name' => 'Petish Club', 'callback' => array(__CLASS__, 'erase')); return $items; }
    public static function export($email, $page = 1) {
        $user = get_user_by('email', $email); if (!$user) return array('data' => array(), 'done' => true);
        $page = max(1, (int) $page);
        $rows = array(); $names = self::action_names(0, $user->ID, 101, ($page - 1) * 100); if (!is_array($names)) $names = array();
        foreach (array_slice($names, 0, 100) as $name) {
            if (!preg_match('/\Apetish_club_(heart|report)_([1-9][0-9]*)_[1-9][0-9]*\z/D', $name, $match)) continue;
            $raw = self::raw((int) $match[2]); $value = get_option($name);
            $rows[] = array('group_id' => 'petish-club', 'group_label' => 'Petish Club', 'item_id' => $name,
                'data' => array(array('name' => 'Interaction', 'value' => $match[1]), array('name' => 'Pet', 'value' => $raw ? $raw['snapshot']['name'] : 'No longer shared'),
                    array('name' => 'Recorded', 'value' => is_array($value) ? (string) ($value['created'] ?? '') : (string) $value),
                    array('name' => 'Reason', 'value' => is_array($value) ? (string) ($value['reason'] ?? '') : '')));
        }
        if ($page === 1) foreach (Petish_Passport_Store::all_for_user($user->ID) as $pet) {
            $raw = self::raw($pet['id']);
            // Owner exports include retained details even when revocation or a failed save
            // has made the public snapshot unavailable. Never export internal file keys.
            $snapshot = get_post_meta($pet['id'], self::META, true);
            $blocked = (bool) get_post_meta($pet['id'], self::BLOCKED, true);
            if (!is_array($snapshot)) {
                if ($blocked) $rows[] = array('group_id' => 'petish-club', 'group_label' => 'Petish Club', 'item_id' => 'sharing-paused-' . $pet['id'], 'data' => array(array('name' => 'Pet', 'value' => $pet['name']), array('name' => 'Sharing', 'value' => 'Paused by moderation')));
                continue;
            }
            $data = array(array('name' => 'Sharing', 'value' => $raw ? 'Public' : ($blocked ? 'Paused by moderation; cleanup pending' : 'Off; cleanup pending')));
            foreach (array('name', 'species', 'variety', 'age', 'bio', 'published') as $field) if (isset($snapshot[$field]) && is_string($snapshot[$field])) $data[] = array('name' => $field === 'variety' ? 'Breed / variety' : ucfirst($field), 'value' => $snapshot[$field]);
            $data[] = array('name' => 'Traits', 'value' => implode(', ', array_filter(is_array($snapshot['traits'] ?? null) ? $snapshot['traits'] : array(), 'is_string')));
            $data[] = array('name' => 'Shared profile', 'value' => $raw ? self::url($snapshot['token']) : 'Unavailable');
            $data[] = array('name' => 'Shared photo', 'value' => $raw && $snapshot['photo'] !== '' ? self::photo_url($snapshot['token']) : 'Unavailable or none');
            $moment_text = array(); $moment_photos = array();
            foreach (is_array($snapshot['moments'] ?? null) ? $snapshot['moments'] : array() as $moment) {
                if (!is_array($moment)) continue;
                $parts = array(); foreach (array('kind', 'created', 'caption') as $field) if (isset($moment[$field]) && is_string($moment[$field])) $parts[] = $moment[$field];
                $moment_text[] = implode(' | ', $parts);
                if ($raw && $moment['photo'] !== '') $moment_photos[] = self::photo_url($snapshot['token'], $moment['token']);
            }
            $data[] = array('name' => 'Shared moment photos', 'value' => $raw ? implode("\n", $moment_photos) : 'Unavailable');
            $data[] = array('name' => 'Shared moments', 'value' => implode("\n", $moment_text));
            $rows[] = array('group_id' => 'petish-club', 'group_label' => 'Petish Club', 'item_id' => 'shared-pet-' . $pet['id'], 'data' => $data);
        }
        return array('data' => $rows, 'done' => count($names) <= 100);
    }
    public static function erase($email, $page = 1) {
        $user = get_user_by('email', $email); $removed = false; $retained = false;
        if ($user) {
            foreach (Petish_Passport_Store::all_for_user($user->ID) as $pet) { $result = self::unpublish($pet['id'], $user->ID); if (is_wp_error($result)) $retained = true; else $removed = true; }
            if (true !== self::with_actor_lock($user->ID, static fn() => self::purge_actions(0, $user->ID))) $retained = true; else $removed = true;
        }
        return array('items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? array('Some Petish Club data needs another removal attempt.') : array(), 'done' => true);
    }
    public static function delete_user($uid) {
        if (true !== self::with_actor_lock($uid, static fn() => self::purge_actions(0, $uid))) wp_die(esc_html__('Community data could not be removed. Please retry account deletion.', 'petish-passport'), '', array('response' => 500));
    }
}
