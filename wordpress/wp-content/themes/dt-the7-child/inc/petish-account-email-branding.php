<?php
/** Presentation for core account emails that bypass WooCommerce's email classes. */
defined( 'ABSPATH' ) || exit;

/** Escape the final native message and link its existing URLs without rebuilding them. */
function petish_account_email_branding_body( $message ) {
    $parts = preg_split( '~(https?://[^\s<>"\']+)~i', $message, -1, PREG_SPLIT_DELIM_CAPTURE );
    $html = '';
    foreach ( $parts as $index => $part ) {
        if ( 1 === $index % 2 ) {
            $html .= '<a href="' . esc_url( $part ) . '" style="color:#6532A1; text-decoration:underline; overflow-wrap:anywhere; word-break:break-all;">' . esc_html( $part ) . '</a>';
        } else {
            $html .= esc_html( $part );
        }
    }
    return '<p class="petish-core-account-message" style="margin:0 0 16px; color:#14213D; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.65; overflow-wrap:anywhere;">' . nl2br( $html, false ) . '</p>';
}

/**
 * Only the selected core mail arrays are converted; sending and authentication stay native.
 * Respect explicit formats and HTML supplied by other plugins. With WooCommerce inactive,
 * return core's original plain-text mail rather than replacing its delivery pipeline.
 */
function petish_account_email_branding_mail( $mail, $heading ) {
    if ( ! is_array( $mail ) || ! isset( $mail['message'] ) || ! is_string( $mail['message'] ) || '' === trim( $mail['message'] ) ) {
        return $mail;
    }
    $headers = $mail['headers'] ?? '';
    if ( ! is_string( $headers ) && ! is_array( $headers ) ) {
        return $mail;
    }
    $header_text = is_array( $headers ) ? implode( "\n", $headers ) : $headers;
    if ( preg_match( '/(?:^|\r?\n)\s*Content-Type\s*:/i', $header_text ) || preg_match( '/<(?:!doctype|html|body|table|div|p)\b/i', $mail['message'] ) ) {
        return $mail;
    }
    if ( ! function_exists( 'wc_get_template_html' ) || ! function_exists( 'petish_email_branding_styles' ) ) {
        return $mail;
    }

    // Render the same shell as customer/order emails; do not trigger an email class.
    $arguments = array( 'email_heading' => $heading, 'email' => null );
    $html = wc_get_template_html( 'emails/email-header.php', $arguments );
    $html .= petish_account_email_branding_body( $mail['message'] );
    $html .= wc_get_template_html( 'emails/email-footer.php', $arguments );
    $css = petish_email_branding_styles( '' );
    $mail['message'] = str_replace( '</head>', '<style type="text/css">' . $css . '</style></head>', $html );

    // Set HTML for this message only; keep every existing routing/header value.
    $content_type = 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' );
    if ( is_array( $headers ) ) {
        $headers[] = $content_type;
    } else {
        $headers .= ( '' === $headers || "\n" === substr( $headers, -1 ) ? '' : "\r\n" ) . $content_type;
    }
    $mail['headers'] = $headers;
    return $mail;
}

function petish_account_email_branding_new_user( $mail ) {
    return petish_account_email_branding_mail( $mail, __( 'Your Petish account', 'petish' ) );
}

function petish_account_email_branding_reset_password( $mail ) {
    return petish_account_email_branding_mail( $mail, __( 'Reset your password', 'petish' ) );
}

add_filter( 'wp_new_user_notification_email', 'petish_account_email_branding_new_user', 999 );
add_filter( 'retrieve_password_notification_email', 'petish_account_email_branding_reset_password', 999 );
