<?php
/** Channel messages. Dedicated private storage; no commerce or Passport projections. */
defined('ABSPATH') || exit;

final class Petish_Chat_Stream {
    const OPTION_ENABLED = 'petish_chat_stream_enabled_v1';
    const OPTION_CONFIG = 'petish_chat_stream_channels_v1';
    const PREFIX = '_petish_chat_stream_';
    const REACTIONS = array('❤️', '👍', '😂', '😮', '😢', '🎉');
    private static $booted = false;
    private static $locked = false;
    public static function enabled() { return get_option('petish_chat_enabled_v1', 'no') === 'yes' && get_option(self::OPTION_ENABLED, 'no') === 'yes'; }
    private static function table($reactions = false) { global $wpdb; return $wpdb->prefix . ($reactions ? 'petish_chat_stream_reactions_v1' : 'petish_chat_stream_messages_v1'); }
    private static function error($code, $message = 'This Pet Chat action is not available.', $status = 400) { return new WP_Error('petish_chat_stream_' . $code, $message, array('status' => $status)); }
    private static function id($id) { return (is_int($id) || is_string($id)) && preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $id) ? (int) $id : 0; }
    private static function current($uid) { return self::id($uid) && is_user_logged_in() && (int) $uid === get_current_user_id(); }
    private static function member($uid) { return substr(hash_hmac('sha256', 'stream-member:' . (int) $uid, wp_salt('auth')), 0, 32); }
    private static function text($value, $max, $empty = false) {
        if (!is_string($value) || !preg_match('//u', $value)) return false;
        $value = trim(sanitize_textarea_field($value));
        return preg_match('/\A.{' . ($empty ? '0' : '1') . ',' . $max . '}\z/usD', $value) ? $value : false;
    }
    private static function cut($text, $length) { preg_match('/\A.{0,' . (int) $length . '}/us', $text, $match); return $match[0] ?? ''; }
    /** Explicit provisioning only. Boot, reads and feature flags never create tables. */
    public static function install_schema() {
        if (!current_user_can('manage_options') && !(defined('WP_CLI') && WP_CLI)) return self::error('permission', 'Only staff can provision Chat storage.', 403);
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $messages = self::table(); $reactions = self::table(true); $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $messages (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
author_id bigint(20) unsigned NOT NULL,
member_key varchar(32) NOT NULL,
channel varchar(48) NOT NULL,
alias varchar(160) NOT NULL,
body longtext NOT NULL,
status varchar(16) NOT NULL,
quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
media longtext NOT NULL,
reports longtext NOT NULL,
created_gmt datetime NOT NULL,
updated_gmt datetime NOT NULL,
revision bigint(20) unsigned NOT NULL DEFAULT 1,
request_hash varchar(64) NOT NULL,
payload_hash varchar(64) NOT NULL,
legacy_key varchar(64) DEFAULT NULL,
legacy_hash varchar(64) DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY actor_request (author_id,request_hash),
UNIQUE KEY legacy_key (legacy_key),
KEY channel_status (channel,status,id),
KEY member_key (member_key),
KEY author_created (author_id,created_gmt)
) $charset;");
        dbDelta("CREATE TABLE $reactions (
message_id bigint(20) unsigned NOT NULL,
actor_id bigint(20) unsigned NOT NULL,
emoji varchar(32) NOT NULL,
PRIMARY KEY  (message_id,actor_id,emoji),
KEY actor_id (actor_id)
) $charset;");
        return self::storage() ? true : self::error('schema', 'Chat storage could not be prepared.', 500);
    }
    private static function storage() { global $wpdb; foreach (array(self::table(), self::table(true)) as $table) if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return false; return true; }
    public static function boot() {
        if (self::$booted) return; self::$booted = true;
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
        add_action('delete_user', array(__CLASS__, 'delete_user'), 2);
        add_action('wpmu_delete_user', array(__CLASS__, 'delete_user'), 2);
    }
    public static function account_status($uid) {
        $available = self::enabled() && self::current($uid) && get_user_by('id', $uid) && !get_user_meta($uid, '_petish_chat_deleting', true);
        return array('available' => (bool) $available, 'can_post' => (bool) ($available && !get_user_meta($uid, '_petish_chat_muted', true)), 'is_staff' => (bool) ($available && current_user_can('manage_options')));
    }
    private static function actor($uid, $posting = true) {
        $status = self::account_status($uid);
        if (!$status['available'] || ($posting && !$status['can_post'])) return self::error('permission', 'Sign in with an available Chat account to continue.', 403);
        return self::storage() ? true : self::error('storage', 'Pet Chat is being prepared. Please try again later.', 503);
    }
    private static function manager($uid) { $allowed = self::actor($uid, false); return is_wp_error($allowed) ? $allowed : (current_user_can('manage_options') ? true : self::error('permission', 'Only staff can manage Pet Chat.', 403)); }
    private static function lock($callback) {
        global $wpdb;
        if (self::$locked) return $callback();
        $key = 'petish-stream-' . substr(hash('sha256', $wpdb->prefix . ':' . (defined('DB_NAME') ? DB_NAME : '')), 0, 40);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $key)) !== '1') return self::error('busy', 'Chat is being updated. Please try again.', 409);
        self::$locked = true;
        try { return $callback(); } finally { self::$locked = false; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
    }
    private static function config() {
        $value = get_option(self::OPTION_CONFIG, null);
        if (is_array($value) && isset($value['categories'], $value['channels'], $value['revision'], $value['audit'])) return $value;
        $categories = array('community' => array('id' => 'community', 'name' => 'Pet community', 'order' => 0, 'archived' => false, 'revision' => 1));
        $channels = array();
        foreach (array('general' => 'General chat', 'care' => 'Care and wellbeing', 'food' => 'Food and nutrition', 'habitat' => 'Homes and enrichment') as $id => $name) $channels[$id] = array('id' => $id, 'name' => $name, 'category_id' => 'community', 'description' => '', 'order' => count($channels), 'read_only' => false, 'archived' => false, 'revision' => 1);
        return array('categories' => $categories, 'channels' => $channels, 'revision' => 1, 'audit' => array());
    }
    private static function ordered($rows) { usort($rows, static function($a, $b) { return ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0)) ?: strcmp($a['id'], $b['id']); }); return $rows; }
    public static function categories($archived = false) { if (!self::enabled()) return array(); return self::ordered(array_values(array_filter(self::config()['categories'], static function($row) use ($archived) { return !$row['archived'] || ($archived && current_user_can('manage_options')); }))); }
    public static function channels($archived = false) {
        if (!self::enabled()) return array(); $config = self::config();
        return self::ordered(array_values(array_filter($config['channels'], static function($row) use ($archived, $config) { return (!$row['archived'] && empty($config['categories'][$row['category_id']]['archived'])) || ($archived && current_user_can('manage_options')); })));
    }
    private static function channel($id, $posting = false) {
        $row = is_string($id) ? (self::config()['channels'][$id] ?? null) : null;
        if (!$row || $row['archived'] || !empty(self::config()['categories'][$row['category_id']]['archived'])) return self::error('channel', 'This channel is not available.', 404);
        if ($posting && $row['read_only']) return self::error('readonly', 'This channel is read-only.', 403);
        return $row;
    }
    private static function row($id) { global $wpdb; return self::id($id) ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d', $id), ARRAY_A) : null; }
    private static function visible($row, $viewer) {
        if (!$row || !self::enabled() || is_wp_error(self::channel($row['channel'])) || !in_array($row['status'], array('published', 'pending', 'draft', 'hidden', 'spam'), true)) return false;
        if ($row['status'] === 'published') return true;
        return self::current($viewer) && ((int) $row['author_id'] === (int) $viewer || current_user_can('manage_options'));
    }
    private static function muted($uid) { $value = self::current($uid) ? get_user_meta($uid, self::PREFIX . 'mutes', true) : array(); return is_array($value) ? $value : array(); }
    private static function project($row, $viewer, $quote = true) {
        global $wpdb;
        $own = self::current($viewer) && (int) $row['author_id'] === (int) $viewer;
        $staff = self::current($viewer) && current_user_can('manage_options');
        $counts = array(); $mine = array();
        foreach ($wpdb->get_results($wpdb->prepare('SELECT emoji,COUNT(*) AS total FROM ' . self::table(true) . ' WHERE message_id=%d GROUP BY emoji', $row['id']), ARRAY_A) as $reaction) if (in_array($reaction['emoji'], self::REACTIONS, true)) $counts[$reaction['emoji']] = (int) $reaction['total'];
        if (self::current($viewer)) $mine = $wpdb->get_col($wpdb->prepare('SELECT emoji FROM ' . self::table(true) . ' WHERE message_id=%d AND actor_id=%d', $row['id'], $viewer));
        $quoted = $quote && $row['quote_id'] ? self::row($row['quote_id']) : null;
        if (!$quoted || $quoted['channel'] !== $row['channel'] || $quoted['status'] !== 'published' || isset(self::muted($viewer)[$quoted['member_key']])) $quoted = null;
        $media = array();
        if (class_exists('Petish_Chat_Stream_Media')) foreach (self::records($row) as $record) $media[] = Petish_Chat_Stream_Media::public_record($record, (int) $row['id']);
        return array('id' => (int) $row['id'], 'channel' => $row['channel'], 'member_id' => $row['member_key'], 'alias' => $row['alias'], 'initials' => strtoupper(self::cut($row['alias'], 2)), 'body' => $row['body'], 'status' => $row['status'], 'created_gmt' => $row['created_gmt'], 'updated_gmt' => $row['updated_gmt'], 'revision' => (int) $row['revision'], 'quote' => $quoted ? array('id' => (int) $quoted['id'], 'alias' => $quoted['alias'], 'body' => self::cut($quoted['body'], 160)) : null, 'media' => $media, 'reactions' => $counts, 'own_reactions' => $mine, 'own' => $own, 'can_edit' => ($own || $staff) && in_array($row['status'], array('published', 'pending', 'draft'), true), 'can_remove' => $own || $staff);
    }
    public static function state($channel, $viewer = 0, $options = array()) {
        global $wpdb;
        if (!self::enabled()) return self::error('disabled', 'Pet Chat is not enabled.', 404);
        if ($viewer && !self::current($viewer)) return self::error('permission', 'Refresh your account session.', 403);
        $selected = self::channel($channel); if (is_wp_error($selected)) return $selected;
        if (!self::storage()) return self::error('storage', 'Pet Chat is being prepared.', 503);
        if (!is_array($options)) return self::error('input');
        $limit = max(1, min(50, (int) ($options['limit'] ?? 40))); $clauses = array('channel=%s'); $args = array($channel);
        if ($viewer) { $clauses[] = "(status='published' OR (author_id=%d AND status IN ('pending','draft')))"; $args[] = $viewer; }
        else $clauses[] = "status='published'";
        foreach (array('before_id' => '<', 'after_id' => '>') as $key => $operator) if (!empty($options[$key])) { $value = self::id($options[$key]); if (!$value) return self::error('cursor'); $clauses[] = 'id' . $operator . '%d'; $args[] = $value; }
        $search = self::text($options['search'] ?? '', 100, true); if ($search === false) return self::error('search');
        if ($search !== '') { $clauses[] = 'body LIKE %s'; $args[] = '%' . $wpdb->esc_like($search) . '%'; }
        $mutes = self::muted($viewer);
        if ($mutes) { $clauses[] = 'member_key NOT IN (' . implode(',', array_fill(0, count($mutes), '%s')) . ')'; $args = array_merge($args, array_keys($mutes)); }
        $args[] = $limit + 1;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $clauses) . ' ORDER BY id DESC LIMIT %d', $args), ARRAY_A);
        $more = count($rows) > $limit; $rows = array_reverse(array_slice($rows, 0, $limit));
        $messages = array_map(static function($row) use ($viewer) { return self::project($row, $viewer); }, $rows);
        $refreshed = array(); $removed = array();
        if (isset($options['visible_ids'])) {
            $ids = $options['visible_ids']; if (!is_array($ids) || array_values($ids) !== $ids || count($ids) > 200) return self::error('cursor');
            foreach ($ids as &$id) { $id = self::id($id); if (!$id) return self::error('cursor'); } unset($id); $ids = array_values(array_unique($ids));
            foreach ($ids as $id) { $row = self::row($id); if ($row && $row['channel'] === $channel && ($row['status'] === 'published' || (self::current($viewer) && (int) $row['author_id'] === (int) $viewer && in_array($row['status'], array('pending', 'draft'), true))) && !isset($mutes[$row['member_key']])) $refreshed[] = self::project($row, $viewer); else $removed[] = $id; }
        }
        $status = self::account_status($viewer); $unread = array(); $reads = $viewer ? get_user_meta($viewer, self::PREFIX . 'reads', true) : array(); $reads = is_array($reads) ? $reads : array();
        foreach (self::channels() as $room) {
            $query = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE channel=%s AND status='published' AND id>%d AND author_id<>%d"; $values = array($room['id'], (int) ($reads[$room['id']] ?? 0), $viewer);
            if ($mutes) { $query .= ' AND member_key NOT IN (' . implode(',', array_fill(0, count($mutes), '%s')) . ')'; $values = array_merge($values, array_keys($mutes)); }
            $unread[$room['id']] = $viewer ? (int) $wpdb->get_var($wpdb->prepare($query, $values)) : 0;
        }
        return array('revision' => self::config()['revision'], 'categories' => self::categories(), 'channels' => self::channels(), 'channel' => $selected, 'messages' => $messages, 'refreshed_messages' => $refreshed, 'removed_ids' => $removed, 'cursor' => array('oldest_id' => $messages ? $messages[0]['id'] : 0, 'latest_id' => $messages ? end($messages)['id'] : 0, 'has_more' => $more), 'viewer' => array('signed_in' => (bool) $status['available'], 'alias' => $status['available'] ? (string) get_user_meta($viewer, '_petish_chat_alias', true) : '', 'can_post' => $status['can_post'] && !$selected['read_only'], 'is_staff' => $status['is_staff'], 'member_id' => $status['available'] ? self::member($viewer) : '', 'muted_members' => array_keys($mutes)), 'unread' => $unread, 'presence' => self::online($mutes), 'management' => $status['is_staff'] ? array('categories' => self::categories(true), 'channels' => self::channels(true), 'revision' => self::config()['revision']) : null);
    }
    private static function ledger($uid, $kind) {
        $now = time(); $key = self::PREFIX . 'events'; $events = get_user_meta($uid, $key, true); $events = is_array($events) ? $events : array();
        $events = array_values(array_filter($events, static function($event) use ($now) { return is_array($event) && (int) ($event['at'] ?? 0) > $now - DAY_IN_SECONDS; }));
        $same = array_filter($events, static function($event) use ($kind) { return ($event['kind'] ?? '') === $kind; });
        if (count($events) >= 1000 || ($kind === 'send' && (count($same) >= 500 || ($same && $now - max(array_column($same, 'at')) < 2))) || ($kind === 'report' && count($same) >= 20) || ($kind !== 'send' && $kind !== 'report' && count($same) >= 240)) return self::error('rate', 'Please slow down and try again later.', 429);
        $events[] = array('kind' => $kind, 'at' => $now); update_user_meta($uid, $key, $events);
        return get_user_meta($uid, $key, true) === $events ? true : self::error('save', 'Your action could not be saved.', 500);
    }
    private static function trusted($uid) {
        global $wpdb;
        if (get_option('petish_chat_moderation_v1', 'first_review') === 'all_review') return false;
        if (get_user_meta($uid, self::PREFIX . 'trusted', true)) return true;
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . " WHERE author_id=%d AND status='published' LIMIT 1", $uid))) return true;
        $legacy = "p.post_type='petish_chat_topic' AND p.post_status='publish' AND EXISTS(SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_petish_chat_ready' AND m.meta_value='1') AND EXISTS(SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_petish_chat_consent' AND m.meta_value='1')";
        if ($wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE $legacy AND p.post_author=%d LIMIT 1", $uid))) return true;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT c.comment_ID FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE $legacy AND c.comment_type='petish_chat' AND c.user_id=%d AND c.comment_approved='1' AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key='_petish_chat_ready' AND m.meta_value='1') AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key='_petish_chat_consent' AND m.meta_value='1') LIMIT 1", $uid));
    }
    public static function send($uid, $channel, $data) {
        return self::lock(static function() use ($uid, $channel, $data) {
            global $wpdb;
            $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed;
            $room = self::channel($channel, true); if (is_wp_error($room)) return $room;
            if (!is_array($data)) return self::error('input');
            $draft = ($data['attachment_pending'] ?? false) === true; $body = self::text($data['body'] ?? '', 1000, $draft);
            $alias = self::text($data['alias'] ?? get_user_meta($uid, '_petish_chat_alias', true), 40);
            if ($body === false || $alias === false || preg_match('/[\r\n]/', $alias)) return self::error('length', 'Use a public name up to 40 characters and a message up to 1,000 characters.');
            if (!current_user_can('manage_options') && preg_match('/\b(?:petish|admin(?:istrator)?|moderator|staff|support)\b/iu', $alias)) return self::error('alias', 'Choose a public name that does not suggest you are staff.');
            if (!in_array($data['consent'] ?? null, array(true, 1, '1'), true)) return self::error('consent', 'Confirm that your public name and contribution will be visible to everyone.');
            $request = $data['request_id'] ?? null;
            if (!is_string($request) || !preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\z/D', $request)) return self::error('request', 'Refresh the message form.');
            $quote_id = empty($data['quote_id']) ? 0 : self::id($data['quote_id']); $quote = $quote_id ? self::row($quote_id) : null;
            if ((!empty($data['quote_id']) && !$quote_id) || ($quote_id && (!$quote || $quote['channel'] !== $channel || $quote['status'] !== 'published'))) return self::error('quote', 'Reply to a published message in this channel.');
            $payload = hash('sha256', wp_json_encode(array($channel, $body, $alias, $quote_id, $draft))); $request_hash = hash('sha256', $request);
            $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE author_id=%d AND request_hash=%s', $uid, $request_hash), ARRAY_A);
            if ($existing) {
                if (!hash_equals($existing['payload_hash'], $payload)) return self::error('conflict', 'This submission was already used for another message.', 409);
                if ($existing['status'] === 'deleted') return array('id' => (int) $existing['id'], 'removed' => true, 'duplicate' => true);
                return array('message' => self::project($existing, $uid), 'duplicate' => true);
            }
            $rate = self::ledger($uid, 'send'); if (is_wp_error($rate)) return $rate;
            $now = current_time('mysql', true); $status = $draft ? 'draft' : (self::trusted($uid) ? 'published' : 'pending');
            $saved = $wpdb->insert(self::table(), array('author_id' => $uid, 'member_key' => self::member($uid), 'channel' => $channel, 'alias' => $alias, 'body' => $body, 'status' => $status, 'quote_id' => $quote_id, 'media' => '[]', 'reports' => '[]', 'created_gmt' => $now, 'updated_gmt' => $now, 'revision' => 1, 'request_hash' => $request_hash, 'payload_hash' => $payload));
            if (!$saved) return self::error('save', 'Your message could not be saved.', 500);
            $id = (int) $wpdb->insert_id; update_user_meta($uid, '_petish_chat_alias', $alias); update_user_meta($uid, self::PREFIX . 'consent', '1');
            return array('message' => self::project(self::row($id), $uid), 'duplicate' => false);
        });
    }
    public static function edit($uid, $id, $data) {
        return self::lock(static function() use ($uid, $id, $data) {
            global $wpdb;
            $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed; $row = self::row($id);
            if (!$row || ((int) $row['author_id'] !== (int) $uid && !current_user_can('manage_options')) || !self::visible($row, $uid)) return self::error('permission', 'You cannot edit this message.', 403);
            $room = self::channel($row['channel'], true); if (is_wp_error($room)) return $room;
            if (!is_array($data) || self::id($data['revision'] ?? 0) !== (int) $row['revision']) return self::error('conflict', 'This message changed. Refresh before editing.', 409);
            $body = self::text($data['body'] ?? null, 1000, (bool) self::records($row)); if ($body === false) return self::error('length', 'Use up to 1,000 characters.');
            $rate = self::ledger($uid, 'mutate'); if (is_wp_error($rate)) return $rate;
            // Edits to published text return to staff review; unreviewed replacement is never public.
            $status = $row['status'] === 'draft' ? 'draft' : 'pending';
            if (false === $wpdb->update(self::table(), array('body' => $body, 'status' => $status, 'updated_gmt' => current_time('mysql', true), 'revision' => (int) $row['revision'] + 1), array('id' => $id))) return self::error('save', 'Your edit could not be saved.', 500);
            return array('message' => self::project(self::row($id), $uid), 'duplicate' => false);
        });
    }
    private static function records($row) { $records = json_decode($row['media'] ?? '[]', true); return is_array($records) ? $records : array(); }
    public static function remove($uid, $id) {
        return self::lock(static function() use ($uid, $id) {
            global $wpdb;
            $allowed = self::actor($uid, false); if (is_wp_error($allowed)) return $allowed; $row = self::row($id);
            if (!$row || ((int) $row['author_id'] !== (int) $uid && !current_user_can('manage_options'))) return self::error('permission', 'You cannot remove this message.', 403);
            $rate = self::ledger($uid, 'mutate'); if (is_wp_error($rate)) return $rate;
            if (false === $wpdb->update(self::table(), array('body' => '', 'alias' => '', 'media' => '[]', 'reports' => '[]', 'status' => 'deleted', 'updated_gmt' => current_time('mysql', true), 'revision' => (int) $row['revision'] + 1), array('id' => $id))) return self::error('save', 'Your message could not be removed.', 500);
            $wpdb->delete(self::table(true), array('message_id' => $id)); $clean = self::delete_media($row);
            return array('id' => (int) $id, 'removed' => true, 'media_cleanup_pending' => !$clean);
        });
    }
    private static function delete_media($row) { $records = self::records($row); if (!$records) return true; if (!class_exists('Petish_Chat_Stream_Media')) return false; $ok = true; foreach ($records as $record) $ok = Petish_Chat_Stream_Media::remove($record, (int) $row['author_id'], (int) $row['id']) && $ok; return $ok; }
    public static function may_upload($id, $uid) { $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed; $row = self::row($id); if (!$row || (int) $row['author_id'] !== (int) $uid || !in_array($row['status'], array('draft', 'pending', 'published'), true) || self::records($row)) return self::error('permission', 'This message cannot accept an attachment.', 403); $room = self::channel($row['channel'], true); return is_wp_error($room) ? $room : true; }
    /** The transport passes only records returned by the trusted media save API, never client JSON. */
    public static function attach_media($uid, $id, $records) {
        return self::lock(static function() use ($uid, $id, $records) {
            global $wpdb;
            $allowed = self::may_upload($id, $uid); if (is_wp_error($allowed)) return $allowed;
            if (!class_exists('Petish_Chat_Stream_Media') || !is_array($records) || count($records) !== 1 || !Petish_Chat_Stream_Media::valid_manifest($records, $uid, $id)) return self::error('media', 'The attachment could not be verified.');
            foreach ($records as $record) if (!Petish_Chat_Stream_Media::file_for_record($record, $uid, $id)) return self::error('media', 'The attachment file is not available.');
            $row = self::row($id); $status = $row['status'] === 'draft' ? (self::trusted($uid) ? 'published' : 'pending') : 'pending';
            if (false === $wpdb->update(self::table(), array('media' => wp_json_encode($records), 'status' => $status, 'revision' => (int) $row['revision'] + 1, 'updated_gmt' => current_time('mysql', true)), array('id' => $id))) return self::error('save', 'The attachment could not be attached.', 500);
            return array('message' => self::project(self::row($id), $uid), 'duplicate' => false);
        });
    }
    public static function media_is_attached($uid, $id, $token, $key) { global $wpdb; if (!self::storage()) return self::error('storage'); $row = self::row($id); if ($wpdb->last_error) return self::error('storage'); if (!$row || (int) $row['author_id'] !== (int) $uid || $row['status'] === 'deleted') return false; foreach (self::records($row) as $record) if (($record['token'] ?? '') === $token && ($record['key'] ?? '') === $key) return true; return false; }
    public static function media_for_viewer($id, $token, $viewer = 0) { if (!self::enabled() || !self::storage() || ($viewer && !self::current($viewer))) return self::error('media', 'Attachment not found.', 404); $row = self::row($id); if (!self::visible($row, $viewer) || isset(self::muted($viewer)[$row['member_key']])) return self::error('media', 'Attachment not found.', 404); foreach (self::records($row) as $record) if (is_string($token) && hash_equals($record['token'] ?? '', $token)) return $record; return self::error('media', 'Attachment not found.', 404); }
    public static function react($uid, $id, $emoji) {
        return self::lock(static function() use ($uid, $id, $emoji) {
            global $wpdb;
            $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed; $row = self::row($id);
            if (!$row || $row['status'] !== 'published' || !self::visible($row, $uid) || isset(self::muted($uid)[$row['member_key']]) || !in_array($emoji, self::REACTIONS, true)) return self::error('reaction', 'Choose an available message and reaction.');
            $rate = self::ledger($uid, 'mutate'); if (is_wp_error($rate)) return $rate;
            $where = array('message_id' => $id, 'actor_id' => $uid, 'emoji' => $emoji);
            $existing = $wpdb->get_var($wpdb->prepare('SELECT message_id FROM ' . self::table(true) . ' WHERE message_id=%d AND actor_id=%d AND emoji=%s', $id, $uid, $emoji));
            $saved = $existing ? $wpdb->delete(self::table(true), $where) : $wpdb->insert(self::table(true), $where);
            return $saved === false ? self::error('save', 'Reaction could not be saved.', 500) : array('message' => self::project($row, $uid), 'duplicate' => false);
        });
    }
    public static function mute($uid, $target, $muted) {
        return self::lock(static function() use ($uid, $target, $muted) {
            $allowed = self::actor($uid, false); if (is_wp_error($allowed)) return $allowed;
            if (!is_string($target) || !preg_match('/\A[a-f0-9]{32}\z/D', $target) || $target === self::member($uid) || !is_bool($muted)) return self::error('member');
            $mutes = self::muted($uid); if ($muted && count($mutes) >= 200 && !isset($mutes[$target])) return self::error('limit', 'Your mute list is full.');
            $rate = self::ledger($uid, 'mutate'); if (is_wp_error($rate)) return $rate;
            if ($muted) $mutes[$target] = 1; else unset($mutes[$target]); update_user_meta($uid, self::PREFIX . 'mutes', $mutes);
            return array('muted_members' => array_keys($mutes));
        });
    }
    public static function mark_read($uid, $channel, $id) {
        return self::lock(static function() use ($uid, $channel, $id) {
            $allowed = self::actor($uid, false); if (is_wp_error($allowed)) return $allowed; $room = self::channel($channel); if (is_wp_error($room)) return $room;
            $row = self::row($id); if (!$row || $row['channel'] !== $channel || $row['status'] !== 'published') return self::error('cursor');
            $reads = get_user_meta($uid, self::PREFIX . 'reads', true); $reads = is_array($reads) ? $reads : array(); $reads[$channel] = max((int) ($reads[$channel] ?? 0), (int) $id);
            $reads = array_intersect_key($reads, self::config()['channels']); update_user_meta($uid, self::PREFIX . 'reads', $reads); return array('channel' => $channel, 'read_id' => $reads[$channel]);
        });
    }
    public static function presence($uid) {
        return self::lock(static function() use ($uid) {
            $allowed = self::actor($uid, false); if (is_wp_error($allowed)) return $allowed;
            if (get_user_meta($uid, self::PREFIX . 'consent', true) !== '1' || !get_user_meta($uid, '_petish_chat_alias', true)) return array('presence' => self::online(self::muted($uid)));
            if ((int) get_user_meta($uid, self::PREFIX . 'presence', true) < time() - 30) update_user_meta($uid, self::PREFIX . 'presence', time());
            return array('presence' => self::online(self::muted($uid)));
        });
    }
    private static function online($mutes = array()) {
        global $wpdb; $result = array();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND CAST(meta_value AS UNSIGNED)>%d ORDER BY CAST(meta_value AS UNSIGNED) DESC LIMIT 30", self::PREFIX . 'presence', time() - 120));
        foreach ($ids as $uid) { $member = self::member($uid); $alias = self::text(get_user_meta($uid, '_petish_chat_alias', true), 40); if ($alias !== false && !isset($mutes[$member]) && !get_user_meta($uid, '_petish_chat_deleting', true) && get_user_meta($uid, self::PREFIX . 'consent', true) === '1') $result[] = array('member_id' => $member, 'alias' => $alias); }
        return $result;
    }
    public static function report($uid, $id, $reason) {
        return self::lock(static function() use ($uid, $id, $reason) {
            global $wpdb;
            $allowed = self::actor($uid, false); if (is_wp_error($allowed)) return $allowed; $row = self::row($id);
            if (!$row || $row['status'] !== 'published' || !self::visible($row, $uid) || !in_array($reason, array('privacy', 'abuse', 'unsafe', 'spam', 'other'), true)) return self::error('report');
            $reports = json_decode($row['reports'], true); $reports = is_array($reports) ? $reports : array();
            if (isset($reports[$uid])) return array('id' => (int) $id, 'reported' => true, 'duplicate' => true);
            if (count($reports) >= 1000) return self::error('limit'); $rate = self::ledger($uid, 'report'); if (is_wp_error($rate)) return $rate;
            $reports[$uid] = array('reason' => $reason, 'at' => current_time('mysql', true));
            return false === $wpdb->update(self::table(), array('reports' => wp_json_encode($reports)), array('id' => $id)) ? self::error('save') : array('id' => (int) $id, 'reported' => true);
        });
    }
    public static function manage($uid, $data) {
        return self::lock(static function() use ($uid, $data) {
            $allowed = self::manager($uid); if (is_wp_error($allowed)) return $allowed; if (!is_array($data)) return self::error('input');
            $op = $data['op'] ?? '';
            if (in_array($op, array('reorder_categories', 'reorder_channels'), true)) return self::reorder($uid, $data);
            if (!is_string($op) || !preg_match('/\A(create|update|archive|restore)_(category|channel)\z/D', $op, $parts)) return self::error('operation');
            $config = self::config(); $bucket = $parts[2] === 'category' ? 'categories' : 'channels'; $create = $parts[1] === 'create';
            $name = self::text($data['name'] ?? '', 80); $id = $data['id'] ?? ($name !== false ? sanitize_title($name) : '');
            if (!is_string($id) || !preg_match('/\A[a-z0-9][a-z0-9-]{0,47}\z/D', $id)) return self::error('identity');
            if ($create && (isset($config[$bucket][$id]) || count($config[$bucket]) >= 50)) return self::error('conflict', 'That item already exists or the channel limit is reached.', 409);
            $row = $config[$bucket][$id] ?? null;
            if (!$create && (!$row || self::id($data['revision'] ?? 0) !== (int) $row['revision'])) return self::error('conflict', 'This setting changed. Refresh before saving.', 409);
            if ($create) $row = $bucket === 'categories' ? array('id' => $id, 'name' => '', 'order' => count($config[$bucket]), 'archived' => false, 'revision' => 0) : array('id' => $id, 'name' => '', 'category_id' => 'community', 'description' => '', 'order' => count($config[$bucket]), 'read_only' => false, 'archived' => false, 'revision' => 0);
            if (in_array($parts[1], array('create', 'update'), true)) {
                if ($name === false) return self::error('name'); $row['name'] = $name;
                if ($bucket === 'channels') { $category = $data['category_id'] ?? $row['category_id']; $description = self::text($data['description'] ?? $row['description'], 300, true); if (!is_string($category) || !isset($config['categories'][$category]) || $description === false) return self::error('category'); $row['category_id'] = $category; $row['description'] = $description; foreach (array('read_only', 'archived') as $key) if (isset($data[$key])) { if (!is_bool($data[$key])) return self::error('input'); $row[$key] = $data[$key]; } }
            } else $row['archived'] = $parts[1] === 'archive';
            $row['revision']++; $config[$bucket][$id] = $row; $config['revision']++; $config['audit'][] = array('actor' => (int) $uid, 'op' => $op, 'id' => $id, 'revision' => $config['revision'], 'at' => current_time('mysql', true)); $config['audit'] = array_slice($config['audit'], -100);
            update_option(self::OPTION_CONFIG, $config, false); if (get_option(self::OPTION_CONFIG) !== $config) return self::error('save', 'Channel settings could not be saved.', 500);
            return array('categories' => self::categories(true), 'channels' => self::channels(true), 'revision' => $config['revision']);
        });
    }
    private static function reorder($uid, $data) {
        $config = self::config(); $bucket = $data['op'] === 'reorder_categories' ? 'categories' : 'channels'; $ids = $data['ids'] ?? null;
        if (self::id($data['revision'] ?? 0) !== (int) $config['revision'] || !is_array($ids) || array_values($ids) !== $ids || count($ids) > 50 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) return self::error('conflict', 'Refresh the channel order before saving.', 409);
        $expected = $config[$bucket];
        if ($bucket === 'channels' && isset($data['category_id'])) { if (!is_string($data['category_id']) || !isset($config['categories'][$data['category_id']])) return self::error('category'); $expected = array_filter($expected, static function($row) use ($data) { return $row['category_id'] === $data['category_id']; }); }
        foreach ($ids as $id) if (!is_string($id) || !isset($expected[$id])) return self::error('order');
        if (count($ids) !== count($expected)) return self::error('order', 'Include each item in this group exactly once.');
        foreach ($ids as $position => $id) { $config[$bucket][$id]['order'] = $position; $config[$bucket][$id]['revision']++; }
        $config['revision']++; $config['audit'][] = array('actor' => (int) $uid, 'op' => $data['op'], 'ids' => $ids, 'at' => current_time('mysql', true)); $config['audit'] = array_slice($config['audit'], -100); update_option(self::OPTION_CONFIG, $config, false);
        return get_option(self::OPTION_CONFIG) === $config ? array('categories' => self::categories(true), 'channels' => self::channels(true), 'revision' => $config['revision']) : self::error('save');
    }
    public static function moderation_queue($uid, $page = 1) {
        global $wpdb; $allowed = self::manager($uid); if (is_wp_error($allowed)) return $allowed; $page = max(1, min(10000, (int) $page));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE status='pending' OR (status='published' AND reports<>'[]') ORDER BY id LIMIT 41 OFFSET %d", ($page - 1) * 40), ARRAY_A);
        $items = array(); foreach (array_slice($rows, 0, 40) as $row) { $item = self::project($row, $uid); $item['reports'] = json_decode($row['reports'], true); $items[] = $item; }
        return array('items' => $items, 'page' => $page, 'has_more' => count($rows) > 40);
    }
    public static function moderate($uid, $data) {
        return self::lock(static function() use ($uid, $data) {
            global $wpdb; $allowed = self::manager($uid); if (is_wp_error($allowed)) return $allowed; if (!is_array($data)) return self::error('input'); $op = $data['op'] ?? '';
            if (in_array($op, array('restrict', 'unrestrict'), true)) {
                $token = $data['member_id'] ?? ''; if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/D', $token)) return self::error('member');
                $target = (int) $wpdb->get_var($wpdb->prepare('SELECT author_id FROM ' . self::table() . ' WHERE member_key=%s LIMIT 1', $token));
                if (!$target || $target === (int) $uid || user_can($target, 'manage_options')) return self::error('member');
                if ($op === 'restrict') update_user_meta($target, '_petish_chat_muted', '1'); else delete_user_meta($target, '_petish_chat_muted');
                self::audit($uid, $op, $token); return array('member_id' => $token, 'restricted' => $op === 'restrict');
            }
            $row = self::row($data['id'] ?? 0); if (!$row || !in_array($op, array('approve', 'hide', 'spam'), true) || !in_array($row['status'], array('pending', 'published', 'hidden', 'spam'), true) || ($row['body'] === '' && !self::records($row))) return self::error('message');
            $status = array('approve' => 'published', 'hide' => 'hidden', 'spam' => 'spam')[$op];
            if (false === $wpdb->update(self::table(), array('status' => $status, 'reports' => '[]', 'revision' => (int) $row['revision'] + 1, 'updated_gmt' => current_time('mysql', true)), array('id' => $row['id']))) return self::error('save');
            if ($op === 'approve') update_user_meta((int) $row['author_id'], self::PREFIX . 'trusted', '1');
            self::audit($uid, $op, (string) $row['id']); return array('message' => self::project(self::row($row['id']), $uid));
        });
    }
    private static function audit($uid, $op, $id) { $config = self::config(); $config['audit'][] = array('actor' => (int) $uid, 'op' => $op, 'id' => $id, 'at' => current_time('mysql', true)); $config['audit'] = array_slice($config['audit'], -100); update_option(self::OPTION_CONFIG, $config, false); }
    /** Staff review hashes bind an explicit import to an immutable legacy snapshot. */
    private static function legacy_rows() {
        global $wpdb;
        $topics = $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type='petish_chat_topic' ORDER BY ID", ARRAY_A);
        $replies = $wpdb->get_results("SELECT c.* FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type='petish_chat_topic' AND c.comment_type='petish_chat' ORDER BY c.comment_ID", ARRAY_A);
        $meta = $wpdb->get_results("SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='petish_chat_topic' ORDER BY m.meta_id", ARRAY_A);
        $commentmeta = $wpdb->get_results("SELECT m.* FROM {$wpdb->commentmeta} m INNER JOIN {$wpdb->comments} c ON c.comment_ID=m.comment_id INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type='petish_chat_topic' AND c.comment_type='petish_chat' ORDER BY m.meta_id", ARRAY_A);
        return array('topics' => $topics, 'replies' => $replies, 'meta' => $meta, 'commentmeta' => $commentmeta);
    }
    public static function inspect_legacy($uid) {
        $allowed = self::manager($uid); if (is_wp_error($allowed)) return $allowed; $rows = self::legacy_rows();
        return array('source_sha256' => hash('sha256', serialize($rows)), 'topic_count' => count($rows['topics']), 'reply_count' => count($rows['replies']), 'source_writes' => false);
    }
    public static function import_legacy($uid, $expected_source_hash) {
        return self::lock(static function() use ($uid, $expected_source_hash) {
            global $wpdb;
            $allowed = self::manager($uid); if (is_wp_error($allowed)) return $allowed; $source = self::legacy_rows();
            if (!is_string($expected_source_hash) || !hash_equals(hash('sha256', serialize($source)), $expected_source_hash)) return self::error('conflict', 'Legacy discussions changed. Review a fresh snapshot before importing.', 409);
            if (count($source['topics']) + count($source['replies']) > 10000) return self::error('limit', 'This legacy archive requires a reviewed batch migration.');
            $topics = array(); $map = array(); $report = array('imported' => 0, 'existing' => 0, 'skipped' => 0, 'conflicts' => array(), 'source_sha256' => $expected_source_hash, 'source_writes' => false);
            $import = static function($key, $original, $row) use (&$report, &$map, $wpdb) {
                $source_hash = hash('sha256', serialize($original));
                $existing = $wpdb->get_row($wpdb->prepare('SELECT id,legacy_hash FROM ' . self::table() . ' WHERE legacy_key=%s', $key), ARRAY_A);
                if ($existing) { $map[$key] = (int) $existing['id']; if (!hash_equals($existing['legacy_hash'], $source_hash)) $report['conflicts'][] = $key; else $report['existing']++; return; }
                $row['legacy_key'] = $key; $row['legacy_hash'] = $source_hash; $row['request_hash'] = hash('sha256', 'legacy:' . $key); $row['payload_hash'] = $source_hash; $row['member_key'] = self::member($row['author_id']); $row['media'] = '[]'; $row['reports'] = '[]'; $row['revision'] = 1;
                if (!$wpdb->insert(self::table(), $row)) { $report['conflicts'][] = $key; return; }
                $map[$key] = (int) $wpdb->insert_id; $report['imported']++;
            };
            foreach ($source['topics'] as $post) {
                $id = (int) $post['ID']; $topics[$id] = $post;
                $meta = array_values(array_filter($source['meta'], static function($row) use ($id) { return (int) $row['post_id'] === $id; }));
                $alias = self::text(get_post_meta($id, '_petish_chat_alias', true), 40);
                if (!in_array($post['post_status'], array('publish', 'pending'), true) || get_post_meta($id, '_petish_chat_ready', true) !== '1' || get_post_meta($id, '_petish_chat_consent', true) !== '1' || $alias === false || !get_user_by('id', (int) $post['post_author'])) { $report['skipped']++; continue; }
                $channel = (string) get_post_meta($id, '_petish_chat_channel', true); if (is_wp_error(self::channel($channel))) $channel = 'general';
                // Preserve the full original body, even if it predates the new 1,000-character composition limit.
                $body = trim(sanitize_textarea_field($post['post_title'] . "\n\n" . $post['post_content']));
                $import('topic:' . $id, array($post, $meta), array('author_id' => (int) $post['post_author'], 'channel' => $channel, 'alias' => $alias, 'body' => $body, 'status' => $post['post_status'] === 'publish' ? 'published' : 'pending', 'quote_id' => 0, 'created_gmt' => $post['post_date_gmt'], 'updated_gmt' => $post['post_modified_gmt']));
            }
            foreach ($source['replies'] as $reply) {
                $id = (int) $reply['comment_ID']; $topic = $topics[(int) $reply['comment_post_ID']] ?? null;
                $meta = array_values(array_filter($source['commentmeta'], static function($row) use ($id) { return (int) $row['comment_id'] === $id; }));
                $alias = self::text(get_comment_meta($id, '_petish_chat_alias', true), 40);
                if (!$topic || !isset($map['topic:' . $topic['ID']]) || !in_array((string) $reply['comment_approved'], array('0', '1'), true) || get_comment_meta($id, '_petish_chat_ready', true) !== '1' || get_comment_meta($id, '_petish_chat_consent', true) !== '1' || $alias === false || !get_user_by('id', (int) $reply['user_id'])) { $report['skipped']++; continue; }
                $topic_row = self::row($map['topic:' . $topic['ID']]); $parent = 'reply:' . (int) $reply['comment_parent'];
                $import('reply:' . $id, array($reply, $meta), array('author_id' => (int) $reply['user_id'], 'channel' => $topic_row['channel'], 'alias' => $alias, 'body' => sanitize_textarea_field($reply['comment_content']), 'status' => $reply['comment_approved'] === '1' && $topic_row['status'] === 'published' ? 'published' : 'pending', 'quote_id' => $map[$parent] ?? $topic_row['id'], 'created_gmt' => $reply['comment_date_gmt'], 'updated_gmt' => $reply['comment_date_gmt']));
            }
            if (!hash_equals($expected_source_hash, hash('sha256', serialize(self::legacy_rows())))) $report['conflicts'][] = 'source_changed_during_import';
            return $report;
        });
    }
    public static function exporters($items) { $items['petish-chat-stream'] = array('exporter_friendly_name' => 'Pet Chat messages', 'callback' => array(__CLASS__, 'export')); return $items; }
    public static function erasers($items) { $items['petish-chat-stream'] = array('eraser_friendly_name' => 'Pet Chat messages', 'callback' => array(__CLASS__, 'erase')); return $items; }
    public static function export($email, $page = 1) {
        global $wpdb; $user = get_user_by('email', $email); if (!$user || !self::storage()) return array('data' => array(), 'done' => true);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE author_id=%d ORDER BY id LIMIT 51 OFFSET %d', $user->ID, (max(1, (int) $page) - 1) * 50), ARRAY_A); $items = array();
        foreach (array_slice($rows, 0, 50) as $row) $items[] = array('group_id' => 'petish-chat-stream', 'group_label' => 'Pet Chat messages', 'item_id' => 'stream-' . $row['id'], 'data' => array(array('name' => 'Channel', 'value' => $row['channel']), array('name' => 'Public name', 'value' => $row['alias']), array('name' => 'Message', 'value' => $row['body']), array('name' => 'Status', 'value' => $row['status']), array('name' => 'Created UTC', 'value' => $row['created_gmt'])));
        return array('data' => $items, 'done' => count($rows) <= 50);
    }
    public static function erase($email, $page = 1) { $user = get_user_by('email', $email); $result = $user ? self::erase_actor((int) $user->ID, 50) : array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true); return is_wp_error($result) ? array('items_removed' => false, 'items_retained' => true, 'messages' => array($result->get_error_message()), 'done' => false) : $result; }
    private static function erase_actor($uid, $limit) {
        return self::lock(static function() use ($uid, $limit) {
            global $wpdb; if (!self::storage()) return array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
            update_user_meta($uid, '_petish_chat_deleting', '1'); $removed = false; $retained = false;
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE author_id=%d ORDER BY id LIMIT %d', $uid, $limit), ARRAY_A);
            foreach ($rows as $row) { if ($wpdb->delete(self::table(), array('id' => $row['id'])) === false) { $retained = true; continue; } if ($wpdb->delete(self::table(true), array('message_id' => $row['id'])) === false || !self::delete_media($row)) $retained = true; $removed = true; }
            if ($wpdb->delete(self::table(true), array('actor_id' => $uid)) === false) $retained = true;
            $left = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE author_id=%d', $uid));
            // Reports are private and may reference this member on other messages.
            $reports = $wpdb->get_results($wpdb->prepare('SELECT id,reports FROM ' . self::table() . ' WHERE reports LIKE %s', '%"' . $wpdb->esc_like((string) $uid) . '":%'), ARRAY_A);
            foreach ($reports as $row) { $value = json_decode($row['reports'], true); if (is_array($value) && isset($value[$uid])) { unset($value[$uid]); if ($wpdb->update(self::table(), array('reports' => $value ? wp_json_encode($value) : '[]'), array('id' => $row['id'])) === false) $retained = true; } }
            if (!$left && class_exists('Petish_Chat_Stream_Media') && !Petish_Chat_Stream_Media::cleanup_user($uid)) $retained = true;
            if (!$left && !$retained) { foreach (array_keys(get_user_meta($uid)) as $key) if (str_starts_with($key, self::PREFIX)) delete_user_meta($uid, $key); $config = self::config(); $config['audit'] = array_values(array_filter($config['audit'], static function($entry) use ($uid) { return ($entry['actor'] ?? 0) !== $uid; })); if (get_option(self::OPTION_CONFIG, null) !== null) update_option(self::OPTION_CONFIG, $config, false); }
            return array('items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? array('Some Chat data could not be removed. Please retry.') : array(), 'done' => !$left && !$retained);
        });
    }
    public static function delete_user($uid) { $result = self::erase_actor((int) $uid, 100000); if (is_wp_error($result) || !$result['done']) wp_die('Chat messages could not be erased. Retry privacy erasure before deleting this account.', '', array('response' => 500)); }
}
