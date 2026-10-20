<?php
/** Hide a redundant shipping-origin control without changing native variations. */
defined( 'ABSPATH' ) || exit;

/** Return an origin only when the parent and every child agree on one custom value. */
function petish_product_single_shipping_origin( $product ) {
    if ( ! $product instanceof WC_Product_Variable ) {
        return null;
    }
    $origin = null;
    foreach ( $product->get_attributes() as $attribute ) {
        if ( ! $attribute instanceof WC_Product_Attribute || 'ships-from' !== sanitize_title( $attribute->get_name() ) ) {
            continue;
        }
        if ( $origin || $attribute->is_taxonomy() || ! $attribute->get_variation() ) {
            return null;
        }
        $options = array_values( $attribute->get_options() );
        if ( count( $options ) !== 1 || ! is_string( $options[0] ) || '' === trim( $options[0] ) || trim( $options[0] ) !== $options[0] ) {
            return null;
        }
        $origin = array( 'attribute' => 'ships-from', 'value' => $options[0] );
    }
    if ( ! $origin ) {
        return null;
    }
    $children = $product->get_children();
    if ( ! $children ) {
        return null;
    }
    foreach ( $children as $child_id ) {
        $child = wc_get_product( $child_id );
        if ( ! $child instanceof WC_Product_Variation || $child->get_parent_id() !== $product->get_id() ) {
            return null;
        }
        $attributes = $child->get_attributes();
        // A wildcard or a second origin needs an explicit choice or fulfilment rule.
        if ( ( $attributes[ $origin['attribute'] ] ?? null ) !== $origin['value'] ) {
            return null;
        }
    }
    return $origin;
}

function petish_product_origin_frontend_context() {
    return ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        && function_exists( 'is_product' ) && is_product();
}

/** Verify the selected cart variation without changing its native attributes. */
function petish_cart_single_shipping_origin( $cart_item ) {
    if ( ! is_array( $cart_item ) || ! isset( $cart_item['data'] )
        || ! $cart_item['data'] instanceof WC_Product_Variation
        || ! isset( $cart_item['variation'] ) || ! is_array( $cart_item['variation'] ) ) {
        return null;
    }
    $variation = $cart_item['data'];
    $parent_id = $variation->get_parent_id();
    if ( ! $parent_id || (int) ( $cart_item['product_id'] ?? 0 ) !== $parent_id
        || (int) ( $cart_item['variation_id'] ?? 0 ) !== $variation->get_id() ) {
        return null;
    }
    static $origins = array();
    if ( ! array_key_exists( $parent_id, $origins ) ) {
        $origins[ $parent_id ] = petish_product_single_shipping_origin( wc_get_product( $parent_id ) );
    }
    $origin = $origins[ $parent_id ];
    if ( ! $origin ) { return null; }
    $selected = $variation->get_attributes();
    if ( ( $selected[ $origin['attribute'] ] ?? null ) !== $origin['value']
        || ( $cart_item['variation'][ 'attribute_' . $origin['attribute'] ] ?? null ) !== $origin['value'] ) {
        return null;
    }
    return $origin;
}

/** Classic cart display metadata only; native cart/order values are retained. */
add_filter( 'woocommerce_get_item_data', function ( $item_data, $cart_item ) {
    if ( is_admin() || ! is_array( $item_data ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return $item_data;
    }
    $cart_context = ( function_exists( 'is_cart' ) && is_cart() )
        || ( function_exists( 'is_checkout' ) && is_checkout() );
    if ( ! $cart_context && defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX ) {
        $endpoint = isset( $_GET['wc-ajax'] ) && is_string( $_GET['wc-ajax'] ) ? wp_unslash( $_GET['wc-ajax'] ) : '';
        $cart_context = in_array( $endpoint, array( 'get_refreshed_fragments', 'update_order_review', 'get_cart_totals', 'update_shipping_method', 'apply_coupon', 'remove_coupon' ), true );
    }
    if ( ! $cart_context ) { return $item_data; }
    $origin = petish_cart_single_shipping_origin( $cart_item );
    if ( ! $origin ) { return $item_data; }
    $label = wc_attribute_label( $origin['attribute'], $cart_item['data'] );
    $value = rawurldecode( $origin['value'] );
    foreach ( $item_data as $index => $row ) {
        if ( is_array( $row ) && ( $row['key'] ?? $row['name'] ?? null ) === $label
            && ( $row['value'] ?? null ) === $value ) {
            $item_data[ $index ]['hidden'] = true;
        }
    }
    return $item_data;
}, 30, 2 );

// Themes load after woocommerce_blocks_loaded on some WooCommerce versions.
// init runs after the Store API helpers exist and before either API dispatch or
// frontend cart hydration. Add only a namespaced presentation flag to each item.
add_action( 'init', function () {
    if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
        || ! class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartItemSchema' ) ) {
        return;
    }
    woocommerce_store_api_register_endpoint_data( array(
        'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
        'namespace' => 'petish_origin',
        'data_callback' => function ( $cart_item ) {
            return array( 'hide_shipping_origin' => null !== petish_cart_single_shipping_origin( $cart_item ) );
        },
        'schema_callback' => function () {
            return array( 'hide_shipping_origin' => array(
                'description' => 'Whether one verified shipping origin is redundant in the cart display.',
                'type' => 'boolean',
                'readonly' => true,
            ) );
        },
        'schema_type' => ARRAY_A,
    ) );
}, 20 );

add_action( 'wp_enqueue_scripts', function () {
    if ( is_admin() || ! ( ( function_exists( 'is_cart' ) && is_cart() )
        || ( function_exists( 'is_checkout' ) && is_checkout() ) )
        || ! wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
        return;
    }
    $file = '/assets/js/cart-origin.js';
    wp_enqueue_script( 'petish-cart-origin', get_stylesheet_directory_uri() . $file,
        array( 'wc-blocks-checkout' ), filemtime( get_stylesheet_directory() . $file ), true );
}, 45 );

// Set only the rendered selector; stored defaults and supplier attributes stay intact.
add_filter( 'woocommerce_dropdown_variation_attribute_options_args', function ( $args ) {
    if ( ! petish_product_origin_frontend_context() || ! isset( $args['product'] )
        || ! $args['product'] instanceof WC_Product_Variable
        || $args['product']->get_id() !== get_queried_object_id() ) {
        return $args;
    }
    $origin = petish_product_single_shipping_origin( $args['product'] );
    if ( $origin && sanitize_title( (string) ( $args['attribute'] ?? '' ) ) === $origin['attribute'] ) {
        if ( ! empty( $args['options'] ) && array_values( $args['options'] ) !== array( $origin['value'] ) ) {
            return $args;
        }
        $args['selected'] = $origin['value'];
    }
    return $args;
}, 20 );

add_action( 'wp_enqueue_scripts', function () {
    if ( ! petish_product_origin_frontend_context() ) {
        return;
    }
    $product = wc_get_product( get_queried_object_id() );
    $origin = petish_product_single_shipping_origin( $product );
    if ( ! $origin ) {
        return;
    }
    $file = '/assets/js/product-origin.js';
    wp_enqueue_script( 'petish-product-origin', get_stylesheet_directory_uri() . $file,
        array( 'jquery', 'wc-add-to-cart-variation' ), filemtime( get_stylesheet_directory() . $file ), true );
    wp_localize_script( 'petish-product-origin', 'petishProductOrigin', array(
        'productId' => $product->get_id(),
        'fieldName' => 'attribute_' . $origin['attribute'],
        'value' => $origin['value'],
    ) );
}, 45 );
