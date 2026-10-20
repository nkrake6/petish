<?php
/** Order the catalogue by dated supplier-reported sales, independently of Petish sales. */
defined( 'ABSPATH' ) || exit;

/** A high reported order count is a popularity signal, not measured trend growth. */
function petish_supplier_hot_badge( $product ) {
    if ( ! $product instanceof WC_Product ) { return ''; }
    $count = (string) $product->get_meta( '_petish_supplier_order_count', true, 'edit' );
    $date = (string) $product->get_meta( '_petish_supplier_order_observed', true, 'edit' );
    $observed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
    if ( ! preg_match( '/^[0-9]+$/D', $count ) || (int) $count < 500 || ! $observed
        || $observed->format( 'Y-m-d' ) !== $date || $observed->getTimestamp() > time()
        || time() - $observed->getTimestamp() > 30 * DAY_IN_SECONDS ) { return ''; }
    $explanation = sprintf( 'Supplier-reported: %s orders. Observed %s.', number_format_i18n( (int) $count ), $date );
    $icon = get_stylesheet_directory_uri() . '/assets/images/ui/hot-flame.png';
    return '<span class="petish-hot-badge petish-hot-badge--icon" title="' . esc_attr( $explanation ) . '">'
        . '<img class="petish-hot-badge__icon" src="' . esc_url( $icon ) . '" width="20" height="20" alt="" aria-hidden="true" decoding="async">'
        . '<span class="petish-hot-badge__label">Hot</span>'
        . '<span class="screen-reader-text"> — ' . esc_html( $explanation ) . '</span></span>';
}

add_action( 'wp_enqueue_scripts', static function () {
    if ( ! ( is_shop() || is_product_taxonomy() || is_product() || is_front_page() || is_search() ) ) { return; }
    $path = '/assets/css/supplier-popularity.css';
    wp_enqueue_style( 'petish-supplier-popularity', get_stylesheet_directory_uri() . $path,
        array( 'petish-design' ), filemtime( get_stylesheet_directory() . $path ) );
}, 45 );

add_filter( 'woocommerce_product_get_image', function ( $image, $product ) {
    if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        || ! ( is_shop() || is_product_taxonomy() || is_product() || is_front_page() || is_search() ) ) { return $image; }
    return $image . petish_supplier_hot_badge( $product );
}, 20, 2 );

add_filter( 'woocommerce_catalog_orderby', function ( $options ) {
    return array( 'petish_supplier_popularity' => __( 'Popular', 'petish' ) ) + $options;
} );

add_filter( 'woocommerce_default_catalog_orderby', function ( $orderby ) {
    return is_admin() ? $orderby : 'petish_supplier_popularity';
} );

add_action( 'woocommerce_product_query', function ( $query ) {
    if ( is_admin() || ! $query->is_main_query() || 'petish_supplier_popularity' !== $query->get( 'orderby' ) ) {
        return;
    }
    $query->set( 'petish_supplier_popularity', true );
    $query->set( 'orderby', 'ID' );
} );

add_filter( 'posts_clauses', function ( $clauses, $query ) {
    if ( ! $query->get( 'petish_supplier_popularity' ) || ! $query->is_main_query() || is_admin() ) {
        return $clauses;
    }
    global $wpdb;
    // Aggregate first, so duplicate metadata cannot duplicate products or break pagination.
    $clauses['join'] .= " LEFT JOIN (
        SELECT post_id, MAX(CAST(meta_value AS UNSIGNED)) AS order_count
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_petish_supplier_order_count' AND meta_value REGEXP '^[0-9]+$'
        GROUP BY post_id
    ) AS petish_supplier_orders ON {$wpdb->posts}.ID = petish_supplier_orders.post_id ";
    // Unknown counts are last, including after genuinely reported zero-order products.
    $clauses['orderby'] = "(petish_supplier_orders.order_count IS NULL) ASC,
        petish_supplier_orders.order_count DESC, {$wpdb->posts}.post_title ASC, {$wpdb->posts}.ID ASC";
    return $clauses;
}, 200, 2 );

