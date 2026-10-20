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
