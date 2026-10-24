<?php
/** Public Pet Chat presentation; all content permissions remain in the service. */
defined( 'ABSPATH' ) || exit;

final class Petish_Chat_UI {
    const REFRESH_ACTION = 'petish_chat_refresh';
    const NONCE_FIELD = 'petish_chat_nonce';
    const OPERATIONS = array( 'create_topic', 'create_reply', 'accept_answer', 'set_closed', 'report', 'moderate' );
    private static $rendering = false;

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'early_cache' ), 0 );
        add_action( 'template_redirect', array( __CLASS__, 'route' ), 8 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 55 );
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'navigation_objects' ), 35, 2 );
        add_filter( 'wp_nav_menu_items', array( __CLASS__, 'navigation_items' ), 35, 2 );
        add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 100 );
        add_action( 'admin_menu', array( __CLASS__, 'moderation_menu' ) );
        foreach ( self::OPERATIONS as $operation ) {
            $handler = static function () use ( $operation ) { self::handle( $operation ); };
            add_action( 'admin_post_petish_chat_' . $operation, $handler );
            add_action( 'admin_post_nopriv_petish_chat_' . $operation, $handler );
        }
        add_action( 'wp_ajax_' . self::REFRESH_ACTION, array( __CLASS__, 'refresh' ) );
        add_action( 'wp_ajax_nopriv_' . self::REFRESH_ACTION, array( __CLASS__, 'refresh' ) );
    }

    public static function is_request() {
        return isset( $_GET['petish_chat'] ) && is_string( $_GET['petish_chat'] ) && '1' === $_GET['petish_chat'];
    }

    public static function early_cache() {
        if ( self::is_request() || ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) && str_starts_with( $_REQUEST['action'], 'petish_chat_' ) ) ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
            do_action( 'litespeed_control_set_nocache', 'Pet Chat public visibility and member actions' );
        }
    }

    public static function session( $context ) {
        return hash_hmac( 'sha256', $context . '|' . get_current_user_id() . '|' . wp_get_session_token(), wp_salt( 'nonce' ) );
    }

    private static function error( $code, $message, $status = 400 ) {
        return new WP_Error( 'petish_chat_ui_' . $code, __( $message, 'petish-passport' ), array( 'status' => $status ) );
    }

    private static function id( $value ) {
        return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/\A[1-9][0-9]{0,17}\z/D', (string) $value ) ? (int) $value : 0;
    }

    private static function text( $input, $name ) {
        return isset( $input[ $name ] ) && is_string( $input[ $name ] ) ? $input[ $name ] : '';
    }

    public static function channels() {
        $out = array();
        foreach ( (array) Petish_Chat::channels() as $slug => $label ) {
            if ( is_string( $slug ) && preg_match( '/\A[a-z][a-z0-9-]{0,39}\z/D', $slug ) && is_string( $label ) ) $out[ $slug ] = $label;
        }
        return $out;
    }

    public static function filters( $input ) {
        if ( ! is_array( $input ) ) return self::error( 'filters', 'Check the channel or topic link and try again.' );
        $channel = self::text( $input, 'channel' );
        $view = isset( $input['view'] ) ? self::text( $input, 'view' ) : 'latest';
        if ( '' !== $channel && ! isset( self::channels()[ $channel ] ) ) return self::error( 'channel', 'This channel is not available.', 404 );
        if ( ! in_array( $view, array( 'latest', 'unanswered', 'answered' ), true ) ) return self::error( 'view', 'Choose a supported conversation filter.' );
        foreach ( array( 'channel', 'view' ) as $key ) if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) ) return self::error( 'filters', 'Check the channel or topic link and try again.' );
        $out = array( 'channel' => $channel, 'topic' => 0, 'view' => $view, 'page' => 1, 'reply_page' => 1 );
        foreach ( array( 'topic', 'page', 'reply_page' ) as $key ) {
            if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] || ( 'topic' === $key && in_array( $input[ $key ], array( 0, '0' ), true ) ) ) continue;
            $id = self::id( $input[ $key ] );
            if ( ! $id || ( 'topic' !== $key && $id > 10000 ) ) return self::error( 'filters', 'Check the page or topic number and try again.' );
            $out[ $key ] = $id;
        }
        return $out;
    }

    private static function route_filters() {
        $input = array();
        foreach ( array( 'channel', 'topic', 'view', 'page', 'reply_page' ) as $key ) {
            if ( isset( $_GET[ 'chat_' . $key ] ) ) $input[ $key ] = wp_unslash( $_GET[ 'chat_' . $key ] );
        }
        return self::filters( $input );
    }

    public static function url( $changes = array(), $anchor = '' ) {
        $query = array( 'petish_chat' => '1' );
        foreach ( array( 'channel', 'topic', 'view', 'page', 'reply_page' ) as $key ) {
            if ( ! isset( $changes[ $key ] ) || '' === $changes[ $key ] || ( 'view' === $key && 'latest' === $changes[ $key ] ) || ( in_array( $key, array( 'page', 'reply_page' ), true ) && (int) $changes[ $key ] <= 1 ) || ( 'topic' === $key && ! $changes[ $key ] ) ) continue;
            $query[ 'chat_' . $key ] = $changes[ $key ];
        }
        $url = add_query_arg( $query, home_url( '/' ) );
        return $url . ( $anchor && preg_match( '/\A[a-z0-9_-]+\z/D', $anchor ) ? '#' . $anchor : '' );
    }

    public static function context( $filters ) {
        return hash( 'sha256', wp_json_encode( array( $filters['channel'], (int) $filters['topic'], $filters['view'], (int) $filters['page'], (int) $filters['reply_page'] ) ) );
    }

    public static function state( $filters, $form = array(), $error = '' ) {
        if ( ! Petish_Chat::enabled() ) return self::error( 'disabled', 'Pet Chat is not available yet.', 404 );
        if ( is_wp_error( $filters ) ) return $filters;
        $uid = get_current_user_id();
        $thread = $filters['topic'] ? Petish_Chat::thread( $filters['topic'], $uid, $filters['reply_page'] ) : null;
        if ( is_wp_error( $thread ) ) return $thread;
        if ( $filters['topic'] && ! is_array( $thread ) ) return self::error( 'missing', 'This conversation is not available.', 404 );
        $listing = $thread ? array( 'items' => array(), 'page' => 1, 'pages' => 0, 'total' => 0 ) : Petish_Chat::threads( $filters, $uid );
        if ( is_wp_error( $listing ) ) return $listing;
        $identity = $uid ? Petish_Chat::identity( $uid ) : array( 'alias' => '', 'can_post' => false );
        if ( ! is_array( $identity ) ) $identity = array( 'alias' => '', 'can_post' => false );
        $channels = self::channels();
        $defaults = array( 'alias' => is_string( $identity['alias'] ?? null ) ? $identity['alias'] : '', 'title' => '', 'body' => '', 'kind' => 'question', 'channel' => $filters['channel'] ?: (string) array_key_first( $channels ), 'request_id' => wp_generate_uuid4(), 'parent_id' => '0' );
        $clean_form = array();
        foreach ( $defaults as $key => $value ) $clean_form[ $key ] = isset( $form[ $key ] ) && is_string( $form[ $key ] ) ? $form[ $key ] : $value;
        $reply_to = null;
        foreach ( $thread['replies'] ?? array() as $reply ) if ( (int) $reply['id'] === self::id( $clean_form['parent_id'] ) && ! $reply['parent_id'] && 'published' === $reply['status'] ) $reply_to = $reply;
        if ( ! $reply_to ) $clean_form['parent_id'] = '0';
        $context = self::context( $filters );
        return array( 'filters' => $filters, 'channels' => $channels, 'thread' => $thread, 'listing' => $listing, 'signed_in' => $uid > 0,
            'can_post' => ! empty( $identity['can_post'] ), 'form' => $clean_form, 'reply_to' => $reply_to, 'error' => $error, 'notice' => '', 'context' => $context, 'session' => self::session( $context ) );
    }

    private static function no_store() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        do_action( 'litespeed_control_set_nocache', 'Pet Chat private member state' );
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
        header( 'Vary: Cookie', false );
        header( 'X-Content-Type-Options: nosniff' );
    }

    public static function route() {
        if ( ! self::is_request() ) return;
        self::no_store();
        $form = isset( $_GET['chat_reply_to'] ) && self::id( $_GET['chat_reply_to'] ) ? array( 'parent_id' => (string) self::id( $_GET['chat_reply_to'] ) ) : array();
        $state = self::state( self::route_filters(), $form );
        self::$rendering = true;
        status_header( is_wp_error( $state ) ? (int) ( $state->get_error_data()['status'] ?? 404 ) : 200 );
        get_header();
        if ( is_wp_error( $state ) ) {
            echo '<main class="petish-chat pch-error-page"><h1>' . esc_html__( 'Pet Chat', 'petish-passport' ) . '</h1><p>' . esc_html( $state->get_error_message() ) . '</p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to Petish', 'petish-passport' ) . '</a></main>';
        } else {
            $notice = isset( $_GET['chat_notice'] ) && is_string( $_GET['chat_notice'] ) ? $_GET['chat_notice'] : '';
            $messages = array( 'pending' => __( 'Your contribution is awaiting moderation. It is visible only to you and the moderation team.', 'petish-passport' ), 'posted' => __( 'Your contribution has been posted.', 'petish-passport' ), 'duplicate' => __( 'This contribution was already received. It has not been posted twice.', 'petish-passport' ), 'reported' => __( 'Your report has been sent to the moderation team.', 'petish-passport' ), 'updated' => __( 'The conversation has been updated.', 'petish-passport' ) );
            $state['notice'] = $messages[ $notice ] ?? '';
            self::render( $state );
        }
        get_footer();
        exit;
    }

    public static function assets() {
        if ( ! Petish_Chat::enabled() || ( ! self::$rendering && ! self::is_request() ) ) return;
        $dir = PETISH_PASSPORT_DIR . 'assets/';
        $url = PETISH_PASSPORT_URL . 'assets/';
        wp_enqueue_style( 'petish-chat', $url . 'pet-chat.css', array(), substr( hash_file( 'sha256', $dir . 'pet-chat.css' ), 0, 16 ) );
        wp_enqueue_script( 'petish-chat', $url . 'pet-chat.js', array(), substr( hash_file( 'sha256', $dir . 'pet-chat.js' ), 0, 16 ), array( 'strategy' => 'defer', 'in_footer' => true ) );
        wp_add_inline_script( 'petish-chat', 'window.petishChat = ' . wp_json_encode( array( 'endpoint' => admin_url( 'admin-ajax.php' ), 'action' => self::REFRESH_ACTION,
            'newUpdates' => __( 'New updates are available.', 'petish-passport' ), 'loadUpdates' => __( 'Show new updates', 'petish-passport' ), 'updated' => __( 'Conversation updated.', 'petish-passport' ),
            'paused' => __( 'Updates paused.', 'petish-passport' ), 'resumed' => __( 'Updates resumed.', 'petish-passport' ), 'pauseLabel' => __( 'Pause updates', 'petish-passport' ), 'resumeLabel' => __( 'Resume updates', 'petish-passport' ),
            'retrying' => __( 'Updates are temporarily unavailable. We will retry shortly.', 'petish-passport' ), 'sessionEnded' => __( 'Your session changed. Sign in or reload to continue; your draft is still here.', 'petish-passport' ),
            'replyingTo' => __( 'Replying to %s', 'petish-passport' ),
            'stopped' => __( 'Updates stopped. Reload this page to reconnect.', 'petish-passport' ) ) ) . ';', 'before' );
    }

    public static function render( $state ) {
        self::template( $state );
    }

    public static function template( $state ) {
        include PETISH_PASSPORT_DIR . 'templates/pet-chat.php';
    }

    public static function feed_html( $state ) {
        ob_start();
        echo '<div data-chat-feed-content>';
        if ( $state['thread'] ) {
            self::thread_html( $state );
        } else {
            $items = $state['listing']['items'] ?? array();
            if ( ! $items ) echo '<div class="pch-empty"><h2>' . esc_html__( 'A little quiet, for now.', 'petish-passport' ) . '</h2><p>' . esc_html__( 'Start a question or conversation and help make this space your own.', 'petish-passport' ) . '</p></div>';
            foreach ( $items as $topic ) self::topic_card( $topic, $state );
            self::pagination( $state['listing']['page'] ?? 1, $state['listing']['pages'] ?? 0, $state['filters'], false );
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function topic_card( $topic, $state ) {
        $id = (int) $topic['id'];
        $url = self::url( array( 'topic' => $id, 'channel' => $topic['channel'] ) );
        $pending = 'pending' === $topic['status'];
        echo '<article class="pch-topic"' . ( $pending ? ' data-chat-private' : '' ) . '><div class="pch-topic-identity">' . self::avatar( $topic['alias'] ) . '<div><span class="pch-alias">' . esc_html( $topic['alias'] ) . '</span><span class="pch-meta">' . esc_html( self::date_label( $topic['created_gmt'] ) ) . '</span></div></div><div class="pch-topic-main"><div class="pch-topic-tags">';
        self::badges( $topic, $state );
        echo '</div><h2><a href="' . esc_url( $url ) . '">' . esc_html( $topic['title'] ) . '</a></h2><p class="pch-excerpt">' . esc_html( wp_trim_words( $topic['body'], 35, '…' ) ) . '</p><a class="pch-thread-link" href="' . esc_url( $url ) . '">' . esc_html( sprintf( _n( '%s reply', '%s replies', (int) $topic['replies_count'], 'petish-passport' ), number_format_i18n( (int) $topic['replies_count'] ) ) ) . '<span aria-hidden="true"> →</span></a></div></article>';
    }

    private static function badges( $topic, $state ) {
        echo '<span class="pch-tag">' . esc_html( $state['channels'][ $topic['channel'] ] ?? $topic['channel'] ) . '</span><span class="pch-tag">' . esc_html( 'question' === $topic['kind'] ? __( 'Question', 'petish-passport' ) : __( 'Conversation', 'petish-passport' ) ) . '</span>';
        if ( 'pending' === $topic['status'] ) echo '<span class="pch-tag pch-pending">' . esc_html__( 'Awaiting moderation · only you and staff', 'petish-passport' ) . '</span>';
        elseif ( 'question' === $topic['kind'] ) echo '<span class="pch-tag ' . ( ! empty( $topic['accepted_answer'] ) ? 'pch-answered' : 'pch-open' ) . '">' . esc_html( ! empty( $topic['accepted_answer'] ) ? __( 'Answered', 'petish-passport' ) : __( 'Open question', 'petish-passport' ) ) . '</span>';
        if ( ! empty( $topic['closed'] ) ) echo '<span class="pch-tag">' . esc_html__( 'Closed to replies', 'petish-passport' ) . '</span>';
    }

    private static function thread_html( $state ) {
        $topic = $state['thread'];
        echo '<article class="pch-thread-topic" id="chat-topic-' . (int) $topic['id'] . '"' . ( 'pending' === $topic['status'] ? ' data-chat-private' : '' ) . '><div class="pch-topic-tags">';
        self::badges( $topic, $state );
        echo '</div><h2>' . esc_html( $topic['title'] ) . '</h2><div class="pch-topic-identity">' . self::avatar( $topic['alias'] ) . '<div><strong class="pch-alias">' . esc_html( $topic['alias'] ) . '</strong><time class="pch-meta" datetime="' . esc_attr( $topic['created_gmt'] ) . '">' . esc_html( self::date_label( $topic['created_gmt'] ) ) . '</time></div></div><div class="pch-body">' . nl2br( esc_html( $topic['body'] ) ) . '</div><div class="pch-message-actions">';
        if ( ! empty( $topic['can_close'] ) ) {
            self::form_start( 'set_closed', $topic['id'] );
            echo '<input type="hidden" name="closed" value="' . ( ! empty( $topic['closed'] ) ? '0' : '1' ) . '"><button class="pch-text-button" type="submit">' . esc_html( ! empty( $topic['closed'] ) ? __( 'Reopen conversation', 'petish-passport' ) : __( 'Close replies', 'petish-passport' ) ) . '</button></form>';
        }
        if ( ! empty( $topic['can_report'] ) ) self::report_form( $topic['id'], 0 );
        echo '</div></article><div class="pch-replies-heading"><h3>' . esc_html__( 'Replies', 'petish-passport' ) . '</h3><span>' . esc_html( number_format_i18n( (int) ( $topic['replies_count'] ?? 0 ) ) ) . '</span></div>';
        $replies = $topic['replies'] ?? array();
        if ( ! $replies ) echo '<p class="pch-empty-replies">' . esc_html__( 'No replies yet. Be the first to join in.', 'petish-passport' ) . '</p>';
        foreach ( $replies as $reply ) {
            echo '<article class="pch-reply' . ( ! empty( $reply['accepted'] ) ? ' pch-accepted' : '' ) . ( ! empty( $reply['parent_id'] ) ? ' pch-nested' : '' ) . '" id="chat-reply-' . (int) $reply['id'] . '"' . ( 'pending' === $reply['status'] ? ' data-chat-private' : '' ) . '>';
            if ( ! empty( $reply['parent_id'] ) ) echo '<p class="pch-reply-context">' . esc_html__( 'Reply to an earlier message', 'petish-passport' ) . '</p>';
            echo '<div class="pch-topic-identity">' . self::avatar( $reply['alias'] ) . '<div><strong class="pch-alias">' . esc_html( $reply['alias'] ) . '</strong><time class="pch-meta" datetime="' . esc_attr( $reply['created_gmt'] ) . '">' . esc_html( self::date_label( $reply['created_gmt'] ) ) . '</time></div></div>';
            if ( 'pending' === $reply['status'] ) echo '<p class="pch-tag pch-pending">' . esc_html__( 'Awaiting moderation · only you and staff', 'petish-passport' ) . '</p>';
            if ( ! empty( $reply['accepted'] ) ) echo '<p class="pch-tag pch-answered">' . esc_html__( 'Chosen answer', 'petish-passport' ) . '</p>';
            echo '<div class="pch-body">' . nl2br( esc_html( $reply['body'] ) ) . '</div><div class="pch-message-actions">';
            if ( ! empty( $topic['can_reply'] ) && empty( $reply['parent_id'] ) && 'published' === $reply['status'] ) echo '<a class="pch-reply-to" href="' . esc_url( add_query_arg( 'chat_reply_to', (int) $reply['id'], self::url( $state['filters'] ) ) . '#chat-composer' ) . '" data-chat-reply-to="' . (int) $reply['id'] . '" data-chat-reply-alias="' . esc_attr( $reply['alias'] ) . '">' . esc_html__( 'Reply to this message', 'petish-passport' ) . '</a>';
            if ( ! empty( $reply['can_accept'] ) ) {
                self::form_start( 'accept_answer', $topic['id'] );
                echo '<input type="hidden" name="reply_id" value="' . (int) $reply['id'] . '"><button class="pch-text-button" type="submit">' . esc_html__( 'Choose as the answer', 'petish-passport' ) . '</button></form>';
            }
            if ( ! empty( $reply['can_report'] ) ) self::report_form( $topic['id'], $reply['id'] );
            echo '</div></article>';
        }
        self::pagination( $topic['reply_page'] ?? 1, $topic['reply_pages'] ?? 0, $state['filters'], true );
    }

    public static function avatar( $alias ) {
        $initial = preg_match( '/[\p{L}\p{N}]/u', $alias, $match ) ? $match[0] : 'P';
        return '<span class="pch-avatar" aria-hidden="true">' . esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial, 'UTF-8' ) : strtoupper( $initial ) ) . '</span>';
    }

    public static function date_label( $value ) {
        $time = is_string( $value ) ? strtotime( $value . ' UTC' ) : false;
        return false !== $time ? wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $time ) : '';
    }

    public static function pagination( $page, $pages, $filters, $replies = false ) {
        if ( $pages < 2 ) return;
        echo '<nav class="pch-pagination" aria-label="' . esc_attr__( 'Conversation pages', 'petish-passport' ) . '">';
        foreach ( array( max( 1, (int) $page - 1 ), min( (int) $pages, (int) $page + 1 ) ) as $next ) {
            if ( $next === (int) $page ) continue;
            $args = $filters; $args[ $replies ? 'reply_page' : 'page' ] = $next;
            echo '<a href="' . esc_url( self::url( $args, 'chat-feed' ) ) . '">' . esc_html( $next < $page ? __( 'Previous', 'petish-passport' ) : __( 'Next', 'petish-passport' ) ) . '</a>';
        }
        echo '<span>' . esc_html( sprintf( __( 'Page %1$s of %2$s', 'petish-passport' ), number_format_i18n( (int) $page ), number_format_i18n( (int) $pages ) ) ) . '</span></nav>';
    }

    public static function nonce_action( $operation, $topic = 0, $target = '', $id = 0 ) {
        $action = 'petish_chat_' . $operation;
        if ( 'moderate' === $operation ) return $action . '_' . $target . '_' . (int) $id;
        return 'create_topic' !== $operation ? $action . '_' . (int) $topic : $action;
    }

    public static function form_start( $operation, $topic = 0 ) {
        echo '<form class="pch-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="petish_chat_' . esc_attr( $operation ) . '"><input type="hidden" name="topic_id" value="' . (int) $topic . '">';
        wp_nonce_field( self::nonce_action( $operation, $topic ), self::NONCE_FIELD, false );
    }

    private static function report_form( $topic, $reply ) {
        echo '<details class="pch-report"><summary>' . esc_html__( 'Report', 'petish-passport' ) . '</summary>';
        self::form_start( 'report', $topic );
        echo '<input type="hidden" name="reply_id" value="' . (int) $reply . '"><label>' . esc_html__( 'Reason', 'petish-passport' ) . '<select name="reason">';
        foreach ( array( 'privacy' => __( 'Privacy', 'petish-passport' ), 'abuse' => __( 'Abuse or harassment', 'petish-passport' ), 'unsafe' => __( 'Unsafe advice', 'petish-passport' ), 'spam' => __( 'Spam', 'petish-passport' ), 'other' => __( 'Other', 'petish-passport' ) ) as $key => $label ) echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
        echo '</select></label><button class="pch-button pch-button-secondary" type="submit">' . esc_html__( 'Send report', 'petish-passport' ) . '</button></form></details>';
    }

    /** Non-exiting write boundary for native security acceptance tests. */
    public static function request( $operation, $input ) {
        if ( ! Petish_Chat::enabled() ) return self::error( 'disabled', 'Pet Chat is not available yet.', 403 );
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_user_logged_in() || ! get_current_user_id() ) return self::error( 'signin', 'Sign in and use the conversation form to continue.', 403 );
        if ( ! is_array( $input ) || ! in_array( $operation, self::OPERATIONS, true ) ) return self::error( 'operation', 'This action is not available.', 400 );
        $topic = self::id( $input['topic_id'] ?? 0 );
        $target = self::text( $input, 'target' ); $target_id = self::id( $input['target_id'] ?? 0 );
        if ( 'create_topic' !== $operation && 'moderate' !== $operation && ! $topic ) return self::error( 'topic', 'Check the conversation and try again.' );
        if ( 'moderate' === $operation && ( ! in_array( $target, array( 'topic', 'reply', 'member' ), true ) || ! $target_id ) ) return self::error( 'target', 'Check the moderation item and try again.' );
        $nonce = self::text( $input, self::NONCE_FIELD );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, self::nonce_action( $operation, $topic, $target, $target_id ) ) ) return self::error( 'nonce', 'The form expired. Reload this page and try again.', 403 );
        $uid = get_current_user_id();
        $data = array();
        foreach ( array( 'alias', 'title', 'body', 'channel', 'kind', 'request_id', 'consent' ) as $key ) $data[ $key ] = self::text( $input, $key );
        if ( 'create_topic' === $operation ) return Petish_Chat::create_topic( $uid, $data );
        if ( 'create_reply' === $operation ) {
            $parent = $input['parent_id'] ?? 0;
            if ( ! in_array( $parent, array( 0, '0' ), true ) && ! self::id( $parent ) ) return self::error( 'parent', 'Choose a valid message to reply to.' );
            $data['parent_id'] = self::id( $parent );
            return Petish_Chat::create_reply( $uid, $topic, $data );
        }
        if ( 'accept_answer' === $operation ) {
            $reply = self::id( $input['reply_id'] ?? 0 );
            return $reply ? Petish_Chat::accept_answer( $uid, $topic, $reply ) : self::error( 'reply', 'Check the answer and try again.' );
        }
        if ( 'set_closed' === $operation ) {
            if ( ! in_array( $input['closed'] ?? null, array( '0', '1' ), true ) ) return self::error( 'closed', 'Choose whether replies should be open or closed.' );
            return Petish_Chat::set_closed( $uid, $topic, '1' === $input['closed'] );
        }
        if ( 'report' === $operation ) {
            $reply = $input['reply_id'] ?? 0;
            if ( ! in_array( $reply, array( 0, '0', '' ), true ) && ! self::id( $reply ) ) return self::error( 'reply', 'Check the reported reply and try again.' );
            return Petish_Chat::report( $uid, $topic, self::id( $reply ), self::text( $input, 'reason' ) );
        }
        return Petish_Chat::moderate( $uid, $target, $target_id, self::text( $input, 'operation' ) );
    }

    public static function handle( $operation ) {
        self::no_store();
        $input = is_array( $_POST ) ? wp_unslash( $_POST ) : array();
        $result = self::request( $operation, $input );
        if ( is_wp_error( $result ) ) {
            $status = (int) ( $result->get_error_data()['status'] ?? 422 );
            if ( $status === 403 || ! in_array( $operation, array( 'create_topic', 'create_reply' ), true ) ) wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => $status ) );
            $filters = self::filters( array( 'topic' => 'create_reply' === $operation ? ( $input['topic_id'] ?? 0 ) : 0 ) );
            $state = self::state( $filters, $input, $result->get_error_message() );
            if ( is_wp_error( $state ) ) wp_die( esc_html( $state->get_error_message() ), '', array( 'response' => 422 ) );
            self::$rendering = true; self::no_store(); status_header( 422 ); get_header(); self::render( $state ); get_footer(); exit;
        }
        if ( 'moderate' === $operation ) { wp_safe_redirect( admin_url( 'admin.php?page=petish-chat-moderation' ), 303 ); exit; }
        $topic = is_array( $result ) ? (int) ( $result['topic_id'] ?? $result['id'] ?? 0 ) : self::id( $input['topic_id'] ?? 0 );
        $notice = 'report' === $operation ? 'reported' : ( is_array( $result ) ? ( ! empty( $result['duplicate'] ) ? 'duplicate' : ( 'pending' === $result['status'] ? 'pending' : 'posted' ) ) : 'updated' );
        $url = add_query_arg( 'chat_notice', $notice, self::url( array( 'topic' => $topic ) ) );
        wp_safe_redirect( $url, 303 ); exit;
    }

    private static function same_origin() {
        if ( 'cross-site' === strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) ) ) return false;
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( '' === $origin ) return true;
        if ( ! is_string( $origin ) ) return false;
        $a = wp_parse_url( $origin ); $b = wp_parse_url( home_url( '/' ) );
        return is_array( $a ) && is_array( $b ) && strtolower( $a['scheme'] ?? '' ) === strtolower( $b['scheme'] ?? '' ) && strtolower( $a['host'] ?? '' ) === strtolower( $b['host'] ?? '' ) && ( $a['port'] ?? null ) === ( $b['port'] ?? null ) && empty( $a['query'] ) && empty( $a['fragment'] ) && in_array( $a['path'] ?? '', array( '', '/' ), true );
    }

    /** Non-exiting read-only projection; no writes or supplied UID. */
    public static function refresh_payload( $input ) {
        if ( ! Petish_Chat::enabled() ) return self::error( 'disabled', 'Pet Chat is not available yet.', 404 );
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::same_origin() ) return self::error( 'origin', 'Updates are unavailable for this request.', 403 );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'action', 'context', 'channel', 'topic', 'view', 'page', 'reply_page' ) ) ) return self::error( 'filters', 'Check the conversation filters and reload.' );
        $filters = self::filters( $input );
        if ( is_wp_error( $filters ) ) return $filters;
        if ( ! hash_equals( self::context( $filters ), self::text( $input, 'context' ) ) ) return self::error( 'context', 'The conversation changed. Reload this page.' );
        $state = self::state( $filters );
        if ( is_wp_error( $state ) ) return $state;
        $html = self::feed_html( $state );
        return array( 'schema' => 1, 'context' => $state['context'], 'session' => $state['session'], 'signed_in' => $state['signed_in'], 'revision' => hash( 'sha256', $html ), 'html' => $html, 'login_url' => wp_login_url( self::url( $filters ) ) );
    }

    public static function refresh() {
        self::no_store();
        $result = self::refresh_payload( is_array( $_POST ) ? wp_unslash( $_POST ) : array() );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ), (int) ( $result->get_error_data()['status'] ?? 400 ) );
        wp_send_json_success( $result );
    }

    private static function local_chat_link( $url ) {
        if ( ! is_string( $url ) || '' === $url || str_starts_with( $url, '//' ) ) return false;
        $candidate = wp_parse_url( $url ); $site = wp_parse_url( home_url( '/pet-chat/' ) );
        if ( ! is_array( $candidate ) || ! is_array( $site ) || ! empty( $candidate['query'] ) || ! empty( $candidate['fragment'] ) || ( isset( $candidate['scheme'] ) && ! isset( $candidate['host'] ) ) ) return false;
        if ( isset( $candidate['host'] ) && ( strtolower( $candidate['host'] ) !== strtolower( $site['host'] ?? '' ) || strtolower( $candidate['scheme'] ?? '' ) !== strtolower( $site['scheme'] ?? '' ) || ( $candidate['port'] ?? null ) !== ( $site['port'] ?? null ) ) ) return false;
        return untrailingslashit( $candidate['path'] ?? '' ) === untrailingslashit( $site['path'] ?? '' );
    }

    public static function navigation_objects( $items, $args ) {
        if ( ! Petish_Chat::enabled() || ! in_array( $args->theme_location ?? '', array( 'primary', 'mobile' ), true ) || ! is_array( $items ) ) return $items;
        foreach ( $items as $key => $item ) {
            if ( ! is_object( $item ) ) continue;
            $old_chat = self::local_chat_link( $item->url ?? '' );
            if ( $old_chat ) { $items[ $key ] = clone $item; $items[ $key ]->url = self::url(); }
            if ( ! self::is_request() ) continue;
            $candidate = wp_parse_url( $items[ $key ]->url ?? '' ); $site = wp_parse_url( home_url( '/' ) );
            if ( ! is_array( $candidate ) || ! is_array( $site ) || isset( $candidate['scheme'] ) && ! isset( $candidate['host'] ) ) continue;
            $local = ! isset( $candidate['host'] ) ? str_starts_with( $items[ $key ]->url ?? '', '/' ) : strtolower( $candidate['host'] ) === strtolower( $site['host'] ?? '' ) && strtolower( $candidate['scheme'] ?? '' ) === strtolower( $site['scheme'] ?? '' ) && ( $candidate['port'] ?? null ) === ( $site['port'] ?? null );
            if ( ! $local || untrailingslashit( $candidate['path'] ?? '' ) !== untrailingslashit( $site['path'] ?? '' ) ) continue;
            parse_str( $candidate['query'] ?? '', $query );
            $chat = isset( $query['petish_chat'] ) && '1' === $query['petish_chat'];
            $home = empty( $candidate['query'] ) && empty( $candidate['fragment'] );
            if ( ! $chat && ! $home ) continue;
            if ( $items[ $key ] === $item ) $items[ $key ] = clone $item;
            $items[ $key ]->classes = array_values( array_diff( (array) ( $item->classes ?? array() ), array( 'current-menu-item', 'current_page_item', 'current-menu-ancestor', 'current_page_parent', 'current_page_ancestor' ) ) );
            $items[ $key ]->current = $chat; $items[ $key ]->current_item_parent = false; $items[ $key ]->current_item_ancestor = false;
            if ( $chat ) $items[ $key ]->classes[] = 'current-menu-item';
        }
        return $items;
    }

    public static function document_title( $title ) {
        if ( ! self::is_request() || ! Petish_Chat::enabled() ) return $title;
        $label = __( 'Pet Chat', 'petish-passport' );
        $id = self::id( $_GET['chat_topic'] ?? 0 );
        if ( $id ) {
            $public = Petish_Chat::thread( $id, 0 );
            if ( is_array( $public ) && 'published' === ( $public['status'] ?? '' ) ) $label = sanitize_text_field( $public['title'] ) . ' · ' . $label;
        }
        return $label . ' – ' . get_bloginfo( 'name' );
    }

    public static function navigation_items( $items, $args ) {
        $location = $args->theme_location ?? '';
        if ( ! Petish_Chat::enabled() || ! in_array( $location, array( 'primary', 'mobile' ), true ) || ! is_string( $items ) || false !== strpos( $items, esc_url( self::url() ) ) ) return $items;
        $locations = get_nav_menu_locations(); $menu_id = (int) ( $locations[ $location ] ?? 0 );
        $menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
        if ( ! is_object( $menu ) || 'nav_menu' !== ( $menu->taxonomy ?? '' ) ) return $items;
        return $items . '<li class="menu-item petish-chat-menu' . ( self::is_request() ? ' current-menu-item' : '' ) . '"><a href="' . esc_url( self::url() ) . '"' . ( self::is_request() ? ' aria-current="page"' : '' ) . '><span class="menu-item-text"><span class="menu-text">' . esc_html__( 'Pet Chat', 'petish-passport' ) . '</span></span></a></li>';
    }

    public static function moderation_menu() {
        if ( Petish_Chat::enabled() ) add_submenu_page( 'woocommerce', __( 'Pet Chat moderation', 'petish-passport' ), __( 'Pet Chat moderation', 'petish-passport' ), 'manage_options', 'petish-chat-moderation', array( __CLASS__, 'moderation_page' ) );
    }

    public static function moderation_page() {
        if ( ! Petish_Chat::enabled() || ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You cannot moderate Pet Chat.', 'petish-passport' ), '', array( 'response' => 403 ) );
        self::no_store();
        $page = self::id( $_GET['chat_page'] ?? 1 ) ?: 1;
        $queue = Petish_Chat::moderation_queue( $page );
        echo '<div class="wrap"><h1>' . esc_html__( 'Pet Chat moderation', 'petish-passport' ) . '</h1><p>' . esc_html__( 'Approve, hide or mark reported contributions as spam. Member restrictions do not affect shop orders.', 'petish-passport' ) . '</p>';
        if ( is_wp_error( $queue ) ) { echo '<p>' . esc_html( $queue->get_error_message() ) . '</p></div>'; return; }
        foreach ( $queue['items'] ?? array() as $item ) {
            echo '<section class="postbox" style="padding:16px"><h2>' . esc_html( $item['title'] ?: __( 'Reply', 'petish-passport' ) ) . '</h2><p><strong>' . esc_html( $item['alias'] ) . '</strong> · ' . esc_html( $item['status'] ) . ' · ' . esc_html( sprintf( __( '%s reports', 'petish-passport' ), number_format_i18n( (int) $item['reports_count'] ) ) ) . '</p><p>' . nl2br( esc_html( $item['body'] ) ) . '</p>';
            if ( ! empty( $item['reason_counts'] ) ) {
                echo '<p>';
                foreach ( $item['reason_counts'] as $reason => $count ) if ( (int) $count > 0 ) echo esc_html( $reason . ': ' . number_format_i18n( (int) $count ) ) . ' · ';
                echo '</p>';
            }
            foreach ( array( 'approve' => __( 'Approve', 'petish-passport' ), 'hide' => __( 'Hide', 'petish-passport' ), 'spam' => __( 'Mark as spam', 'petish-passport' ) ) as $operation => $label ) self::moderation_form( $item['target'], $item['id'], $operation, $label );
            if ( ! empty( $item['member_id'] ) ) {
                self::moderation_form( 'member', $item['member_id'], 'mute', __( 'Pause member posting', 'petish-passport' ) );
                self::moderation_form( 'member', $item['member_id'], 'unmute', __( 'Allow member posting', 'petish-passport' ) );
            }
            echo '</section>';
        }
        if ( empty( $queue['items'] ) ) echo '<p>' . esc_html__( 'No contributions are awaiting moderation.', 'petish-passport' ) . '</p>';
        if ( ! empty( $queue['pages'] ) && (int) $queue['pages'] > 1 ) {
            for ( $next = max( 1, $page - 1 ); $next <= min( (int) $queue['pages'], $page + 1 ); $next++ ) echo '<a class="button" href="' . esc_url( add_query_arg( 'chat_page', $next, admin_url( 'admin.php?page=petish-chat-moderation' ) ) ) . '">' . (int) $next . '</a> ';
        }
        echo '</div>';
    }

    private static function moderation_form( $target, $id, $operation, $label ) {
        echo '<form style="display:inline-block;margin:0 8px 8px 0" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="petish_chat_moderate"><input type="hidden" name="target" value="' . esc_attr( $target ) . '"><input type="hidden" name="target_id" value="' . (int) $id . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
        wp_nonce_field( self::nonce_action( 'moderate', 0, $target, $id ), self::NONCE_FIELD, false );
        echo '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
    }
}
