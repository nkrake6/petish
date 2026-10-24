<?php
/** PetChat acceptance proof against real WordPress, users, posts and comments. */
if (PHP_SAPI !== 'cli') exit(2);
$mode = $argv[1] ?? 'full';
if (!in_array($mode, array('full', 'incomplete_ui'), true)) exit(2);
umask(0077);
ob_start();
require '/srv/petish-native/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$checks = array();
$failure = null;
$owner = bin2hex(random_bytes(16));
$users = array();
$options = array();
$mail_calls = 0;

function chat_check($name, $condition) {
    global $checks;
    $checks[$name] = $condition === true;
    if (!$checks[$name]) throw new RuntimeException($name);
}
function chat_ok($value, $name) {
    chat_check($name, !is_wp_error($value));
    return $value;
}
function chat_error($value, $name) {
    chat_check($name, is_wp_error($value));
    return $value;
}
function chat_option($key, $value) {
    global $options;
    if (!array_key_exists($key, $options)) $options[$key] = get_option($key, null);
    update_option($key, $value, false);
}
function chat_user($suffix, $role = 'customer') {
    global $owner, $users;
    $id = chat_ok(wp_insert_user(array(
        'user_login' => 'chat-' . $owner . '-' . $suffix,
        'user_pass' => bin2hex(random_bytes(24)),
        'user_email' => 'private-mail-' . $suffix . '-' . $owner . '@example.invalid',
        'display_name' => 'PRIVATE DISPLAY ' . $suffix . ' ' . $owner,
        'first_name' => 'PRIVATE FIRST ' . $suffix,
        'last_name' => 'PRIVATE LAST ' . $suffix,
        'role' => $role,
    )), 'owned_' . $suffix . '_created');
    $users[] = (int) $id;
    update_user_meta($id, '_petish_native_owner', $owner);
    update_user_meta($id, 'billing_phone', 'PRIVATE-PHONE-' . $owner);
    return (int) $id;
}
function chat_request($extra = array()) {
    return array_merge(array(
        'alias' => 'Clover keeper', 'title' => 'A safe room for little paws',
        'body' => 'What are your favourite ways to make a calm and enriching home?',
        'channel' => 'general', 'kind' => 'question', 'request_id' => bin2hex(random_bytes(16)), 'consent' => '1',
    ), $extra);
}
function chat_topic($uid, $extra = array()) {
    wp_set_current_user($uid);
    return chat_ok(Petish_Chat::create_topic($uid, chat_request($extra)), 'owned_topic_created');
}
function chat_reply($uid, $topic, $extra = array()) {
    wp_set_current_user($uid);
    return chat_ok(Petish_Chat::create_reply($uid, $topic, chat_request(array_merge(array(
        'body' => 'Give them room to explore, safe hiding places and patient introductions.',
    ), $extra))), 'owned_reply_created');
}
function chat_capture($callback) {
    ob_start();
    try { $callback(); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
function chat_ids($list) {
    return is_array($list) && isset($list['items']) ? array_map(static fn($item) => (int) $item['id'], $list['items']) : array();
}
function chat_snapshot() {
    global $wpdb;
    $rows = array();
    $rows['topics'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$wpdb->posts}` WHERE post_type=%s ORDER BY ID", Petish_Chat::TYPE), ARRAY_A);
    $rows['meta'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$wpdb->postmeta}` WHERE meta_key LIKE %s ORDER BY meta_id", $wpdb->esc_like('_petish_chat_') . '%'), ARRAY_A);
    $rows['replies'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$wpdb->comments}` WHERE comment_type=%s ORDER BY comment_ID", Petish_Chat::COMMENT_TYPE), ARRAY_A);
    $rows['commentmeta'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$wpdb->commentmeta}` WHERE meta_key LIKE %s ORDER BY meta_id", $wpdb->esc_like('_petish_chat_') . '%'), ARRAY_A);
    $rows['usermeta'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$wpdb->usermeta}` WHERE meta_key LIKE %s ORDER BY umeta_id", $wpdb->esc_like('_petish_chat_') . '%'), ARRAY_A);
    $rows['options'] = $wpdb->get_results($wpdb->prepare("SELECT option_name,option_value,autoload FROM `{$wpdb->options}` WHERE option_name LIKE %s ORDER BY option_name", '%' . $wpdb->esc_like('petish_chat') . '%'), ARRAY_A);
    return hash('sha256', serialize($rows));
}
function chat_rejected_without_write($callback, $name) {
    $before = chat_snapshot();
    chat_error($callback(), $name);
    chat_check($name . '_no_write', chat_snapshot() === $before);
}
function chat_readonly($callback, &$writes) {
    $writes = 0;
    $guard = static function($sql) use (&$writes) {
        if (!preg_match('/\A\s*(?:SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) {
            $writes++;
            throw new RuntimeException('public_projection_attempted_sql_write');
        }
        return $sql;
    };
    add_filter('query', $guard, PHP_INT_MAX);
    try { return $callback(); }
    finally { remove_filter('query', $guard, PHP_INT_MAX); }
}
function chat_assert_identity_private($projection, $label) {
    global $owner;
    $text = wp_json_encode($projection);
    foreach (array('private-mail-', 'PRIVATE DISPLAY', 'PRIVATE FIRST', 'PRIVATE LAST', 'PRIVATE-PHONE-', 'private-pet-' . $owner) as $i => $sentinel)
        chat_check($label . '_excludes_identity_' . $i, strpos($text, $sentinel) === false);
}
function chat_age_actor($uid) {
    global $wpdb;
    $date = gmdate('Y-m-d H:i:s', time() - 60);
    $wpdb->query($wpdb->prepare("UPDATE `{$wpdb->posts}` SET post_date=%s,post_date_gmt=%s WHERE post_type=%s AND post_author=%d", $date, $date, Petish_Chat::TYPE, $uid));
    $wpdb->query($wpdb->prepare("UPDATE `{$wpdb->comments}` SET comment_date=%s,comment_date_gmt=%s WHERE comment_type=%s AND user_id=%d", $date, $date, Petish_Chat::COMMENT_TYPE, $uid));
    clean_user_cache($uid);
    foreach ($wpdb->get_col($wpdb->prepare("SELECT ID FROM `{$wpdb->posts}` WHERE post_type=%s AND post_author=%d", Petish_Chat::TYPE, $uid)) as $id) clean_post_cache((int) $id);
    foreach ($wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM `{$wpdb->comments}` WHERE comment_type=%s AND user_id=%d", Petish_Chat::COMMENT_TYPE, $uid)) as $id) clean_comment_cache((int) $id);
}
/** Deliberately malformed legacy rows exercise read filtering, not a write interface. */
function chat_raw_reply($topic, $uid, $status = '1', $type = null, $parent = 0, $ready = true, $consent = true) {
    global $wpdb, $owner;
    $type = $type ?? Petish_Chat::COMMENT_TYPE;
    $date = gmdate('Y-m-d H:i:s', time() - 60);
    chat_check('owned_legacy_comment_inserted', $wpdb->insert($wpdb->comments, array(
        'comment_post_ID' => $topic, 'user_id' => $uid, 'comment_parent' => $parent,
        'comment_author' => 'PRIVATE DISPLAY LEGACY', 'comment_author_email' => 'private-mail-legacy@example.invalid',
        'comment_author_url' => 'https://example.invalid/private-profile', 'comment_author_IP' => '192.0.2.45',
        'comment_agent' => 'PRIVATE-AGENT-' . $owner, 'comment_content' => 'Owned legacy reply ' . $owner,
        'comment_approved' => $status, 'comment_type' => $type, 'comment_date' => $date, 'comment_date_gmt' => $date,
    )) === 1);
    $id = (int) $wpdb->insert_id;
    update_comment_meta($id, Petish_Chat::ALIAS, 'Safe legacy alias');
    if ($consent) update_comment_meta($id, Petish_Chat::CONSENT, '1');
    if ($ready) update_comment_meta($id, Petish_Chat::READY, '1');
    clean_comment_cache($id);
    return $id;
}
function chat_reply_ids($thread) {
    return array_map(static fn($reply) => (int) $reply['id'], $thread['replies']);
}
function chat_approve($admin, $target, $id) {
    wp_set_current_user($admin);
    return chat_ok(Petish_Chat::moderate($admin, $target, $id, 'approve'), 'owned_' . $target . '_approved');
}
function chat_reply_storage($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->comments}` WHERE comment_ID=%d", $id), ARRAY_A);
}
function chat_raw_topic($uid, $status = 'publish', $type = null, $ready = true, $consent = true) {
    global $wpdb, $owner;
    $date = gmdate('Y-m-d H:i:s', time() - 60);
    chat_check('owned_legacy_topic_inserted', $wpdb->insert($wpdb->posts, array(
        'post_author' => $uid, 'post_date' => $date, 'post_date_gmt' => $date,
        'post_modified' => $date, 'post_modified_gmt' => $date, 'post_status' => $status,
        'post_type' => $type ?? Petish_Chat::TYPE, 'post_title' => 'Owned legacy topic ' . $owner,
        'post_content' => 'Owned legacy message for boundary checks.', 'post_excerpt' => '', 'post_content_filtered' => '',
        'comment_status' => 'closed', 'ping_status' => 'closed', 'to_ping' => '', 'pinged' => '',
    )) === 1);
    $id = (int) $wpdb->insert_id;
    foreach (array(Petish_Chat::CHANNEL => 'general', Petish_Chat::KIND => 'question', Petish_Chat::ALIAS => 'Safe legacy alias', Petish_Chat::CLOSED => '0') as $key => $value)
        update_post_meta($id, $key, $value);
    if ($consent) update_post_meta($id, Petish_Chat::CONSENT, '1');
    if ($ready) update_post_meta($id, Petish_Chat::READY, '1');
    clean_post_cache($id);
    return $id;
}
function chat_contended($callback, $scope_number, $name) {
    global $wpdb;
    $conn = mysqli_init();
    chat_check($name . '_secondary_connection', mysqli_real_connect($conn, 'localhost', DB_USER, DB_PASSWORD, DB_NAME, null, '/run/mysqld/mysqld.sock'));
    $held = array(); $seen = 0;
    $guard = static function($sql) use ($conn, &$held, &$seen, $scope_number) {
        if (preg_match("/\\ASELECT GET_LOCK\\('([^']+)',5\\)\\z/D", $sql, $match)) {
            $seen++;
            if ($seen === $scope_number) {
                $key = mysqli_real_escape_string($conn, $match[1]);
                $row = mysqli_fetch_row(mysqli_query($conn, "SELECT GET_LOCK('" . $key . "',0)"));
                if ($row[0] !== '1') throw new RuntimeException('native_lock_fixture_failed');
                $held[] = $key;
            }
        }
        return $sql;
    };
    add_filter('query', $guard, PHP_INT_MAX);
    try {
        $before = chat_snapshot();
        $result = $callback();
        chat_check($name . '_busy', is_wp_error($result) && $result->get_error_code() === 'petish_chat_busy');
        chat_check($name . '_actually_contended', $seen === $scope_number && count($held) === 1);
        chat_check($name . '_no_write', chat_snapshot() === $before);
    } finally {
        remove_filter('query', $guard, PHP_INT_MAX);
        foreach ($held as $key) mysqli_query($conn, "SELECT RELEASE_LOCK('" . $key . "')");
        mysqli_close($conn);
    }
}
function chat_ui_input($operation, $topic = 0, $extra = array()) {
    $target = $extra['target'] ?? '';
    $target_id = $extra['target_id'] ?? 0;
    return array_merge(chat_request(), array('topic_id' => (string) $topic,
        Petish_Chat_UI::NONCE_FIELD => wp_create_nonce(Petish_Chat_UI::nonce_action($operation, $topic, $target, $target_id))), $extra);
}
function chat_refresh_input($extra = array()) {
    $filters = chat_ok(Petish_Chat_UI::filters(array_merge(array('channel' => 'care'), $extra)), 'poll_filters_valid');
    return array_merge($filters, array('action' => Petish_Chat_UI::REFRESH_ACTION, 'context' => Petish_Chat_UI::context($filters)));
}
function chat_artifact($name, $state) {
    $directory = '/checks/pet-chat-artifacts';
    if (!is_dir($directory)) mkdir($directory, 0700);
    $css = file_get_contents(PETISH_PASSPORT_DIR . 'assets/pet-chat.css');
    $html = chat_capture(static function() use ($state) { Petish_Chat_UI::render($state); });
    $shell = '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'"><title>PetChat synthetic native fixture</title><style>'
        . ':root{--color-bg:#f7f8fc;--color-surface:#fff;--color-text:#182640;--color-muted:#5a6780;--color-border:#dce1e9;--color-primary:#6532a1;--color-primary-light:#ede7ff;--color-secondary:#0f4c5c;--color-accent:#00d2f3;--font-body:Arial,sans-serif}body{margin:0;background:var(--color-bg);color:var(--color-text);font:17px/1.55 Arial,sans-serif}'
        . $css . '</style></head><body>' . $html . '</body></html>';
    chat_check('synthetic_artifact_size_' . $name, strlen($shell) < 4194304);
    file_put_contents($directory . '/' . $name . '.html', $shell);
    chmod($directory . '/' . $name . '.html', 0600);
}

if ($mode === 'incomplete_ui') {
    try {
        chat_check('incomplete_disconnected_origin', defined('PETISH_FULFILMENT_NATIVE_OWNER') && wp_get_environment_type() === 'local'
            && home_url() === 'http://petish-fulfilment.invalid' && DB_NAME === 'petish_fulfilment_native');
        chat_check('incomplete_core_guards_loaded_without_ui', class_exists('Petish_Chat') && !class_exists('Petish_Chat_UI'));
        chat_check('incomplete_feature_stays_off', Petish_Chat::enabled() === false);
        wp_set_current_user(0);
        $stored_topic = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$wpdb->posts}` WHERE post_type=%s AND post_status='publish' ORDER BY ID LIMIT 1", Petish_Chat::TYPE));
        $stored_reply = (int) $wpdb->get_var($wpdb->prepare("SELECT comment_ID FROM `{$wpdb->comments}` WHERE comment_type=%s AND comment_approved='1' ORDER BY comment_ID LIMIT 1", Petish_Chat::COMMENT_TYPE));
        chat_check('incomplete_fixture_has_real_stored_chat', $stored_topic > 0 && $stored_reply > 0);
        chat_check('incomplete_native_post_query_hides_stored_chat', count(get_posts(array('post_type' => Petish_Chat::TYPE, 'post_status' => 'any', 'numberposts' => 100))) === 0);
        chat_check('incomplete_native_comments_query_hides_stored_chat', count(get_comments(array('type' => Petish_Chat::COMMENT_TYPE, 'status' => 'all', 'number' => 100))) === 0);
        $denied = apply_filters('pre_comment_approved', 1, array('comment_post_ID' => $stored_topic, 'comment_type' => Petish_Chat::COMMENT_TYPE));
        chat_check('incomplete_native_submission_guard_active', is_wp_error($denied));
        chat_check('incomplete_no_ui_handlers_or_assets', has_action('wp_ajax_petish_chat_refresh') === false
            && !wp_style_is('petish-chat', 'enqueued') && !wp_script_is('petish-chat', 'enqueued'));
        chat_error(Petish_Chat::threads(), 'incomplete_public_reader_disabled');
        chat_error(Petish_Chat::create_topic(0, chat_request()), 'incomplete_writer_disabled');
    } catch (Throwable $error) {
        $failure = $error instanceof RuntimeException && isset($checks[$error->getMessage()]) ? $error->getMessage() : 'unexpected_native_failure';
        file_put_contents('/checks/pet-chat-failure.private.txt', (string) $error);
        chmod('/checks/pet-chat-failure.private.txt', 0600);
    }
    while (ob_get_level()) ob_end_clean();
    echo wp_json_encode(array('status' => $failure === null ? 'success' : 'failure', 'category' => $failure ?? 'complete', 'checks' => $checks));
    exit($failure === null ? 0 : 1);
}

try {
    chat_check('disconnected_origin', defined('PETISH_FULFILMENT_NATIVE_OWNER') && wp_get_environment_type() === 'local'
        && home_url() === 'http://petish-fulfilment.invalid' && DB_NAME === 'petish_fulfilment_native');
    chat_check('exact_native_versions', get_bloginfo('version') === '7.1.3' && WC_VERSION === '11.2.1');
    chat_check('http_transport_blocked', is_wp_error(wp_remote_get('https://example.invalid/')));
    chat_check('cron_disabled', defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
    chat_check('module_loaded_by_plugin', class_exists('Petish_Chat') && class_exists('Petish_Chat_UI'));
    $capture_mail = static function($return) use (&$mail_calls) { $mail_calls++; return false; };
    add_filter('pre_wp_mail', $capture_mail, PHP_INT_MAX);

    $admin = (int) get_users(array('role' => 'administrator', 'number' => 1))[0]->ID;
    $alice = chat_user('alice');
    $bob = chat_user('bob');
    $carol = chat_user('carol');
    $manager = chat_user('manager', 'shop_manager');

    // The default must remain disabled until explicitly enabled in this owned fixture.
    chat_check('module_default_off', get_option(Petish_Chat::OPTION_ENABLED, null) === null && Petish_Chat::enabled() === false);
    wp_set_current_user($alice);
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request()), 'disabled_create_denied');
    chat_option(Petish_Chat::OPTION_ENABLED, 'yes');
    chat_check('module_enabled_by_exact_option', Petish_Chat::enabled() === true);
    foreach (array(true, 1, '1', 'true', array('yes')) as $i => $wrong) {
        update_option(Petish_Chat::OPTION_ENABLED, $wrong, false);
        chat_check('flag_strict_type_' . $i, Petish_Chat::enabled() === false);
    }
    update_option(Petish_Chat::OPTION_ENABLED, 'yes', false);
    Petish_Chat::register_types();

    $type = get_post_type_object(Petish_Chat::TYPE);
    chat_check('private_native_post_type', $type && !$type->public && !$type->publicly_queryable && !$type->show_in_rest && $type->exclude_from_search);

    // Callers cannot impersonate a signed-in member or submit anonymously.
    wp_set_current_user(0);
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request()), 'anonymous_create_denied');
    wp_set_current_user($alice);
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($bob, chat_request()), 'actor_spoof_denied');
    foreach (array(null, array(), false, 'bad', str_repeat('a', 31), str_repeat('a', 33)) as $i => $bad) {
        chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request(array('request_id' => $bad))), 'request_id_invalid_' . $i);
    }
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request(array('channel' => 'private-secret'))), 'unknown_channel_denied');
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request(array('kind' => 'direct-message'))), 'private_message_kind_denied');
    foreach (array(null, false, 0, '0', 'yes', array('1')) as $i => $bad)
        chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request(array('consent' => $bad))), 'publication_consent_required_' . $i);
    foreach (array('Petish support', 'Moderator', 'Administrator', 'Staff member') as $i => $alias)
        chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request(array('alias' => $alias))), 'staff_impersonation_alias_denied_' . $i);
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request(array('title' => str_repeat('t', 121)))), 'title_limit_enforced');
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request(array('body' => str_repeat('b', 4001)))), 'body_limit_enforced');

    $request = chat_request();
    $first = chat_ok(Petish_Chat::create_topic($alice, $request), 'first_topic_created');
    $topic_id = (int) $first['id'];
    chat_check('first_post_requires_moderation', $first['status'] === 'pending' && get_post_status($topic_id) === 'pending');
    $again = chat_ok(Petish_Chat::create_topic($alice, $request), 'topic_retry_result');
    chat_check('topic_retry_is_idempotent', (int) $again['id'] === $topic_id && $again['duplicate'] === true);
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, array_merge($request, array('body' => 'A different message'))), 'request_reuse_with_different_body_denied');
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($alice, chat_request()), 'posting_interval_enforced');
    chat_check('own_pending_visible', !is_wp_error(Petish_Chat::thread($topic_id, $alice)));
    chat_error(Petish_Chat::thread($topic_id, 0), 'pending_not_public_even_for_owner_default_view');
    wp_set_current_user($bob);
    chat_error(Petish_Chat::thread($topic_id, $bob), 'other_member_pending_denied');
    chat_error(Petish_Chat::thread($topic_id, $alice), 'spoofed_viewer_pending_denied');
    chat_check('pending_excluded_from_directory', !in_array($topic_id, chat_ids(chat_ok(Petish_Chat::threads(array(), $bob), 'public_pending_directory')), true));

    // Test native capabilities, not a substituted role string or administrator UID.
    wp_set_current_user($manager);
    chat_rejected_without_write(static fn() => Petish_Chat::moderate($manager, 'topic', $topic_id, 'approve'), 'shop_manager_cannot_moderate');
    wp_set_current_user($admin);
    chat_ok(Petish_Chat::moderate($admin, 'topic', $topic_id, 'approve'), 'admin_approved_topic');
    chat_check('topic_actually_published', get_post_status($topic_id) === 'publish');
    wp_set_current_user(0);
    $public = chat_ok(Petish_Chat::thread($topic_id), 'approved_topic_public');
    chat_assert_identity_private($public, 'public_topic');
    chat_check('explicit_public_alias_only', $public['alias'] === 'Clover keeper');

    // Approval grants trust only to this member; retries never create a new post.
    chat_age_actor($alice);
    $second = chat_topic($alice, array('title' => 'Another safe question', 'alias' => 'Clover keeper'));
    $second_topic = (int) $second['id'];
    chat_check('approved_chat_history_grants_trust', $second['status'] === 'published');
    $reply_request = chat_request(array('alias' => 'Meadow keeper', 'body' => 'A cosy hide and safe space to explore.'));
    wp_set_current_user($bob);
    chat_rejected_without_write(static fn() => Petish_Chat::create_reply($bob, $topic_id, chat_request(array('consent' => false))), 'reply_publication_consent_required');
    $reply = chat_ok(Petish_Chat::create_reply($bob, $topic_id, $reply_request), 'first_reply_created');
    $reply_id = (int) $reply['id'];
    chat_check('first_reply_requires_moderation', $reply['status'] === 'pending' && chat_reply_storage($reply_id)['comment_approved'] === '0');
    $raw = chat_reply_storage($reply_id);
    chat_check('reply_does_not_store_private_contact_or_tracking', $raw['comment_author_email'] === '' && $raw['comment_author_url'] === ''
        && $raw['comment_author_IP'] === '' && $raw['comment_agent'] === '');
    $again = chat_ok(Petish_Chat::create_reply($bob, $topic_id, $reply_request), 'reply_retry_result');
    chat_check('reply_retry_is_idempotent', (int) $again['id'] === $reply_id && $again['duplicate'] === true);
    $bob_view = chat_ok(Petish_Chat::thread($topic_id, $bob), 'own_pending_reply_view');
    chat_check('pending_reply_visible_only_to_owner', in_array($reply_id, chat_reply_ids($bob_view), true));
    wp_set_current_user($alice);
    $alice_view = chat_ok(Petish_Chat::thread($topic_id, $alice), 'other_pending_reply_view');
    chat_check('pending_reply_excluded_from_other_member', !in_array($reply_id, chat_reply_ids($alice_view), true));
    $spoofed_view = chat_ok(Petish_Chat::thread($topic_id, $bob), 'spoofed_reply_viewer');
    chat_check('spoofed_pending_reply_owner_not_honoured', !in_array($reply_id, chat_reply_ids($spoofed_view), true));
    chat_rejected_without_write(static fn() => Petish_Chat::accept_answer($alice, $topic_id, $reply_id), 'pending_answer_cannot_be_accepted');
    chat_approve($admin, 'reply', $reply_id);
    wp_set_current_user(0);
    $public = chat_ok(Petish_Chat::thread($topic_id), 'approved_reply_public');
    chat_check('approved_reply_visible_publicly', in_array($reply_id, chat_reply_ids($public), true));
    chat_assert_identity_private($public, 'public_reply');

    // Approved reply history grants trust, while role and unrelated comments do not.
    chat_age_actor($bob);
    $trusted_reply = chat_reply($bob, $second_topic, array('alias' => 'Meadow keeper'));
    $other_reply_id = (int) $trusted_reply['id'];
    chat_check('approved_reply_history_grants_trust', $trusted_reply['status'] === 'published');
    wp_set_current_user($carol);
    chat_rejected_without_write(static fn() => Petish_Chat::accept_answer($carol, $topic_id, $reply_id), 'non_owner_cannot_accept_answer');
    wp_set_current_user($alice);
    chat_rejected_without_write(static fn() => Petish_Chat::accept_answer($alice, $topic_id, $other_reply_id), 'cross_topic_answer_denied');
    chat_ok(Petish_Chat::accept_answer($alice, $topic_id, $reply_id), 'owner_accepts_answer');
    chat_check('accepted_answer_visible', Petish_Chat::thread($topic_id, $alice)['accepted_answer'] === $reply_id);
    chat_check('answered_filter_uses_actual_approved_answer', in_array($topic_id, chat_ids(Petish_Chat::threads(array('view' => 'answered'), $alice)), true));
    chat_check('unanswered_filter_excludes_answered_topic', !in_array($topic_id, chat_ids(Petish_Chat::threads(array('view' => 'unanswered'), $alice)), true));

    // Threads may nest once, and a parent must be published in this exact topic.
    chat_age_actor($alice);
    $own_reply = chat_reply($alice, $topic_id);
    wp_set_current_user($alice);
    chat_rejected_without_write(static fn() => Petish_Chat::accept_answer($alice, $topic_id, $own_reply['id']), 'own_answer_cannot_be_accepted');
    chat_age_actor($bob);
    wp_set_current_user($bob);
    chat_rejected_without_write(static fn() => Petish_Chat::create_reply($bob, $topic_id, chat_request(array('parent_id' => $other_reply_id))), 'cross_topic_reply_parent_denied');
    $nested = chat_reply($bob, $topic_id, array('parent_id' => $reply_id));
    chat_check('one_nested_reply_supported', get_comment($nested['id'])->comment_parent === (string) $reply_id);
    chat_age_actor($bob);
    wp_set_current_user($bob);
    chat_rejected_without_write(static fn() => Petish_Chat::create_reply($bob, $topic_id, chat_request(array('parent_id' => $nested['id']))), 'second_nested_level_denied');
    wp_set_current_user($alice);
    chat_rejected_without_write(static fn() => Petish_Chat::accept_answer($alice, $topic_id, $nested['id']), 'nested_answer_cannot_be_accepted');

    // A closed topic is readable but cannot accept another post, even from a trusted member.
    wp_set_current_user($carol);
    chat_rejected_without_write(static fn() => Petish_Chat::set_closed($carol, $topic_id, true), 'other_member_cannot_close');
    wp_set_current_user($alice);
    chat_rejected_without_write(static fn() => Petish_Chat::set_closed($alice, $topic_id, 'yes'), 'closed_state_requires_boolean');
    chat_ok(Petish_Chat::set_closed($alice, $topic_id, true), 'owner_closes_topic');
    chat_check('closed_topic_stays_readable', Petish_Chat::thread($topic_id, $alice)['closed'] === true);
    wp_set_current_user($bob);
    chat_rejected_without_write(static fn() => Petish_Chat::create_reply($bob, $topic_id, chat_request()), 'closed_topic_new_reply_denied');
    $retry_closed = chat_ok(Petish_Chat::create_reply($bob, $topic_id, $reply_request), 'closed_topic_original_retry');
    chat_check('closed_topic_retry_not_duplicated', $retry_closed['duplicate'] === true && (int) $retry_closed['id'] === $reply_id);
    wp_set_current_user($alice);
    chat_ok(Petish_Chat::set_closed($alice, $topic_id, false), 'owner_reopens_topic');

    // Moderation hides the accepted answer and its descendants immediately.
    wp_set_current_user($admin);
    chat_ok(Petish_Chat::moderate($admin, 'reply', $reply_id, 'hide'), 'moderator_hides_accepted_answer');
    wp_set_current_user(0);
    $after_hide = chat_ok(Petish_Chat::thread($topic_id), 'hidden_answer_public_projection');
    chat_check('hidden_answer_clears_accepted_status', $after_hide['accepted_answer'] === 0);
    chat_check('hidden_parent_hides_nested_reply', !in_array($reply_id, chat_reply_ids($after_hide), true) && !in_array((int) $nested['id'], chat_reply_ids($after_hide), true));
    chat_approve($admin, 'reply', $reply_id);

    // A muted member cannot post/close/accept, but can still report abuse.
    wp_set_current_user($admin);
    chat_ok(Petish_Chat::moderate($admin, 'member', $bob, 'mute'), 'moderator_mutes_member');
    wp_set_current_user($bob);
    chat_rejected_without_write(static fn() => Petish_Chat::create_reply($bob, $topic_id, chat_request()), 'muted_reply_denied');
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($bob, chat_request()), 'muted_topic_denied');
    chat_check('muted_identity_cannot_post', Petish_Chat::identity($bob)['can_post'] === false);
    chat_ok(Petish_Chat::report($bob, $topic_id, 0, 'unsafe'), 'muted_member_can_report');
    $report_before = chat_snapshot();
    chat_ok(Petish_Chat::report($bob, $topic_id, 0, 'privacy'), 'repeat_report_result');
    chat_check('reports_deduplicated_without_rewrite', chat_snapshot() === $report_before);
    wp_set_current_user($alice);
    chat_rejected_without_write(static fn() => Petish_Chat::report($alice, $topic_id, 0, 'other'), 'self_report_denied');
    wp_set_current_user($carol);
    chat_rejected_without_write(static fn() => Petish_Chat::report($carol, $topic_id, $other_reply_id, 'spam'), 'cross_topic_report_denied');
    chat_rejected_without_write(static fn() => Petish_Chat::report($carol, $topic_id, 0, 'made-up'), 'report_reason_allowlisted');
    wp_set_current_user($admin);
    chat_ok(Petish_Chat::moderate($admin, 'member', $bob, 'unmute'), 'moderator_unmutes_member');

    // Real MariaDB advisory locks must serialize actor and topic mutations.
    wp_set_current_user($carol);
    chat_contended(static fn() => Petish_Chat::report($carol, $topic_id, 0, 'abuse'), 1, 'actor_lock_contention');
    chat_contended(static fn() => Petish_Chat::report($carol, $topic_id, 0, 'abuse'), 2, 'topic_lock_contention');
    chat_ok(Petish_Chat::report($carol, $topic_id, 0, 'abuse'), 'report_succeeds_after_lock_release');

    // First-review trust must come from an approved Chat item, never a role,
    // product review or forged comment on a different WordPress post type.
    $ordinary = chat_raw_topic($alice, 'publish', 'post');
    $reviewer = chat_user('reviewer');
    chat_raw_reply($ordinary, $reviewer, '1', 'review');
    chat_raw_reply($ordinary, $reviewer, '1');
    $reviewer_topic = chat_topic($reviewer);
    chat_check('unrelated_review_and_cross_cpt_chat_do_not_grant_trust', $reviewer_topic['status'] === 'pending');
    $manager_topic = chat_topic($manager);
    chat_check('staff_role_does_not_skip_first_review', $manager_topic['status'] === 'pending');
    chat_option(Petish_Chat::OPTION_MODERATION, 'all_review');
    chat_age_actor($alice);
    $all_review_topic = chat_topic($alice);
    chat_check('all_review_mode_applies_to_trusted_members', $all_review_topic['status'] === 'pending');
    update_option(Petish_Chat::OPTION_MODERATION, 'first_review', false);

    // Native storage may contain old/private/incorrectly typed rows. The public
    // projection must still enforce status, CPT, readiness, parent and actor.
    $private_topic = chat_raw_topic($alice, 'private');
    $trashed_topic = chat_raw_topic($alice, 'trash');
    $draft_topic = chat_raw_topic($alice, 'draft');
    $unready_topic = chat_raw_topic($alice, 'publish', null, false);
    $unconsented_topic = chat_raw_topic($alice, 'publish', null, true, false);
    $cross_type_topic = chat_raw_topic($alice, 'publish', 'page');
    $hidden_replies = array(
        chat_raw_reply($topic_id, $carol, '0'),
        chat_raw_reply($topic_id, $carol, 'spam'),
        chat_raw_reply($topic_id, $carol, 'trash'),
        chat_raw_reply($topic_id, $carol, '1', 'review'),
        chat_raw_reply($topic_id, $carol, '1', '', 0, true),
        chat_raw_reply($topic_id, $carol, '1', null, 0, false),
        chat_raw_reply($topic_id, $carol, '1', null, 0, true, false),
        chat_raw_reply($topic_id, $carol, '1', null, $other_reply_id),
        chat_raw_reply($ordinary, $carol),
    );
    wp_set_current_user(0);
    foreach (array($private_topic, $trashed_topic, $draft_topic, $unready_topic, $unconsented_topic, $cross_type_topic) as $i => $id)
        chat_error(Petish_Chat::thread($id), 'nonpublic_or_cross_cpt_topic_denied_' . $i);
    $public_view = chat_readonly(static fn() => Petish_Chat::thread($topic_id), $read_writes);
    chat_check('public_read_is_sql_readonly', !is_wp_error($public_view) && $read_writes === 0);
    chat_check('public_replies_exclude_pending_spam_trash_cross_type_unready_cross_parent', count(array_intersect($hidden_replies, chat_reply_ids($public_view))) === 0);
    $directory = chat_readonly(static fn() => Petish_Chat::threads(array()), $read_writes);
    chat_check('directory_read_is_sql_readonly', !is_wp_error($directory) && $read_writes === 0);
    chat_check('directory_excludes_private_trashed_draft_unready_unconsented_cross_cpt', count(array_intersect(array($private_topic, $trashed_topic, $draft_topic, $unready_topic, $unconsented_topic, $cross_type_topic), chat_ids($directory))) === 0);
    chat_assert_identity_private($public_view, 'legacy_public_projection');
    chat_check('public_projection_has_no_raw_user_ids', !preg_match('/"(?:user_id|member_id|post_author|comment_author_email|comment_author_IP|comment_agent)"\s*:/', wp_json_encode($public_view)));

    // Strict IDs and pagination reject coercion and arrays before accessing an
    // unrelated object. Zero is only a documented sentinel for no reply/parent.
    foreach (array(true, false, 1.5, array($topic_id), '0', '-1', '01', '1e2', (string) $topic_id . 'x') as $i => $bad) {
        chat_error(Petish_Chat::thread($bad), 'strict_topic_id_' . $i);
    }
    foreach (array(0, -1, true, array(1), '1e2', '00001', '100000') as $i => $bad) {
        chat_error(Petish_Chat::threads(array('page' => $bad)), 'strict_directory_page_' . $i);
        chat_error(Petish_Chat::thread($topic_id, 0, $bad), 'strict_reply_page_' . $i);
    }
    chat_error(Petish_Chat::threads(array('channel' => array('general'))), 'channel_array_denied');
    chat_error(Petish_Chat::threads(array('view' => 'private')), 'private_view_denied');

    // Shared per-day and short posting limits use actual stored native rows.
    $limited_topic_actor = chat_user('topiclimit');
    for ($i = 0; $i < Petish_Chat::TOPICS_PER_DAY; $i++) chat_raw_topic($limited_topic_actor, 'pending');
    wp_set_current_user($limited_topic_actor);
    $limit = Petish_Chat::create_topic($limited_topic_actor, chat_request());
    chat_check('daily_topic_limit_enforced', is_wp_error($limit) && $limit->get_error_code() === 'petish_chat_rate');
    $limited_reply_actor = chat_user('replylimit');
    for ($i = 0; $i < Petish_Chat::REPLIES_PER_DAY; $i++) chat_raw_reply($topic_id, $limited_reply_actor, '0');
    wp_set_current_user($limited_reply_actor);
    $limit = Petish_Chat::create_reply($limited_reply_actor, $topic_id, chat_request());
    chat_check('daily_reply_limit_enforced', is_wp_error($limit) && $limit->get_error_code() === 'petish_chat_rate');
    for ($i = 0; $i < Petish_Chat::REPORTS_PER_DAY; $i++)
        add_user_meta($carol, Petish_Chat::REPORT_PREFIX . 'topic_' . (900000 + $i), array('reason' => 'other', 'created_gmt' => gmdate('Y-m-d H:i:s')), true);
    wp_set_current_user($carol);
    $limit_before = chat_snapshot();
    $limit = Petish_Chat::report($carol, $second_topic, $other_reply_id, 'abuse');
    chat_check('daily_report_limit_enforced', is_wp_error($limit) && $limit->get_error_code() === 'petish_chat_rate');
    chat_check('daily_report_limit_no_write', chat_snapshot() === $limit_before);
    chat_ok(Petish_Chat::report($carol, $topic_id, 0, 'abuse'), 'existing_report_still_idempotent_at_limit');
    chat_check('report_deduplication_precedes_rate_count', chat_snapshot() === $limit_before);

    // Invalid optional reply IDs must not silently report a different target.
    $reporter = chat_user('reporter');
    wp_set_current_user($reporter);
    foreach (array(true, false, array($reply_id), '-1', '01', '1e2') as $i => $bad)
        chat_rejected_without_write(static fn() => Petish_Chat::report($reporter, $topic_id, $bad, 'other'), 'strict_report_reply_id_' . $i);

    // WordPress' generic comments, feeds, native form and REST API are not a
    // second route around PetChat's actor, moderation and public projections.
    wp_set_current_user(0);
    $generic = get_comments(array('post_id' => $topic_id, 'status' => 'all', 'number' => 100));
    chat_check('generic_comment_query_excludes_chat_topic', count($generic) === 0);
    $generic = get_comments(array('type' => Petish_Chat::COMMENT_TYPE, 'status' => 'approve', 'number' => 100));
    chat_check('generic_chat_comment_query_excludes_raw_records', count($generic) === 0);
    $native_topics = get_posts(array('post_type' => Petish_Chat::TYPE, 'post_status' => 'any', 'numberposts' => 100));
    chat_check('generic_post_query_excludes_chat_type', count($native_topics) === 0);
    $native_topic_by_id = new WP_Query(array('p' => $topic_id, 'post_type' => 'any', 'post_status' => 'publish'));
    chat_check('generic_direct_id_query_excludes_chat_topic', count($native_topic_by_id->posts) === 0);
    $feed_query = new WP_Query(array('post_type' => Petish_Chat::TYPE, 'feed' => 'rss2', 'posts_per_page' => 100));
    chat_check('native_feed_query_excludes_chat_topics', count($feed_query->posts) === 0);
    wp_set_current_user($bob);
    $native_before = chat_snapshot();
    $native_comment = wp_new_comment(array('comment_post_ID' => $topic_id, 'user_id' => $bob, 'comment_content' => 'Native form bypass attempt',
        'comment_author' => 'Native bypass', 'comment_author_email' => '', 'comment_type' => Petish_Chat::COMMENT_TYPE, 'comment_approved' => 1), true);
    chat_check('native_form_comment_rejected', is_wp_error($native_comment));
    chat_check('native_form_comment_no_write', chat_snapshot() === $native_before);
    $native_update = wp_update_comment(array('comment_ID' => $reply_id, 'comment_content' => 'Native update bypass attempt'), true);
    chat_check('native_member_comment_update_rejected', is_wp_error($native_update));
    chat_check('native_member_comment_update_no_write', chat_snapshot() === $native_before);
    $rest_request = new WP_REST_Request('POST', '/wp/v2/comments');
    $rest_request->set_body_params(array('post' => $topic_id, 'content' => 'REST bypass attempt'));
    $rest_result = rest_do_request($rest_request);
    chat_check('native_rest_comment_creation_rejected', $rest_result->get_status() >= 400);
    chat_check('native_rest_comment_creation_no_write', chat_snapshot() === $native_before);
    wp_set_current_user(0);
    $rest_request = new WP_REST_Request('GET', '/wp/v2/comments/' . $reply_id);
    $rest_result = rest_do_request($rest_request);
    chat_check('native_rest_comment_read_rejected', $rest_result->get_status() >= 400);
    $rest_request = new WP_REST_Request('GET', '/wp/v2/comments');
    $rest_request->set_query_params(array('post' => $topic_id));
    $rest_result = rest_do_request($rest_request);
    chat_check('native_rest_comment_collection_excludes_chat', $rest_result->get_status() >= 400 || $rest_result->get_data() === array());

    // Exercise real WordPress nonces and current principals at the HTTP boundary.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $ui_actor = chat_user('uimember');
    wp_set_current_user($ui_actor);
    $ui_data = chat_ui_input('create_topic', 0, array('title' => 'Pending UI note ' . $owner, 'channel' => 'care', 'uid' => (string) $bob));
    foreach (Petish_Chat_UI::OPERATIONS as $operation) {
        $input = chat_ui_input($operation, $topic_id, array('target' => 'topic', 'target_id' => (string) $topic_id));
        $input[Petish_Chat_UI::NONCE_FIELD] = 'invalid';
        chat_rejected_without_write(static fn() => Petish_Chat_UI::request($operation, $input), 'http_' . $operation . '_csrf_denied');
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('create_topic', $ui_data), 'http_get_write_denied');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    wp_set_current_user($bob);
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('create_topic', $ui_data), 'other_member_nonce_denied');
    wp_set_current_user(0);
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('create_topic', $ui_data), 'http_guest_write_denied');
    wp_set_current_user($ui_actor);
    $ui_created = chat_ok(Petish_Chat_UI::request('create_topic', $ui_data), 'native_http_form_posted');
    $ui_topic = (int) $ui_created['id'];
    chat_check('http_submitted_uid_cannot_change_author', (int) get_post($ui_topic)->post_author === $ui_actor && $ui_created['status'] === 'pending');
    $ui_again = chat_ok(Petish_Chat_UI::request('create_topic', $ui_data), 'native_http_form_retry');
    chat_check('http_retry_is_idempotent', $ui_again['duplicate'] === true && (int) $ui_again['id'] === $ui_topic);
    $without_consent = chat_ui_input('create_reply', $topic_id, array('parent_id' => (string) $reply_id));
    unset($without_consent['consent']);
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('create_reply', $without_consent), 'http_missing_publication_consent_denied');
    $wrong_parent = chat_ui_input('create_reply', $topic_id, array('parent_id' => (string) $other_reply_id));
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('create_reply', $wrong_parent), 'http_cross_topic_parent_denied');
    $wrong_parent = chat_ui_input('create_reply', $topic_id, array('parent_id' => array($reply_id)));
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('create_reply', $wrong_parent), 'http_parent_array_denied');
    chat_age_actor($ui_actor);
    $ui_nested = chat_ok(Petish_Chat_UI::request('create_reply', chat_ui_input('create_reply', $topic_id, array('parent_id' => (string) $reply_id))), 'native_http_nested_reply_created');
    chat_check('http_nested_reply_retains_exact_parent', get_comment($ui_nested['id'])->comment_parent === (string) $reply_id && $ui_nested['status'] === 'pending');
    $scoped = chat_ui_input('report', $topic_id, array('reply_id' => '0', 'reason' => 'other'));
    $scoped['topic_id'] = (string) $second_topic;
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('report', $scoped), 'nonce_bound_to_exact_topic');
    $scoped = chat_ui_input('report', $topic_id, array('reply_id' => '0', 'reason' => 'other'));
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('set_closed', $scoped), 'nonce_bound_to_exact_operation');
    foreach (array(true, array($topic_id), '-1', '01', '1e2') as $i => $bad) {
        $scoped = chat_ui_input('report', $topic_id, array('reply_id' => '0', 'reason' => 'other'));
        $scoped['topic_id'] = $bad;
        chat_rejected_without_write(static fn() => Petish_Chat_UI::request('report', $scoped), 'http_topic_id_strict_' . $i);
    }

    // Polling is an actual read-only projection of the current session. Logging
    // out or switching members must remove another account's pending content.
    $poll = chat_refresh_input();
    $_SERVER['HTTP_ORIGIN'] = home_url();
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    $signed_in = chat_readonly(static fn() => Petish_Chat_UI::refresh_payload($poll), $read_writes);
    chat_check('signed_in_poll_readonly', !is_wp_error($signed_in) && $read_writes === 0);
    chat_check('signed_in_poll_own_pending_visible', $signed_in['signed_in'] === true && strpos($signed_in['html'], 'Pending UI note ' . $owner) !== false);
    chat_assert_identity_private($signed_in, 'poll_public_identity');
    wp_set_current_user($bob);
    $switched = chat_readonly(static fn() => Petish_Chat_UI::refresh_payload($poll), $read_writes);
    chat_check('switched_member_poll_hides_previous_pending', !is_wp_error($switched) && $switched['signed_in'] === true && strpos($switched['html'], 'Pending UI note ' . $owner) === false && $read_writes === 0);
    wp_set_current_user(0);
    $signed_out = chat_readonly(static fn() => Petish_Chat_UI::refresh_payload($poll), $read_writes);
    chat_check('signed_out_poll_hides_previous_pending', !is_wp_error($signed_out) && $signed_out['signed_in'] === false && strpos($signed_out['html'], 'Pending UI note ' . $owner) === false && $read_writes === 0);
    chat_check('poll_context_stable_across_authentication', $signed_in['context'] === $signed_out['context']);
    chat_check('poll_revision_changes_after_logout', $signed_in['revision'] !== $signed_out['revision']);
    chat_check('poll_session_is_opaque_hash', isset($signed_in['session'], $switched['session'], $signed_out['session'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $signed_in['session']) === 1 && preg_match('/\A[a-f0-9]{64}\z/D', $signed_out['session']) === 1);
    chat_check('poll_session_changes_on_account_switch', $signed_in['session'] !== $switched['session'] && $switched['session'] !== $signed_out['session']);
    $guest_again = chat_readonly(static fn() => Petish_Chat_UI::refresh_payload($poll), $read_writes);
    chat_check('guest_poll_session_is_stable', !is_wp_error($guest_again) && $guest_again['session'] === $signed_out['session'] && $read_writes === 0);
    wp_set_current_user($ui_actor);
    $expires = time() + HOUR_IN_SECONDS;
    $tokens = WP_Session_Tokens::get_instance($ui_actor);
    $first_token = $tokens->create($expires);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($ui_actor, $expires, 'logged_in', $first_token);
    $first_session = chat_readonly(static fn() => Petish_Chat_UI::refresh_payload($poll), $read_writes);
    $next_token = $tokens->create($expires);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($ui_actor, $expires, 'logged_in', $next_token);
    $next_session = chat_readonly(static fn() => Petish_Chat_UI::refresh_payload($poll), $read_writes);
    chat_check('poll_fingerprint_changes_when_same_member_session_rotates', !is_wp_error($first_session) && !is_wp_error($next_session)
        && $first_session['session'] !== $next_session['session'] && $read_writes === 0);
    chat_check('poll_never_exposes_native_session_tokens', strpos(wp_json_encode($first_session), $first_token) === false
        && strpos(wp_json_encode($next_session), $next_token) === false);
    unset($_COOKIE[LOGGED_IN_COOKIE]);
    wp_set_current_user(0);
    $tampered = array_merge($poll, array('uid' => $ui_actor));
    chat_error(Petish_Chat_UI::refresh_payload($tampered), 'poll_submitted_uid_rejected');
    $_SERVER['HTTP_ORIGIN'] = 'https://foreign.example.invalid';
    chat_error(Petish_Chat_UI::refresh_payload($poll), 'foreign_origin_poll_rejected');
    $_SERVER['HTTP_ORIGIN'] = home_url(); $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    chat_error(Petish_Chat_UI::refresh_payload($poll), 'cross_site_fetch_poll_rejected');
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin'; $_SERVER['REQUEST_METHOD'] = 'GET';
    chat_error(Petish_Chat_UI::refresh_payload($poll), 'get_poll_rejected');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $tampered = array_merge($poll, array('context' => str_repeat('0', 64)));
    chat_error(Petish_Chat_UI::refresh_payload($tampered), 'poll_wrong_context_rejected');

    // Export the real native render for optional offline visual/browser checks.
    wp_set_current_user(0);
    chat_artifact('pet-chat-directory', chat_ok(Petish_Chat_UI::state(Petish_Chat_UI::filters(array())), 'native_directory_state'));
    chat_artifact('pet-chat-thread', chat_ok(Petish_Chat_UI::state(Petish_Chat_UI::filters(array('topic' => $topic_id))), 'native_thread_state'));
    wp_set_current_user($ui_actor);
    chat_artifact('pet-chat-pending', chat_ok(Petish_Chat_UI::state(Petish_Chat_UI::filters(array('topic' => $ui_topic))), 'native_pending_state'));

    // The native privacy exporter/eraser must operate on the selected account,
    // remove a whole owned thread, and keep another owner's conversation intact.
    $erase_actor = chat_user('erase');
    $erase_topic = chat_topic($erase_actor, array('title' => 'A thread to erase'));
    $erase_topic_id = (int) $erase_topic['id'];
    chat_approve($admin, 'topic', $erase_topic_id);
    $reply_to_erased_topic = chat_raw_reply($erase_topic_id, $alice);
    chat_age_actor($erase_actor);
    $erase_reply = chat_reply($erase_actor, $topic_id, array('body' => "I'm sharing my rabbit's favourite calm corner."));
    $erase_reply_id = (int) $erase_reply['id'];
    chat_check('apostrophes_preserved_by_native_comment_insert', get_comment($erase_reply_id)->comment_content === "I'm sharing my rabbit's favourite calm corner.");
    $child_of_erased_reply = chat_raw_reply($topic_id, $alice, '1', null, $erase_reply_id);
    wp_set_current_user($alice);
    chat_ok(Petish_Chat::accept_answer($alice, $topic_id, $erase_reply_id), 'erasable_answer_selected');
    wp_set_current_user($reporter);
    chat_ok(Petish_Chat::report($reporter, $erase_topic_id, 0, 'other'), 'erasable_topic_reported');
    chat_ok(Petish_Chat::report($reporter, $topic_id, $erase_reply_id, 'other'), 'erasable_reply_reported');
    $export = Petish_Chat::export(get_userdata($erase_actor)->user_email);
    chat_check('native_privacy_export_includes_owned_chat', !empty($export['data']) && $export['done'] === true
        && strpos(wp_json_encode($export['data']), 'A thread to erase') !== false);
    wp_set_current_user($admin);
    $erased = Petish_Chat::erase(get_userdata($erase_actor)->user_email);
    chat_check('native_privacy_erasure_completed', $erased['done'] === true && $erased['items_removed'] === true && $erased['items_retained'] === false);
    chat_check('erasure_deletes_owned_topic_and_all_its_replies', get_post($erase_topic_id) === null && get_comment($reply_to_erased_topic) === null);
    chat_check('erasure_deletes_owned_reply_elsewhere', get_comment($erase_reply_id) === null && get_post($topic_id) !== null);
    chat_check('erasure_clears_accepted_answer', (int) get_post_meta($topic_id, Petish_Chat::ACCEPTED, true) === 0);
    chat_check('erasure_does_not_promote_nested_reply_to_public_answer', get_comment($child_of_erased_reply)
        && get_comment($child_of_erased_reply)->comment_approved === '0');
    chat_check('erasure_purges_reports_about_deleted_targets', get_user_meta($reporter, Petish_Chat::REPORT_PREFIX . 'topic_' . $erase_topic_id, true) === ''
        && get_user_meta($reporter, Petish_Chat::REPORT_PREFIX . 'reply_' . $erase_reply_id, true) === '');
    chat_check('erasure_removes_saved_public_alias', get_user_meta($erase_actor, Petish_Chat::ALIAS, true) === '');
    wp_set_current_user($erase_actor);
    chat_rejected_without_write(static fn() => Petish_Chat::create_topic($erase_actor, chat_request()), 'erasing_account_cannot_resume_posting');
    wp_set_current_user(0);
    chat_error(Petish_Chat::thread($erase_topic_id), 'erased_topic_not_public');

    // The hosted proof library itself executes against these real native
    // objects, not stubs, and remains behind an actual SQL-write barrier.
    define('PETISH_CHAT_VERIFY_LIBRARY', true);
    require '/checks/native-verifiers/verify-pet-chat.php';
    $report = array('checks' => array());
    chat_readonly(static function() { petish_chat_proof_contracts(rtrim(ABSPATH, '/')); return true; }, $proof_writes);
    chat_check('hosted_proof_library_sql_readonly', $proof_writes === 0 && !empty($report['checks']) && count(array_filter($report['checks'], static fn($value) => $value !== true)) === 0);
    foreach ($report['checks'] as $key => $value) chat_check('hosted_' . $key, $value);
    $cache_calls = 0;
    $cache_capture = static function() use (&$cache_calls) { $cache_calls++; };
    add_action('litespeed_control_set_nocache', $cache_capture, PHP_INT_MAX);
    $_GET['petish_chat'] = '1'; Petish_Chat_UI::early_cache(); unset($_GET['petish_chat']);
    remove_action('litespeed_control_set_nocache', $cache_capture, PHP_INT_MAX);
    chat_check('early_cache_guard_at_init_zero', has_action('init', array('Petish_Chat_UI', 'early_cache')) === 0);
    chat_check('route_disables_page_cache_before_render', defined('DONOTCACHEPAGE') && DONOTCACHEPAGE === true && $cache_calls === 1);

    // Turning the optional feature off keeps old records protected, and cannot
    // leave its writer, polling or UI assets available to a guessed URL.
    update_option(Petish_Chat::OPTION_ENABLED, 'no', false);
    wp_set_current_user(0);
    chat_check('feature_off_native_post_queries_still_private', count(get_posts(array('post_type' => Petish_Chat::TYPE, 'post_status' => 'any', 'numberposts' => 100))) === 0);
    chat_check('feature_off_native_comment_queries_still_private', count(get_comments(array('type' => Petish_Chat::COMMENT_TYPE, 'status' => 'all', 'number' => 100))) === 0);
    chat_error(Petish_Chat::threads(), 'feature_off_reader_denied');
    chat_error(Petish_Chat_UI::refresh_payload($poll), 'feature_off_poll_denied');
    wp_set_current_user($ui_actor);
    chat_rejected_without_write(static fn() => Petish_Chat_UI::request('create_topic', chat_ui_input('create_topic')), 'feature_off_http_write_denied');
    wp_dequeue_style('petish-chat'); wp_dequeue_script('petish-chat'); $_GET['petish_chat'] = '1';
    Petish_Chat_UI::assets();
    chat_check('feature_off_no_ui_assets', !wp_style_is('petish-chat', 'enqueued') && !wp_script_is('petish-chat', 'enqueued'));
    unset($_GET['petish_chat']);
    chat_check('no_customer_or_staff_notification_mail_attempted', $mail_calls === 0);
} catch (Throwable $error) {
    $failure = $error instanceof RuntimeException && isset($checks[$error->getMessage()]) ? $error->getMessage() : 'unexpected_native_failure';
    file_put_contents('/checks/pet-chat-failure.private.txt', (string) $error);
    chmod('/checks/pet-chat-failure.private.txt', 0600);
} finally {
    wp_set_current_user(0);
    foreach ($options as $key => $value) {
        if ($value === null) delete_option($key); else update_option($key, $value, false);
    }
}
while (ob_get_level()) ob_end_clean();
echo wp_json_encode(array('status' => $failure === null ? 'success' : 'failure', 'category' => $failure ?? 'complete', 'checks' => $checks));
exit($failure === null ? 0 : 1);
