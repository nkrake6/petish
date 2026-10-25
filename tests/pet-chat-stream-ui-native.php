<?php
/** Native UI transport/template proof. Only an owned, disconnected .invalid WordPress sandbox. */
if ( PHP_SAPI !== 'cli' ) exit( 2 );
umask( 0077 );
$native_root = getenv( 'PETISH_CHAT_NATIVE_ROOT' );
if ( ! is_string( $native_root ) || ! is_dir( $native_root ) || is_link( $native_root ) ) exit( 2 );
if ( ! defined( 'DOING_AJAX' ) ) define( 'DOING_AJAX', true );
ob_start();
require rtrim( $native_root, '/' ) . '/wp-load.php';
if ( ! defined( 'PETISH_FULFILMENT_NATIVE_OWNER' ) || ! preg_match( '/\A[a-f0-9]{32,64}\z/D', (string) PETISH_FULFILMENT_NATIVE_OWNER ) || ! str_ends_with( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), '.invalid' ) || ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || WP_HTTP_BLOCK_EXTERNAL !== true || ! defined( 'DISABLE_WP_CRON' ) || DISABLE_WP_CRON !== true ) exit( 2 );
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once WP_PLUGIN_DIR . '/petish-passport/includes/class-petish-chat-stream.php';
require_once WP_PLUGIN_DIR . '/petish-passport/includes/class-petish-chat-stream-ui.php';
Petish_Chat_Stream::boot();
$checks = array(); $failure = null; $users = array(); $options = array(); $mail = 0; $http = 0; $fixture = null;
$owner = bin2hex( random_bytes( 8 ) );
add_filter( 'pre_http_request', static function () { global $http; $http++; throw new RuntimeException( 'UI proof attempted external HTTP.' ); }, PHP_INT_MIN, 3 );
add_filter( 'pre_wp_mail', static function () { global $mail; $mail++; throw new RuntimeException( 'UI proof attempted external mail.' ); }, PHP_INT_MIN );
class Petish_Stream_UI_Stop extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new Petish_Stream_UI_Stop( 'json' ); }; } );
function stream_ui_check( $label, $condition ) { global $checks; $checks[ $label ] = true === $condition; if ( ! $checks[ $label ] ) throw new RuntimeException( $label ); }
function stream_ui_ok( $value, $label ) { stream_ui_check( $label, ! is_wp_error( $value ) ); return $value; }
function stream_ui_option( $key, $value ) { global $options; if ( ! array_key_exists( $key, $options ) ) $options[ $key ] = get_option( $key, null ); update_option( $key, $value, false ); }
function stream_ui_user( $role ) { global $owner, $users; $id = stream_ui_ok( wp_insert_user( array( 'user_login' => 'stream-ui-' . $owner . '-' . $role, 'user_pass' => bin2hex( random_bytes( 24 ) ), 'user_email' => 'stream-ui-' . $owner . '-' . $role . '@example.invalid', 'display_name' => 'PRIVATE NATIVE DISPLAY', 'role' => $role ) ), 'create_' . $role ); $users[] = (int) $id; return (int) $id; }
function stream_ui_action( $op, $payload = array(), $method = 'POST' ) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $payload + array( 'op' => $op, 'session' => Petish_Chat_Stream_UI::session(), Petish_Chat_Stream_UI::NONCE_FIELD => wp_create_nonce( Petish_Chat_Stream_UI::nonce_action( $op ) ) );
    http_response_code( 200 ); ob_start();
    try { Petish_Chat_Stream_UI::ajax(); } catch ( Petish_Stream_UI_Stop $stop ) {}
    finally { $body = ob_get_clean(); }
    $result = json_decode( $body, true );
    if ( ! is_array( $result ) ) throw new RuntimeException( 'UI transport did not return JSON.' );
    return array( 'success' => $result['success'], 'data' => $result['data'], 'status' => http_response_code() );
}
try {
    $admin = stream_ui_user( 'administrator' ); $member = stream_ui_user( 'customer' );
    wp_set_current_user( $admin ); stream_ui_ok( Petish_Chat_Stream::install_schema(), 'explicit_storage_prepared' );
    stream_ui_option( 'petish_chat_enabled_v1', 'yes' ); stream_ui_option( Petish_Chat_Stream::OPTION_ENABLED, 'yes' ); stream_ui_option( Petish_Chat_Stream::OPTION_CONFIG, null );
    stream_ui_check( 'complete_UI_ready', Petish_Chat_Stream_UI::ready() );
    $read = stream_ui_action( 'read', array( 'channel' => 'care' ) );
    stream_ui_check( 'read_channel_slug_transported', $read['success'] && $read['data']['result']['channel']['id'] === 'care' );
    stream_ui_check( 'state_has_session_binding', $read['data']['result']['session'] === Petish_Chat_Stream_UI::session() );
    stream_ui_check( 'URL_uses_slug', str_contains( Petish_Chat_Stream_UI::url( 'habitat' ), 'chat_channel=habitat' ) );
    stream_ui_check( 'default_channel_general', str_contains( Petish_Chat_Stream_UI::url(), 'chat_channel=general' ) );
    $denied = stream_ui_action( 'read', array(), 'GET' ); stream_ui_check( 'GET_rejected', ! $denied['success'] && 405 === $denied['status'] );
    $denied = stream_ui_action( 'read', array( Petish_Chat_Stream_UI::NONCE_FIELD => 'invalid' ) ); stream_ui_check( 'bad_nonce_rejected', ! $denied['success'] && 403 === $denied['status'] );
    $denied = stream_ui_action( 'send', array( Petish_Chat_Stream_UI::NONCE_FIELD => wp_create_nonce( Petish_Chat_Stream_UI::nonce_action( 'read' ) ) ) ); stream_ui_check( 'nonce_cannot_cross_operation', ! $denied['success'] && 403 === $denied['status'] );
    $denied = stream_ui_action( 'read', array( 'session' => 'wrong' ) ); stream_ui_check( 'session_mismatch_rejected', ! $denied['success'] && 409 === $denied['status'] );
    $denied = stream_ui_action( 'read', array( 'visible_ids' => array( array( '1' ) ) ) ); stream_ui_check( 'nested_visible_ids_rejected', ! $denied['success'] && 400 === $denied['status'] );
    $denied = stream_ui_action( 'read', array( 'visible_ids' => array_fill( 0, 201, '1' ) ) ); stream_ui_check( 'visible_window_bounded', ! $denied['success'] && 400 === $denied['status'] );
    $managed = stream_ui_action( 'manage', array( 'operation' => 'create_channel', 'name' => 'UI native room', 'category_id' => 'community' ) ); stream_ui_check( 'staff_create_channel_via_transport', $managed['success'] );
    $room = array_values( array_filter( $managed['data']['result']['channels'], static fn( $row ) => 'ui-native-room' === $row['id'] ) )[0];
    $updated = stream_ui_action( 'manage', array( 'operation' => 'update_channel', 'id' => 'ui-native-room', 'revision' => (string) $room['revision'], 'name' => 'UI native room', 'read_only' => '1', 'archived' => '0' ) );
    $room = array_values( array_filter( $updated['data']['result']['channels'] ?? array(), static fn( $row ) => 'ui-native-room' === $row['id'] ) )[0] ?? null;
    stream_ui_check( 'canonical_booleans_and_slug_accepted', $updated['success'] && $room['read_only'] === true && $room['archived'] === false );
    foreach ( array( 'yes', 'true', '', array(), 1 ) as $i => $bad ) { $denied = stream_ui_action( 'manage', array( 'operation' => 'update_channel', 'id' => 'ui-native-room', 'read_only' => $bad ) ); stream_ui_check( 'noncanonical_boolean_' . $i, ! $denied['success'] && 400 === $denied['status'] ); }
    wp_set_current_user( $member );
    $sent = stream_ui_action( 'send', array( 'channel' => 'care', 'body' => "**A cosy corner** for our little pets.\nWhat enrichment did your pet enjoy today?", 'alias' => 'Clover keeper', 'consent' => '1', 'request_id' => bin2hex( random_bytes( 16 ) ) ) );
    $message = $sent['data']['result']['message'] ?? null;
    stream_ui_check( 'member_send_to_slug', $sent['success'] && $message['channel'] === 'care' );
    stream_ui_check( 'first_contribution_explicitly_pending', $message['status'] === 'pending' );
    $own = Petish_Chat_Stream_UI::state( 'care' );
    stream_ui_check( 'chosen_alias_only', $own['viewer']['alias'] === 'Clover keeper' && ! str_contains( wp_json_encode( $own ), 'PRIVATE NATIVE DISPLAY' ) );
    wp_set_current_user( $admin );
    $approved = stream_ui_action( 'moderate', array( 'operation' => 'approve', 'id' => (string) $message['id'] ) ); stream_ui_check( 'staff_moderation_operation_transported', $approved['success'] && $approved['data']['result']['message']['status'] === 'published' );
    wp_set_current_user( 0 ); $public = Petish_Chat_Stream_UI::state( 'care' ); stream_ui_check( 'public_read_has_published_message', count( $public['messages'] ) === 1 );
    $denied = stream_ui_action( 'react', array( 'id' => (string) $message['id'], 'emoji' => '👍' ) ); stream_ui_check( 'guest_mutation_rejected', ! $denied['success'] && 401 === $denied['status'] );
    $safe = Petish_Chat_Stream_UI::formatted( '<img src=x onerror=alert(1)> **bold** *italic* __underline__ https://example.invalid/pet' );
    stream_ui_check( 'arbitrary_HTML_escaped', ! str_contains( $safe, '<img' ) && str_contains( $safe, '&lt;img' ) );
    stream_ui_check( 'limited_formatting_rendered', str_contains( $safe, '<strong>bold</strong>' ) && str_contains( $safe, '<em>italic</em>' ) && str_contains( $safe, '<u>underline</u>' ) );
    stream_ui_check( 'external_links_safe', str_contains( $safe, 'rel="noopener noreferrer nofollow ugc"' ) && ! str_contains( Petish_Chat_Stream_UI::formatted( 'javascript:alert(1)' ), '<a' ) );
    stream_ui_check( 'external_image_embedding_denied', ! Petish_Chat_Stream_UI::media_url( 'https://external.invalid/photo.png' ) );
    wp_set_current_user( $member ); $state = Petish_Chat_Stream_UI::state( 'care' );
    // Synthetic visual fixture includes no live endpoint, account data or supplier information.
    $state['messages'][0]['body'] = "**A cosy corner** for our little pets.\nWhat enrichment did your pet enjoy today?";
    ob_start(); Petish_Chat_Stream_UI::template( $state ); $html = ob_get_clean();
    stream_ui_check( 'template_channel_slug', str_contains( $html, 'data-chat-channel="care"' ) );
    stream_ui_check( 'template_category_slug', str_contains( $html, 'data-chat-category="community"' ) );
    stream_ui_check( 'keyboard_divider', str_contains( $html, 'role="separator"' ) && str_contains( $html, 'aria-valuemin="180"' ) );
    stream_ui_check( 'mobile_two_navigation_rows', str_contains( $html, 'pcs-mobile-topics' ) && str_contains( $html, 'pcs-mobile-channels' ) );
    stream_ui_check( '1000_character_limit', str_contains( $html, 'maxlength="1000"' ) );
    stream_ui_check( 'no_Gravatar_or_broadcast_embeds', ! str_contains( $html, 'gravatar' ) && ! str_contains( $html, '<iframe' ) );
    $hostile = $state; $hostile['channel']['name'] = '</script><script>alert(1)</script>'; ob_start(); Petish_Chat_Stream_UI::template( $hostile ); $hostile_html = ob_get_clean(); stream_ui_check( 'template_and_JSON_delimiters_escaped', ! str_contains( $hostile_html, '</script><script>alert(1)' ) );
    $output = getenv( 'PETISH_CHAT_UI_FIXTURE' );
    if ( is_string( $output ) && str_starts_with( $output, '/private/tmp/' ) && ! is_link( $output ) ) {
        $config = Petish_Chat_Stream_UI::config( $state ); $config['endpoint'] = 'https://fixture.invalid/disabled'; $config['interval'] = 86400000; $config['upload']['enabled'] = false;
        $json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        $html = preg_replace_callback( '~(<script type="application/json" data-chat-config>).*?(</script>)~s', static fn( $match ) => $match[1] . $json . $match[2], $html );
        $css = file_get_contents( PETISH_PASSPORT_DIR . 'assets/pet-chat-stream.css' );
        $theme_css = file_get_contents( __DIR__ . '/../wordpress/wp-content/themes/dt-the7-child/assets/css/petish.css' );
        $theme_tokens = explode( '/* Override generated The7 variables', $theme_css, 2 )[0];
        $js = file_get_contents( PETISH_PASSPORT_DIR . 'assets/pet-chat-stream.js' );
        $fixture = file_put_contents( $output, '<!doctype html><html lang="en" data-theme="light"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pet Chat synthetic UI proof</title><style>body{margin:0;background:var(--color-bg,#f8f9fc)}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}</style><style>' . $theme_tokens . $css . '</style><body>' . $html . '<script>' . $js . '</script></body></html>' ) !== false;
        stream_ui_check( 'synthetic_visual_fixture_saved', $fixture );
    }
    stream_ui_option( Petish_Chat_Stream::OPTION_ENABLED, 'no' ); stream_ui_check( 'feature_off_not_ready', ! Petish_Chat_Stream_UI::ready() ); $denied = stream_ui_action( 'read', array( 'channel' => 'care' ) ); stream_ui_check( 'feature_off_transport_denied', ! $denied['success'] && 404 === $denied['status'] );
} catch ( Throwable $error ) { $failure = $error->getMessage(); }
finally {
    wp_set_current_user( 0 );
    foreach ( array_reverse( $users ) as $uid ) wp_delete_user( $uid );
    foreach ( $options as $key => $value ) { if ( null === $value ) delete_option( $key ); else update_option( $key, $value, false ); }
    $_POST = array();
}
stream_ui_check( 'no_external_mail', 0 === $mail ); stream_ui_check( 'no_external_HTTP', 0 === $http );
ob_end_clean();
echo wp_json_encode( array( 'passed' => null === $failure, 'checks' => $checks, 'failure' => $failure, 'fixture' => $fixture ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure ? 0 : 1 );
