<?php
/** Real native storage/mutation proof. Runs only inside an owned disconnected .invalid site. */
if (PHP_SAPI !== 'cli') exit(2);
umask(0077); ob_start();
$native_root = getenv('PETISH_CHAT_NATIVE_ROOT') ?: '/srv/petish-native';
if (!is_string($native_root) || !is_dir($native_root) || is_link($native_root)) exit(2);
require rtrim($native_root, '/') . '/wp-load.php';
if (!defined('PETISH_FULFILMENT_NATIVE_OWNER') || !preg_match('/\A[a-f0-9]{32,64}\z/D', (string) PETISH_FULFILMENT_NATIVE_OWNER) || !str_ends_with((string) wp_parse_url(home_url('/'), PHP_URL_HOST), '.invalid') || !defined('WP_HTTP_BLOCK_EXTERNAL') || WP_HTTP_BLOCK_EXTERNAL !== true || !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true) exit(2);
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once WP_PLUGIN_DIR . '/petish-passport/includes/class-petish-chat-stream.php';
Petish_Chat_Stream::boot();
$checks = array(); $failure = null; $users = array(); $legacy_ids = array(); $options = array(); $owner = bin2hex(random_bytes(8)); $mail = 0; $http = 0;
add_filter('pre_http_request', static function() { global $http; $http++; throw new RuntimeException('Native stream proof attempted external HTTP.'); }, PHP_INT_MIN, 3);
add_filter('pre_wp_mail', static function() { global $mail; $mail++; throw new RuntimeException('Native stream proof attempted mail.'); }, PHP_INT_MIN);
function stream_check($name, $condition) { global $checks; $checks[$name] = $condition === true; if (!$checks[$name]) throw new RuntimeException($name); }
function stream_ok($value, $label) { stream_check($label, !is_wp_error($value)); return $value; }
function stream_error($value, $label) { stream_check($label, is_wp_error($value)); return $value; }
function stream_as($uid, $method, ...$args) { wp_set_current_user($uid); return Petish_Chat_Stream::$method($uid, ...$args); }
function stream_option($key, $value) { global $options; if (!array_key_exists($key, $options)) $options[$key] = get_option($key, null); update_option($key, $value, false); }
function stream_user($suffix, $role = 'customer') { global $users, $owner; $id = stream_ok(wp_insert_user(array('user_login' => 'stream-' . $owner . '-' . $suffix, 'user_pass' => bin2hex(random_bytes(24)), 'user_email' => 'PRIVATE-EMAIL-' . $suffix . '-' . $owner . '@example.invalid', 'display_name' => 'PRIVATE ACCOUNT ' . $suffix, 'first_name' => 'PRIVATE FIRST', 'role' => $role)), 'created_' . $suffix); $users[] = (int) $id; update_user_meta($id, 'billing_phone', 'PRIVATE PHONE'); return (int) $id; }
function stream_data($extra = array()) { return array_merge(array('body' => 'A little world for small pets.', 'alias' => 'Clover keeper', 'consent' => '1', 'request_id' => bin2hex(random_bytes(16))), $extra); }
function stream_age($uid) { $events = get_user_meta($uid, '_petish_chat_stream_events', true); if (is_array($events)) { foreach ($events as &$event) $event['at'] = time() - 60; unset($event); update_user_meta($uid, '_petish_chat_stream_events', $events); } }
function stream_ids($state) { return array_column($state['messages'], 'id'); }
function stream_snapshot() { global $wpdb; return hash('sha256', serialize(array($wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'petish_chat_stream_messages_v1 ORDER BY id', ARRAY_A), $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'petish_chat_stream_reactions_v1 ORDER BY message_id,actor_id,emoji', ARRAY_A), $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->usermeta} WHERE meta_key LIKE %s ORDER BY umeta_id", $wpdb->esc_like('_petish_chat_stream_') . '%'), ARRAY_A), get_option(Petish_Chat_Stream::OPTION_CONFIG, null)))); }
function stream_rejected($callback, $label) { $before = stream_snapshot(); stream_error($callback(), $label); stream_check($label . '_no_writes', stream_snapshot() === $before); }
function stream_readonly($callback, $label) { $guard = static function($sql) { if (!preg_match('/\A\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) throw new RuntimeException('Stream read attempted SQL mutation.'); return $sql; }; add_filter('query', $guard, PHP_INT_MAX); try { $result = $callback(); } finally { remove_filter('query', $guard, PHP_INT_MAX); } return stream_ok($result, $label); }
try {
    $admin = stream_user('admin', 'administrator'); $alice = stream_user('alice'); $bob = stream_user('bob'); $fresh = stream_user('fresh');
    wp_set_current_user($admin); stream_ok(Petish_Chat_Stream::install_schema(), 'explicit_native_schema_installed');
    stream_option('petish_chat_enabled_v1', 'yes'); stream_option(Petish_Chat_Stream::OPTION_ENABLED, 'no');
    stream_check('new_module_defaults_off', !Petish_Chat_Stream::enabled());
    stream_rejected(static fn() => stream_as($alice, 'send', 'general', stream_data()), 'feature_off_send_refused');
    stream_option(Petish_Chat_Stream::OPTION_ENABLED, 'yes'); stream_option(Petish_Chat_Stream::OPTION_CONFIG, null);
    wp_set_current_user(0); $empty = stream_readonly(static fn() => Petish_Chat_Stream::state('general'), 'public_empty_state_is_readonly');
    stream_check('four_starter_channels', count($empty['channels']) === 4 && count($empty['categories']) === 1);
    stream_rejected(static fn() => Petish_Chat_Stream::send($alice, 'general', stream_data()), 'forged_guest_actor_refused');
    wp_set_current_user($alice);
    foreach (array(array('consent' => '0'), array('body' => str_repeat('x', 1001)), array('alias' => 'Petish staff'), array('request_id' => 'bad'), array('quote_id' => -1)) as $n => $bad) stream_rejected(static fn() => Petish_Chat_Stream::send($alice, 'general', stream_data($bad)), 'invalid_input_' . $n);
    $data = stream_data(array('body' => 'First contribution for review.'));
    $first = stream_ok(Petish_Chat_Stream::send($alice, 'general', $data), 'first_message_saved')['message'];
    stream_check('first_message_requires_approval', $first['status'] === 'pending');
    $again = stream_ok(Petish_Chat_Stream::send($alice, 'general', $data), 'idempotent_retry_succeeds');
    stream_check('idempotency_reuses_single_id', $again['duplicate'] && $again['message']['id'] === $first['id']);
    stream_rejected(static fn() => Petish_Chat_Stream::send($alice, 'general', array_merge($data, array('body' => 'Changed retry.'))), 'idempotency_payload_conflict');
    stream_rejected(static fn() => Petish_Chat_Stream::send($alice, 'general', stream_data()), 'durable_send_interval');
    $own = stream_readonly(static fn() => Petish_Chat_Stream::state('general', $alice), 'owner_state_is_readonly');
    stream_check('owner_sees_own_pending', in_array($first['id'], stream_ids($own), true));
    wp_set_current_user($bob); stream_check('other_member_cannot_read_pending', !in_array($first['id'], stream_ids(Petish_Chat_Stream::state('general', $bob)), true));
    stream_error(Petish_Chat_Stream::state('general', $alice), 'forged_pending_viewer_refused');
    wp_set_current_user(0); stream_check('guest_cannot_read_pending', !in_array($first['id'], stream_ids(Petish_Chat_Stream::state('general')), true));
    stream_rejected(static fn() => stream_as($bob, 'moderate', array('op' => 'approve', 'id' => $first['id'])), 'member_cannot_approve');
    $approved = stream_ok(stream_as($admin, 'moderate', array('op' => 'approve', 'id' => $first['id'])), 'staff_approves_first')['message'];
    stream_check('approved_message_published', $approved['status'] === 'published');
    stream_age($alice); $second = stream_ok(stream_as($alice, 'send', 'general', stream_data(array('body' => 'Trusted immediate message.'))), 'trusted_second_saved')['message'];
    stream_check('trusted_message_is_immediate', $second['status'] === 'published');
    $reply = stream_ok(stream_as($bob, 'send', 'general', stream_data(array('alias' => 'Hazel keeper', 'body' => 'A quoted answer.', 'quote_id' => $first['id']))), 'quoted_first_message_saved')['message'];
    stream_check('quote_is_same_channel_public_projection', $reply['quote']['id'] === $first['id'] && $reply['status'] === 'pending');
    stream_ok(stream_as($admin, 'moderate', array('op' => 'approve', 'id' => $reply['id'])), 'quoted_message_approved');
    stream_age($bob);
    stream_rejected(static fn() => stream_as($bob, 'send', 'care', stream_data(array('quote_id' => $first['id']))), 'cross_channel_quote_refused');
    stream_rejected(static fn() => stream_as($bob, 'edit', $first['id'], array('body' => 'Foreign edit', 'revision' => 2)), 'foreign_owner_edit_refused');
    stream_rejected(static fn() => stream_as($bob, 'remove', $first['id']), 'foreign_owner_delete_refused');
    $reaction = stream_ok(stream_as($alice, 'react', $reply['id'], '👍'), 'reaction_added')['message'];
    stream_check('reaction_count_and_own_selection', $reaction['reactions']['👍'] === 1 && in_array('👍', $reaction['own_reactions'], true));
    $reaction = stream_ok(stream_as($alice, 'react', $reply['id'], '👍'), 'reaction_toggled')['message']; stream_check('reaction_toggle_not_duplicate_vote', empty($reaction['reactions']['👍']));
    stream_rejected(static fn() => stream_as($alice, 'react', $reply['id'], '<script>'), 'reaction_allowlist');
    stream_ok(stream_as($alice, 'mute', $reply['member_id'], true), 'personal_mute_saved');
    $muted = Petish_Chat_Stream::state('general', $alice); stream_check('muted_author_hidden', !in_array($reply['id'], stream_ids($muted), true));
    stream_ok(stream_as($alice, 'mute', $reply['member_id'], false), 'personal_unmute_saved');
    stream_ok(stream_as($bob, 'mark_read', 'general', $second['id']), 'read_marker_saved'); $read = stream_ok(stream_as($bob, 'mark_read', 'general', $first['id']), 'read_marker_backward_retry'); stream_check('read_marker_is_monotonic', $read['read_id'] === $second['id']);
    $presence = stream_ok(stream_as($alice, 'presence'), 'presence_saved'); stream_check('presence_uses_chosen_alias', in_array('Clover keeper', array_column($presence['presence'], 'alias'), true));
    wp_set_current_user(0); $public = stream_readonly(static fn() => Petish_Chat_Stream::state('general'), 'populated_public_state_is_readonly'); $encoded = wp_json_encode($public);
    foreach (array('PRIVATE-EMAIL-', 'PRIVATE ACCOUNT', 'PRIVATE FIRST', 'PRIVATE PHONE', 'author_id', 'user_login', 'billing_') as $sentinel) stream_check('public_identity_excludes_' . md5($sentinel), strpos($encoded, $sentinel) === false);
    stream_check('public_member_ids_are_opaque', preg_match('/\A[a-f0-9]{32}\z/D', $public['messages'][0]['member_id']) === 1);
    $report = stream_ok(stream_as($bob, 'report', $second['id'], 'privacy'), 'member_report_saved'); $duplicate = stream_ok(stream_as($bob, 'report', $second['id'], 'privacy'), 'member_report_deduplicated'); stream_check('report_dedup_flag', $duplicate['duplicate']);
    $queue = stream_ok(stream_as($admin, 'moderation_queue'), 'staff_moderation_queue'); stream_check('reported_message_in_staff_queue', in_array($second['id'], array_column($queue['items'], 'id'), true));
    stream_ok(stream_as($admin, 'moderate', array('op' => 'restrict', 'member_id' => $reply['member_id'])), 'staff_restricts_posting');
    stream_rejected(static fn() => stream_as($bob, 'send', 'general', stream_data()), 'restricted_member_cannot_post');
    stream_check('restriction_does_not_change_shop_role', get_userdata($bob)->roles === array('customer'));
    stream_ok(stream_as($admin, 'moderate', array('op' => 'unrestrict', 'member_id' => $reply['member_id'])), 'staff_reinstates_posting');
    $edited = stream_ok(stream_as($alice, 'edit', $second['id'], array('body' => 'Edited text requires staff review.', 'revision' => $second['revision'])), 'owner_edits_message')['message']; stream_check('edited_public_text_returns_to_review', $edited['status'] === 'pending');
    stream_rejected(static fn() => stream_as($alice, 'edit', $second['id'], array('body' => 'Stale edit', 'revision' => $second['revision'])), 'stale_edit_revision_refused');
    $created = stream_ok(stream_as($admin, 'manage', array('op' => 'create_category', 'name' => 'Care spaces')), 'staff_creates_category');
    stream_ok(stream_as($admin, 'manage', array('op' => 'create_channel', 'name' => 'Rabbit room', 'category_id' => 'care-spaces')), 'staff_creates_channel');
    wp_set_current_user($admin); $state = Petish_Chat_Stream::state('general', $admin); $room = array_values(array_filter($state['management']['channels'], static fn($room) => $room['id'] === 'rabbit-room'))[0];
    $managed = stream_ok(Petish_Chat_Stream::manage($admin, array('op' => 'update_channel', 'id' => 'rabbit-room', 'name' => 'Rabbit room', 'category_id' => 'community', 'read_only' => true, 'revision' => $room['revision'])), 'staff_moves_channel_and_sets_readonly');
    stream_rejected(static fn() => stream_as($alice, 'send', 'rabbit-room', stream_data()), 'readonly_channel_blocks_member_post');
    wp_set_current_user($admin); $order = array_reverse(array_column($managed['channels'], 'id')); $ordered = stream_ok(Petish_Chat_Stream::manage($admin, array('op' => 'reorder_channels', 'ids' => $order, 'revision' => $managed['revision'])), 'staff_reorders_channels'); stream_check('channel_order_is_applied', array_column($ordered['channels'], 'id') === $order);
    stream_rejected(static fn() => Petish_Chat_Stream::manage($admin, array('op' => 'reorder_channels', 'ids' => $order, 'revision' => $managed['revision'])), 'stale_order_revision_refused');
    $categories = array_reverse(array_column($ordered['categories'], 'id')); $ordered = stream_ok(Petish_Chat_Stream::manage($admin, array('op' => 'reorder_categories', 'ids' => $categories, 'revision' => $ordered['revision'])), 'staff_reorders_categories'); stream_check('category_order_is_applied', array_column($ordered['categories'], 'id') === $categories);
    $room = array_values(array_filter($ordered['channels'], static fn($room) => $room['id'] === 'rabbit-room'))[0]; $archived = stream_ok(Petish_Chat_Stream::manage($admin, array('op' => 'archive_channel', 'id' => 'rabbit-room', 'revision' => $room['revision'])), 'staff_archives_channel');
    wp_set_current_user(0); stream_error(Petish_Chat_Stream::state('rabbit-room'), 'archived_channel_not_public');
    wp_set_current_user($admin); $room = array_values(array_filter($archived['channels'], static fn($room) => $room['id'] === 'rabbit-room'))[0]; stream_ok(Petish_Chat_Stream::manage($admin, array('op' => 'restore_channel', 'id' => 'rabbit-room', 'revision' => $room['revision'])), 'staff_restores_channel');
    // A pre-existing pending pilot contribution must remain pending and source-identical.
    $legacy = wp_insert_post(array('post_type' => 'petish_chat_topic', 'post_status' => 'pending', 'post_author' => $fresh, 'post_title' => 'Historical conversation', 'post_content' => 'Historical pending message', 'comment_status' => 'closed'), true); stream_ok($legacy, 'owned_legacy_pending_created'); $legacy_ids[] = $legacy;
    foreach (array('_petish_chat_ready' => '1', '_petish_chat_consent' => '1', '_petish_chat_alias' => 'Historical keeper', '_petish_chat_channel' => 'general') as $key => $value) update_post_meta($legacy, $key, $value);
    $inspection = stream_ok(Petish_Chat_Stream::inspect_legacy($admin), 'legacy_snapshot_inspected'); $imported = stream_ok(Petish_Chat_Stream::import_legacy($admin, $inspection['source_sha256']), 'explicit_legacy_import');
    stream_check('legacy_source_unchanged', $inspection['source_sha256'] === Petish_Chat_Stream::inspect_legacy($admin)['source_sha256'] && get_post_status($legacy) === 'pending');
    stream_check('one_pending_legacy_record_imported', $imported['imported'] === 1 && !$imported['conflicts']);
    $repeat = stream_ok(Petish_Chat_Stream::import_legacy($admin, $inspection['source_sha256']), 'legacy_import_idempotent'); stream_check('legacy_import_no_duplicate', $repeat['imported'] === 0 && $repeat['existing'] === 1);
    wp_set_current_user($fresh); $historical = Petish_Chat_Stream::state('general', $fresh); $historical_rows = array_values(array_filter($historical['messages'], static fn($message) => str_contains($message['body'], 'Historical pending message'))); stream_check('historical_owner_keeps_pending_access', count($historical_rows) === 1 && $historical_rows[0]['status'] === 'pending');
    wp_set_current_user(0); stream_check('historical_pending_not_published', !str_contains(wp_json_encode(Petish_Chat_Stream::state('general')), 'Historical pending message'));
    wp_set_current_user($admin); stream_rejected(static fn() => Petish_Chat_Stream::import_legacy($admin, str_repeat('0', 64)), 'unreviewed_legacy_hash_refused');
    wp_update_post(array('ID' => $legacy, 'post_content' => 'Changed historical pending message'));
    $changed_source = Petish_Chat_Stream::inspect_legacy($admin); $conflict = stream_ok(Petish_Chat_Stream::import_legacy($admin, $changed_source['source_sha256']), 'changed_legacy_source_reports_conflict'); stream_check('changed_import_never_overwrites_existing_copy', count($conflict['conflicts']) === 1 && $conflict['imported'] === 0);
    wp_set_current_user($fresh); $history_after = Petish_Chat_Stream::state('general', $fresh); stream_check('original_import_keeps_reviewed_pending_text', str_contains(wp_json_encode($history_after), 'Historical pending message') && !str_contains(wp_json_encode($history_after), 'Changed historical pending message'));
    stream_ok(stream_as($alice, 'remove', $first['id']), 'owner_removes_message'); wp_set_current_user($bob); $after_delete = Petish_Chat_Stream::state('general', $bob); $quoted = array_values(array_filter($after_delete['messages'], static fn($row) => $row['id'] === $reply['id']))[0]; stream_check('deleted_quote_body_is_not_retained_publicly', $quoted['quote'] === null);
    $refresh = stream_readonly(static fn() => Petish_Chat_Stream::state('general', $bob, array('visible_ids' => array($first['id'], $second['id'], $reply['id'], 999999999))), 'loaded_history_refresh_is_readonly');
    stream_check('loaded_history_revokes_deleted_and_now_private_messages', in_array($first['id'], $refresh['removed_ids'], true) && in_array($second['id'], $refresh['removed_ids'], true) && in_array(999999999, $refresh['removed_ids'], true));
    stream_check('loaded_history_refreshes_only_currently_visible_records', array_column($refresh['refreshed_messages'], 'id') === array($reply['id']));
    stream_error(Petish_Chat_Stream::state('general', $bob, array('visible_ids' => array_fill(0, 201, $reply['id']))), 'loaded_history_refresh_is_bounded');
    $events = get_user_meta($bob, '_petish_chat_stream_events', true); update_user_meta($bob, '_petish_chat_stream_events', array_fill(0, 500, array('kind' => 'send', 'at' => time() - 60)));
    stream_rejected(static fn() => stream_as($bob, 'send', 'general', stream_data()), 'durable_daily_message_limit'); update_user_meta($bob, '_petish_chat_stream_events', $events);
    // A second real local database connection holds the exact service advisory lock.
    $connection = mysqli_init(); $host_parts = explode(':', DB_HOST, 2); $socket = $host_parts[1] ?? '';
    stream_check('secondary_connection_is_unix_socket_only', $host_parts[0] === 'localhost' && str_starts_with($socket, '/') && file_exists($socket));
    stream_check('real_secondary_local_connection', mysqli_real_connect($connection, 'localhost', DB_USER, DB_PASSWORD, DB_NAME, 0, $socket));
    $lock_name = 'petish-stream-' . substr(hash('sha256', $wpdb->prefix . ':' . DB_NAME), 0, 40);
    try { $locked = mysqli_query($connection, "SELECT GET_LOCK('" . mysqli_real_escape_string($connection, $lock_name) . "',0)"); stream_check('real_service_lock_held', (string) mysqli_fetch_row($locked)[0] === '1'); stream_rejected(static fn() => stream_as($bob, 'send', 'general', stream_data()), 'real_concurrent_mutation_is_serialized'); }
    finally { mysqli_query($connection, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($connection, $lock_name) . "')"); mysqli_close($connection); }
    $erased = stream_ok(Petish_Chat_Stream::erase(get_userdata($alice)->user_email), 'native_privacy_eraser'); stream_check('privacy_eraser_completes', $erased['done'] && !$erased['items_retained']);
    global $wpdb; stream_check('privacy_removes_authored_stream_rows', (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'petish_chat_stream_messages_v1 WHERE author_id=%d', $alice)) === 0);
    stream_check('no_mail_or_external_network', $mail === 0 && $http === 0);
} catch (Throwable $error) { $failure = $error->getMessage(); }
finally {
    wp_set_current_user(0);
    foreach ($legacy_ids as $id) wp_delete_post($id, true);
    foreach (array_reverse($users) as $id) wp_delete_user($id);
    foreach ($options as $key => $value) { if ($value === null) delete_option($key); else update_option($key, $value, false); }
}
ob_end_clean();
echo wp_json_encode(array('status' => $failure === null ? 'success' : 'failure', 'checks' => $checks, 'count' => count($checks), 'failure' => $failure, 'mail_attempts' => $mail, 'http_attempts' => $http, 'scope' => 'Owned disconnected native WordPress/WooCommerce only; no hosted data or connections.'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($failure === null ? 0 : 1);