/** Existing shopper destination only; a country fallback never invents an address. */
function petish_supplier_free_delivery_destination() {
    if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->countries ) { return array(); }
    $countries = WC()->countries->get_shipping_countries();
    $customer = WC()->customer;
    $country = $customer ? $customer->get_shipping_country() : '';
    if ( ! isset( $countries[ $country ] ) ) {
        $base = wc_get_base_location();
        return isset( $countries[ $base['country'] ] )
            ? array( 'country' => $base['country'], 'state' => '', 'postcode' => '', 'city' => '' ) : array();
    }
    return array( 'country' => $country, 'state' => $customer->get_shipping_state(),
        'postcode' => $customer->get_shipping_postcode(), 'city' => $customer->get_shipping_city() );
}

function petish_supplier_free_delivery_destination_label() {
    $destination = petish_supplier_free_delivery_destination();
    $countries = function_exists( 'WC' ) && WC() && WC()->countries ? WC()->countries->get_countries() : array();
    return isset( $countries[ $destination['country'] ?? '' ] )
        ? sprintf( __( 'For delivery to %s (one item).', 'petish' ), $countries[ $destination['country'] ] ) : '';
}

/** Numeric metadata is ranked without integer overflow; unknown follows genuine zero. */
function petish_supplier_free_delivery_compare( $left, $right ) {
    $counts = array();
    foreach ( array( $left, $right ) as $product ) {
        $count = $product->get_meta( '_petish_supplier_order_count', true, 'edit' );
        $counts[] = ( is_string( $count ) || is_int( $count ) ) && preg_match( '/^[0-9]+$/D', (string) $count )
            ? ( ltrim( (string) $count, '0' ) ?: '0' ) : null;
    }
    if ( ( null === $counts[0] ) !== ( null === $counts[1] ) ) { return null === $counts[0] ? 1 : -1; }
    if ( null !== $counts[0] ) {
        $order = strlen( $counts[1] ) <=> strlen( $counts[0] );
        if ( 0 === $order ) { $order = strcmp( $counts[1], $counts[0] ); }
        if ( 0 !== $order ) { return $order; }
    }
    $prices = array();
    foreach ( array( $left, $right ) as $product ) {
        $price = $product->get_price();
        $price = is_numeric( $price ) && is_finite( (float) $price ) && (float) $price >= 0
            ? wc_get_price_to_display( $product, array( 'price' => (float) $price ) ) : -1;
        $prices[] = is_numeric( $price ) && is_finite( (float) $price ) ? (float) $price : -1;
    }
    $order = $prices[1] <=> $prices[0];
    return 0 !== $order ? $order : $left->get_id() <=> $right->get_id();
}

/**
 * Opt-in product/variation meta `_petish_supplier_free_delivery_evidence` is one array:
 * schema_version:1, reviewed:true, supplier_url (current parent's supplier URL),
 * shipping_fingerprint (current leaf fingerprint), country, destination_scope
 * (exact states/cities/postcodes lists), country_scope_verified:true when unscoped,
 * supplier_shipping_amount:"0", currency (ISO code), quantity:1, observed_utc and
 * expires_utc (YYYY-MM-DDTHH:MM:SSZ, at most seven days apart). No records are created.
 * Each available variation needs its own evidence; merchant delivery prices are separate.
 */
