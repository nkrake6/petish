<?php
/** Public topic discussions. Independent of private Passports, reviews and commerce. */
defined('ABSPATH') || exit;

final class Petish_Chat {
    const TYPE = 'petish_chat_topic';
    const COMMENT_TYPE = 'petish_chat';
    const OPTION_ENABLED = 'petish_chat_enabled_v1';
    const OPTION_MODERATION = 'petish_chat_moderation_v1';
    const READY = '_petish_chat_ready';
    const CHANNEL = '_petish_chat_channel';
    const KIND = '_petish_chat_kind';
    const ALIAS = '_petish_chat_alias';
    const CLOSED = '_petish_chat_closed';
    const ACCEPTED = '_petish_chat_accepted';
    const CONSENT = '_petish_chat_consent';
    const MUTED = '_petish_chat_muted';
    const DELETING = '_petish_chat_deleting';
    const REPORT_PREFIX = '_petish_chat_report_';
    const REQUEST_PREFIX = 'petish_chat_request_';
    const PAGE_SIZE = 20;
    const REPLY_PAGE_SIZE = 40;
    const TOPICS_PER_DAY = 5;
    const REPLIES_PER_DAY = 40;
    const REPORTS_PER_DAY = 20;
    const POST_INTERVAL = 15;
    private static $booted = false;
    private static $internal = false;
    private static $locks = array();

