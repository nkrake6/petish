<?php
/**
 * Petish shared HTML email header. Native email bodies and order hooks are retained.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 10.7.0
 */
defined( 'ABSPATH' ) || exit;

$petish_email_logo = function_exists( 'petish_email_branding_logo_url' )
    ? petish_email_branding_logo_url()
    : get_stylesheet_directory_uri() . '/assets/brand/repaired/combined-light-v2.png';
$petish_email_home = apply_filters( 'woocommerce_email_header_image_url', home_url( '/' ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="format-detection" content="telephone=no" />
    <title><?php echo esc_html( $store_name ?? get_bloginfo( 'name', 'display' ) ); ?></title>
</head>
<body style="margin:0; padding:0; background-color:#F6F3FC;" <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="outer_wrapper" role="presentation" style="width:100%; background-color:#F6F3FC;">
<tr><td align="center" valign="top" id="petish_outer_cell" style="padding:28px 12px;">
<!--[if mso]><table border="0" cellpadding="0" cellspacing="0" width="600" role="presentation"><tr><td><![endif]-->
<div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" style="width:100%; max-width:600px; margin:0 auto;">
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="inner_wrapper" role="presentation" style="background-color:#FFFFFF; border-radius:16px;">
<tr><td align="center" valign="top">
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" role="presentation" style="background-color:#FFFFFF; border:1px solid #E6DFF0; border-top:5px solid #6532A1; border-radius:16px;">
<tr><td id="template_header_image" style="padding:28px 32px 22px; background-color:#FFFFFF; text-align:left;">
    <?php if ( $petish_email_home ) : ?><a href="<?php echo esc_url( $petish_email_home ); ?>" style="display:inline-block; text-decoration:none;"><?php endif; ?>
    <img src="<?php echo esc_url( $petish_email_logo ); ?>" width="240" height="160" alt="<?php esc_attr_e( 'Petish — Small pets, big adventures.', 'petish' ); ?>" style="display:block; width:240px; max-width:100%; height:auto; border:0;" />
    <?php if ( $petish_email_home ) : ?></a><?php endif; ?>
</td></tr>
<tr><td align="left" valign="top">
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" role="presentation" style="background-color:#EDE7FF; color:#14213D;">
<tr><td id="header_wrapper" style="padding:24px 32px; text-align:left;">
<h1 style="margin:0; color:#14213D; font-family:Arial,Helvetica,sans-serif; font-size:27px; line-height:1.25; font-weight:700;"><?php echo esc_html( $email_heading ); ?></h1>
</td></tr>
</table>
</td></tr>
<tr><td align="center" valign="top">
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body" role="presentation" style="background-color:#FFFFFF;">
<tr><td valign="top" id="body_content">
<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
<tr><td valign="top" id="body_content_inner_cell" style="padding:28px 32px 18px;">
<div id="body_content_inner" style="color:#14213D; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.65; text-align:left;">
