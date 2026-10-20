<?php
/** Species collections with explicit public controls and private account context. */
defined( 'ABSPATH' ) || exit;

final class Petish_Catalogue {
    const ENDPOINT = 'for-my-pet';
    const BROWSE_TAXONOMY = 'petish_pet_type';
    private static $layout_open = false;

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'endpoint' ) );
        add_action( 'init', array( __CLASS__, 'public_pet_taxonomy' ) );
        add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
        add_filter( 'woocommerce_endpoint_for-my-pet_title', static function() { return 'For my pet'; } );
        add_action( 'woocommerce_account_for-my-pet_endpoint', array( __CLASS__, 'collection' ) );
        add_action( 'template_redirect', array( __CLASS__, 'privacy_headers' ), 5 );
        add_action( 'woocommerce_product_query', array( __CLASS__, 'archive_query' ), 30 );
        add_action( 'woocommerce_before_main_content', array( __CLASS__, 'banner' ), 15 );
        add_action( 'woocommerce_after_main_content', array( __CLASS__, 'archive_end' ), 5 );
        add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'card_label' ), 20 );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_review' ), 25 );
        add_action( 'admin_post_petish_catalogue_active', array( __CLASS__, 'switch_pet' ) );
        add_action( 'admin_post_nopriv_petish_catalogue_active', array( __CLASS__, 'switch_pet' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ), 60 );
    }
    public static function endpoint() { add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES ); }
    /** Merchandising collections are separate from signed editorial assessments. */
    public static function public_pet_taxonomy() {
        register_taxonomy( self::BROWSE_TAXONOMY, 'product', array(
            'labels' => array( 'name' => 'Pet collections', 'singular_name' => 'Pet collection', 'menu_name' => 'Pet collections' ),
            'public' => false, 'show_ui' => true, 'show_admin_column' => true,
            'show_in_rest' => false, 'query_var' => false, 'rewrite' => false, 'hierarchical' => true,
            'capabilities' => array( 'manage_terms' => 'manage_woocommerce', 'edit_terms' => 'manage_woocommerce', 'delete_terms' => 'manage_woocommerce', 'assign_terms' => 'edit_products' ),
        ) );
    }
    public static function query_vars( $vars ) { $vars[ self::ENDPOINT ] = self::ENDPOINT; return $vars; }
    public static function url() { return wc_get_account_endpoint_url( self::ENDPOINT ); }
    public static function menu( $items ) {
        $out = array();
        foreach ( $items as $key => $value ) {
            $out[ $key ] = $value;
            if ( 'my-pets' === $key ) { $out[ self::ENDPOINT ] = 'For my pet'; }
        }
        return $out;
    }
    public static function is_catalogue() { return is_shop() || is_product_taxonomy() || is_product() || ( is_search() && 'product_query' === get_query_var( 'wc_query' ) ); }
    // Pause the customer-facing review feature while the editorial reviews are
    // completed. Private collections and signed review records stay unchanged.
    public static function reviews_visible() { return (bool) apply_filters( 'petish_public_species_reviews_enabled', false ); }
    public static function context() {
        $pet = is_user_logged_in() ? Petish_Passport_Store::active( get_current_user_id() ) : null;
        $explicit = isset( $_GET['petish_species'] );
        $value = $explicit && is_string( $_GET['petish_species'] ) ? sanitize_key( wp_unslash( $_GET['petish_species'] ) ) : '';
        $species = $explicit ? ( isset( Petish_Product_Review::species()[ $value ] ) ? $value : '' ) : ( self::reviews_visible() && $pet && isset( Petish_Product_Review::species()[ $pet['species'] ] ) ? $pet['species'] : '' );
        return array( 'pet' => $pet, 'species' => $species, 'explicit' => $explicit );
    }
    public static function privacy_headers() {
        if ( self::is_catalogue() || ( is_account_page() && is_wc_endpoint_url( self::ENDPOINT ) ) ) {
            wc_nocache_headers();
            header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
            header( 'Vary: Cookie', false );
        }
    }
    public static function archive_query( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) { return; }
        $species = self::context()['species'];
        if ( $species ) {
            if ( self::reviews_visible() ) {
                $query->set( 'meta_query', Petish_Product_Review::and_query( $query->get( 'meta_query' ), Petish_Product_Review::clause( $species ) ) );
            } else {
                $clause = array( 'taxonomy' => self::BROWSE_TAXONOMY, 'field' => 'slug', 'terms' => array( $species ), 'include_children' => false );
                $existing = $query->get( 'tax_query' );
                $query->set( 'tax_query', $existing ? array( 'relation' => 'AND', $existing, $clause ) : array( $clause ) );
            }
            $query->set( 'cache_results', false );
        }
    }
    public static function styles() {
        if ( ! self::is_catalogue() && ! is_account_page() ) { return; }
        wp_enqueue_style( 'petish-catalogue', PETISH_PASSPORT_URL . 'assets/catalogue.css', array( 'petish-design' ), filemtime( PETISH_PASSPORT_DIR . 'assets/catalogue.css' ) );
        if ( self::is_catalogue() && ! is_product() ) {
            wp_enqueue_script( 'petish-catalogue', PETISH_PASSPORT_URL . 'assets/catalogue.js', array(), filemtime( PETISH_PASSPORT_DIR . 'assets/catalogue.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
        }
    }
    public static function archive_base() {
        if ( is_product_taxonomy() ) {
            $term = get_queried_object();
            $url = $term ? get_term_link( $term ) : false;
            if ( ! is_wp_error( $url ) && $url ) { return $url; }
        }
        return wc_get_page_permalink( 'shop' );
    }
    public static function public_args() {
        $args = array();
        foreach ( array( 's', 'orderby', 'min_price', 'max_price', 'rating_filter' ) as $key ) {
            if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) { $args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); }
        }
        if ( isset( $args['s'] ) ) { $args['post_type'] = 'product'; }
        // Preserve Woo's layered attribute filters, but never arbitrary private IDs/redirects.
        foreach ( $_GET as $key => $value ) {
            if ( is_string( $key ) && preg_match( '/^(?:filter_|query_type_)[a-z0-9_-]+$/D', $key ) && is_string( $value ) ) {
                $args[ $key ] = sanitize_text_field( wp_unslash( $value ) );
            }
        }
        return $args;
    }
    public static function all_url() { return add_query_arg( array_merge( self::public_args(), array( 'petish_species' => 'all' ) ), self::archive_base() ); }
    public static function banner() {
        if ( ! self::is_catalogue() || is_product() || self::$layout_open ) { return; }
        $context = self::context();
        $pets = is_user_logged_in() ? Petish_Passport_Store::all_for_user( get_current_user_id() ) : array();
        $product_id = 0;
        $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
        if ( is_wp_error( $categories ) ) { $categories = array(); }
        $browse_args = array_merge( self::public_args(), array( 'petish_species' => $context['species'] ?: 'all' ) );
        $category_args = array_merge( self::public_args(), array( 'petish_species' => $context['species'] ?: 'all' ) );
        $current_category = is_tax( 'product_cat' ) ? get_queried_object_id() : 0;
        echo '<div class="pc-shop-layout">';
        include PETISH_PASSPORT_DIR . 'templates/catalogue-banner.php';
        echo '<div class="pc-shop-results">';
        self::$layout_open = true;
    }
    public static function archive_end() {
        if ( ! self::$layout_open ) { return; }
        echo '</div></div>';
        self::$layout_open = false;
    }
    public static function switch_pet() {
        if ( ! is_user_logged_in() ) { wp_safe_redirect( wc_get_page_permalink( 'myaccount' ), 303 ); exit; }
        if ( ! function_exists( 'wc_add_notice' ) ) { require_once WC_ABSPATH . 'includes/wc-notice-functions.php'; }
        if ( ! WC()->session ) { WC()->initialize_session(); }
        $nonce = isset( $_POST['petish_catalogue_nonce'] ) && is_string( $_POST['petish_catalogue_nonce'] ) ? wp_unslash( $_POST['petish_catalogue_nonce'] ) : '';
        $id = isset( $_POST['pet_id'] ) && is_string( $_POST['pet_id'] ) ? absint( $_POST['pet_id'] ) : 0;
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_verify_nonce( $nonce, 'petish_catalogue_active' ) ) {
            wc_add_notice( 'Your session could not be verified. Please try again.', 'error' );
        } else {
            $result = Petish_Passport_Store::set_active( $id, get_current_user_id() );
            wc_add_notice( is_wp_error( $result ) ? 'This Passport is not available in your account.' : 'Your active pet has been changed.', is_wp_error( $result ) ? 'error' : 'success' );
        }
        $product_id = isset( $_POST['product_id'] ) && is_string( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $return = $product_id && 'product' === get_post_type( $product_id ) && 'publish' === get_post_status( $product_id ) ? get_permalink( $product_id ) : wc_get_page_permalink( 'shop' );
        if ( ! $product_id && ! self::reviews_visible() ) { $return = add_query_arg( 'petish_species', 'all', $return ); }
        wp_safe_redirect( $return, 303 );
        exit;
    }
    public static function label( $product, $species ) {
        $record = $species ? Petish_Product_Review::record( $product, $species ) : array( 'status' => 'unreviewed' );
        if ( 'eligible' === $record['status'] ) { return 'Reviewed for ' . Petish_Product_Review::species()[ $species ]; }
        if ( 'excluded' === $record['status'] ) { return 'Excluded for ' . Petish_Product_Review::species()[ $species ]; }
        if ( ! $species ) {
            foreach ( Petish_Product_Review::species() as $key => $name ) {
                if ( 'unreviewed' !== Petish_Product_Review::record( $product, $key )['status'] ) { return 'Species review available'; }
            }
        }
        return 'Not yet assessed';
    }
    public static function card_label() {
        global $product;
        if ( ! $product || ! self::reviews_visible() ) { return; }
        echo '<p class="petish-review-label">' . esc_html( self::label( $product, self::is_catalogue() ? self::context()['species'] : '' ) ) . '</p>';
    }
    public static function single_review() {
        global $product;
        if ( ! $product || ! self::reviews_visible() ) { return; }
        $context = self::context();
        include PETISH_PASSPORT_DIR . 'templates/product-review-summary.php';
    }
    public static function products( $species, $page = 1, $limit = 8 ) {
        $args = array( 'status' => 'publish', 'type' => array( 'simple', 'variable' ), 'visibility' => 'catalog', 'paginate' => true, 'limit' => $limit, 'page' => $page, 'orderby' => 'ID', 'order' => 'DESC', 'petish_species' => $species );
        if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) { $args['stock_status'] = array( 'instock', 'onbackorder' ); }
        return wc_get_products( $args );
    }
    public static function collection() {
        if ( ! is_user_logged_in() ) { return; }
        $pet = Petish_Passport_Store::active( get_current_user_id() );
        $species = $pet && isset( Petish_Product_Review::species()[ $pet['species'] ] ) ? $pet['species'] : '';
        $page = isset( $_GET['collection_page'] ) && is_string( $_GET['collection_page'] ) ? max( 1, absint( $_GET['collection_page'] ) ) : 1;
        $results = $species ? self::products( $species, $page ) : null;
        include PETISH_PASSPORT_DIR . 'templates/account-collection.php';
    }
}
