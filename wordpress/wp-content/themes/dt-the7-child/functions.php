<?php
/**
 * Petish visual system for The7 Classic. No parent-theme files are modified.
 */
defined( 'ABSPATH' ) || exit;

require_once get_stylesheet_directory() . '/includes/product-option-labels.php';
require_once get_stylesheet_directory() . '/includes/product-origin.php';
require_once get_stylesheet_directory() . '/includes/header-navigation.php';
require_once get_stylesheet_directory() . '/includes/collection-header.php';
require_once get_stylesheet_directory() . '/includes/market-selector.php';
require_once get_stylesheet_directory() . '/includes/supplier-popularity.php';
require_once get_stylesheet_directory() . '/includes/product-retail-rounding.php';
require_once get_stylesheet_directory() . '/inc/petish-email-branding.php';

// Let the photo anchor open WooCommerce's existing PhotoSwipe viewer on click/tap.
// Inline hover zoom otherwise intercepts that gesture; the viewer retains its own zoom.
add_filter( 'woocommerce_single_product_zoom_enabled', '__return_false' );

// Currency and delivery-country choices personalize prices, including homepage cards.
add_action( 'template_redirect', function () {
    if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
    if ( is_front_page() || ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() || is_product() ) )
        || ( is_search() && 'product' === get_query_var( 'post_type' ) ) ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'Vary: Cookie', false );
    }
}, 0 );

