<?php
/**
 * Petish presentation for WooCommerce's native email action button.
 *
 * @package WooCommerce\Templates\Emails
 * @version 11.0.0
 * @var string $url Native button destination, including its authentication data.
 * @var string $label Native button text.
 */
defined( 'ABSPATH' ) || exit;
?>
<p style="margin:24px 0;">
    <a class="button" href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:13px 20px;background-color:#6532A1;color:#FFFFFF;border:1px solid #6532A1;border-radius:8px;font-weight:700;font-size:15px;line-height:1.4;text-decoration:none;">
        <?php echo esc_html( $label ); ?>
    </a>
</p>