    public static function enabled() { return 'yes' === get_option(self::OPTION_ENABLED, 'no'); }
    public static function channels() {
        return array('general' => __('General chat', 'petish-passport'), 'care' => __('Care and wellbeing', 'petish-passport'),
            'food' => __('Food and nutrition', 'petish-passport'), 'habitat' => __('Homes and enrichment', 'petish-passport'));
    }
    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;
        add_action('init', array(__CLASS__, 'register_types'));
        add_filter('pre_comment_approved', array(__CLASS__, 'native_submission_guard'), 200, 2);
        add_filter('rest_pre_insert_comment', array(__CLASS__, 'rest_submission_guard'), 200, 2);
        add_filter('rest_preprocess_comment', array(__CLASS__, 'rest_submission_guard'), 200, 2);
        add_filter('wp_update_comment_data', array(__CLASS__, 'update_guard'), 200, 3);
        add_filter('comments_clauses', array(__CLASS__, 'query_guard'), 200, 2);
        add_action('pre_get_posts', array(__CLASS__, 'post_query_guard'), 200);
        add_filter('posts_clauses', array(__CLASS__, 'posts_guard'), 200, 2);
        add_filter('rest_prepare_comment', array(__CLASS__, 'rest_guard'), 200, 3);
        add_filter('xmlrpc_prepare_comment', array(__CLASS__, 'xmlrpc_comment_guard'), 200, 2);
        add_filter('xmlrpc_prepare_post', array(__CLASS__, 'xmlrpc_post_guard'), 200, 2);
        add_filter('notify_moderator', array(__CLASS__, 'notification_guard'), 200, 2);
        add_filter('notify_post_author', array(__CLASS__, 'notification_guard'), 200, 2);
        add_filter('option_jetpack_sync_settings_post_types_blacklist', array(__CLASS__, 'sync_post_types'), 200);
        add_filter('default_option_jetpack_sync_settings_post_types_blacklist', array(__CLASS__, 'sync_post_types'), 200);
        add_filter('jetpack_sync_prevent_sending_post_data', array(__CLASS__, 'sync_post_data'), 200, 2);
        add_filter('jetpack_sync_whitelisted_comment_types', array(__CLASS__, 'sync_comment_types'), 200);
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
        add_action('delete_user', array(__CLASS__, 'mark_deleting'), 0);
        add_action('wpmu_delete_user', array(__CLASS__, 'mark_deleting'), 0);
        add_action('delete_user', array(__CLASS__, 'delete_user'), 1);
        add_action('wpmu_delete_user', array(__CLASS__, 'delete_user'), 1);
    }
    public static function register_types() {
        if (!self::enabled() || post_type_exists(self::TYPE)) return;
        register_post_type(self::TYPE, array('label' => __('Pet Chat topics', 'petish-passport'), 'public' => false,
            'publicly_queryable' => false, 'exclude_from_search' => true, 'show_ui' => true, 'show_in_menu' => false,
            'show_in_nav_menus' => false, 'show_in_admin_bar' => false, 'show_in_rest' => false, 'query_var' => false,
            'rewrite' => false, 'has_archive' => false, 'can_export' => false, 'delete_with_user' => false,
            'supports' => array('title', 'editor'), 'map_meta_cap' => false,
            'capabilities' => array_fill_keys(array('edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
                'publish_posts', 'read_private_posts', 'create_posts', 'delete_posts', 'delete_private_posts',
                'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts'), 'manage_options')));
        foreach (array(self::READY, self::CHANNEL, self::KIND, self::ALIAS, self::CLOSED, self::ACCEPTED, self::CONSENT) as $key) {
            register_post_meta(self::TYPE, $key, array('single' => true, 'type' => 'string', 'show_in_rest' => false,
                'auth_callback' => static function () { return current_user_can('manage_options'); }));
        }
    }
    private static function error($code, $message, $status = 400) {
        return new WP_Error('petish_chat_' . $code, __($message, 'petish-passport'), array('status' => $status));
    }
    private static function id($value) {
        return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $value) ? (int) $value : 0;
    }
    private static function viewer($uid) {
        $uid = self::id($uid);
        return $uid && is_user_logged_in() && $uid === get_current_user_id() ? $uid : 0;
    }
    private static function available($uid) { return $uid && get_user_by('id', $uid) && !get_user_meta($uid, self::DELETING, true); }
    private static function actor($uid, $posting = true) {
        if (!self::enabled() || !self::viewer($uid) || !self::available($uid)) return self::error('signin', 'Sign in to take part in Pet Chat.', 403);
        if ($posting && get_user_meta($uid, self::MUTED, true)) return self::error('muted', 'Posting is paused for this account. Please contact Petish for help.', 403);
        return true;
    }
    private static function manager($uid) {
        return self::enabled() && self::viewer($uid) && self::available($uid) && current_user_can('manage_options');
    }
    public static function identity($uid) {
        if (!self::enabled() || !self::viewer($uid) || !self::available($uid)) return array('alias' => '', 'can_post' => false);
        return array('alias' => (string) get_user_meta($uid, self::ALIAS, true), 'can_post' => true === self::actor($uid));
    }
    /** Every multi-object operation locks one actor first, then one topic; never a second actor. */
    private static function lock($scope, $callback) {
        global $wpdb;
        $name = 'petish-chat-' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->prefix . ':' . $scope), 0, 48);
        if (isset(self::$locks[$name])) return $callback();
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $name))) return self::error('busy', 'Pet Chat is being updated. Please try again.', 409);
        self::$locks[$name] = true;
        if (preg_match('/\Aactor:([1-9][0-9]*)\z/D', $scope, $match)) { clean_user_cache((int) $match[1]); wp_cache_delete((int) $match[1], 'user_meta'); }
        try { return $callback(); }
        finally { unset(self::$locks[$name]); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    }
    private static function text($value, $max, $multiline = false) {
        if (!is_string($value)) return false;
        $text = trim($multiline ? sanitize_textarea_field($value) : sanitize_text_field($value));
        return preg_match('/\A.{1,' . $max . '}\z/usD', $text) ? $text : false;
    }
    private static function normalise($data, $topic = false) {
        if (!is_array($data)) return self::error('input', 'Check your message and try again.');
        $clean = array('alias' => self::text($data['alias'] ?? null, 40), 'body' => self::text($data['body'] ?? null, 4000, true));
        if (false === $clean['alias'] || false === $clean['body']) return self::error('length', 'Enter a public name up to 40 characters and a message up to 4,000 characters.');
        if (!in_array($data['consent'] ?? null, array(true, 1, '1'), true)) return self::error('consent', 'Confirm that your public name and message will be visible to everyone.');
        $clean['consent'] = '1';
        if (!current_user_can('manage_options') && preg_match('/\b(?:petish|admin(?:istrator)?|moderator|staff|support)\b/iu', $clean['alias'])) return self::error('alias', 'Choose a public name that does not suggest you are Petish staff.');
        $token = $data['request_id'] ?? null;
        if (!is_string($token) || !preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\z/D', $token)) return self::error('request', 'Refresh the form before posting your message.');
        $clean['request_id'] = $token;
        if ($topic) {
            $clean['title'] = self::text($data['title'] ?? null, 120);
            $clean['channel'] = $data['channel'] ?? null; $clean['kind'] = $data['kind'] ?? null;
            if (false === $clean['title'] || !is_string($clean['channel']) || !isset(self::channels()[$clean['channel']])
                || !in_array($clean['kind'], array('question', 'discussion'), true)) return self::error('topic', 'Choose a channel, discussion type and title up to 120 characters.');
        } else {
            $parent = $data['parent_id'] ?? 0;
            if (0 !== $parent && '0' !== $parent && !self::id($parent)) return self::error('parent', 'Choose a valid message to reply to.');
            $clean['parent_id'] = (int) $parent;
        }
        return $clean;
    }
    private static function saved_meta($kind, $id, $key, $value) {
        if ('post' === $kind) { update_post_meta($id, $key, $value); return (string) $value === (string) get_post_meta($id, $key, true); }
        update_comment_meta($id, $key, $value); return (string) $value === (string) get_comment_meta($id, $key, true);
    }
    /** Durable reservation survives interrupted inserts; an incomplete retry cannot create a second object. */
    private static function reservation($uid, $operation, $clean, $callback) {
        $key = self::REQUEST_PREFIX . $uid . '_' . hash('sha256', $clean['request_id']);
        $fingerprint = hash('sha256', $operation . ':' . wp_json_encode($clean));
        $existing = get_option($key, false);
        if (false !== $existing) {
            if (!is_array($existing) || ($existing['uid'] ?? 0) !== $uid || ($existing['fingerprint'] ?? '') !== $fingerprint) return self::error('conflict', 'This form has already been used for a different message. Refresh before posting again.', 409);
            if (empty($existing['id'])) return self::error('incomplete', 'This message is still being checked. Please contact Petish before submitting it again.', 409);
            $result = self::submission_result($operation, (int) $existing['id']);
            if (is_wp_error($result)) return $result;
            $result['duplicate'] = true; return $result;
        }
        $record = array('uid' => $uid, 'fingerprint' => $fingerprint, 'operation' => $operation, 'id' => 0, 'created_gmt' => gmdate('Y-m-d H:i:s'));
        if (!add_option($key, $record, '', false)) return self::error('busy', 'Your message is already being submitted. Please try again.', 409);
        $result = $callback($key, $record);
        // A reserved but interrupted insertion remains non-public and cannot be blindly repeated.
        if (is_wp_error($result) && !$result->get_error_data('petish_chat_retained')) {
            $saved = get_option($key, false);
            if (is_array($saved) && empty($saved['id']) && empty($saved['inserted'])) delete_option($key);
        }
        return $result;
    }
    private static function journal($key, $record, $id, $inserted = true) {
        $record['id'] = (int) $id; $record['inserted'] = $inserted;
        update_option($key, $record, false);
        return get_option($key, false) === $record;
    }
    private static function trusted($uid) {
        global $wpdb;
        if ('all_review' === get_option(self::OPTION_MODERATION, 'first_review')) return false;
        $topic = $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type=%s AND p.post_author=%d AND p.post_status='publish' AND EXISTS(SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key=%s AND m.meta_value='1') LIMIT 1", self::TYPE, $uid, self::READY));
        if ($topic && self::topic_record((int) $topic)) return true;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT c.comment_ID FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE c.user_id=%d AND c.comment_type=%s AND c.comment_approved='1' AND p.post_type=%s AND p.post_status='publish' AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key=%s AND m.meta_value='1') ORDER BY c.comment_ID DESC LIMIT 100", $uid, self::COMMENT_TYPE, self::TYPE, self::READY));
        foreach ($ids as $id) if (self::reply_public(self::reply_record((int) $id))) return true;
        return false;
    }
    private static function rate($uid, $operation) {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $topics = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_author=%d AND post_date_gmt>=%s", self::TYPE, $uid, $since));
        $replies = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type=%s AND user_id=%d AND comment_date_gmt>=%s", self::COMMENT_TYPE, $uid, $since));
        if (('topic' === $operation && $topics >= self::TOPICS_PER_DAY) || ('reply' === $operation && $replies >= self::REPLIES_PER_DAY)) return self::error('rate', 'You have reached today\'s posting limit. Please try again later.', 429);
        $last_topic = $wpdb->get_var($wpdb->prepare("SELECT MAX(post_date_gmt) FROM {$wpdb->posts} WHERE post_type=%s AND post_author=%d", self::TYPE, $uid));
        $last_reply = $wpdb->get_var($wpdb->prepare("SELECT MAX(comment_date_gmt) FROM {$wpdb->comments} WHERE comment_type=%s AND user_id=%d", self::COMMENT_TYPE, $uid));
        $last = max(strtotime(($last_topic ?: '1970-01-01 00:00:00') . ' UTC'), strtotime(($last_reply ?: '1970-01-01 00:00:00') . ' UTC'));
        return time() - $last < self::POST_INTERVAL ? self::error('rate', 'Please wait a moment before posting another message.', 429) : true;
    }
    private static function submission_result($operation, $id) {
        if ('topic' === $operation) {
            $post = get_post($id);
            if (!$post || self::TYPE !== $post->post_type) return self::error('missing', 'This message is no longer available.', 404);
            if ('1' !== (string) get_post_meta($id, self::READY, true) || '1' !== (string) get_post_meta($id, self::CONSENT, true)) return self::error('retained', 'This topic is held for recovery. Please contact Petish.', 409);
            return array('id' => $id, 'topic_id' => $id, 'status' => 'publish' === $post->post_status ? 'published' : 'pending', 'duplicate' => false);
        }
        $comment = get_comment($id);
        if (!$comment || self::COMMENT_TYPE !== $comment->comment_type) return self::error('missing', 'This message is no longer available.', 404);
        if ('1' !== (string) get_comment_meta($id, self::READY, true) || '1' !== (string) get_comment_meta($id, self::CONSENT, true)) return self::error('retained', 'This reply is held for recovery. Please contact Petish.', 409);
        return array('id' => $id, 'topic_id' => (int) $comment->comment_post_ID, 'status' => '1' === (string) $comment->comment_approved ? 'published' : 'pending', 'duplicate' => false);
    }
    public static function create_topic($uid, $data) {
        $uid = self::id($uid); $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed;
        $clean = self::normalise($data, true); if (is_wp_error($clean)) return $clean;
        return self::lock('actor:' . $uid, static function () use ($uid, $clean) {
            $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed;
            return self::reservation($uid, 'topic', $clean, static function ($key, $record) use ($uid, $clean) {
                $rate = self::rate($uid, 'topic'); if (is_wp_error($rate)) return $rate;
                $publish = self::trusted($uid);
                $id = wp_insert_post(wp_slash(array('post_type' => self::TYPE, 'post_status' => 'pending', 'post_author' => $uid,
                    'post_title' => $clean['title'], 'post_content' => $clean['body'], 'post_date' => current_time('mysql'),
                    'post_date_gmt' => current_time('mysql', true), 'comment_status' => 'closed', 'ping_status' => 'closed')), true);
                if (is_wp_error($id) || !$id) return self::error('save', 'Your topic could not be saved. Please try again.', 500);
                if (!self::journal($key, $record, $id)) return self::error('retained', 'Your topic is held for recovery. Please contact Petish before posting again.', 500);
                foreach (array(self::CHANNEL => $clean['channel'], self::KIND => $clean['kind'], self::ALIAS => $clean['alias'], self::CLOSED => '0', self::CONSENT => '1', self::READY => '1') as $meta => $value) {
                    if (!self::saved_meta('post', $id, $meta, $value)) { delete_post_meta($id, self::READY); return self::error('retained', 'Your topic is held for recovery. Please contact Petish.', 500); }
                }
                update_user_meta($uid, self::ALIAS, $clean['alias']);
                if ($publish) {
                    $saved = wp_update_post(array('ID' => $id, 'post_status' => 'publish'), true);
                    if (is_wp_error($saved) || 'publish' !== get_post_status($id)) return self::error('retained', 'Your topic was saved and is awaiting review.', 500);
                }
                return self::submission_result('topic', $id);
            });
        });
    }
    public static function create_reply($uid, $topic_id, $data) {
        $uid = self::id($uid); $topic_id = self::id($topic_id); $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed;
        $clean = self::normalise($data); if (is_wp_error($clean)) return $clean;
        $clean['topic_id'] = $topic_id;
        return self::lock('actor:' . $uid, static function () use ($uid, $topic_id, $clean) {
            return self::lock('topic:' . $topic_id, static function () use ($uid, $topic_id, $clean) {
                $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed;
                return self::reservation($uid, 'reply', $clean, static function ($key, $record) use ($uid, $topic_id, $clean) {
                    $topic = self::topic_record($topic_id);
                    if (!$topic || 'publish' !== $topic->post_status) return self::error('missing', 'This topic is not available.', 404);
                    if ('1' === (string) get_post_meta($topic_id, self::CLOSED, true)) return self::error('closed', 'This topic is closed to new replies.', 409);
                    if ($clean['parent_id']) {
                        $parent = self::reply_record($clean['parent_id']);
                        if (!$parent || (int) $parent->comment_post_ID !== $topic_id || (int) $parent->comment_parent || !self::reply_public($parent)) return self::error('parent', 'Choose a published reply in this topic. Replies can be nested once.');
                    }
                    $rate = self::rate($uid, 'reply'); if (is_wp_error($rate)) return $rate;
                    $publish = self::trusted($uid);
                    // Native insert does not dispatch comment_post notification mail. Own limits are actor-scoped, without retaining IP/email.
                    self::$internal = true;
                    try { $id = wp_insert_comment(array('comment_post_ID' => $topic_id, 'comment_type' => self::COMMENT_TYPE, 'user_id' => $uid,
                        'comment_parent' => $clean['parent_id'], 'comment_author' => $clean['alias'], 'comment_author_email' => '',
                        'comment_author_url' => '', 'comment_author_IP' => '', 'comment_agent' => '', 'comment_content' => $clean['body'], 'comment_approved' => 0)); }
                    finally { self::$internal = false; }
                    if (!$id) return self::error('save', 'Your reply could not be saved. Please try again.', 500);
                    if (!self::journal($key, $record, $id)) return self::error('retained', 'Your reply is held for recovery. Please contact Petish.', 500);
                    foreach (array(self::ALIAS => $clean['alias'], self::CONSENT => '1', self::READY => '1') as $meta => $value) {
                        if (!self::saved_meta('comment', $id, $meta, $value)) { delete_comment_meta($id, self::READY); return self::error('retained', 'Your reply is held for recovery. Please contact Petish.', 500); }
                    }
                    update_user_meta($uid, self::ALIAS, $clean['alias']);
                    if ($publish) {
                        $saved = wp_set_comment_status($id, 'approve', true);
                        $promoted = get_comment($id);
                        if (is_wp_error($saved) || !$saved || !$promoted || '1' !== (string) $promoted->comment_approved) return self::error('retained', 'Your reply was saved and is awaiting review.', 500);
                    }
                    return self::submission_result('reply', (int) $id);
                });
            });
        });
    }
    private static function topic_record($id) {
        $post = $id ? get_post($id) : null;
        $channel = $post ? get_post_meta($id, self::CHANNEL, true) : null;
        if (!$post || self::TYPE !== $post->post_type || '1' !== (string) get_post_meta($id, self::READY, true) || '1' !== (string) get_post_meta($id, self::CONSENT, true)
            || !is_string($channel) || !isset(self::channels()[$channel]) || !in_array(get_post_meta($id, self::KIND, true), array('question', 'discussion'), true)
            || !self::available((int) $post->post_author)) return false;
        return $post;
    }
    private static function reply_record($id) {
        $comment = $id ? get_comment($id) : null;
        return $comment && self::COMMENT_TYPE === $comment->comment_type && '1' === (string) get_comment_meta($id, self::READY, true) && '1' === (string) get_comment_meta($id, self::CONSENT, true)
            && self::available((int) $comment->user_id) ? $comment : false;
    }
    private static function reply_public($comment) {
        if (!$comment || '1' !== (string) $comment->comment_approved) return false;
        $topic = self::topic_record((int) $comment->comment_post_ID);
        if (!$topic || 'publish' !== $topic->post_status) return false;
        if ((int) $comment->comment_parent) {
            $parent = self::reply_record((int) $comment->comment_parent);
            return $parent && !(int) $parent->comment_parent && (int) $parent->comment_post_ID === (int) $comment->comment_post_ID && '1' === (string) $parent->comment_approved;
        }
        return true;
    }
    private static function accepted($topic) {
        if ('question' !== get_post_meta($topic->ID, self::KIND, true)) return 0;
        $id = self::id(get_post_meta($topic->ID, self::ACCEPTED, true)); $reply = self::reply_record($id);
        return $reply && !(int) $reply->comment_parent && (int) $reply->comment_post_ID === (int) $topic->ID
            && (int) $reply->user_id !== (int) $topic->post_author && self::reply_public($reply) ? $id : 0;
    }
    private static function reply_where($topic_id, $viewer) {
        global $wpdb;
        $where = $wpdb->prepare("c.comment_post_ID=%d AND c.comment_type=%s AND (c.comment_approved='1' OR (c.comment_approved='0' AND c.user_id=%d AND c.user_id>0))
            AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} r WHERE r.comment_id=c.comment_ID AND r.meta_key=%s AND r.meta_value='1')
            AND EXISTS(SELECT 1 FROM {$wpdb->users} u WHERE u.ID=c.user_id)
            AND NOT EXISTS(SELECT 1 FROM {$wpdb->usermeta} d WHERE d.user_id=c.user_id AND d.meta_key=%s AND d.meta_value<>'')
            AND (c.comment_parent=0 OR EXISTS(SELECT 1 FROM {$wpdb->comments} parent WHERE parent.comment_ID=c.comment_parent AND parent.comment_post_ID=c.comment_post_ID AND parent.comment_type=%s AND parent.comment_parent=0 AND parent.comment_approved='1'
                AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} pr WHERE pr.comment_id=parent.comment_ID AND pr.meta_key=%s AND pr.meta_value='1')
                AND EXISTS(SELECT 1 FROM {$wpdb->users} pu WHERE pu.ID=parent.user_id)
                AND NOT EXISTS(SELECT 1 FROM {$wpdb->usermeta} pd WHERE pd.user_id=parent.user_id AND pd.meta_key=%s AND pd.meta_value<>'')))",
            $topic_id, self::COMMENT_TYPE, $viewer, self::READY, self::DELETING, self::COMMENT_TYPE, self::READY, self::DELETING);
        return $where . $wpdb->prepare(" AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} pc WHERE pc.comment_id=c.comment_ID AND pc.meta_key=%s AND pc.meta_value='1')
            AND (c.comment_parent=0 OR EXISTS(SELECT 1 FROM {$wpdb->commentmeta} ppc WHERE ppc.comment_id=c.comment_parent AND ppc.meta_key=%s AND ppc.meta_value='1'))", self::CONSENT, self::CONSENT);
    }
    private static function topic_projection($topic, $viewer) {
        global $wpdb;
        $id = (int) $topic->ID; $owner = $viewer && $viewer === (int) $topic->post_author;
        $published = 'publish' === $topic->post_status; $member = $viewer && true === self::actor($viewer);
        $closed = '1' === (string) get_post_meta($id, self::CLOSED, true);
        $reply_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} c WHERE " . self::reply_where($id, $viewer));
        $item = array('id' => $id, 'channel' => (string) get_post_meta($id, self::CHANNEL, true), 'kind' => (string) get_post_meta($id, self::KIND, true),
            'title' => sanitize_text_field($topic->post_title), 'body' => sanitize_textarea_field($topic->post_content), 'alias' => sanitize_text_field(get_post_meta($id, self::ALIAS, true)),
            'created_gmt' => $topic->post_date_gmt, 'updated_gmt' => $topic->post_modified_gmt, 'status' => $published ? 'published' : 'pending',
            'closed' => $closed, 'accepted_answer' => self::accepted($topic), 'replies_count' => $reply_count, 'is_owner' => (bool) $owner,
            'can_reply' => (bool) ($member && $published && !$closed), 'can_close' => (bool) ($member && ($owner || current_user_can('manage_options'))),
            'can_accept' => (bool) ($member && $published && 'question' === get_post_meta($id, self::KIND, true) && ($owner || current_user_can('manage_options'))),
            'can_report' => (bool) ($viewer && self::available($viewer) && !$owner && $published));
        $item['revision'] = hash('sha256', wp_json_encode($item));
        return $item;
    }
    public static function threads($filters = array(), $viewer = 0) {
        global $wpdb;
        if (!self::enabled()) return self::error('disabled', 'Pet Chat is not available.', 404);
        if (!is_array($filters)) return self::error('filters', 'Choose a valid Pet Chat view.');
        $viewer = self::viewer($viewer); $channel = $filters['channel'] ?? ''; $view = $filters['view'] ?? 'latest';
        if (!is_string($channel) || ($channel !== '' && !isset(self::channels()[$channel])) || !in_array($view, array('latest', 'unanswered', 'answered'), true)) return self::error('filters', 'Choose a valid channel and view.');
        $page = self::page($filters['page'] ?? 1); if (!$page) return self::error('page', 'Choose a valid page.');
        $where = $wpdb->prepare("p.post_type=%s AND (p.post_status='publish' OR (p.post_status='pending' AND p.post_author=%d AND p.post_author>0))
            AND EXISTS(SELECT 1 FROM {$wpdb->postmeta} r WHERE r.post_id=p.ID AND r.meta_key=%s AND r.meta_value='1')
            AND EXISTS(SELECT 1 FROM {$wpdb->users} u WHERE u.ID=p.post_author)
            AND NOT EXISTS(SELECT 1 FROM {$wpdb->usermeta} d WHERE d.user_id=p.post_author AND d.meta_key=%s AND d.meta_value<>'')", self::TYPE, $viewer, self::READY, self::DELETING);
        $where .= $wpdb->prepare(" AND EXISTS(SELECT 1 FROM {$wpdb->postmeta} pc WHERE pc.post_id=p.ID AND pc.meta_key=%s AND pc.meta_value='1')", self::CONSENT);
        if ($channel !== '') $where .= $wpdb->prepare(" AND EXISTS(SELECT 1 FROM {$wpdb->postmeta} ch WHERE ch.post_id=p.ID AND ch.meta_key=%s AND ch.meta_value=%s)", self::CHANNEL, $channel);
        if ('latest' !== $view) {
            $where .= $wpdb->prepare(" AND EXISTS(SELECT 1 FROM {$wpdb->postmeta} k WHERE k.post_id=p.ID AND k.meta_key=%s AND k.meta_value='question')", self::KIND);
            $answer = $wpdb->prepare("EXISTS(SELECT 1 FROM {$wpdb->postmeta} a INNER JOIN {$wpdb->comments} ac ON ac.comment_ID=CAST(a.meta_value AS UNSIGNED) WHERE a.post_id=p.ID AND a.meta_key=%s AND ac.comment_post_ID=p.ID AND ac.comment_type=%s AND ac.comment_approved='1' AND ac.comment_parent=0 AND ac.user_id<>p.post_author
                AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} ar WHERE ar.comment_id=ac.comment_ID AND ar.meta_key=%s AND ar.meta_value='1')
                AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} apc WHERE apc.comment_id=ac.comment_ID AND apc.meta_key=%s AND apc.meta_value='1')
                AND EXISTS(SELECT 1 FROM {$wpdb->users} au WHERE au.ID=ac.user_id) AND NOT EXISTS(SELECT 1 FROM {$wpdb->usermeta} ad WHERE ad.user_id=ac.user_id AND ad.meta_key=%s AND ad.meta_value<>''))", self::ACCEPTED, self::COMMENT_TYPE, self::READY, self::CONSENT, self::DELETING);
            $where .= ' AND ' . ('unanswered' === $view ? 'NOT ' : '') . $answer;
        }
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . $where);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE $where ORDER BY p.post_date_gmt DESC,p.ID DESC LIMIT %d OFFSET %d", self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE));
        $items = array();
        foreach ($ids as $id) { $topic = self::topic_record((int) $id); if ($topic) $items[] = self::topic_projection($topic, $viewer); }
        return array('items' => $items, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)), 'total' => $total);
    }
    private static function page($value) { return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $value) ? (int) $value : 0; }
    public static function thread($id, $viewer = 0, $page = 1) {
        global $wpdb;
        if (!self::enabled()) return self::error('disabled', 'Pet Chat is not available.', 404);
        $viewer = self::viewer($viewer); $id = self::id($id); $page = self::page($page);
        if (!$page) return self::error('page', 'Choose a valid reply page.');
        $topic = self::topic_record($id);
        if (!$topic || ('publish' !== $topic->post_status && !('pending' === $topic->post_status && $viewer && $viewer === (int) $topic->post_author))) return self::error('missing', 'This topic is not available.', 404);
        $item = self::topic_projection($topic, $viewer); $where = self::reply_where($id, $viewer);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT c.comment_ID FROM {$wpdb->comments} c WHERE $where ORDER BY c.comment_date_gmt ASC,c.comment_ID ASC LIMIT %d OFFSET %d", self::REPLY_PAGE_SIZE, ($page - 1) * self::REPLY_PAGE_SIZE));
        $item['replies'] = array();
        foreach ($ids as $reply_id) {
            $reply = self::reply_record((int) $reply_id); if (!$reply) continue;
            $owner = $viewer && $viewer === (int) $reply->user_id; $public = self::reply_public($reply);
            $item['replies'][] = array('id' => (int) $reply_id, 'topic_id' => $id, 'parent_id' => (int) $reply->comment_parent,
                'alias' => sanitize_text_field(get_comment_meta($reply_id, self::ALIAS, true)), 'body' => sanitize_textarea_field($reply->comment_content),
                'created_gmt' => $reply->comment_date_gmt, 'status' => $public ? 'published' : 'pending', 'accepted' => (int) $reply_id === $item['accepted_answer'],
                'is_owner' => (bool) $owner, 'can_report' => (bool) ($viewer && self::available($viewer) && !$owner && $public),
                'can_accept' => (bool) ($item['can_accept'] && $public && !(int) $reply->comment_parent && (int) $reply->user_id !== (int) $topic->post_author));
        }
        $item['reply_page'] = $page; $item['reply_total'] = $item['replies_count'];
        $item['reply_pages'] = max(1, (int) ceil($item['reply_total'] / self::REPLY_PAGE_SIZE));
        $item['revision'] = hash('sha256', wp_json_encode($item));
        return $item;
    }
    public static function accept_answer($uid, $topic_id, $reply_id) {
        $uid = self::id($uid); $topic_id = self::id($topic_id); $reply_id = self::id($reply_id);
        return self::lock('actor:' . $uid, static function () use ($uid, $topic_id, $reply_id) {
            return self::lock('topic:' . $topic_id, static function () use ($uid, $topic_id, $reply_id) {
                $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed;
                $topic = self::topic_record($topic_id); $reply = self::reply_record($reply_id);
                if (!$topic || 'publish' !== $topic->post_status || 'question' !== get_post_meta($topic_id, self::KIND, true)
                    || ($uid !== (int) $topic->post_author && !current_user_can('manage_options')) || !$reply
                    || (int) $reply->comment_post_ID !== $topic_id || (int) $reply->comment_parent || !self::reply_public($reply)
                    || (int) $reply->user_id === (int) $topic->post_author) return self::error('answer', 'Only the question owner can select a published answer from this topic.', 403);
                return self::saved_meta('post', $topic_id, self::ACCEPTED, (string) $reply_id) ? true : self::error('save', 'The answer could not be selected. Please retry.', 500);
            });
        });
    }
    public static function set_closed($uid, $topic_id, $closed) {
        $uid = self::id($uid); $topic_id = self::id($topic_id);
        if (!is_bool($closed)) return self::error('input', 'Choose whether to close or reopen this topic.');
        return self::lock('actor:' . $uid, static function () use ($uid, $topic_id, $closed) {
            return self::lock('topic:' . $topic_id, static function () use ($uid, $topic_id, $closed) {
                $allowed = self::actor($uid); if (is_wp_error($allowed)) return $allowed;
                $topic = self::topic_record($topic_id);
                if (!$topic || !in_array($topic->post_status, array('publish', 'pending'), true) || ($uid !== (int) $topic->post_author && !current_user_can('manage_options'))) return self::error('permission', 'You cannot change this topic.', 403);
                return self::saved_meta('post', $topic_id, self::CLOSED, $closed ? '1' : '0') ? true : self::error('save', 'The topic could not be updated.', 500);
            });
        });
    }
    public static function report($uid, $topic_id, $reply_id = 0, $reason = 'other') {
        if (0 !== $reply_id && '0' !== $reply_id && !self::id($reply_id)) return self::error('report', 'Choose a valid message to report.');
        $uid = self::id($uid); $topic_id = self::id($topic_id); $reply_id = self::id($reply_id);
        if (!in_array($reason, array('privacy', 'abuse', 'unsafe', 'spam', 'other'), true)) return self::error('reason', 'Choose a report reason.');
        return self::lock('actor:' . $uid, static function () use ($uid, $topic_id, $reply_id, $reason) {
            return self::lock('topic:' . $topic_id, static function () use ($uid, $topic_id, $reply_id, $reason) {
                $allowed = self::actor($uid, false); if (is_wp_error($allowed)) return $allowed;
                $topic = self::topic_record($topic_id); $reply = $reply_id ? self::reply_record($reply_id) : null;
                if (!$topic || 'publish' !== $topic->post_status || ($reply_id && (!$reply || (int) $reply->comment_post_ID !== $topic_id || !self::reply_public($reply)))
                    || $uid === (int) ($reply ? $reply->user_id : $topic->post_author)) return self::error('report', 'This message cannot be reported.', 403);
                $key = self::REPORT_PREFIX . ($reply_id ? 'reply_' . $reply_id : 'topic_' . $topic_id);
                if (get_user_meta($uid, $key, true)) return true;
                $reports = get_user_meta($uid); $recent = 0; $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
                foreach ($reports as $name => $values) if (str_starts_with($name, self::REPORT_PREFIX)) foreach ($values as $value) { $value = maybe_unserialize($value); if (is_array($value) && ($value['created_gmt'] ?? '') >= $since) $recent++; }
                if ($recent >= self::REPORTS_PER_DAY) return self::error('rate', 'You have reached today\'s report limit. Please contact Petish if something needs urgent attention.', 429);
                $value = array('reason' => $reason, 'created_gmt' => gmdate('Y-m-d H:i:s'));
                return add_user_meta($uid, $key, $value, true) ? true : self::error('save', 'Your report could not be saved. Please retry.', 500);
            });
        });
    }
    private static function reports_count($target, $id) { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key=%s", self::REPORT_PREFIX . $target . '_' . $id)); }
    private static function report_reasons($target, $id) {
        global $wpdb;
        $counts = array();
        foreach (array('privacy', 'abuse', 'unsafe', 'spam', 'other') as $reason) {
            $pattern = '%' . $wpdb->esc_like('s:6:"reason";s:' . strlen($reason) . ':"' . $reason . '";') . '%';
            $counts[$reason] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value LIKE %s", self::REPORT_PREFIX . $target . '_' . $id, $pattern));
        }
        return $counts;
    }
    public static function moderation_queue($page = 1) {
        global $wpdb;
        if (!self::manager(get_current_user_id())) return self::error('permission', 'You cannot moderate Pet Chat.', 403);
        $page = self::page($page); if (!$page) return self::error('page', 'Choose a valid moderation page.');
        $query = $wpdb->prepare("SELECT 'topic' target,p.ID id,p.post_date_gmt created_gmt FROM {$wpdb->posts} p WHERE p.post_type=%s AND (p.post_status IN('pending','draft','trash') OR EXISTS(SELECT 1 FROM {$wpdb->usermeta} r WHERE r.meta_key=CONCAT(%s,p.ID)))
            UNION ALL SELECT 'reply' target,c.comment_ID id,c.comment_date_gmt created_gmt FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type=%s AND c.comment_type=%s AND (c.comment_approved IN('0','spam','trash') OR EXISTS(SELECT 1 FROM {$wpdb->usermeta} r WHERE r.meta_key=CONCAT(%s,c.comment_ID)))", self::TYPE, self::REPORT_PREFIX . 'topic_', self::TYPE, self::COMMENT_TYPE, self::REPORT_PREFIX . 'reply_');
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM ($query) queue");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM ($query) queue ORDER BY created_gmt DESC,id DESC LIMIT %d OFFSET %d", self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE), ARRAY_A);
        $items = array();
        foreach ($rows as $row) {
            $reply = 'reply' === $row['target'] ? get_comment((int) $row['id']) : null; $post = get_post($reply ? $reply->comment_post_ID : (int) $row['id']);
            if (!$post) continue;
            $items[] = array('target' => $row['target'], 'id' => (int) $row['id'], 'topic_id' => (int) $post->ID,
                'title' => sanitize_text_field($post->post_title), 'alias' => sanitize_text_field($reply ? get_comment_meta($reply->comment_ID, self::ALIAS, true) : get_post_meta($post->ID, self::ALIAS, true)),
                'body' => sanitize_textarea_field($reply ? $reply->comment_content : $post->post_content), 'status' => $reply ? (string) $reply->comment_approved : $post->post_status,
                'created_gmt' => $row['created_gmt'], 'reports_count' => self::reports_count($row['target'], (int) $row['id']), 'reason_counts' => self::report_reasons($row['target'], (int) $row['id']), 'member_id' => (int) ($reply ? $reply->user_id : $post->post_author));
        }
        return array('items' => $items, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)), 'total' => $total);
    }
    public static function moderate($uid, $target, $id, $operation) {
        $uid = self::id($uid); $id = self::id($id);
        if (!self::manager($uid) || !$id || !in_array($target, array('topic', 'reply', 'member'), true)) return self::error('permission', 'You cannot moderate Pet Chat.', 403);
        if ('member' === $target) {
            if (!in_array($operation, array('mute', 'unmute'), true) || $id === $uid) return self::error('operation', 'Choose a valid member moderation action.');
            return self::lock('actor:' . $id, static function () use ($uid, $id, $operation) {
                if (!self::manager($uid) || !self::available($id)) return self::error('permission', 'This member is not available for moderation.', 403);
                if ('mute' === $operation) {
                    update_user_meta($id, self::MUTED, '1');
                    return '1' === (string) get_user_meta($id, self::MUTED, true) ? true : self::error('save', 'Posting could not be paused for this member.', 500);
                }
                delete_user_meta($id, self::MUTED);
                return !get_user_meta($id, self::MUTED, true) ? true : self::error('save', 'Posting could not be restored for this member.', 500);
            });
        }
        if (!in_array($operation, array('approve', 'hide', 'spam'), true)) return self::error('operation', 'Choose a valid moderation action.');
        $reply = 'reply' === $target ? get_comment($id) : null; $topic_id = $reply ? (int) $reply->comment_post_ID : $id; $topic = get_post($topic_id);
        if (!$topic || self::TYPE !== $topic->post_type || ('reply' === $target && (!$reply || self::COMMENT_TYPE !== $reply->comment_type))) return self::error('missing', 'This message is not available.', 404);
        $owner = (int) ($reply ? $reply->user_id : $topic->post_author);
        return self::lock('actor:' . $owner, static function () use ($uid, $target, $id, $operation, $topic_id, $owner) {
            return self::lock('topic:' . $topic_id, static function () use ($uid, $target, $id, $operation, $topic_id, $owner) {
                if (!self::manager($uid)) return self::error('permission', 'You cannot moderate Pet Chat.', 403);
                $topic = self::topic_record($topic_id); $reply = 'reply' === $target ? self::reply_record($id) : null;
                if (!$topic || !self::available($owner) || ('reply' === $target && (!$reply || (int) $reply->comment_post_ID !== $topic_id))) return self::error('missing', 'This message is not available.', 404);
                if ('approve' === $operation && $reply && ('publish' !== $topic->post_status || ((int) $reply->comment_parent && !self::reply_public(self::reply_record((int) $reply->comment_parent))))) return self::error('parent', 'Publish the topic and parent message before this reply.');
                if ('topic' === $target) {
                    $status = array('approve' => 'publish', 'hide' => 'draft', 'spam' => 'trash')[$operation];
                    $saved = wp_update_post(array('ID' => $id, 'post_status' => $status), true);
                    if (is_wp_error($saved) || get_post_status($id) !== $status) return self::error('save', 'The topic could not be moderated.', 500);
                } else {
                    $status = array('approve' => 'approve', 'hide' => 'hold', 'spam' => 'spam')[$operation];
                    $saved = wp_set_comment_status($id, $status, true); if (is_wp_error($saved) || !$saved) return self::error('save', 'The reply could not be moderated.', 500);
                }
                if ('approve' !== $operation && ('topic' === $target || (int) get_post_meta($topic_id, self::ACCEPTED, true) === $id)) delete_post_meta($topic_id, self::ACCEPTED);
                return true;
            });
        });
    }
    /** Core comments, feeds and widgets must not become a second public or guest-write interface. */
    public static function native_submission_guard($approved, $data) {
        if (self::$internal) return $approved;
        $post_id = self::id($data['comment_post_ID'] ?? 0);
        if (($post_id && self::TYPE === get_post_type($post_id)) || self::COMMENT_TYPE === ($data['comment_type'] ?? '')) return self::error('form', 'Please use the Pet Chat form to post.', 403);
        return $approved;
    }
    public static function rest_submission_guard($prepared, $request) {
        if (is_wp_error($prepared)) return $prepared;
        $existing = isset($request['id']) ? get_comment(self::id($request['id'])) : null;
        if ($existing && (self::COMMENT_TYPE === $existing->comment_type || self::TYPE === get_post_type((int) $existing->comment_post_ID))) return self::error('form', 'Please use Pet Chat to manage this message.', 403);
        $data = is_object($prepared) ? (array) $prepared : $prepared;
        $allowed = self::native_submission_guard(true, is_array($data) ? $data : array());
        return is_wp_error($allowed) ? $allowed : $prepared;
    }
    public static function update_guard($data, $comment = array(), $raw = array()) {
        if (is_wp_error($data) || self::$internal) return $data;
        $id = (int) ($data['comment_ID'] ?? ($comment['comment_ID'] ?? 0)); $record = get_comment($id);
        $chat = $record && (self::COMMENT_TYPE === $record->comment_type || self::TYPE === get_post_type((int) $record->comment_post_ID));
        if ($chat && (!current_user_can('manage_options') || (defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST))) return self::error('permission', 'Chat messages can only be changed through Pet Chat.', 403);
        return $data;
    }
    public static function query_guard($clauses, $query) {
        global $wpdb;
        if (($query->query_vars['petish_chat_private'] ?? false) === true && current_user_can('manage_options')) return $clauses;
        if (is_admin() && current_user_can('manage_options') && !(defined('REST_REQUEST') && REST_REQUEST) && !(defined('DOING_AJAX') && DOING_AJAX)) return $clauses;
        $clauses['where'] .= $wpdb->prepare(" AND {$wpdb->comments}.comment_type<>%s AND NOT EXISTS(SELECT 1 FROM {$wpdb->posts} pct WHERE pct.ID={$wpdb->comments}.comment_post_ID AND pct.post_type=%s)", self::COMMENT_TYPE, self::TYPE);
        return $clauses;
    }
    private static function native_admin() {
        return is_admin() && current_user_can('manage_options') && !(defined('REST_REQUEST') && REST_REQUEST) && !(defined('DOING_AJAX') && DOING_AJAX);
    }
    /** Also runs for suppress_filters get_posts() calls; custom public service reads use fixed SQL projections. */
    public static function post_query_guard($query) {
        if (self::native_admin()) return;
        $types = $query->get('post_type');
        if (self::TYPE === $types || (is_array($types) && in_array(self::TYPE, $types, true))) {
            $types = array_values(array_diff((array) $types, array(self::TYPE)));
            $query->set('post_type', $types ?: 'post');
            if (!$types) $query->set('post__in', array(0));
        }
        foreach (array('p', 'page_id') as $key) { $id = self::id($query->get($key)); if ($id && self::TYPE === get_post_type($id)) $query->set('post__in', array(0)); }
    }
    public static function posts_guard($clauses, $query) {
        global $wpdb;
        if (!self::native_admin()) $clauses['where'] .= $wpdb->prepare(" AND {$wpdb->posts}.post_type<>%s", self::TYPE);
        return $clauses;
    }
    public static function rest_guard($response, $comment, $request) {
        if (self::COMMENT_TYPE === $comment->comment_type || self::TYPE === get_post_type((int) $comment->comment_post_ID)) return self::error('missing', 'This comment is not available.', 404);
        return $response;
    }
    public static function notification_guard($notify, $id) {
        $comment = get_comment($id);
        return $comment && (self::COMMENT_TYPE === $comment->comment_type || self::TYPE === get_post_type((int) $comment->comment_post_ID)) ? false : $notify;
    }
    public static function xmlrpc_comment_guard($data, $comment) {
        return self::COMMENT_TYPE === $comment->comment_type || self::TYPE === get_post_type((int) $comment->comment_post_ID) ? array() : $data;
    }
    public static function xmlrpc_post_guard($data, $post) { return self::TYPE === $post->post_type ? array() : $data; }
    /** Native Jetpack Sync exclusions are request-local and preserve every unrelated setting/type. */
    public static function sync_post_types($types) {
        $types = is_array($types) ? $types : array();
        if (!in_array(self::TYPE, $types, true)) $types[] = self::TYPE;
        return $types;
    }
    public static function sync_post_data($blocked, $post) { return is_object($post) && self::TYPE === ($post->post_type ?? '') ? true : $blocked; }
    public static function sync_comment_types($types) {
        return is_array($types) ? array_values(array_diff($types, array(self::COMMENT_TYPE))) : $types;
    }
    public static function exporters($items) { $items['petish-chat'] = array('exporter_friendly_name' => 'Pet Chat', 'callback' => array(__CLASS__, 'export')); return $items; }
    public static function erasers($items) { $items['petish-chat'] = array('eraser_friendly_name' => 'Pet Chat', 'callback' => array(__CLASS__, 'erase')); return $items; }
    public static function export($email, $page = 1) {
        global $wpdb;
        $user = get_user_by('email', $email); $page = self::page($page);
        if (!$user || !$page) return array('data' => array(), 'done' => true);
        $uid = (int) $user->ID; $offset = ($page - 1) * 50;
        $query = $wpdb->prepare("SELECT 'topic' kind,p.ID id,p.post_title title,p.post_content body,p.post_date_gmt created_gmt,p.post_status status FROM {$wpdb->posts} p WHERE p.post_type=%s AND p.post_author=%d
            UNION ALL SELECT 'reply' kind,c.comment_ID id,'' title,c.comment_content body,c.comment_date_gmt created_gmt,c.comment_approved status FROM {$wpdb->comments} c WHERE c.comment_type=%s AND c.user_id=%d", self::TYPE, $uid, self::COMMENT_TYPE, $uid);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM ($query) owned ORDER BY kind,id LIMIT 51 OFFSET %d", $offset), ARRAY_A); $data = array();
        foreach (array_slice($rows, 0, 50) as $row) {
            $alias = 'topic' === $row['kind'] ? get_post_meta($row['id'], self::ALIAS, true) : get_comment_meta($row['id'], self::ALIAS, true);
            $data[] = array('group_id' => 'petish-chat', 'group_label' => 'Pet Chat', 'item_id' => $row['kind'] . '-' . $row['id'], 'data' => array(
                array('name' => 'Public name', 'value' => (string) $alias), array('name' => 'Type', 'value' => $row['kind']), array('name' => 'Title', 'value' => $row['title']),
                array('name' => 'Message', 'value' => $row['body']), array('name' => 'Created UTC', 'value' => $row['created_gmt']), array('name' => 'Status', 'value' => $row['status'])));
        }
        if (1 === $page) {
            $data[] = array('group_id' => 'petish-chat', 'group_label' => 'Pet Chat', 'item_id' => 'settings', 'data' => array(array('name' => 'Public name', 'value' => (string) get_user_meta($uid, self::ALIAS, true)), array('name' => 'Posting paused', 'value' => get_user_meta($uid, self::MUTED, true) ? 'Yes' : 'No')));
        }
        $reports = $wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key LIKE %s ORDER BY umeta_id LIMIT 51 OFFSET %d", $uid, $wpdb->esc_like(self::REPORT_PREFIX) . '%', $offset), ARRAY_A);
        foreach (array_slice($reports, 0, 50) as $report) {
            $value = maybe_unserialize($report['meta_value']); if (!is_array($value)) continue;
            $data[] = array('group_id' => 'petish-chat', 'group_label' => 'Pet Chat', 'item_id' => $report['meta_key'], 'data' => array(array('name' => 'Report', 'value' => substr($report['meta_key'], strlen(self::REPORT_PREFIX))), array('name' => 'Reason', 'value' => (string) ($value['reason'] ?? 'other')), array('name' => 'Created UTC', 'value' => (string) ($value['created_gmt'] ?? ''))));
        }
        return array('data' => $data, 'done' => count($rows) <= 50 && count($reports) <= 50);
    }
    public static function mark_deleting($uid) {
        $uid = self::id($uid);
        $result = self::lock('actor:' . $uid, static function () use ($uid) { update_user_meta($uid, self::DELETING, '1'); return '1' === (string) get_user_meta($uid, self::DELETING, true); });
        if (true !== $result) wp_die(esc_html__('Chat activity could not be paused. Please retry account deletion.', 'petish-passport'), '', array('response' => 500));
    }
    /** Active-site erasure removes owned topics and their replies, and owned replies elsewhere. */
    private static function erase_actor($uid, $limit = 50) {
        global $wpdb;
        return self::lock('actor:' . $uid, static function () use ($uid, $limit, $wpdb) {
            update_user_meta($uid, self::DELETING, '1');
            if ('1' !== (string) get_user_meta($uid, self::DELETING, true)) return array('items_removed' => false, 'items_retained' => true, 'messages' => array('Chat activity could not be paused.'), 'done' => false);
            $removed = false; $retained = false;
            $topics = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_author=%d ORDER BY ID LIMIT %d", self::TYPE, $uid, $limit));
            foreach ($topics as $id) {
                $result = self::lock('topic:' . $id, static function () use ($id, $wpdb) {
                    $comments = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_type=%s", $id, self::COMMENT_TYPE));
                    foreach ($comments as $comment_id) if (!self::purge_reports('reply', (int) $comment_id)) return false;
                    return self::purge_reports('topic', (int) $id) && (bool) wp_delete_post((int) $id, true);
                });
                if (true === $result) $removed = true; else $retained = true;
            }
            $replies = $wpdb->get_results($wpdb->prepare("SELECT comment_ID,comment_post_ID FROM {$wpdb->comments} WHERE comment_type=%s AND user_id=%d ORDER BY comment_ID LIMIT %d", self::COMMENT_TYPE, $uid, $limit), ARRAY_A);
            foreach ($replies as $row) {
                $result = self::lock('topic:' . $row['comment_post_ID'], static function () use ($row, $wpdb) {
                    // Core reparents deleted comments. Hold children first so a nested response never silently becomes an eligible public root answer.
                    $children = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_parent=%d AND comment_type=%s AND comment_post_ID=%d", $row['comment_ID'], self::COMMENT_TYPE, $row['comment_post_ID']));
                    foreach ($children as $child) { $held = wp_set_comment_status((int) $child, 'hold', true); if (is_wp_error($held) || !$held) return false; }
                    if ((int) get_post_meta($row['comment_post_ID'], self::ACCEPTED, true) === (int) $row['comment_ID']) delete_post_meta($row['comment_post_ID'], self::ACCEPTED);
                    return self::purge_reports('reply', (int) $row['comment_ID']) && (bool) wp_delete_comment((int) $row['comment_ID'], true);
                });
                if (true === $result) $removed = true; else $retained = true;
            }
            $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_author=%d", self::TYPE, $uid))
                + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type=%s AND user_id=%d", self::COMMENT_TYPE, $uid));
            if (!$left && !$retained) {
                foreach (array_keys(get_user_meta($uid)) as $key) if (str_starts_with($key, self::REPORT_PREFIX) || in_array($key, array(self::ALIAS, self::MUTED), true)) { delete_user_meta($uid, $key); if (get_user_meta($uid, $key, true) !== '') $retained = true; else $removed = true; }
                // Reservations contain private author bindings; remove only this actor's fixed-format records.
                $prefix = $wpdb->esc_like(self::REQUEST_PREFIX . $uid . '_') . '%';
                $journals = $wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d", $prefix, $limit), ARRAY_A);
                foreach ($journals as $journal) { $value = maybe_unserialize($journal['option_value']); if (is_array($value) && ($value['uid'] ?? 0) === $uid) { delete_option($journal['option_name']); if (get_option($journal['option_name'], false) !== false) $retained = true; else $removed = true; } else $retained = true; }
                $left += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix));
            }
            return array('items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? array('Some Chat data could not be removed. Please retry erasure.') : array(), 'done' => !$left && !$retained);
        });
    }
    private static function purge_reports($target, $id) {
        delete_metadata('user', 0, self::REPORT_PREFIX . $target . '_' . $id, '', true);
        return 0 === self::reports_count($target, $id);
    }
    public static function erase($email, $page = 1) {
        $user = get_user_by('email', $email);
        if (!$user) return array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
        $result = self::erase_actor((int) $user->ID);
        return is_wp_error($result) ? array('items_removed' => false, 'items_retained' => true, 'messages' => array($result->get_error_message()), 'done' => false) : $result;
    }
    public static function delete_user($uid) {
        $result = self::erase_actor(self::id($uid), 5000);
        if (is_wp_error($result) || !$result['done'] || $result['items_retained']) wp_die(esc_html__('Some Chat data could not be removed. Use the privacy eraser before retrying account deletion.', 'petish-passport'), '', array('response' => 500));
    }
}
