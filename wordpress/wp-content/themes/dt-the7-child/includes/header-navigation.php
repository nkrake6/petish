<?php
/** Keep the short header menu separate from the fuller footer navigation. */
defined( 'ABSPATH' ) || exit;

// The7 fires this after search; the market selector follows at priority 10.
add_action( 'presscore_render_header_element-search', function () {
    $classes = function_exists( 'presscore_get_mini_widget_class' )
        ? presscore_get_mini_widget_class( 'header-elements-search', 'petish-theme-switch-wrapper' )
        : array( 'petish-theme-switch-wrapper' );
    ?>
    <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-petish-theme-switch hidden>
        <button type="button" class="petish-theme-switch" data-petish-theme-toggle role="switch" aria-label="<?php esc_attr_e( 'Dark mode', 'petish' ); ?>" aria-checked="false" title="<?php esc_attr_e( 'Switch to dark mode', 'petish' ); ?>">
            <svg class="petish-theme-switch__moon" aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24"><path d="M20.9 13.2A9 9 0 0 1 10.8 3.1 9 9 0 1 0 20.9 13.2Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <svg class="petish-theme-switch__sun" aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
        </button>
    </div>
    <?php
}, 5 );

add_filter( 'wp_nav_menu_objects', function ( $items, $args ) {
    if ( ! in_array( $args->theme_location ?? '', array( 'primary', 'mobile' ), true ) ) {
        return $items;
    }
    $about_url = untrailingslashit( home_url( '/about-petish/' ) );
    return array_filter( $items, static function ( $item ) use ( $about_url ) {
        return untrailingslashit( $item->url ) !== $about_url;
    } );
}, 20, 2 );
