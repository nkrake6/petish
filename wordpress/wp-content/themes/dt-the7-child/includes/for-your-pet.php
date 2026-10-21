<?php
/** Private, read-only homepage picks; staff rules never substitute for species review. */
defined( 'ABSPATH' ) || exit;

final class Petish_For_Your_Pet {
    const PREFIX = '_petish_fyp_';
    const ENABLED = '_petish_fyp_enabled';
    const SPECIES = '_petish_fyp_species';
    const KEYWORDS = '_petish_fyp_keywords';
    const PAGE_SIZE = 50;
    const HISTORY_PAGES = 20;
    const PRODUCT_PAGES = 10;
    const MAX_ITEMS = 10000;

    public static function boot() {
        add_action( 'wp_ajax_petish_for_your_pet', array( __CLASS__, 'ajax' ) );
        add_action( 'wp_ajax_nopriv_petish_for_your_pet', array( __CLASS__, 'ajax' ) );
        add_action( 'init', array( __CLASS__, 'register_meta' ) );
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_editor' ) );
        add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'product_query' ), 20, 2 );
        foreach ( array( 'product', 'product_variation' ) as $type ) {
            add_filter( 'woocommerce_rest_pre_insert_' . $type . '_object', array( __CLASS__, 'rest_guard' ), 10, 3 );
            add_filter( 'woocommerce_rest_prepare_' . $type . '_object', array( __CLASS__, 'rest_hide' ), 10, 3 );
        }
        add_filter( 'woocommerce_duplicate_product_exclude_meta', array( __CLASS__, 'duplicate_keys' ) );
    }

    private static function species() {
        return class_exists( 'Petish_Product_Review' ) ? Petish_Product_Review::species() : array();
    }
    private static function failure() {
        return new WP_Error( 'petish_picks_unavailable', 'Your picks are temporarily unavailable.' );
    }
    private static function length( $text ) { return is_string( $text ) ? preg_match_all( '/./us', $text ) : false; }
    private static function words( $text ) {
        if ( ! is_string( $text ) || false === self::length( $text ) ) { return ''; }
        $text = preg_replace( '/\s+/u', ' ', str_replace( array( '’', '‘' ), "'", trim( $text ) ) );
        if ( function_exists( 'mb_convert_case' ) && defined( 'MB_CASE_FOLD' ) ) { return mb_convert_case( $text, MB_CASE_FOLD, 'UTF-8' ); }
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    }
    private static function phrase_in( $text, $phrase ) {
        return '' !== $phrase && 1 === preg_match( '/(?<![\p{L}\p{N}_])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}_])/iu', $text );
    }
    private static function negative( $text ) {
        return 1 === preg_match( "/(?<![\p{L}\p{N}_])(?:no|not|never|without|lack(?:s|ing)?|hardly|rarely|seldom|avoid(?:s|ed|ing)?|dislike(?:s|d)?|hate(?:s|d)?|afraid|scared|anxious|allerg(?:ic|y|ies)|cannot|(?:do|does|did|is|are|was|were|ca|wo|should|could|would|have|has|had|must|need)n'?t)(?![\p{L}\p{N}_])/iu", $text );
    }

    /** Existing traits match exactly. A negative sentence vetoes that phrase throughout the bio/tags. */
    public static function matches( $pet, $keywords ) {
        if ( ! is_array( $pet ) || ! is_array( $keywords ) || count( $keywords ) > 20 ) { return false; }
        $bio = $pet['bio'] ?? ''; $traits = $pet['traits'] ?? array();
        if ( ! is_string( $bio ) || self::length( $bio ) > 400 || false === self::length( $bio ) || ! is_array( $traits ) || count( $traits ) > 3 ) { return false; }
        $tags = array();
        foreach ( $traits as $trait ) {
            if ( ! is_string( $trait ) || false === self::length( $trait ) || self::length( $trait ) > 24 ) { return false; }
            $tags[] = self::words( $trait );
        }
        // Commas and "but" do not end negation: "doesn't like digging, chewing or running".
        $clauses = preg_split( '/[.!?;\r\n]+/u', $bio );
        foreach ( $keywords as $keyword ) {
            if ( ! is_string( $keyword ) || false === self::length( $keyword ) || self::length( $keyword ) > 60 ) { continue; }
            $phrase = self::words( $keyword ); $positive = false; $veto = false;
            foreach ( $tags as $tag ) {
                if ( '' !== $phrase && 1 === preg_match( '/\A' . preg_quote( $phrase, '/' ) . '\z/iu', $tag ) && ! self::negative( $tag ) ) { $positive = true; }
            }
            foreach ( $clauses as $clause ) {
                $clause = self::words( $clause );
                if ( self::phrase_in( $clause, $phrase ) ) {
                    if ( self::negative( $clause ) ) { $veto = true; } else { $positive = true; }
                }
            }
            if ( $positive && ! $veto ) { return true; }
        }
        return false;
    }

    /** Reject malformed saved rules instead of broadening the audience. */
    public static function rules( $product ) {
        $empty = array( 'enabled' => false, 'species' => array(), 'keywords' => array() );
        if ( ! $product instanceof WC_Product || '1' !== $product->get_meta( self::ENABLED, true, 'edit' ) ) { return $empty; }
        $species = $product->get_meta( self::SPECIES, true, 'edit' );
        $keywords = $product->get_meta( self::KEYWORDS, true, 'edit' );
        if ( ! is_array( $species ) || ! $species || count( $species ) > count( self::species() ) || ! is_array( $keywords ) || ! $keywords || count( $keywords ) > 20 ) { return $empty; }
        foreach ( $species as $slug ) { if ( ! is_string( $slug ) || ! isset( self::species()[ $slug ] ) ) { return $empty; } }
        foreach ( $keywords as $keyword ) {
            if ( ! is_string( $keyword ) || '' === trim( $keyword ) || false === self::length( $keyword ) || self::length( $keyword ) > 60 || sanitize_text_field( $keyword ) !== $keyword ) { return $empty; }
        }
        return array( 'enabled' => true, 'species' => array_values( array_unique( $species ) ), 'keywords' => array_values( array_unique( $keywords ) ) );
    }

    private static function current_user() {
        $id = get_current_user_id();
        if ( ! $id || '1' === (string) get_user_meta( $id, '_petish_club_deleting', true ) ) { return false; }
        $user = get_user_by( 'id', $id );
        return $user && (int) $user->ID === (int) $id ? $user : false;
    }
    private static function paid_statuses() {
        $statuses = array();
        foreach ( array_merge( (array) wc_get_is_paid_statuses(), array( 'refunded' ) ) as $status ) {
            if ( ! is_string( $status ) ) { continue; }
            $status = preg_replace( '/^wc-/', '', $status );
            if ( preg_match( '/^[a-z][a-z0-9-]{0,30}$/D', $status ) && ! in_array( $status, array( 'pending', 'failed', 'cancelled', 'checkout-draft' ), true ) ) { $statuses[] = $status; }
        }
        return array_values( array_unique( $statuses ) );
    }
    private static function page( $result, $key ) {
        if ( is_wp_error( $result ) || ! is_object( $result ) || ! isset( $result->$key, $result->total, $result->max_num_pages ) || ! is_array( $result->$key )
            || ! is_numeric( $result->total ) || (int) $result->total != $result->total || $result->total < 0
            || ! is_numeric( $result->max_num_pages ) || (int) $result->max_num_pages != $result->max_num_pages || $result->max_num_pages < 0 ) { return false; }
        return (int) $result->max_num_pages === (int) ceil( $result->total / self::PAGE_SIZE );
    }

    /** Woo CRUD supports both HPOS and legacy orders. No caller-supplied identity is accepted. */
    public static function purchase_history() {
        $user = self::current_user();
        if ( ! $user || ! function_exists( 'wc_get_orders' ) || ! is_email( $user->user_email ) ) { return self::failure(); }
        $statuses = self::paid_statuses(); $ids = array(); $item_count = 0;
        try {
            foreach ( array( false, true ) as $guest ) {
                $seen = array(); $expected_total = null; $expected_pages = null;
                $query = array( 'type' => 'shop_order', 'customer_id' => $guest ? 0 : (int) $user->ID, 'status' => $statuses,
                    'paginate' => true, 'limit' => self::PAGE_SIZE, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects' );
                if ( $guest ) { $query['billing_email'] = $user->user_email; }
                for ( $page = 1; $page <= self::HISTORY_PAGES; $page++ ) {
                    $result = wc_get_orders( $query + array( 'page' => $page ) );
                    if ( ! self::page( $result, 'orders' ) || $result->max_num_pages > self::HISTORY_PAGES ) { return self::failure(); }
                    if ( null === $expected_total ) { $expected_total = (int) $result->total; $expected_pages = (int) $result->max_num_pages; }
                    if ( $expected_total !== (int) $result->total || $expected_pages !== (int) $result->max_num_pages
                        || count( $result->orders ) !== min( self::PAGE_SIZE, max( 0, $expected_total - ( $page - 1 ) * self::PAGE_SIZE ) ) ) { return self::failure(); }
                    foreach ( $result->orders as $order ) {
                        if ( ! $order instanceof WC_Order || ! $order->get_id() || isset( $seen[ $order->get_id() ] ) ) { return self::failure(); }
                        $seen[ $order->get_id() ] = true;
                        // A datastore/plugin must not turn an email match into another member's history.
                        if ( $guest ? ( 0 !== (int) $order->get_customer_id() || 0 !== strcasecmp( $order->get_billing_email(), $user->user_email ) ) : ( (int) $user->ID !== (int) $order->get_customer_id() ) ) { return self::failure(); }
                        if ( ! in_array( $order->get_status(), $statuses, true ) ) { continue; }
                        foreach ( $order->get_items( 'line_item' ) as $item ) {
                            if ( ++$item_count > self::MAX_ITEMS || ! $item instanceof WC_Order_Item_Product ) { return self::failure(); }
                            foreach ( array( $item->get_product_id(), $item->get_variation_id() ) as $id ) {
                                $id = absint( $id ); if ( ! $id ) { continue; } $ids[ $id ] = true;
                                $product = wc_get_product( $id );
                                if ( is_wp_error( $product ) ) { return self::failure(); }
                                if ( $product instanceof WC_Product && $product->is_type( 'variation' ) && $product->get_parent_id() ) { $ids[ (int) $product->get_parent_id() ] = true; }
                            }
                        }
                    }
                    if ( $page >= $expected_pages ) { break; }
                }
                if ( count( $seen ) !== $expected_total ) { return self::failure(); }
                // Detect new/deleted orders while a multi-page history was being read.
                $check = wc_get_orders( $query + array( 'page' => 1 ) );
                if ( ! self::page( $check, 'orders' ) || (int) $check->total !== $expected_total || (int) $check->max_num_pages !== $expected_pages ) { return self::failure(); }
            }
        } catch ( Throwable $error ) { return self::failure(); }
        return array_map( 'intval', array_keys( $ids ) );
    }

    public static function product_query( $args, $vars ) {
        if ( true === ( $vars['petish_for_your_pet'] ?? false ) ) {
            $clause = array( array( 'key' => self::ENABLED, 'value' => '1' ) );
            $args['meta_query'] = empty( $args['meta_query'] ) ? $clause : array( 'relation' => 'AND', $args['meta_query'], $clause );
            $args['orderby'] = array( 'date' => 'DESC', 'ID' => 'DESC' );
            $args['cache_results'] = false;
        }
        return $args;
    }

    /** Only the signed-in owner's saved active Passport is read; Store::active() writes fallbacks. */
    public static function selection() {
        $result = array( 'state' => 'guest', 'products' => array(), 'species' => '' );
        if ( ! get_current_user_id() ) { return $result; }
        $result['state'] = 'unavailable'; $user = self::current_user();
        if ( ! $user || ! class_exists( 'Petish_Passport_Store' ) || ! class_exists( 'Petish_Product_Review' ) || ! function_exists( 'wc_get_products' ) ) { return $result; }
        $pet = Petish_Passport_Store::get( get_user_meta( $user->ID, Petish_Passport_Store::ACTIVE, true ), $user->ID );
        if ( is_wp_error( $pet ) || ! is_array( $pet ) ) { $result['state'] = 'no_pet'; return $result; }
        $species = $pet['species'] ?? ''; $result['state'] = 'empty';
        if ( ! is_string( $species ) || ! isset( self::species()[ $species ] ) ) { return $result; }
        $result['species'] = $species;
        $history = self::purchase_history();
        if ( is_wp_error( $history ) ) { $result['state'] = 'unavailable'; return $result; }
        $excluded = array_fill_keys( $history, true ); $selected = array(); $seen = array();
        try {
            for ( $page = 1; $page <= self::PRODUCT_PAGES; $page++ ) {
                $batch = wc_get_products( array( 'status' => 'publish', 'type' => array( 'simple', 'variable' ), 'visibility' => 'catalog', 'stock_status' => array( 'instock', 'onbackorder' ),
                    'paginate' => true, 'page' => $page, 'limit' => self::PAGE_SIZE, 'orderby' => 'date', 'order' => 'DESC',
                    'petish_for_your_pet' => true, 'petish_species' => $species, 'exclude' => $history ) );
                if ( ! self::page( $batch, 'products' ) ) { throw new RuntimeException( 'Incomplete product query.' ); }
                foreach ( $batch->products as $product ) {
                    if ( ! $product instanceof WC_Product ) { throw new RuntimeException( 'Invalid product query.' ); }
                    $id = $product->get_id();
                    if ( isset( $seen[ $id ] ) ) { continue; } $seen[ $id ] = true;
                    if ( isset( $excluded[ $id ] ) || ( $product->get_parent_id() && isset( $excluded[ $product->get_parent_id() ] ) )
                        || ! $product->is_type( array( 'simple', 'variable' ) ) || 'publish' !== $product->get_status() || ! $product->is_visible() || ! $product->is_purchasable() || ! $product->is_in_stock() ) { continue; }
                    $rules = self::rules( $product );
                    if ( ! $rules['enabled'] || ! in_array( $species, $rules['species'], true ) || ! self::matches( $pet, $rules['keywords'] )
                        || 'eligible' !== ( Petish_Product_Review::record( $product, $species )['status'] ?? '' ) ) { continue; }
                    $selected[] = $product;
                    if ( count( $selected ) >= 4 ) { break 2; }
                }
                if ( $page >= $batch->max_num_pages ) { break; }
            }
        } catch ( Throwable $error ) { $result['state'] = 'unavailable'; return $result; }
        $result['products'] = $selected; $result['state'] = $selected ? 'ready' : 'empty';
        return $result;
    }

    public static function render() {
        $selection = self::selection();
        $collection = class_exists( 'Petish_Catalogue' ) ? Petish_Catalogue::url() : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );
        if ( 'ready' !== $selection['state'] ) {
            $destination = 'no_pet' === $selection['state'] && class_exists( 'Petish_Passport_UI' ) ? Petish_Passport_UI::url() : $collection;
            $copy = array(
                'guest' => array( 'A little inspiration, just for your pet.', 'Sign in and choose your pet to see their latest picks.', 'Sign in' ),
                'no_pet' => array( 'Let’s get to know your little companion.', 'Choose your active pet in your account to discover their picks.', 'Choose your pet' ),
                'empty' => array( 'More little discoveries are on the way.', 'We’re building a collection to match your pet. Explore their collection in the meantime.', 'Explore your collection' ),
                'unavailable' => array( 'Your picks are taking a little pause.', 'Please try again shortly, or explore your pet’s collection.', 'Explore your collection' ),
            );
            $text = $copy[ $selection['state'] ];
            return '<div class="ph-collection-empty ph-for-pet-empty"><div class="ph-empty-copy"><h3>' . esc_html( $text[0] ) . '</h3><p>' . esc_html( $text[1] ) . '</p><div class="ph-actions"><a class="petish-button petish-button-secondary" href="' . esc_url( $destination ) . '">' . esc_html( $text[2] ) . '</a></div></div></div>';
        }
        $html = '<div class="ph-product-grid">';
        foreach ( $selection['products'] as $product ) {
            $name = $product->get_name(); $price = $product->get_price_html();
            $html .= '<article class="ph-product"><a class="ph-product-link" href="' . esc_url( $product->get_permalink() ) . '"><span class="ph-product-image">'
                . wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'decoding' => 'async' ) ) );
            if ( function_exists( 'petish_supplier_free_delivery_badge' ) ) { $html .= wp_kses_post( petish_supplier_free_delivery_badge( $product ) ); }
            $html .= '</span><div class="ph-product-body">';
            if ( class_exists( 'Petish_Catalogue' ) && Petish_Catalogue::reviews_visible() ) { $html .= '<span class="ph-product-review">' . esc_html( Petish_Catalogue::label( $product, $selection['species'] ) ) . '</span>'; }
            $html .= '<h3 title="' . esc_attr( $name ) . '">' . esc_html( $name ) . '</h3>';
            if ( $price ) { $html .= '<span class="ph-product-price">' . wp_kses_post( $price ) . '</span>'; }
            $html .= '<span class="ph-card-link">' . esc_html__( 'View product', 'petish' ) . '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14m-5-5 5 5-5 5"/></svg></span></div></a></article>';
        }
        return $html . '</div><div class="ph-for-pet-footer"><a class="ph-text-link" href="' . esc_url( $collection ) . '">' . esc_html__( 'Explore your collection', 'petish' ) . '</a></div>';
    }

    public static function ajax() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
        do_action( 'litespeed_control_set_nocache', 'Private Petish homepage picks' );
        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
        header( 'Vary: Cookie', false ); header( 'X-Content-Type-Options: nosniff', true );
        if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { header( 'Allow: GET', true ); wp_send_json_error( array( 'message' => 'Use GET to read your picks.' ), 405 ); return; }
        wp_send_json_success( array( 'html' => self::render() ) );
    }

    public static function register_meta() {
        foreach ( array( self::ENABLED => 'string', self::SPECIES => 'array', self::KEYWORDS => 'array' ) as $key => $type ) {
            register_post_meta( 'product', $key, array( 'type' => $type, 'single' => true, 'show_in_rest' => false, 'auth_callback' => array( __CLASS__, 'may_edit_meta' ) ) );
        }
    }
    public static function may_edit_meta( $allowed, $key, $id ) { return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_post', $id ); }
    public static function tab( $tabs ) {
        if ( current_user_can( 'manage_woocommerce' ) ) { $tabs['petish_for_your_pet'] = array( 'label' => 'For your pet', 'target' => 'petish_fyp_data', 'priority' => 75, 'class' => array( 'show_if_simple', 'show_if_variable' ) ); }
        return $tabs;
    }
    public static function panel() {
        global $product_object;
        if ( ! $product_object || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $product_object->get_id() ) ) { return; }
        $species = $product_object->get_meta( self::SPECIES, true, 'edit' ); $keywords = $product_object->get_meta( self::KEYWORDS, true, 'edit' );
        echo '<div id="petish_fyp_data" class="panel woocommerce_options_panel hidden"><input type="hidden" name="petish_fyp_marker" value="1">';
        wp_nonce_field( 'petish_fyp_' . $product_object->get_id(), 'petish_fyp_nonce' );
        echo '<p class="form-field"><label for="petish_fyp_enabled">For your pet picks</label><input type="checkbox" class="checkbox" id="petish_fyp_enabled" name="petish_fyp_enabled" value="1" ' . checked( '1', $product_object->get_meta( self::ENABLED, true, 'edit' ), false ) . '><span class="description">Enable this staff-curated match. A current eligible Petish species review is also required.</span></p>';
        echo '<fieldset class="form-field"><legend>Pet species</legend>';
        foreach ( self::species() as $slug => $label ) { echo '<label style="float:none;width:auto;margin-right:16px"><input type="checkbox" name="petish_fyp_species[]" value="' . esc_attr( $slug ) . '" ' . checked( true, is_array( $species ) && in_array( $slug, $species, true ), false ) . '> ' . esc_html( $label ) . '</label>'; }
        echo '</fieldset><p class="form-field"><label for="petish_fyp_keywords">Matching words or phrases</label><input type="text" id="petish_fyp_keywords" name="petish_fyp_keywords" class="long" maxlength="1239" value="' . esc_attr( is_array( $keywords ) ? implode( ', ', array_filter( $keywords, 'is_string' ) ) : '' ) . '"><span class="description">Up to 20 comma-separated words or phrases (60 characters each). Match existing personality tags exactly or whole phrases in the pet’s bio. List aliases separately, e.g. curious, inquisitive. Negative bio sentences are excluded. This does not approve a product review.</span></p></div>';
    }
    public static function save_editor( $product ) {
        if ( '1' !== ( $_POST['petish_fyp_marker'] ?? '' ) || ! $product instanceof WC_Product ) { return; }
        $id = $product->get_id(); $nonce = isset( $_POST['petish_fyp_nonce'] ) && is_string( $_POST['petish_fyp_nonce'] ) ? wp_unslash( $_POST['petish_fyp_nonce'] ) : '';
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $id ) || ! wp_verify_nonce( $nonce, 'petish_fyp_' . $id )
            || ! $product->is_type( array( 'simple', 'variable' ) ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) ) { return; }
        $enabled = '1' === ( $_POST['petish_fyp_enabled'] ?? '' );
        $species = isset( $_POST['petish_fyp_species'] ) && is_array( $_POST['petish_fyp_species'] ) ? wp_unslash( $_POST['petish_fyp_species'] ) : array();
        $input = isset( $_POST['petish_fyp_keywords'] ) && is_string( $_POST['petish_fyp_keywords'] ) ? wp_unslash( $_POST['petish_fyp_keywords'] ) : '';
        $valid = count( $species ) <= count( self::species() ) && strlen( $input ) <= 5000; $keywords = array();
        foreach ( $species as $slug ) { if ( ! is_string( $slug ) || ! isset( self::species()[ $slug ] ) ) { $valid = false; } }
        foreach ( explode( ',', $input ) as $keyword ) {
            $keyword = trim( sanitize_text_field( $keyword ) ); if ( '' === $keyword ) { continue; }
            if ( false === self::length( $keyword ) || self::length( $keyword ) > 60 ) { $valid = false; }
            $keywords[] = $keyword;
        }
        if ( count( $keywords ) > 20 || ( $enabled && ( ! $species || ! $keywords ) ) ) { $valid = false; }
        if ( ! $valid ) {
            $product->update_meta_data( self::ENABLED, '0' );
            if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) { WC_Admin_Meta_Boxes::add_error( 'For your pet matching was disabled. Choose valid species and up to 20 matching phrases of 60 characters each.' ); }
            return;
        }
        $product->update_meta_data( self::ENABLED, $enabled ? '1' : '0' );
        $product->update_meta_data( self::SPECIES, array_values( array_unique( $species ) ) );
        $product->update_meta_data( self::KEYWORDS, array_values( array_unique( $keywords ) ) );
    }
    public static function rest_guard( $product, $request, $creating ) {
        foreach ( (array) $request->get_param( 'meta_data' ) as $meta ) {
            if ( ! is_array( $meta ) ) { continue; }
            $key = $meta['key'] ?? ''; $stored = ! empty( $meta['id'] ) ? get_metadata_by_mid( 'post', absint( $meta['id'] ) ) : false;
            if ( ( is_string( $key ) && str_starts_with( $key, self::PREFIX ) ) || ( $stored && str_starts_with( $stored->meta_key, self::PREFIX ) ) ) {
                return new WP_Error( 'petish_picks_editor_required', 'Edit For your pet rules in the product editor.', array( 'status' => 400 ) );
            }
        }
        return $product;
    }
    public static function rest_hide( $response, $product, $request ) {
        $data = $response->get_data();
        if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
            $data['meta_data'] = array_values( array_filter( $data['meta_data'], static function ( $row ) {
                $data = $row instanceof WC_Meta_Data ? $row->get_data() : (array) $row;
                return ! is_string( $data['key'] ?? null ) || ! str_starts_with( $data['key'], self::PREFIX );
            } ) );
            $response->set_data( $data );
        }
        return $response;
    }
    public static function duplicate_keys( $keys ) { return array_values( array_unique( array_merge( $keys, array( self::ENABLED, self::SPECIES, self::KEYWORDS ) ) ) ); }
}
