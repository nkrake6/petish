<?php
/** Compact catalogue titles using The7's existing heading and breadcrumb output. */
defined( 'ABSPATH' ) || exit;

function petish_has_collection_header() {
    return ! is_search() && function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
}

add_filter( 'presscore_get_page_title', function ( $title ) {
    if ( petish_has_collection_header() && is_shop() && class_exists( 'Petish_Catalogue' ) ) {
        $species = Petish_Catalogue::context()['species'];
        $names = array( 'rabbit' => 'Rabbits', 'guinea-pig' => 'Guinea pigs', 'hamster' => 'Hamsters', 'chinchilla' => 'Chinchillas' );
        if ( isset( $names[ $species ] ) ) { return esc_html( $names[ $species ] ); }
    }
    return $title;
}, 30 );

add_filter( 'body_class', function ( $classes ) {
    if ( petish_has_collection_header() ) {
        $classes[] = 'petish-collection-archive';
    }
    return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
    if ( ! petish_has_collection_header() ) {
        return;
    }
    $file = '/assets/css/collection-header.css';
    wp_enqueue_style( 'petish-collection-header', get_stylesheet_directory_uri() . $file, array( 'petish-design' ), filemtime( get_stylesheet_directory() . $file ) );
}, 41 );

add_filter( 'presscore_page_title', function ( $title_html ) {
    if ( ! petish_has_collection_header() || '' === trim( $title_html ) ) {
        return $title_html;
    }

    $collections = array(
        'enrichment-play' => array( 'intro' => 'Foraging toys, puzzles and little discoveries for curious pets.', 'image' => 'enrichment-play-v1.webp' ),
        'hides-tunnels'   => array( 'intro' => 'Explore hideaways, tunnels and bridges for their space.', 'image' => 'hides-tunnels-v1.webp' ),
        'feeding-hay'    => array( 'intro' => 'Bowls, hay feeders and accessories for daily routines.', 'image' => 'feeding-hay-v2.webp' ),
        'bedding-liners' => array( 'intro' => 'Browse fleece liners and bedding accessories for their space.', 'image' => 'bedding-liners-v2.webp' ),
        'cleaning-care'  => array( 'intro' => 'Practical tools for cage cleaning and everyday care.', 'image' => 'cleaning-care-v2.webp' ),
        'travel-carriers' => array( 'intro' => 'Explore carriers and accessories for trips with your small pet.', 'image' => '' ),
    );
    $intro = '';
    $image = '';
    $image_html = '';

    if ( is_shop() ) {
        $intro = 'Explore enrichment, hides and everyday care for your small pet.';
        $image = 'enrichment-play-v1.webp';
    } elseif ( is_product_category() ) {
        $term = get_queried_object();
        if ( $term instanceof WP_Term ) {
            $collection = $collections[ $term->slug ] ?? array();
            $intro = $collection['intro'] ?? '';
            // Category thumbnails stay editable through native WooCommerce settings.
            $attachment_id = absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
            if ( $attachment_id ) {
                $image_html = wp_get_attachment_image( $attachment_id, 'medium', false, array( 'class' => 'petish-collection-image', 'alt' => '', 'loading' => 'eager', 'sizes' => '144px' ) );
            }
            $image = $collection['image'] ?? '';
        }
    }

    if ( empty( $image_html ) && $image && is_file( get_stylesheet_directory() . '/assets/images/categories/' . $image ) ) {
        $image_html = '<img class="petish-collection-image" src="' . esc_url( get_stylesheet_directory_uri() . '/assets/images/categories/' . $image ) . '" alt="" width="960" height="720" decoding="async">';
    }

    // Retain the native single H1, structured breadcrumbs and archive descriptions.
    // Tags and custom product taxonomies also get the compact layout without invented imagery.
    return '<div class="petish-collection-copy">' . $title_html
        . ( $intro ? '<p class="petish-collection-intro">' . esc_html( $intro ) . '</p>' : '' )
        . '</div>' . ( ! empty( $image_html ) ? $image_html : '' );
} );
