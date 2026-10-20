<?php
/** Shared presentation for WooCommerce's native transactional HTML emails. */
defined( 'ABSPATH' ) || exit;

function petish_email_branding_logo_url() {
    return apply_filters(
        'petish_email_logo_url',
        get_stylesheet_directory_uri() . '/assets/brand/combined_light_landscape.png'
    );
}

/** Keep native order/account messages and plain-text emails unchanged. */
function petish_email_branding_styles( $css, $email = null ) {
    return $css . "\n" . '
/* Petish shared email shell: no remote fonts or frontend stylesheet dependency. */
body, #outer_wrapper { background-color: #F6F3FC !important; }
body, table, td, th, p, a, h1, h2, h3, address { font-family: Arial, Helvetica, sans-serif !important; }
#outer_wrapper { width: 100%; margin: 0; border-collapse: collapse; }
#petish_outer_cell { padding: 28px 12px; }
#wrapper { width: 100%; max-width: 600px; margin: 0 auto; padding: 0 !important; }
#inner_wrapper { width: 100%; background-color: #FFFFFF !important; border-radius: 16px; }
#template_container { width: 100%; background-color: #FFFFFF !important; border: 1px solid #E6DFF0 !important; border-top: 5px solid #6532A1 !important; border-radius: 16px !important; box-shadow: none !important; overflow: hidden; }
#template_header_image { padding: 28px 32px 22px !important; text-align: left !important; background-color: #FFFFFF !important; }
#template_header_image img { display: block; width: 240px !important; max-width: 100% !important; height: auto !important; border: 0; outline: none; text-decoration: none; }
#template_header { background-color: #EDE7FF !important; color: #14213D !important; border-radius: 0 !important; }
#header_wrapper { padding: 24px 32px !important; text-align: left !important; }
#template_header h1, #template_header h1 a { color: #14213D !important; background-color: transparent !important; font-size: 27px !important; font-weight: 700 !important; line-height: 1.25 !important; letter-spacing: -0.4px; margin: 0 !important; }
#template_body, #body_content { background-color: #FFFFFF !important; }
#body_content_inner_cell { padding: 28px 32px 18px !important; }
#body_content_inner { color: #14213D !important; font-size: 15px !important; line-height: 1.65 !important; text-align: left; }
#body_content_inner p { margin: 0 0 16px; }
#body_content_inner h2 { color: #14213D !important; font-size: 19px !important; font-weight: 700; line-height: 1.35; margin: 24px 0 14px; }
#body_content_inner h3 { color: #14213D !important; font-size: 16px !important; font-weight: 700; }
#body_content_inner a, #credit a { color: #6532A1 !important; font-weight: 600; text-decoration: underline; }
#body_content_inner .td, #body_content_inner th, #body_content_inner td { border-color: #E6DFF0 !important; color: #14213D; }
#body_content_inner table.td { width: 100%; border: 1px solid #E6DFF0; border-collapse: collapse; }
#body_content_inner .email-order-details { width: 100%; border-collapse: collapse; }
#body_content_inner table.td th, #body_content_inner table.td td, #body_content_inner .email-order-details th, #body_content_inner .email-order-details td { padding: 11px 12px !important; vertical-align: top; font-size: 14px; }
#body_content_inner table.td thead th, #body_content_inner .email-order-details thead th { background-color: #F5F0FF !important; font-weight: 700; }
#body_content_inner .order-totals-total th, #body_content_inner .order-totals-total td { font-weight: 700; }
#body_content_inner .address { padding: 14px !important; border: 1px solid #E6DFF0 !important; border-radius: 8px; font-style: normal; }
#body_content_inner .button, #body_content_inner a.button { background-color: #6532A1 !important; border: 1px solid #6532A1 !important; border-radius: 8px; color: #FFFFFF !important; display: inline-block; font-size: 15px; font-weight: 700; line-height: 1.4; padding: 13px 20px; text-align: center; text-decoration: none !important; }
#body_content_inner .email-introduction, #body_content_inner .email-additional-content { padding: 0 !important; }
#template_footer { background-color: #FFFFFF !important; border-top: 1px solid #E6DFF0; border-radius: 0 0 16px 16px; }
#petish_support { padding: 24px 32px !important; text-align: left; color: #526077; font-size: 13px; line-height: 1.6; }
#petish_support p { margin: 0 0 10px; }
#petish_support a { color: #6532A1 !important; text-decoration: underline; }
#petish_support .petish-support-title { color: #14213D !important; font-size: 15px; font-weight: 700; }
#credit { padding: 0 !important; color: #526077 !important; text-align: left !important; font-size: 12px !important; line-height: 1.6; }
#credit p { margin: 10px 0 0; }
[dir="rtl"] #template_header_image, [dir="rtl"] #header_wrapper, [dir="rtl"] #body_content_inner, [dir="rtl"] #petish_support, [dir="rtl"] #credit { text-align: right !important; }
[dir="rtl"] #template_header_image img { margin-left: auto !important; margin-right: 0 !important; }
.petish-email-tagline { color: #526077; font-size: 12px; line-height: 1.5; margin: 0 0 10px; }
@media only screen and (max-width: 620px) {
    #petish_outer_cell { padding: 12px 8px !important; }
    #wrapper, #template_container, #inner_wrapper { width: 100% !important; max-width: 600px !important; }
    #template_header_image { padding: 22px 20px 18px !important; }
    #header_wrapper { padding: 22px 20px !important; }
    #template_header h1 { font-size: 24px !important; }
    #body_content_inner_cell { padding: 24px 20px 16px !important; }
    #body_content_inner { font-size: 15px !important; }
    #petish_support { padding: 22px 20px !important; }
    #body_content_inner table.td th, #body_content_inner table.td td, #body_content_inner .email-order-details th, #body_content_inner .email-order-details td { padding: 9px 8px !important; font-size: 13px !important; }
}
';
}
add_filter( 'woocommerce_email_styles', 'petish_email_branding_styles', 999, 2 );
