<?php
/**
 * Plugin Name: Petish Passport
 * Description: Optional private multi-pet profiles for WooCommerce customers.
 * Version: 0.2.0
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: Petish
 * Text Domain: petish-passport
 */
defined( 'ABSPATH' ) || exit;
define( 'PETISH_PASSPORT_VERSION', '0.2.0' );
define( 'PETISH_PASSPORT_FILE', __FILE__ );
define( 'PETISH_PASSPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PETISH_PASSPORT_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Petish Passport requires WooCommerce to be active.', 'petish-passport' ) . '</p></div>';
        } );
        return;
    }
    foreach ( array( 'media', 'store', 'ui' ) as $module ) {
        $file = PETISH_PASSPORT_DIR . 'includes/class-petish-passport-' . $module . '.php';
        if ( ! is_readable( $file ) ) {
            return; // Installation is incomplete; never expose a partially loaded customer flow.
        }
    }
    foreach ( array( 'class-petish-product-review.php', 'class-petish-catalogue.php', 'class-petish-supplier-intake.php' ) as $file ) {
        if ( ! is_readable( PETISH_PASSPORT_DIR . 'includes/' . $file ) ) { return; }
    }
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-media.php';
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-store.php';
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-ui.php';
    Petish_Passport_Media::boot();
    Petish_Passport_Store::boot();
    Petish_Passport_UI::boot();
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-product-review.php';
    Petish_Product_Review::boot();
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-catalogue.php';
    Petish_Catalogue::boot();
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-supplier-intake.php';
    Petish_Supplier_Intake::boot();
}, 20 );

register_activation_hook( __FILE__, function () {
    add_rewrite_endpoint( 'my-pets', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'for-my-pet', EP_ROOT | EP_PAGES );
    flush_rewrite_rules( false );
    update_option( 'petish_passport_schema_version', 2, false );
} );
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules( false );
} );

add_action( 'admin_init', function () {
    if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
        wp_add_privacy_policy_content( 'Petish Passport',
            '<p>Petish Passport stores the pet profiles you choose to create: pet name, species, broad age range, shopping interest, and an optional photograph, associated with your customer account. Profiles and photographs are private to that account. Photos are resized and re-encoded, and originals are not retained by Passport.</p>' .
            '<p>You can edit or delete profiles from My Account &gt; My Pets. Deleting a pet removes its current profile and stored photo from the active site. Passport data is included in WordPress personal-data export and erasure workflows. Backup retention is managed separately and must be specified in the store privacy policy before launch.</p>'
        );
    }
} );
