<?php
/** Keep native WooCommerce CSV intake out of the live catalogue until reviewed. */
defined( 'ABSPATH' ) || exit;
final class Petish_Supplier_Intake {
    public static function boot() {
        add_filter( 'woocommerce_product_import_pre_insert_product_object', array( __CLASS__, 'hold_csv' ), 100, 2 );
    }
    public static function hold_csv( $product, $data ) {
        $product->set_status( 'draft' );
        $product->update_meta_data( '_petish_intake_state', 'needs-review' );
        // CSV data cannot carry editorial approval from another product/store.
        foreach ( Petish_Product_Review::duplicate_keys( array() ) as $key ) { $product->delete_meta_data( $key ); }
        if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $parent->set_status( 'draft' );
                $parent->update_meta_data( '_petish_intake_state', 'needs-review' );
                $parent->save();
                Petish_Product_Review::invalidate( $parent->get_id() );
            }
        }
        return $product;
    }
}