function petish_supplier_free_delivery_supplier_verified( $product, $destination ) {
    if ( ! class_exists( 'Petish_Supplier_Quotes' ) ) { return false; }
    $records = array();
    foreach ( $product->get_meta_data() as $metadata ) {
        $item = $metadata->get_data();
        if ( '_petish_supplier_free_delivery_evidence' === $item['key'] ) { $records[] = $item['value']; }
    }
    if ( ! is_array( $records ) || 1 !== count( $records ) || ! is_array( $records[0] ) ) { return false; }
    $row = $records[0];
    $keys = array( 'schema_version', 'reviewed', 'supplier_url', 'shipping_fingerprint', 'country', 'destination_scope',
        'country_scope_verified', 'supplier_shipping_amount', 'currency', 'quantity', 'observed_utc', 'expires_utc' );
    if ( array_diff( array_keys( $row ), $keys ) || 1 !== ( $row['schema_version'] ?? null )
        || true !== ( $row['reviewed'] ?? false ) || 1 !== ( $row['quantity'] ?? null )
        || ! is_string( $row['supplier_shipping_amount'] ?? null )
        || ! preg_match( '/^0(?:\.0{1,6})?$/D', $row['supplier_shipping_amount'] )
        || ! is_string( $row['currency'] ?? null ) || ! isset( get_woocommerce_currencies()[ $row['currency'] ] )
        || ( $row['country'] ?? null ) !== ( $destination['country'] ?? '' ) ) { return false; }
    $parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
    $fingerprint = Petish_Supplier_Quotes::fingerprint( $product );
    if ( ! $parent || is_wp_error( $fingerprint ) || ( $row['shipping_fingerprint'] ?? null ) !== $fingerprint
        || ( $row['supplier_url'] ?? null ) !== $parent->get_meta( '_petish_supplier_product_url', true, 'edit' ) ) { return false; }
    $times = array();
    foreach ( array( 'observed_utc', 'expires_utc' ) as $key ) {
        $text = $row[ $key ] ?? null;
        if ( ! is_string( $text ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $text ) ) { return false; }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $text, new DateTimeZone( 'UTC' ) );
        if ( ! $date || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $text ) { return false; }
        $times[] = $date->getTimestamp();
    }
    $now = time();
    if ( $times[0] > $now || $times[1] <= $now || $times[1] <= $times[0]
        || $times[1] - $times[0] > 7 * DAY_IN_SECONDS ) { return false; }
    $scope = $row['destination_scope'] ?? null;
    if ( ! is_array( $scope ) || array_diff( array_keys( $scope ), array( 'states', 'cities', 'postcodes' ) ) ) { return false; }
    $pinned = false;
    foreach ( array( 'states' => 'state', 'cities' => 'city', 'postcodes' => 'postcode' ) as $key => $field ) {
        $values = $scope[ $key ] ?? array();
        if ( ! is_array( $values ) || ! array_is_list( $values ) || count( $values ) > 1000 ) { return false; }
        $normalize = static function ( $value ) use ( $key ) {
            $value = trim( preg_replace( '/\s+/u', ' ', $value ) );
            return 'cities' === $key ? strtolower( $value ) : ( 'postcodes' === $key ? wc_normalize_postcode( $value ) : strtoupper( $value ) );
        };
        $allowed = array();
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 100 || false !== strpbrk( $value, '*?%' ) ) { return false; }
            $allowed[] = $normalize( $value );
        }
        if ( $allowed ) {
            $pinned = true;
            if ( ! in_array( $normalize( $destination[ $field ] ?? '' ), $allowed, true ) ) { return false; }
        }
    }
    return $pinned || true === ( $row['country_scope_verified'] ?? false );
}

