<?php
/** Offline presentation fixture: real UI/template, synthetic public projections. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'PETISH_PASSPORT_DIR', dirname( __DIR__, 2 ) . '/wordpress/wp-content/plugins/petish-passport/' );
define( 'PETISH_PASSPORT_URL', 'https://pet-chat-fixture.petish.test/assets/' );
const FIXTURE_ORIGIN = 'https://pet-chat-fixture.petish.test';

// These presentation helpers intentionally do not emulate storage, authentication,
// moderation or nonce verification. Those run against WordPress in native proof.
function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_textarea( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return esc_attr( $url ); }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = '' ) { return esc_attr( $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = '' ) { echo esc_attr( $text ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
function home_url( $path = '/' ) { return FIXTURE_ORIGIN . $path; }
function admin_url( $path = '' ) { return FIXTURE_ORIGIN . '/wp-admin/' . $path; }
function add_query_arg( $query, $value = '', $url = '' ) {
    if ( is_array( $query ) ) $url = $value;
    else $query = array( $query => $value );
    $parts = explode( '#', $url, 2 );
    return $parts[0] . ( str_contains( $parts[0], '?' ) ? '&' : '?' ) . http_build_query( $query ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
}
function wp_login_url( $redirect = '' ) { return FIXTURE_ORIGIN . '/wp-login.php?redirect_to=' . rawurlencode( $redirect ); }
function _n( $single, $plural, $number, $domain = '' ) { return 1 === $number ? $single : $plural; }
function number_format_i18n( $number ) { return number_format( $number ); }
function wp_trim_words( $text, $length = 55, $more = null ) { $words = preg_split( '/\s+/', $text ); return count( $words ) > $length ? implode( ' ', array_slice( $words, 0, $length ) ) . $more : $text; }
function get_option( $key, $default = false ) { return array( 'petish_chat_enabled_v1' => 'yes', 'date_format' => 'j M Y', 'time_format' => 'H:i' )[ $key ] ?? $default; }
function wp_date( $format, $time ) { return gmdate( $format, $time ); }
function wp_nonce_field( $action, $name = '_wpnonce', $referer = true ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="fixture-' . hash( 'sha256', $action ) . '">'; }
function selected( $actual, $expected, $echo = true ) { $value = $actual === $expected ? ' selected="selected"' : ''; if ( $echo ) echo $value; return $value; }
function checked( $actual, $expected = true, $echo = true ) { $value = $actual === $expected ? ' checked="checked"' : ''; if ( $echo ) echo $value; return $value; }
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {}
function wp_add_inline_script( $handle, $script, $position = '' ) { $GLOBALS['fixture_config'] = $script; }

require PETISH_PASSPORT_DIR . 'includes/class-petish-chat.php';
require PETISH_PASSPORT_DIR . 'includes/class-petish-chat-ui.php';

$scenario = $argv[1] ?? 'listing-member';
if ( ! in_array( $scenario, array( 'listing-member', 'listing-guest', 'listing-private', 'thread-member', 'thread-guest', 'thread-updated', 'thread-pending', 'thread-closed', 'listing-empty', 'listing-error', 'listing-escaped' ), true ) ) exit( 2 );
$member = ! str_ends_with( $scenario, '-guest' );
$thread_mode = str_starts_with( $scenario, 'thread-' );
$filters = array( 'channel' => 'general', 'topic' => $thread_mode ? 101 : 0, 'view' => 'latest', 'page' => 1, 'reply_page' => 1 );
$long = 'A thoughtful question about enrichment, comfortable habitats and helping a shy small pet settle in';
$topic = array( 'id' => 101, 'channel' => 'general', 'kind' => 'question', 'title' => $long,
    'body' => "What has worked for your pets?\nI am looking for practical experiences, not urgent veterinary advice. " . str_repeat( 'a', 95 ),
    'alias' => 'HabitatHelper', 'created_gmt' => '2026-10-24 10:00:00', 'updated_gmt' => '2026-10-24 10:15:00', 'status' => 'published', 'closed' => false,
    'accepted_answer' => 202, 'replies_count' => 2, 'is_owner' => $member, 'can_reply' => $member, 'can_close' => $member,
    'can_accept' => $member, 'can_report' => $member, 'revision' => hash( 'sha256', 'fixture-topic' ) );
$reply = array( 'id' => 202, 'topic_id' => 101, 'parent_id' => 0, 'alias' => 'CloverKeeper',
    'body' => "A cardboard hide and a quiet corner helped here.\nWe introduced one change at a time.", 'created_gmt' => '2026-10-24 10:05:00', 'status' => 'published', 'accepted' => true, 'is_owner' => false, 'can_report' => $member, 'can_accept' => $member );
$topic['replies'] = array( $reply, array_replace( $reply, array( 'id' => 203, 'alias' => 'SmallPaws', 'body' => 'Sharing a second experience in this conversation.', 'accepted' => false ) ) );
$topic['reply_page'] = 1; $topic['reply_pages'] = 2; $topic['reply_total'] = 42;
if ( 'thread-updated' === $scenario ) {
    $topic['replies'][] = array_replace( $reply, array( 'id' => 204, 'alias' => 'GardenFriend', 'body' => 'This is the new public reply.', 'accepted' => false ) );
    $topic['replies_count'] = 3;
}
if ( 'thread-pending' === $scenario ) { $topic['status'] = 'pending'; $topic['can_reply'] = false; }
if ( 'thread-closed' === $scenario ) { $topic['closed'] = true; $topic['can_reply'] = false; }
$second = array_replace( $topic, array( 'id' => 102, 'kind' => 'discussion', 'title' => 'A small discovery worth sharing', 'body' => 'What enrichment activity surprised you this week?', 'alias' => 'PublicAliasOnly', 'accepted_answer' => 0, 'replies_count' => 0 ) );
if ( 'listing-private' === $scenario ) $second['status'] = 'pending';
if ( 'listing-escaped' === $scenario ) {
    $topic['title'] = '<img src="https://forbidden.invalid/x" onerror="window.__chatInjected=true">';
    $topic['alias'] = '<script>window.__chatInjected=true</script>';
    $topic['body'] = '<svg onload="window.__chatInjected=true"> & private-looking text is still plain text';
}
$state = array( 'filters' => $filters, 'channels' => Petish_Chat::channels(), 'thread' => $thread_mode ? $topic : null,
    'listing' => array( 'items' => 'listing-empty' === $scenario ? array() : array( $topic, $second ), 'page' => 1, 'pages' => 3, 'total' => 42 ),
    'signed_in' => $member, 'can_post' => $member, 'session' => hash( 'sha256', $member ? 'public-fixture-session-A' : 'public-fixture-guest' ),
    'form' => array( 'alias' => 'ChosenPublicName', 'title' => '', 'body' => '', 'kind' => 'question', 'channel' => 'general', 'request_id' => '00ab00ab-00ab-40ab-80ab-00ab00ab00ab' ),
    'error' => 'listing-error' === $scenario ? 'Please add a title; your draft remains below.' : '', 'notice' => '', 'context' => Petish_Chat_UI::context( $filters ) );
if ( 'listing-error' === $scenario ) { $state['form']['body'] = 'A retained draft with quotes " and <literal> text.'; }
$_GET['petish_chat'] = '1';
Petish_Chat_UI::assets();
ob_start(); Petish_Chat_UI::render( $state ); $html = ob_get_clean();
echo json_encode( array( 'html' => $html, 'config' => $GLOBALS['fixture_config'] ?? '', 'feed' => Petish_Chat_UI::feed_html( $state ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
