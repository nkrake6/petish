<?php
/**
 * Petish shared HTML email footer; native merchant footer filter is preserved.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 10.4.0
 */
defined( 'ABSPATH' ) || exit;
$email = $email ?? null;
$petish_footer_text = get_option( 'woocommerce_email_footer_text' );
if ( apply_filters( 'woocommerce_is_email_preview', false ) ) {
    $petish_footer_preview = get_transient( 'woocommerce_email_footer_text' );
    $petish_footer_text = false !== $petish_footer_preview ? $petish_footer_preview : $petish_footer_text;
}
$petish_account_url = function_exists( 'wc_get_page_permalink' )
    ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
?>
</div>
</td></tr></table>
</td></tr></table>
</td></tr>
<tr><td align="left" valign="top">
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_footer" role="presentation" style="background-color:#FFFFFF; border-top:1px solid #E6DFF0;">
<tr><td id="petish_support" style="padding:24px 32px; text-align:left; color:#526077; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:1.6;">
<p class="petish-support-title" style="margin:0 0 8px; color:#14213D; font-size:15px; font-weight:700;"><?php esc_html_e( 'Need a hand?', 'petish' ); ?></p>
<p style="margin:0 0 12px;"><?php esc_html_e( 'Our team is here to help.', 'petish' ); ?> <a href="mailto:orders@petish.biz" style="color:#6532A1; text-decoration:underline;">orders@petish.biz</a></p>
<p style="margin:0 0 16px;"><a href="<?php echo esc_url( $petish_account_url ); ?>" style="color:#6532A1; text-decoration:underline;"><?php esc_html_e( 'My account', 'petish' ); ?></a><span aria-hidden="true"> &nbsp;&middot;&nbsp; </span><a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#6532A1; text-decoration:underline;"><?php esc_html_e( 'Visit Petish', 'petish' ); ?></a></p>
<p class="petish-email-tagline" style="margin:0; color:#526077; font-size:12px;"><?php esc_html_e( 'Small pets, big adventures.', 'petish' ); ?></p>
<div id="credit" style="color:#526077; font-size:12px; line-height:1.6; text-align:left;">
<?php
// Match WooCommerce's native footer pipeline, including merchant custom text.
echo wp_kses_post( wpautop( wptexturize( apply_filters( 'woocommerce_email_footer_text', $petish_footer_text, $email ) ) ) );
?>
</div>
</td></tr></table>
</td></tr>
</table>
</td></tr></table>
</div>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body>
</html>
