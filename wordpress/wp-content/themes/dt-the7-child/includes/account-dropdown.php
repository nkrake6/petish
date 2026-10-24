<?php
/** Cacheable header shell; account identity and nonce-bearing links are private fragments. */
defined( 'ABSPATH' ) || exit;

final class Petish_Account_Dropdown {
    const AJAX_ACTION = 'petish_account_dropdown';
    const STYLE_HANDLE = 'petish-account-dropdown';
    const SCRIPT_HANDLE = 'petish-account-dropdown';

    public static function boot() {
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'items' ), 30, 2 );
        // The native search widget is followed by appearance (5) and market (10).
        add_action( 'presscore_render_header_element-search', array( __CLASS__, 'render' ), 20 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 46 );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'state' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'state' ) );
    }

    private static function origin( $url ) {
        $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] )
            || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) ) return false;
        return array( strtolower( $parts['scheme'] ), strtolower( $parts['host'] ),
            (int) ( $parts['port'] ?? ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 ) ) );
    }

    private static function local_url( $url, $https_only = false ) {
        if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) return false;
        $decoded = $url;
        for ( $i = 0; $i < 4; $i++ ) {
            $decoded = rawurldecode( $decoded );
            if ( preg_match( '/[\x00-\x1f\x7f]/', $decoded ) ) return false;
        }
        $origin = self::origin( $url );
        return $origin && $origin === self::origin( home_url( '/' ) ) && ( ! $https_only || 'https' === $origin[0] );
    }

    private static function is_account_url( $url, $account ) {
        if ( ! self::local_url( $url ) || ! self::local_url( $account ) ) return false;
        $actual = wp_parse_url( $url ); $expected = wp_parse_url( $account );
        return untrailingslashit( $actual['path'] ?? '/' ) === untrailingslashit( $expected['path'] ?? '/' )
            && empty( $actual['query'] ) && empty( $actual['fragment'] );
    }

    /** The account control lives after the header widgets, so remove its old menu branch. */
    public static function items( $items, $args ) {
        if ( ! is_array( $items ) || ! in_array( $args->theme_location ?? '', array( 'primary', 'mobile' ), true )
            || ! function_exists( 'wc_get_page_permalink' ) ) return $items;
        $account = wc_get_page_permalink( 'myaccount' ); $branch_ids = array();
        foreach ( $items as $item ) {
            if ( is_object( $item ) && isset( $item->ID, $item->url ) && empty( $item->menu_item_parent ) && self::is_account_url( $item->url, $account ) ) {
                $branch_ids[(string) $item->ID] = true;
            }
        }
        if ( ! $branch_ids ) return $items;
        for ( $pass = 0; $pass < count( $items ); $pass++ ) {
            $changed = false;
            foreach ( $items as $item ) if ( is_object( $item ) && isset( $item->ID, $item->menu_item_parent )
                && isset( $branch_ids[(string) $item->menu_item_parent] ) && ! isset( $branch_ids[(string) $item->ID] ) ) {
                $branch_ids[(string) $item->ID] = true; $changed = true;
            }
            if ( ! $changed ) break;
        }
        return array_filter( $items, static function ( $item ) use ( $branch_ids ) {
            return ! is_object( $item ) || ! isset( $item->ID ) || ! isset( $branch_ids[(string) $item->ID] );
        } );
    }

    /** Cacheable native mini-widget shell: never read account identity or private links. */
    public static function render() {
        if ( ! function_exists( 'wc_get_page_permalink' ) ) return;
        $account = wc_get_page_permalink( 'myaccount' );
        if ( ! self::local_url( $account ) ) return;
        $classes = array( 'petish-account-menu', 'petish-account-widget' );
        if ( function_exists( 'presscore_get_mini_widget_class' ) ) {
            $classes = presscore_get_mini_widget_class( 'header-elements-search', $classes );
        }
        echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-petish-account>';
        echo '<a class="petish-account-link" href="' . esc_url( $account ) . '" aria-label="' . esc_attr__( 'My account', 'petish' ) . '">';
        echo '<svg class="petish-account-glyph" aria-hidden="true" focusable="false" width="22" height="22" viewBox="0 0 24 24"><path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        echo '<span class="menu-text">' . esc_html__( 'My account', 'petish' ) . '</span></a></div>';
    }

    public static function assets() {
        if ( is_admin() || ! function_exists( 'wc_get_page_permalink' ) ) return;
        $dir = get_stylesheet_directory(); $uri = get_stylesheet_directory_uri();
        // Deployment archives intentionally normalize mtimes; content versions
        // invalidate browser caches when either asset changes.
        $css_version = substr( hash_file( 'sha256', $dir . '/assets/css/account-dropdown.css' ), 0, 12 );
        $js_version = substr( hash_file( 'sha256', $dir . '/assets/js/account-dropdown.js' ), 0, 12 );
        wp_enqueue_style( self::STYLE_HANDLE, $uri . '/assets/css/account-dropdown.css', array( 'petish-design' ), $css_version );
        wp_enqueue_script( self::SCRIPT_HANDLE, $uri . '/assets/js/account-dropdown.js', array(), $js_version, array( 'strategy' => 'defer', 'in_footer' => true ) );
        // No account name, avatar, account endpoints, session token or logout nonce.
        wp_add_inline_script( self::SCRIPT_HANDLE, 'window.petishAccountDropdown = ' . wp_json_encode( array(
            'endpoint' => admin_url( 'admin-ajax.php' ), 'action' => self::AJAX_ACTION,
            'accountUrl' => wc_get_page_permalink( 'myaccount' ),
            'openLabel' => __( 'Open account navigation', 'petish' ), 'closeLabel' => __( 'Close account navigation', 'petish' ),
        ) ) . ';', 'before' );
    }

    /** Own-session read only. No caller-supplied user ID, order data or user metadata writes. */
    public static function payload() {
        $guest = array( 'signed_in' => false, 'avatar' => '', 'initial' => '', 'items' => array() );
        if ( ! is_user_logged_in() || ! function_exists( 'wc_get_account_menu_items' ) || ! function_exists( 'wc_get_account_endpoint_url' ) ) return $guest;
        $user = wp_get_current_user();
        if ( ! is_object( $user ) || ! is_int( $user->ID ) || $user->ID < 1 || $user->ID !== get_current_user_id() ) return $guest;
        $items = array(); $native_items = wc_get_account_menu_items();
        if ( ! is_array( $native_items ) ) return $guest;
        foreach ( array_slice( $native_items, 0, 50, true ) as $key => $label ) {
            if ( ! is_string( $key ) || ! preg_match( '/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $key ) || ! is_string( $label ) || strlen( $label ) > 512 || preg_match( '//u', $label ) !== 1 ) continue;
            $url = wc_get_account_endpoint_url( $key );
            if ( ! self::local_url( $url ) ) continue;
            $label = trim( wp_strip_all_tags( $label ) );
            if ( '' === $label ) continue;
            $items[] = array( 'key' => $key, 'label' => $label, 'url' => $url );
        }
        $initial = 'P'; $name = wp_strip_all_tags( (string) ( $user->display_name ?? '' ) );
        if ( preg_match( '/[\p{L}\p{N}]/u', $name, $match ) ) $initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $match[0], 'UTF-8' ) : strtoupper( $match[0] );
        $avatar = function_exists( 'get_avatar_url' ) ? get_avatar_url( $user->ID, array( 'size' => 64, 'default' => 'blank', 'scheme' => 'https' ) ) : '';
        // Gravatar and other remote fallbacks never become browser image requests.
        if ( ! self::local_url( $avatar, true ) ) $avatar = '';
        return array( 'signed_in' => true, 'avatar' => $avatar, 'initial' => $initial, 'items' => $items );
    }

    private static function same_origin() {
        if ( 'cross-site' === strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) ) ) return false;
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        return is_string( $origin ) && ( '' === $origin || self::origin( $origin ) === self::origin( home_url( '/' ) ) );
    }

    public static function state() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
        header( 'Vary: Cookie', false );
        header( 'X-Content-Type-Options: nosniff' );
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::same_origin() ) {
            wp_send_json_error( array( 'message' => 'Account navigation is unavailable.' ), 403 );
            return;
        }
        wp_send_json_success( self::payload() );
    }
}
