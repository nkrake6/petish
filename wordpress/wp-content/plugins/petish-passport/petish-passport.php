<?php
/**
 * Plugin Name: Petish Passport
 * Description: Private pet passports, Pet Club sharing and optional moderated customer product reviews for WooCommerce.
 * Version: 0.4.0
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: Petish
 * Text Domain: petish-passport
 */
defined( 'ABSPATH' ) || exit;
define( 'PETISH_PASSPORT_VERSION', '0.4.0' );
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
    foreach ( array( 'media', 'video-inspector', 'video', 'store', 'moments', 'community', 'ui' ) as $module ) {
        $file = PETISH_PASSPORT_DIR . 'includes/class-petish-passport-' . $module . '.php';
        if ( ! is_readable( $file ) ) {
            return; // Installation is incomplete; never expose a partially loaded customer flow.
        }
    }
    foreach ( array( 'class-petish-product-review.php', 'class-petish-catalogue.php', 'class-petish-supplier-intake.php' ) as $file ) {
        if ( ! is_readable( PETISH_PASSPORT_DIR . 'includes/' . $file ) ) { return; }
    }
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-media.php';
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-video-inspector.php';
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-video.php';
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-store.php';
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-moments.php';
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-community.php';
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-passport-ui.php';
    Petish_Passport_Media::boot();
    Petish_Passport_Store::boot();
    Petish_Passport_Moments::boot();
    Petish_Passport_Community::boot();
    Petish_Passport_UI::boot();
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-product-review.php';
    Petish_Product_Review::boot();
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-catalogue.php';
    Petish_Catalogue::boot();
    require_once PETISH_PASSPORT_DIR . 'includes/class-petish-supplier-intake.php';
    Petish_Supplier_Intake::boot();

    // Customer reviews are an opt-in module. Existing Passport features remain
    // available if a partial code transfer has not yet supplied the new files.
    $review_modules = array(
        'class-petish-customer-reviews.php',
        'class-petish-customer-review-media.php',
        'class-petish-customer-reviews-ui.php',
    );
    foreach ( $review_modules as $review_module ) {
        if ( ! is_readable( PETISH_PASSPORT_DIR . 'includes/' . $review_module ) ) {
            return;
        }
    }
    foreach ( $review_modules as $review_module ) {
        require_once PETISH_PASSPORT_DIR . 'includes/' . $review_module;
    }
    Petish_Customer_Reviews::boot();
    Petish_Customer_Review_Media::boot();
    Petish_Customer_Reviews_UI::boot();
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
            '<p>Petish Passport stores the pet profiles you choose to create: pet name, species, optional breed or variety, broad age range, shopping interest, optional biography, personality tags, photographs, short videos and journal entries, associated with your customer account. New and existing passports remain private unless you explicitly share a pet. Photos are resized and re-encoded, and originals are not retained by Passport. Videos are stored as uploaded, without transcoding or removal of embedded metadata.</p>' .
            '<p>Sharing publishes a selected copy of your pet’s name, species, breed or variety when supplied, biography, personality tags, chosen photo, optional age range and selected moments. Account details, shopping preferences and unselected moments are excluded. You can update or withdraw that shared copy from My Pets. Signed-in members can add or remove a heart on a shared pet and report a profile for review. Heart and report records are associated privately with their member accounts; only aggregate heart counts are displayed.</p>' .
            '<p>Only explicitly selected moments are shared. Shared videos include any metadata embedded in the uploaded file. You can edit or delete profiles from My Account &gt; My Pets. Deleting a pet removes its profile, moments, shared copy and associated photos and videos from the active site. Passport data is included in WordPress personal-data export and erasure workflows. Backup retention is managed separately and must be specified in the store privacy policy before launch.</p>'
        );
    }
} );