/** Read-only one-item quote; never consult personal approvals or initialize a cart/session. */
function petish_supplier_free_delivery_checkout_zero( $product, $destination ) {
    if ( ! class_exists( 'WC_Shipping_Zones' ) || ! class_exists( 'Petish_Quote_Currency' )
        || wc_string_to_bool( apply_filters( 'woocommerce_shipping_prices_include_tax', false ) ) ) { return false; }
    $variation = $product->is_type( 'variation' );
    $package = array( 'contents' => array( array( 'data' => $product, 'quantity' => 1,
        'product_id' => $variation ? $product->get_parent_id() : $product->get_id(),
        'variation_id' => $variation ? $product->get_id() : 0 ) ), 'destination' => $destination );
    $automatic = 'automatic' === get_option( 'petish_shipping_mode', 'quote' );
    $method_id = $automatic ? 'petish_automatic_shipping' : 'petish_supplier_shipping';
    $class = $automatic ? 'Petish_Automatic_Shipping_Method' : 'Petish_Supplier_Shipping_Method';
    $zone = WC_Shipping_Zones::get_zone_matching_package( $package );
    if ( ! $zone ) { return false; }
    // Native method loading fires woocommerce_shipping_init for the quote method class.
    $methods = $zone->get_shipping_methods( true );
    if ( ! class_exists( $class ) ) { return false; }
    $enabled = false;
    foreach ( $methods as $method ) {
        if ( $method instanceof $class && $method_id === $method->id && 'yes' === $method->enabled && $method->get_instance_id() > 0 ) { $enabled = true; }
    }
    if ( ! $enabled ) { return false; }
    if ( $automatic ) {
        if ( ! class_exists( 'Petish_Automatic_Shipping' ) ) { return false; }
        $result = Petish_Automatic_Shipping::rate_args( $package );
        $cost = is_wp_error( $result ) ? null : ( $result['cost'] ?? null );
    } else {
        if ( ! class_exists( 'Petish_Supplier_Quotes' ) ) { return false; }
        // Passing the book explicitly prevents fallback to customer-specific approvals.
        $book = get_option( Petish_Supplier_Quotes::OPTION, array() );
        if ( ! is_array( $book ) ) { return false; }
        $result = Petish_Supplier_Quotes::quote_package( $package, $book );
        $cost = is_wp_error( $result ) ? null : Petish_Quote_Currency::convert( $result['cost'] );
        if ( is_wp_error( $cost ) ) { return false; }
    }
    return is_numeric( $cost ) && is_finite( (float) $cost ) && 0.0 === (float) $cost;
}

function petish_supplier_free_delivery_eligible( $product ) {
    if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status()
        || ! $product->is_purchasable() || ! $product->is_in_stock() ) { return false; }
    if ( $product->is_type( 'variation' ) ) {
        // Woo variations are never individually catalog-visible; use their native option visibility.
        $parent = wc_get_product( $product->get_parent_id() );
        if ( ! $parent || ! $parent->is_type( 'variable' ) || 'publish' !== $parent->get_status()
            || ! $parent->is_visible() || ! $product->variation_is_visible() ) { return false; }
    } elseif ( ! $product->is_visible() ) { return false; }
    $destination = petish_supplier_free_delivery_destination();
    if ( ! $destination ) { return false; }
    if ( $product->is_type( 'variable' ) ) {
        $available = 0;
        foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
            if ( ! $variation instanceof WC_Product || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) { continue; }
            if ( ! $variation->is_type( 'variation' ) || $variation->get_parent_id() !== $product->get_id()
                || ! petish_supplier_free_delivery_eligible( $variation ) ) { return false; }
            $available++;
        }
        return $available > 0;
    }
    return $product->is_type( array( 'simple', 'variation' ) ) && $product->needs_shipping()
        && petish_supplier_free_delivery_supplier_verified( $product, $destination )
        && petish_supplier_free_delivery_checkout_zero( $product, $destination );
}

function petish_supplier_free_delivery_badge( $product ) {
    if ( ! petish_supplier_free_delivery_eligible( $product ) ) { return ''; }
    return '<span class="petish-free-delivery-badge" title="' . esc_attr( petish_supplier_free_delivery_destination_label() ) . '">'
        . '<img src="' . esc_url( get_stylesheet_directory_uri() . '/assets/images/ui/free-delivery-van.png' )
        . '" width="1600" height="592" alt="' . esc_attr__( 'Free delivery', 'petish' ) . '" loading="lazy" decoding="async"></span>';
}