add_filter( 'body_class', function ( $classes ) {
    $classes[] = 'petish-site';
    if ( is_page( 'brand-preview' ) ) $classes[] = 'petish-brand-preview';
    return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    // The7 loads its generated styles at 20 and the child stylesheet at 30.
    wp_enqueue_style( 'petish-fonts', $uri . '/assets/fonts/fonts.css', array(), filemtime( $dir . '/assets/fonts/fonts.css' ) );
    wp_enqueue_style( 'petish-design', $uri . '/assets/css/petish.css', array( 'style', 'petish-fonts' ), filemtime( $dir . '/assets/css/petish.css' ) );
    if ( function_exists( 'is_product' ) && is_product() ) {
        wp_enqueue_style( 'petish-product-purchase', $uri . '/assets/css/product-purchase.css', array( 'petish-design' ), filemtime( $dir . '/assets/css/product-purchase.css' ) );
    }
    if ( is_front_page() && file_exists( $dir . '/assets/css/home.css' ) ) {
        wp_enqueue_style( 'petish-home', $uri . '/assets/css/home.css', array( 'petish-design' ), filemtime( $dir . '/assets/css/home.css' ) );
        if ( file_exists( $dir . '/assets/js/home.js' ) ) {
            wp_enqueue_script( 'petish-home', $uri . '/assets/js/home.js', array(), filemtime( $dir . '/assets/js/home.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
        }
    }
    wp_enqueue_script( 'petish-theme', $uri . '/assets/js/theme.js', array(), filemtime( $dir . '/assets/js/theme.js' ), array( 'strategy' => 'defer', 'in_footer' => false ) );
    // The7's default Roboto stylesheet is no longer needed.
    wp_dequeue_style( 'dt-web-fonts' );
}, 40 );

add_action( 'wp_head', function () {
    // Apply the saved choice before CSS/markup paints. Storage may be unavailable.
    ?>
    <script id="petish-theme-bootstrap">
    (function () {
        var p = 'system';
        try {
            var s = localStorage.getItem('petish-theme');
            if (['light', 'dark', 'system'].indexOf(s) !== -1) p = s;
        } catch (e) {}
        var d = p === 'dark' || (p === 'system' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
        var theme = d ? 'dark' : 'light';
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.themePreference = p;
        <?php if ( function_exists( 'is_product' ) && is_product() ) : ?>
        // Invalidate only the cached product-message appearance before WooPayments mounts it.
        // Its native cache does not distinguish Petish's light and dark themes.
        try {
            var themeKey = 'petish-wcpay-bnpl-appearance-theme';
            if (localStorage.getItem(themeKey) !== theme) {
                localStorage.removeItem('wcpay_appearance_bnpl_product_page');
                localStorage.setItem(themeKey, theme);
            }
        } catch (e) {}
        <?php endif; ?>
    })();
    </script>
    <?php
}, 1 );

add_filter( 'the7_meta_viewport', function () {
    return '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
} );

/**
 * Keep the provider's product payment controls below the Add to cart form.
 * Reuse the registered WooPayments callbacks before Stripe mounts its iframes;
 * the checkout, cart and payment processing hooks retain their native positions.
 */
add_action( 'wp', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
        return;
    }
    $product = wc_get_product( get_queried_object_id() );
    if ( ! $product || ! $product->is_type( array( 'simple', 'variable' ) ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
        return;
    }

    global $wp_filter;
    $callbacks = array();
    // This is WooPayments' native, product-only express display callback.
    if ( isset( $wp_filter['woocommerce_after_add_to_cart_form'] ) ) {
        foreach ( $wp_filter['woocommerce_after_add_to_cart_form']->callbacks as $priority => $items ) {
            foreach ( $items as $item ) {
                $callback = $item['function'];
                if ( is_array( $callback ) && isset( $callback[0], $callback[1] )
                    && is_object( $callback[0] )
                    && is_a( $callback[0], 'WC_Payments_Express_Checkout_Button_Display_Handler' )
                    && 'display_express_checkout_buttons' === $callback[1] ) {
                    $callbacks[] = $callback;
                    remove_action( 'woocommerce_after_add_to_cart_form', $callback, $priority );
                }
            }
        }
    }
    $messaging = array( 'WC_Payments', 'load_stripe_bnpl_site_messaging' );
    $message_priority = has_action( 'woocommerce_single_product_summary', $messaging );
    if ( false !== $message_priority ) {
        remove_action( 'woocommerce_single_product_summary', $messaging, $message_priority );
        $callbacks[] = $messaging;
    }
    if ( ! $callbacks ) {
        return;
    }

    add_filter( 'body_class', function ( $classes ) {
        $classes[] = 'petish-product-wallet-layout';
        return $classes;
    } );
    add_action( 'woocommerce_after_add_to_cart_form', function () use ( $callbacks ) {
        echo '<section class="petish-product-payments" aria-label="' . esc_attr__( 'Payment options', 'petish' ) . '">';
        foreach ( $callbacks as $callback ) {
            call_user_func( $callback );
        }
        echo '</section>';
    }, 1 );
}, 20 );

// Ask WooPayments for its supported white wallet artwork, without recolouring it.
add_filter( 'wcpay_express_checkout_js_params', function ( $params ) {
    if ( in_array( $params['button_context'] ?? '', array( 'product', 'cart', 'checkout' ), true )
        && isset( $params['button'] ) ) {
        $params['button']['theme'] = 'light-outline';
    }
    return $params;
} );

// The7 deliberately makes this helper pluggable. One image preserves mobile/sticky markup.
if ( ! function_exists( 'presscore_get_logo_image' ) ) {
    function presscore_get_logo_image( $logos = array(), $class = '' ) {
        $base = get_stylesheet_directory_uri() . '/assets/brand/';
        return sprintf(
            '<img class="%1$s" src="%2$s" data-petish-logo data-light="%2$s" data-dark="%3$s" alt="%4$s" width="1942" height="809" decoding="async">',
            esc_attr( trim( $class . ' petish-logo' ) ),
            esc_url( $base . 'repaired/lettering-light-v2.png' ),
            esc_url( $base . 'repaired/lettering-dark-v2.png' ),
            esc_attr( 'Petish — Small pets, big adventures.' )
        );
    }
}

function petish_render_appearance_control() {
    ?>
    <label class="petish-theme-control">
        <span><?php esc_html_e( 'Colour theme', 'petish' ); ?></span>
        <select data-petish-theme aria-label="Colour theme" aria-describedby="petish-theme-help">
            <option value="system">System</option>
            <option value="light">Light</option>
            <option value="dark">Dark</option>
        </select>
    </label>
    <?php
}
add_action( 'woocommerce_before_edit_account_form', function () {
    if ( ! is_user_logged_in() ) {
        return;
    }
    echo '<section class="petish-card petish-account-appearance" aria-labelledby="petish-appearance-heading"><h2 id="petish-appearance-heading">' . esc_html__( 'Appearance', 'petish' ) . '</h2>';
    petish_render_appearance_control();
    echo '<p id="petish-theme-help" class="petish-caption">' . esc_html__( 'Changes apply immediately and are saved in this browser.', 'petish' ) . '</p></section>';
} );

// Remove the top contact bar's content before The7 copies widgets into mobile menus.
add_filter( 'presscore_get_header_elements_list-top_bar_left', '__return_empty_array' );
add_filter( 'presscore_get_header_elements_list-top_bar_right', '__return_empty_array' );

// Keep supplier option keys for DSers while hiding its meaningless simple-product row.
add_filter( 'woocommerce_display_product_attributes', function ( $display, $product ) {
    if ( $product->is_type( 'simple' ) ) {
        foreach ( $product->get_attributes() as $attribute ) {
            if ( 'Default Title' === $attribute->get_name() && array( 'Default Title' ) === $attribute->get_options() ) {
                unset( $display[ 'attribute_' . sanitize_title_with_dashes( $attribute->get_name() ) ] );
            }
        }
    }
    return $display;
}, 10, 2 );

/** Show an existing introduction beside the price when the summary is empty. */
function petish_product_summary_fallback( $short_description ) {
    if ( '' !== trim( (string) $short_description ) || is_admin()
        || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax()
        || ! function_exists( 'is_product' ) || ! is_product() || is_feed() ) {
        return $short_description;
    }

    global $product;
    if ( ! $product instanceof WC_Product || $product->get_id() !== get_queried_object_id()
        || ! doing_action( 'woocommerce_single_product_summary' )
        || doing_action( 'woocommerce_' . $product->get_type() . '_add_to_cart' ) ) {
        return $short_description;
    }

    // Woo also formats variation descriptions with this filter inside the cart form.
    // Read saved copy without running content filters, embeds or shortcodes.
    $content = strip_shortcodes( $product->get_description( 'edit' ) );
    preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', wpautop( $content ), $paragraphs );
    foreach ( $paragraphs[1] as $paragraph ) {
        if ( preg_match( '/<(?:h[1-6]|table|ul|ol|iframe|script|style)\b/i', $paragraph )
            || preg_match( '/\[\/?[a-z][^\]]*\]/i', $paragraph ) ) {
            continue;
        }
        $text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $paragraph, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        if ( '' === $text || preg_match( '/^(?:specifications?|product details?|description|features|why you.ll like it|package contents|sku|spu|material|colou?r|size|dimensions?|shipping|delivery|contact us)\s*(?::|[-–—]|$)/iu', $text )
            || preg_match( '/^(?:100%\s*(?:brand\s*)?new\b|please note\b|about (?:shipping|delivery|us)\b|dropshipping\b)/iu', $text ) ) {
            continue;
        }
        return wpautop( esc_html( wp_trim_words( $text, 50, '…' ) ) );
    }
    return $short_description;
}
// Run before WooCommerce's native short-description formatting filters.
add_filter( 'woocommerce_short_description', 'petish_product_summary_fallback', 7 );

/** Advertise the lowest available option without changing any variation price. */
function petish_variable_starting_price( $price_html, $variable_product ) {
    if ( ( is_admin() && ! wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        || is_cart() || is_checkout() || ! $variable_product instanceof WC_Product_Variable ) {
        return $price_html;
    }

    $prices = array();
    foreach ( $variable_product->get_available_variations( 'objects' ) as $variation ) {
        if ( ! $variation->variation_is_visible() || ! $variation->variation_is_active()
            || ! $variation->is_purchasable() || ! $variation->is_in_stock() || $variation->is_on_backorder( 1 )
            || '' === $variation->get_price() || ! is_numeric( $variation->get_price() ) ) {
            continue;
        }
        $display_price = (float) wc_get_price_to_display( $variation );
        if ( is_finite( $display_price ) && $display_price >= 0 ) {
            $prices[] = $display_price;
        }
    }
    if ( ! $prices ) {
        return '';
    }

    $minimum = min( $prices );
    $maximum = max( $prices );
    $html = wc_price( $minimum );
    if ( wc_format_decimal( $minimum, wc_get_price_decimals() ) !== wc_format_decimal( $maximum, wc_get_price_decimals() ) ) {
        $html = esc_html__( 'From', 'petish' ) . ' ' . $html;
    }
    return $html . $variable_product->get_price_suffix();
}
add_filter( 'woocommerce_variable_price_html', 'petish_variable_starting_price', 20, 2 );

add_action( 'wp_head', function () {
    if ( has_site_icon() ) {
        return;
    }
    $base = get_stylesheet_directory_uri() . '/assets/brand/';
    foreach ( array( 'light', 'dark' ) as $mode ) {
        printf( '<link rel="icon" type="image/png" sizes="32x32" href="%1$s" media="(prefers-color-scheme: %2$s)" data-petish-icon data-mode="%2$s">' . "\n",
            esc_url( $base . 'icon_P_' . $mode . '_32.png' ), esc_attr( $mode ) );
    }
}, 100 );

add_filter( 'wp_robots', function ( $robots ) {
    if ( is_page( 'brand-preview' ) ) {
        $robots['noindex'] = true;
        $robots['nofollow'] = true;
    }
    return $robots;
} );
