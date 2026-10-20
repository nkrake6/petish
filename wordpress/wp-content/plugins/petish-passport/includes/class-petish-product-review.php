<?php
/** Editorial product reviews. A species label alone never approves a product. */
defined( 'ABSPATH' ) || exit;

final class Petish_Product_Review {
    const PREFIX = '_petish_review_';
    private static $saving = array();
    private static $internal = false;
    private static $fields = array( 'status', 'source', 'date', 'due', 'limits', 'signature', 'reviewer', 'updated', 'valid', 'epoch' );

    public static function boot() {
        add_action( 'wp_after_insert_post', array( __CLASS__, 'post_changed' ), 10, 4 );
        add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ), 10, 2 );
        add_action( 'set_object_terms', array( __CLASS__, 'terms_changed' ), 10, 6 );
        add_action( 'deleted_term_relationships', array( __CLASS__, 'attribute_terms_removed' ), 10, 3 );
        add_action( 'edited_term', array( __CLASS__, 'attribute_term_edited' ), 10, 3 );
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_editor' ) );
        add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'before_save' ), 1 );
        add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'after_save' ), 100 );
        foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 4 );
        }
        add_filter( 'woocommerce_rest_pre_insert_product_object', array( __CLASS__, 'rest_guard' ), 10, 3 );
        add_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( __CLASS__, 'rest_guard' ), 10, 3 );
        add_filter( 'woocommerce_rest_prepare_product_object', array( __CLASS__, 'rest_hide' ), 10, 3 );
        add_filter( 'woocommerce_rest_prepare_product_variation_object', array( __CLASS__, 'rest_hide' ), 10, 3 );
        add_filter( 'woocommerce_duplicate_product_exclude_meta', array( __CLASS__, 'duplicate_keys' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_style' ) );
        add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'product_query' ), 10, 2 );
    }

    public static function species() {
        return array_intersect_key( Petish_Passport_Store::species(), array_flip( array( 'rabbit', 'hamster', 'guinea-pig', 'chinchilla' ) ) );
    }
    public static function key( $species, $field ) { return self::PREFIX . $species . '_' . $field; }
    public static function today() { return current_datetime()->format( 'Y-m-d' ); }
    public static function valid_date( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value ) ) { return false; }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
        return $date && $date->format( 'Y-m-d' ) === $value;
    }
    public static function epoch() { return hash_hmac( 'sha256', 'petish-review-epoch-v1', wp_salt( 'auth' ) ); }
    public static function source( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 2048 ) { return ''; }
        $url = esc_url_raw( $value, array( 'http', 'https' ) );
        $parts = wp_parse_url( $url );
        return $parts && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ? $url : '';
    }
    public static function normalise( $data ) {
        $result = array();
        foreach ( array( 'status', 'source', 'date', 'due', 'limits' ) as $field ) {
            $value = is_array( $data ) && isset( $data[ $field ] ) && is_string( $data[ $field ] ) ? $data[ $field ] : '';
            $result[ $field ] = 'limits' === $field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
        }
        if ( ! in_array( $result['status'], array( 'unreviewed', 'eligible', 'excluded' ), true ) ) { $result['status'] = 'unreviewed'; }
        $result['source'] = self::source( $result['source'] );
        $result['limits'] = wp_html_excerpt( $result['limits'], 1500, '' );
        return $result;
    }
    public static function complete( $record ) {
        return in_array( $record['status'], array( 'eligible', 'excluded' ), true )
            && self::source( $record['source'] ) === $record['source'] && '' !== $record['source']
            && self::valid_date( $record['date'] ) && self::valid_date( $record['due'] )
            && $record['date'] <= self::today() && $record['due'] >= self::today()
            && $record['due'] >= $record['date'] && '' !== trim( $record['limits'] );
    }
    private static function specification( $product ) {
        $attributes = array();
        foreach ( $product->get_attributes( 'edit' ) as $key => $attribute ) {
            if ( is_object( $attribute ) && method_exists( $attribute, 'get_data' ) ) {
                $data = $attribute->get_data();
                // Term IDs are sets; their read order can change after persistence.
                if ( $attribute->is_taxonomy() ) { $data['options'] = array_map( 'intval', $data['options'] ); sort( $data['options'], SORT_NUMERIC ); }
                $attributes[ $key ] = $data;
            } else { $attributes[ $key ] = $attribute; }
        }
        ksort( $attributes );
        $defaults = $product->get_default_attributes( 'edit' ); ksort( $defaults );
        return array( $product->get_id(), $product->get_type(), $product->get_sku( 'edit' ), $product->get_name( 'edit' ), $product->get_description( 'edit' ), $product->get_short_description( 'edit' ), $product->get_weight( 'edit' ), $product->get_length( 'edit' ), $product->get_width( 'edit' ), $product->get_height( 'edit' ), $attributes, $defaults );
    }
    private static function signature( $product, $record, $species ) {
        return hash_hmac( 'sha256', wp_json_encode( array( $species, self::specification( $product ), $record ) ), wp_salt( 'auth' ) );
    }
    public static function raw( $product, $species ) {
        $data = array();
        foreach ( array( 'status', 'source', 'date', 'due', 'limits' ) as $field ) {
            $data[ $field ] = get_post_meta( $product->get_id(), self::key( $species, $field ), true );
        }
        return self::normalise( $data );
    }
    public static function record( $product, $species, $require_flag = true ) {
        if ( ! $product || ! isset( self::species()[ $species ] ) || ! $product->is_type( array( 'simple', 'variable' ) ) ) { return array( 'status' => 'unreviewed' ); }
        $record = self::raw( $product, $species );
        $signature = get_post_meta( $product->get_id(), self::key( $species, 'signature' ), true );
        if ( ! self::complete( $record ) || ! is_string( $signature ) || ! hash_equals( self::signature( $product, $record, $species ), $signature )
            || ( $require_flag && '1' !== get_post_meta( $product->get_id(), self::key( $species, 'valid' ), true ) ) ) {
            return array( 'status' => 'unreviewed' );
        }
        return $record;
    }
    public static function clause( $species ) {
        if ( ! isset( self::species()[ $species ] ) ) { return array( array( 'key' => '_petish_no_such_review', 'value' => 'never' ) ); }
        return array(
            'relation' => 'AND',
            array( 'key' => self::key( $species, 'status' ), 'value' => 'eligible' ),
            array( 'key' => self::key( $species, 'valid' ), 'value' => '1' ),
            array( 'key' => self::key( $species, 'epoch' ), 'value' => self::epoch() ),
            array( 'key' => self::key( $species, 'signature' ), 'value' => '', 'compare' => '!=' ),
            array( 'key' => self::key( $species, 'date' ), 'value' => array( '1000-01-01', self::today() ), 'compare' => 'BETWEEN', 'type' => 'DATE' ),
            array( 'key' => self::key( $species, 'due' ), 'value' => self::today(), 'compare' => '>=', 'type' => 'DATE' ),
        );
    }
    public static function and_query( $existing, $required ) {
        return $existing ? array( 'relation' => 'AND', $existing, $required ) : $required;
    }
    public static function product_query( $args, $vars ) {
        if ( ! empty( $vars['petish_species'] ) ) {
            $species = is_string( $vars['petish_species'] ) ? $vars['petish_species'] : '';
            $args['meta_query'] = self::and_query( $args['meta_query'] ?? array(), self::clause( $species ) );
            $args['cache_results'] = false;
        }
        return $args;
    }

    public static function tab( $tabs ) {
        if ( current_user_can( 'manage_woocommerce' ) ) { $tabs['petish_reviews'] = array( 'label' => 'Petish reviews', 'target' => 'petish_review_data', 'priority' => 70 ); }
        return $tabs;
    }
    public static function panel() {
        global $product_object;
        if ( ! current_user_can( 'manage_woocommerce' ) || ! $product_object ) { return; }
        $product = $product_object;
        include PETISH_PASSPORT_DIR . 'templates/product-reviews-admin.php';
    }
    private static function error( $message ) {
        if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) { WC_Admin_Meta_Boxes::add_error( $message ); }
    }
    public static function save_editor( $product ) {
        $id = $product->get_id();
        if ( empty( $_POST['petish_review_marker'] ) ) { return; }
        $nonce = isset( $_POST['petish_review_nonce'] ) && is_string( $_POST['petish_review_nonce'] ) ? wp_unslash( $_POST['petish_review_nonce'] ) : '';
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $id ) || ! wp_verify_nonce( $nonce, 'petish_review_' . $id )
            || ! $product->is_type( array( 'simple', 'variable' ) ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) ) { return; }
        if ( empty( $_POST['petish_review_confirm'] ) || '1' !== $_POST['petish_review_confirm'] ) { return; }
        $input = isset( $_POST['petish_reviews'] ) && is_array( $_POST['petish_reviews'] ) ? wp_unslash( $_POST['petish_reviews'] ) : array();
        foreach ( self::species() as $species => $label ) {
            $record = self::normalise( $input[ $species ] ?? array() );
            if ( 'unreviewed' !== $record['status'] && ! self::complete( $record ) ) {
                $record['status'] = 'unreviewed';
                self::error( $label . ': review not activated. Add a valid source URL, review date, review-due date that has not passed and limitations.' );
            }
            foreach ( $record as $field => $value ) { $product->update_meta_data( self::key( $species, $field ), $value ); }
            $product->update_meta_data( self::key( $species, 'signature' ), self::complete( $record ) ? self::signature( $product, $record, $species ) : '' );
            $product->update_meta_data( self::key( $species, 'epoch' ), self::complete( $record ) ? self::epoch() : '' );
            $product->update_meta_data( self::key( $species, 'reviewer' ), get_current_user_id() );
            $product->update_meta_data( self::key( $species, 'updated' ), gmdate( 'c' ) );
        }
    }
    public static function before_save( $product ) {
        if ( ! $product->get_id() || $product->is_type( 'variation' ) ) { return; }
        self::$saving[ $product->get_id() ] = true;
        self::$internal = true;
        foreach ( self::species() as $species => $label ) { delete_post_meta( $product->get_id(), self::key( $species, 'valid' ) ); }
        self::$internal = false;
    }
    public static function after_save( $product ) {
        $id = $product->get_id();
        if ( $product->is_type( 'variation' ) ) { self::invalidate( $product->get_parent_id() ); return; }
        unset( self::$saving[ $id ] );
        self::reconcile( $product );
    }
    private static function reconcile( $product ) {
        if ( ! $product ) { return; }
        $id = $product->get_id();
        self::$internal = true;
        foreach ( self::species() as $species => $label ) {
            if ( 'unreviewed' !== self::record( $product, $species, false )['status'] ) { update_post_meta( $id, self::key( $species, 'valid' ), '1' ); }
            else {
                delete_post_meta( $id, self::key( $species, 'valid' ) );
                // An invalidated approval must never revive after a later unrelated save.
                delete_post_meta( $id, self::key( $species, 'signature' ) );
            }
        }
        self::$internal = false;
        clean_post_cache( $id );
        wc_delete_product_transients( $id );
    }
    public static function invalidate( $id ) {
        if ( ! $id ) { return; }
        self::$internal = true;
        foreach ( self::species() as $species => $label ) {
            delete_post_meta( $id, self::key( $species, 'valid' ) );
            delete_post_meta( $id, self::key( $species, 'signature' ) );
        }
        self::$internal = false;
        clean_post_cache( $id );
        wc_delete_product_transients( $id );
    }
    public static function meta_changed( $meta_id, $object_id, $key, $value ) {
        if ( self::$internal || isset( self::$saving[ $object_id ] ) || ! in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) { return; }
        $variation_attribute = 'product_variation' === get_post_type( $object_id ) && str_starts_with( $key, 'attribute_' );
        if ( ! $variation_attribute && ! str_starts_with( $key, self::PREFIX ) && ! in_array( $key, array( '_sku', '_product_attributes', '_default_attributes', '_weight', '_length', '_width', '_height' ), true ) ) { return; }
        if ( 'product_variation' === get_post_type( $object_id ) ) { self::invalidate( wp_get_post_parent_id( $object_id ) ); }
        else { self::invalidate( $object_id ); }
    }
    public static function post_changed( $id, $post, $update, $before ) {
        if ( isset( self::$saving[ $id ] ) ) { return; }
        if ( 'product_variation' === $post->post_type ) {
            self::invalidate( $post->post_parent );
            if ( $before && $before->post_parent !== $post->post_parent ) { self::invalidate( $before->post_parent ); }
        } elseif ( 'product' === $post->post_type && $before ) {
            foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
                if ( $post->$field !== $before->$field ) {
                    // Classic editor's outer post hook follows Woo's save. Preserve a
                    // freshly confirmed review only when its signed specification matches.
                    clean_post_cache( $id );
                    self::reconcile( wc_get_product( $id ) );
                    break;
                }
            }
        }
    }
    public static function post_deleted( $id, $post ) {
        if ( $post && 'product_variation' === $post->post_type ) { self::invalidate( $post->post_parent ); }
    }
    public static function terms_changed( $id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
        if ( ! isset( self::$saving[ $id ] ) && ( 'product_type' === $taxonomy || str_starts_with( $taxonomy, 'pa_' ) ) && $tt_ids !== $old_tt_ids ) { self::invalidate( $id ); }
    }
    public static function attribute_terms_removed( $id, $tt_ids, $taxonomy ) {
        if ( ! isset( self::$saving[ $id ] ) && str_starts_with( $taxonomy, 'pa_' ) ) { self::invalidate( $id ); }
    }
    public static function attribute_term_edited( $term_id, $tt_id, $taxonomy ) {
        if ( ! str_starts_with( $taxonomy, 'pa_' ) ) { return; }
        $ids = get_objects_in_term( $term_id, $taxonomy );
        if ( ! is_wp_error( $ids ) ) { foreach ( $ids as $id ) { self::invalidate( $id ); } }
    }
    public static function rest_guard( $product, $request, $creating ) {
        foreach ( (array) $request->get_param( 'meta_data' ) as $meta ) {
            if ( ! is_array( $meta ) ) { continue; }
            $key = $meta['key'] ?? '';
            $stored = ! empty( $meta['id'] ) ? get_metadata_by_mid( 'post', absint( $meta['id'] ) ) : false;
            if ( ( is_string( $key ) && str_starts_with( $key, self::PREFIX ) ) || ( $stored && str_starts_with( $stored->meta_key, self::PREFIX ) ) ) {
                return new WP_Error( 'petish_review_editor_required', 'Edit Petish reviews in the product review panel so validation and approval checks run.', array( 'status' => 400 ) );
            }
        }
        return $product;
    }
    public static function rest_hide( $response, $product, $request ) {
        $data = $response->get_data();
        if ( isset( $data['meta_data'] ) ) {
            $data['meta_data'] = array_values( array_filter( $data['meta_data'], static function( $row ) {
                $data = $row instanceof WC_Meta_Data ? $row->get_data() : (array) $row;
                return ! str_starts_with( $data['key'] ?? '', self::PREFIX );
            } ) );
            $response->set_data( $data );
        }
        return $response;
    }
    public static function duplicate_keys( $keys ) {
        foreach ( array_keys( self::species() ) as $species ) {
            foreach ( self::$fields as $field ) { $keys[] = self::key( $species, $field ); }
        }
        return array_unique( $keys );
    }
    public static function admin_style() {
        $screen = get_current_screen();
        if ( $screen && 'product' === $screen->post_type ) {
            wp_enqueue_style( 'petish-reviews-admin', PETISH_PASSPORT_URL . 'assets/reviews-admin.css', array(), PETISH_PASSPORT_VERSION );
        }
    }
}
