<?php
/** Optional Pet Chat channel presentation. Service projections are the only content source. */
defined( 'ABSPATH' ) || exit;

final class Petish_Chat_Stream_UI {
    const ACTION = 'petish_chat_stream';
    const NONCE_FIELD = 'petish_chat_stream_nonce';
    const OPERATIONS = array( 'read', 'send', 'edit', 'delete', 'react', 'report', 'mute', 'mark_read', 'presence', 'manage', 'moderate', 'queue', 'upload' );
    private static $rendering = false;

    public static function ready() {
        if ( ! class_exists( 'Petish_Chat_Stream' ) || ! Petish_Chat_Stream::enabled() ) return false;
        foreach ( array( 'includes/class-petish-chat-stream-ui.php', 'templates/pet-chat-stream.php', 'assets/pet-chat-stream.css', 'assets/pet-chat-stream.js' ) as $file ) {
            if ( ! is_readable( PETISH_PASSPORT_DIR . $file ) ) return false;
        }
        return true;
    }

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'early_cache' ), 0 );
        add_action( 'template_redirect', array( __CLASS__, 'route' ), 7 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 56 );
        add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
        add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax' ) );
        // Existing Chat owns the menu and document-title hooks. Do not add duplicates.
    }

    public static function is_request() {
        return isset( $_GET['petish_chat'] ) && is_string( $_GET['petish_chat'] ) && '1' === $_GET['petish_chat'];
    }

    public static function early_cache() {
        if ( self::is_request() || ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) && self::ACTION === $_REQUEST['action'] ) ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
            do_action( 'litespeed_control_set_nocache', 'Pet Chat channel visibility and member actions' );
        }
    }

    public static function no_store() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        do_action( 'litespeed_control_set_nocache', 'Pet Chat channel session' );
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
        header( 'Vary: Cookie', false );
        header( 'X-Content-Type-Options: nosniff' );
    }

    public static function url( $channel = 'general' ) {
        $args = array( 'petish_chat' => '1' );
        if ( self::channel_key( $channel ) ) $args['chat_channel'] = $channel;
        return add_query_arg( $args, home_url( '/' ) );
    }

    public static function session() {
        return hash_hmac( 'sha256', 'petish-chat-channels|' . get_current_user_id() . '|' . wp_get_session_token(), wp_salt( 'nonce' ) );
    }

    public static function nonce_action( $operation ) {
        return 'petish_chat_stream_' . $operation . '_' . get_current_user_id();
    }

    public static function nonces() {
        $out = array();
        foreach ( self::OPERATIONS as $operation ) $out[ $operation ] = wp_create_nonce( self::nonce_action( $operation ) );
        return $out;
    }

    private static function id( $value ) {
        return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/\A[1-9][0-9]{0,17}\z/D', (string) $value ) ? (int) $value : 0;
    }

    private static function channel_key( $value ) {
        return is_string( $value ) && preg_match( '/\A[a-z0-9][a-z0-9-]{0,47}\z/D', $value ) ? $value : '';
    }

    private static function input( $input, $key, $default = '' ) {
        return isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? $input[ $key ] : $default;
    }

    private static function fail( $message, $status = 400 ) {
        wp_send_json_error( array( 'message' => $message ), $status );
    }

    public static function state( $channel = 'general', $options = array() ) {
        if ( ! self::ready() ) return new WP_Error( 'petish_chat_unavailable', __( 'Pet Chat is not available yet.', 'petish-passport' ), array( 'status' => 404 ) );
        $state = Petish_Chat_Stream::state( $channel, get_current_user_id(), $options );
        if ( is_wp_error( $state ) ) return $state;
        if ( ! is_array( $state ) ) return new WP_Error( 'petish_chat_unavailable', __( 'Pet Chat could not be loaded.', 'petish-passport' ) );
        $state['session'] = self::session();
        return $state;
    }

    public static function route() {
        if ( ! self::is_request() || ! self::ready() ) return;
        self::no_store();
        $channel = isset( $_GET['chat_channel'] ) ? self::channel_key( wp_unslash( $_GET['chat_channel'] ) ) : 'general';
        $state = self::state( $channel );
        self::$rendering = true;
        status_header( is_wp_error( $state ) ? (int) ( $state->get_error_data()['status'] ?? 404 ) : 200 );
        get_header();
        if ( is_wp_error( $state ) ) {
            echo '<main class="petish-chat-stream pcs-error-page"><h1>' . esc_html__( 'Pet Chat', 'petish-passport' ) . '</h1><p>' . esc_html( $state->get_error_message() ) . '</p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to Petish', 'petish-passport' ) . '</a></main>';
        } else {
            self::template( $state );
        }
        get_footer();
        exit;
    }

    public static function assets() {
        if ( ! self::ready() || ( ! self::$rendering && ! self::is_request() ) ) return;
        $base = PETISH_PASSPORT_DIR . 'assets/';
        wp_enqueue_style( 'petish-chat-stream', PETISH_PASSPORT_URL . 'assets/pet-chat-stream.css', array(), substr( hash_file( 'sha256', $base . 'pet-chat-stream.css' ), 0, 16 ) );
        wp_enqueue_script( 'petish-chat-stream', PETISH_PASSPORT_URL . 'assets/pet-chat-stream.js', array(), substr( hash_file( 'sha256', $base . 'pet-chat-stream.js' ), 0, 16 ), array( 'strategy' => 'defer', 'in_footer' => true ) );
    }

    public static function template( $state ) {
        include PETISH_PASSPORT_DIR . 'templates/pet-chat-stream.php';
    }

    public static function capabilities() {
        return class_exists( 'Petish_Chat_Stream_Media' ) ? Petish_Chat_Stream_Media::capabilities() : array( 'enabled' => false, 'upload_ready' => false, 'max_bytes' => 250 * 1024 * 1024, 'accept' => '', 'label' => __( 'Uploads are unavailable on this server.', 'petish-passport' ) );
    }

    public static function config( $state ) {
        return array( 'endpoint' => admin_url( 'admin-ajax.php' ), 'action' => self::ACTION, 'nonces' => self::nonces(), 'state' => $state, 'upload' => self::capabilities(), 'maxLength' => 1000, 'interval' => 10000,
            'loginUrl' => wp_login_url( self::url( $state['channel']['id'] ?? 'general' ) ), 'reactions' => array( '❤️', '👍', '😂', '😮', '😢', '🎉' ) );
    }

    public static function ajax() {
        self::no_store();
        if ( ! self::ready() ) self::fail( __( 'Pet Chat is unavailable.', 'petish-passport' ), 404 );
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) self::fail( __( 'Use a POST request.', 'petish-passport' ), 405 );
        $input = wp_unslash( $_POST );
        $op = self::input( $input, 'op' );
        if ( ! in_array( $op, self::OPERATIONS, true ) ) self::fail( __( 'Choose a supported action.', 'petish-passport' ) );
        if ( ! wp_verify_nonce( self::input( $input, self::NONCE_FIELD ), self::nonce_action( $op ) ) ) self::fail( __( 'Your session changed. Reload Pet Chat to continue; keep your draft first.', 'petish-passport' ), 403 );
        if ( ! hash_equals( self::session(), self::input( $input, 'session' ) ) ) self::fail( __( 'Your account session changed. Reload Pet Chat to continue.', 'petish-passport' ), 409 );
        $uid = get_current_user_id();
        if ( 'read' !== $op && ! $uid ) self::fail( __( 'Sign in to join Pet Chat.', 'petish-passport' ), 401 );
        $channel = self::channel_key( $input['channel'] ?? 'general' );
        $id = self::id( $input['id'] ?? 0 );
        $result = null;
        switch ( $op ) {
            case 'read':
                $options = array( 'limit' => 50 );
                foreach ( array( 'before_id', 'after_id' ) as $key ) if ( self::id( $input[ $key ] ?? 0 ) ) $options[ $key ] = self::id( $input[ $key ] );
                if ( isset( $input['visible_ids'] ) ) {
                    if ( ! is_array( $input['visible_ids'] ) || count( $input['visible_ids'] ) > 200 ) self::fail( __( 'The loaded message window is invalid.', 'petish-passport' ) );
                    $options['visible_ids'] = array();
                    foreach ( $input['visible_ids'] as $visible_id ) { if ( ! self::id( $visible_id ) ) self::fail( __( 'The loaded message window is invalid.', 'petish-passport' ) ); $options['visible_ids'][] = self::id( $visible_id ); }
                }
                if ( '' !== self::input( $input, 'search' ) ) $options['search'] = self::input( $input, 'search' );
                $result = self::state( $channel, $options );
                break;
            case 'send':
                $result = Petish_Chat_Stream::send( $uid, $channel, array( 'body' => self::input( $input, 'body' ), 'alias' => self::input( $input, 'alias' ), 'consent' => self::input( $input, 'consent' ), 'request_id' => self::input( $input, 'request_id' ), 'quote_id' => self::id( $input['quote_id'] ?? 0 ), 'attachment_pending' => '1' === self::input( $input, 'attachment_pending' ) ) );
                break;
            case 'edit':
                $result = Petish_Chat_Stream::edit( $uid, $id, array( 'body' => self::input( $input, 'body' ), 'revision' => self::input( $input, 'revision' ) ) );
                break;
            case 'delete': $result = Petish_Chat_Stream::remove( $uid, $id ); break;
            case 'react': $result = Petish_Chat_Stream::react( $uid, $id, self::input( $input, 'emoji' ) ); break;
            case 'report': $result = Petish_Chat_Stream::report( $uid, $id, self::input( $input, 'reason', 'other' ) ); break;
            case 'mute': $result = Petish_Chat_Stream::mute( $uid, self::input( $input, 'member_id' ), '1' === self::input( $input, 'muted' ) ); break;
            case 'mark_read': $result = Petish_Chat_Stream::mark_read( $uid, $channel, $id ); break;
            case 'presence': $result = Petish_Chat_Stream::presence( $uid ); break;
            case 'queue': $result = Petish_Chat_Stream::moderation_queue( $uid, max( 1, (int) self::input( $input, 'page', '1' ) ) ); break;
            case 'manage': $input['op'] = self::input( $input, 'operation' ); foreach ( array( 'read_only', 'archived' ) as $field ) { if ( isset( $input[ $field ] ) ) { if ( ! is_string( $input[ $field ] ) || ! in_array( $input[ $field ], array( '0', '1' ), true ) ) self::fail( __( 'Choose a supported channel setting.', 'petish-passport' ) ); $input[ $field ] = '1' === $input[ $field ]; } } $result = Petish_Chat_Stream::manage( $uid, $input ); break;
            case 'moderate': $input['op'] = self::input( $input, 'operation' ); $result = Petish_Chat_Stream::moderate( $uid, $input ); break;
            case 'upload': $result = self::upload( $uid, $id ); break;
        }
        if ( is_wp_error( $result ) ) self::fail( $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) );
        wp_send_json_success( array( 'result' => $result, 'session' => self::session(), 'nonces' => self::nonces() ) );
    }

    private static function upload( $uid, $message_id ) {
        if ( ! class_exists( 'Petish_Chat_Stream_Media' ) || ! method_exists( 'Petish_Chat_Stream', 'attach_media' ) ) return new WP_Error( 'petish_chat_upload_unavailable', __( 'Uploads are unavailable on this server.', 'petish-passport' ) );
        $records = Petish_Chat_Stream_Media::save_uploads( $_FILES, $uid, $message_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Dedicated media validator checks every upload byte.
        if ( is_wp_error( $records ) ) return $records;
        $result = Petish_Chat_Stream::attach_media( $uid, $message_id, $records );
        if ( is_wp_error( $result ) ) {
            Petish_Chat_Stream_Media::delete_manifest( $records, $uid, $message_id );
            return $result;
        }
        $commit = Petish_Chat_Stream_Media::commit_manifest( $records, $uid, $message_id );
        if ( is_wp_error( $commit ) ) {
            // Binding already succeeded. Retain the private cleanup journal; its sweep checks the database binding.
            $result['housekeeping_pending'] = true;
        }
        return $result;
    }

    public static function formatted( $body, $depth = 0 ) {
        if ( ! is_string( $body ) ) return '';
        if ( $depth >= 5 ) return esc_html( $body );
        $pattern = '~(\*\*(?=\S)[\s\S]+?\*\*|__(?=\S)[\s\S]+?__|\*(?=\S)[^*\n]+?\*|https?://[^\s<>]+)~u';
        $parts = preg_split( $pattern, $body, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) return esc_html( $body );
        $out = '';
        foreach ( $parts as $i => $part ) {
            if ( 0 === $i % 2 ) { $out .= esc_html( $part ); continue; }
            if ( preg_match( '~\Ahttps?://~i', $part ) ) {
                $out .= '<a href="' . esc_url( $part, array( 'http', 'https' ) ) . '" target="_blank" rel="noopener noreferrer nofollow ugc">' . esc_html( $part ) . '</a>';
            } elseif ( str_starts_with( $part, '**' ) ) {
                $out .= '<strong>' . self::formatted( substr( $part, 2, -2 ), $depth + 1 ) . '</strong>';
            } elseif ( str_starts_with( $part, '__' ) ) {
                $out .= '<u>' . self::formatted( substr( $part, 2, -2 ), $depth + 1 ) . '</u>';
            } else {
                $out .= '<em>' . self::formatted( substr( $part, 1, -1 ), $depth + 1 ) . '</em>';
            }
        }
        return $out;
    }

    public static function date_iso( $value ) {
        $stamp = is_string( $value ) ? strtotime( $value . ( str_ends_with( $value, 'Z' ) ? '' : ' UTC' ) ) : false;
        return $stamp ? gmdate( 'c', $stamp ) : '';
    }

    public static function message_html( $message, $previous = null ) {
        $id = (int) ( $message['id'] ?? 0 );
        $stamp = strtotime( self::date_iso( $message['created_gmt'] ?? '' ) );
        $previous_stamp = $previous ? strtotime( self::date_iso( $previous['created_gmt'] ?? '' ) ) : 0;
        $grouped = $previous && ( $message['member_id'] ?? '' ) === ( $previous['member_id'] ?? '' ) && $stamp && $previous_stamp && $stamp - $previous_stamp >= 0 && $stamp - $previous_stamp <= 900 && ( $message['status'] ?? '' ) === ( $previous['status'] ?? '' );
        echo '<article class="pcs-message' . ( $grouped ? ' pcs-grouped' : '' ) . '" id="pet-chat-message-' . $id . '" data-message-id="' . $id . '"' . ( 'published' !== ( $message['status'] ?? '' ) ? ' data-chat-private' : '' ) . '><span class="pcs-avatar" aria-hidden="true">' . esc_html( $message['initials'] ?? 'P' ) . '</span><div class="pcs-message-meta"><strong class="pcs-alias">' . esc_html( $message['alias'] ?? '' ) . '</strong><time datetime="' . esc_attr( self::date_iso( $message['created_gmt'] ?? '' ) ) . '">' . esc_html( $stamp ? wp_date( get_option( 'time_format' ), $stamp ) : '' ) . '</time>';
        if ( ! empty( $message['updated_gmt'] ) && $message['updated_gmt'] !== ( $message['created_gmt'] ?? '' ) ) echo '<span class="pcs-edited">' . esc_html__( 'edited', 'petish-passport' ) . '</span>';
        echo '</div>';
        if ( 'published' !== ( $message['status'] ?? '' ) ) echo '<span class="pcs-pending">' . esc_html__( 'Awaiting moderation · only you and staff', 'petish-passport' ) . '</span>';
        if ( ! empty( $message['quote'] ) ) {
            $quote = $message['quote'];
            echo '<button type="button" class="pcs-quote" data-chat-jump="' . (int) ( $quote['id'] ?? 0 ) . '"><span aria-hidden="true">↪</span><span><strong>' . esc_html( $quote['alias'] ?? '' ) . '</strong>: ' . esc_html( wp_html_excerpt( $quote['body'] ?: __( 'Attachment', 'petish-passport' ), 160, '…' ) ) . '</span></button>';
        }
        echo '<div class="pcs-message-body">' . self::formatted( $message['body'] ?? '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped limited-format renderer.
        if ( ! empty( $message['media'] ) ) {
            echo '<div class="pcs-media">';
            foreach ( $message['media'] as $media ) {
                $url = $media['url'] ?? '';
                if ( ! is_string( $url ) || ! self::media_url( $url ) ) continue;
                if ( 'video' === ( $media['kind'] ?? '' ) ) echo '<video controls preload="metadata" src="' . esc_url( $url ) . '" aria-label="' . esc_attr( $media['label'] ?? __( 'Uploaded video', 'petish-passport' ) ) . '"></video>';
                else echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $media['label'] ?? __( 'Shared pet photo', 'petish-passport' ) ) . '" loading="lazy" decoding="async"></a>';
            }
            echo '</div>';
        }
        echo '<div class="pcs-message-actions" data-chat-message-actions="' . $id . '"></div></article>';
    }

    public static function media_url( $url ) {
        $parts = wp_parse_url( $url );
        $home = wp_parse_url( home_url( '/' ) );
        return is_array( $parts ) && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && strtolower( $parts['host'] ) === strtolower( $home['host'] ?? '' ) && ( $parts['scheme'] ?? '' ) === ( $home['scheme'] ?? '' ) && ( $parts['port'] ?? null ) === ( $home['port'] ?? null );
    }
}
