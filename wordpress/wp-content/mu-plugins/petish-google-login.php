<?php
/**
 * Plugin Name: Petish Google Account Access
 * Description: Customer registration and WooCommerce placement for Nextend's Google provider.
 * Version: 0.1.2
 */
defined( 'ABSPATH' ) || exit;

// Presentation is limited to the logged-out account entry, not password resets.
function petish_is_guest_account_entry() {
    return ! is_user_logged_in()
        && function_exists( 'is_account_page' ) && is_account_page()
        && ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'lost-password' ) );
}

add_filter( 'body_class', function ( $classes ) {
    if ( petish_is_guest_account_entry() ) {
        $classes[] = 'petish-guest-auth';
    }
    return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
    $file = __DIR__ . '/petish-google-login/guest-auth.css';
    if ( petish_is_guest_account_entry() && is_file( $file ) ) {
        wp_enqueue_style(
            'petish-guest-auth',
            plugin_dir_url( __FILE__ ) . 'petish-google-login/guest-auth.css',
            array( 'petish-design' ),
            filemtime( $file )
        );
    }
}, 45 );

// Existing accounts must authenticate and link Google deliberately.
add_filter( 'nsl_google_auto_link_allowed', function () {
    // Nextend otherwise redirects to the login form without explaining the refusal.
    if ( class_exists( '\NSL\Notices' ) ) {
        \NSL\Notices::addError( __( 'Please sign in to your existing Petish account with your password, then link Google in Account details.', 'petish' ) );
    }
    return false;
} );

add_filter( 'nsl_finalize_settings_nextend_social_login', function ( $settings ) {
    $settings['avatar_store'] = 0;
    $settings['store_access_token'] = 0;
    $settings['redirect_prevent_external'] = 1;
    return $settings;
} );

// Respect the store's registration setting without enabling WordPress-wide signup.
add_filter( 'nsl_is_register_allowed', function ( $allowed, $provider ) {
    if ( 'google' !== $provider->getId() ) {
        return $allowed;
    }
    return function_exists( 'WC' ) && get_role( 'customer' )
        && 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
}, 100, 2 );

add_action( 'nsl_google_register_new_user', function ( $user_id ) {
    $user = get_user_by( 'id', $user_id );
    if ( $user && get_role( 'customer' ) ) {
        $user->set_role( 'customer' );
    }
} );

function petish_google_account_buttons( $link = false ) {
    if ( ! class_exists( 'NextendSocialLogin' ) || ! NextendSocialLogin::isProviderEnabled( 'google' )
        || ! shortcode_exists( 'nextend_social_login' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
        return;
    }
    if ( $link ) {
        $shortcode = '[nextend_social_login provider="google" login="0" link="1" unlink="1" heading="Google account"]';
    } else {
        $shortcode = sprintf(
            '[nextend_social_login provider="google" style="fullwidth" customlabel="Continue with Google" redirect="%s"]',
            esc_url( wc_get_page_permalink( 'myaccount' ) )
        );
    }
    $buttons = do_shortcode( $shortcode );
    if ( '' === trim( $buttons ) ) {
        return;
    }
    if ( ! $link ) {
        echo '<p class="petish-auth-intro">' . esc_html__( 'Sign in or create your Petish account.', 'petish' ) . '</p>';
    }
    echo '<div class="petish-google-login' . ( $link ? '' : ' petish-auth-google' ) . '">' . $buttons . '</div>'; // Trusted Nextend shortcode output.
    if ( ! $link ) {
        echo '<p class="petish-google-login-help">' . esc_html__( 'Already have a Petish account? Sign in with your password, then link Google in Account details.', 'petish' ) . '</p>';
        echo '<div class="petish-auth-divider"><span>' . esc_html__( 'Or use your email', 'petish' ) . '</span></div>';
    }
}

add_action( 'woocommerce_before_customer_login_form', function () {
    if ( ! is_user_logged_in() ) {
        petish_google_account_buttons();
    }
} );

add_action( 'woocommerce_after_edit_account_form', function () {
    if ( is_user_logged_in() ) {
        petish_google_account_buttons( true );
    }
} );
