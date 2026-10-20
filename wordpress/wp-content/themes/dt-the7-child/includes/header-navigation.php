<?php
/** Keep the short header menu separate from the fuller footer navigation. */
defined( 'ABSPATH' ) || exit;

add_filter( 'wp_nav_menu_objects', function ( $items, $args ) {
    if ( ! in_array( $args->theme_location ?? '', array( 'primary', 'mobile' ), true ) ) {
        return $items;
    }
    $about_url = untrailingslashit( home_url( '/about-petish/' ) );
    return array_filter( $items, static function ( $item ) use ( $about_url ) {
        return untrailingslashit( $item->url ) !== $about_url;
    } );
}, 20, 2 );
