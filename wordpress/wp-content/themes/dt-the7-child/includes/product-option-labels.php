<?php
/**
 * Customer-facing option labels for the audited supplier catalogue.
 * Native attribute keys, option values and DSers mappings are never changed.
 */
defined( 'ABSPATH' ) || exit;

final class Petish_Product_Option_Labels {
    /** Actual callback requests, including each nested batch sub-request. */
    private static $rest_requests = array();

    // Observed 19 October 2026. Fingerprints cover sorted, exact native options.
    private const MAP = array(
        147 => array( 'kind' => 'colour', 'options' => '98fb2eb93d182b101c7a65741e39cd0f68f13dd5477efd3cd2fca658d7adc2b2' ), // Cage-Mounted Hay Feeder
        152 => array( 'kind' => 'colour', 'options' => 'e546da54c36a34edf6ed0e0fc221120ca49ac41a71dfc625bd6718d3c8e53d52' ), // Round Ceramic Pet Bowl
        181 => array( 'kind' => 'colour', 'options' => 'd374c0ba009316a65f9f5fd7adca3cbd95c2e4a54d7e8943830764bd60a9e116' ), // Washable Fleece Cage Liner
        228 => array( 'kind' => 'option', 'options' => '73f39d0eb314108eb8d23154f01880016879bec85f8ff6ff0f1e6bdf58b3ca61' ), // Loofah Chew Toys — 2 Pieces
        242 => array( 'kind' => 'colour', 'options' => 'de62a175c1dde3442ddfcb098c79ef34caf4a40debbd6a330fae62a207ec0ed7' ), // Corner & Gap Cleaning Brush
        248 => array( 'kind' => 'size', 'options' => '1bdcaee85aab1a7ab9f3538267b5c0abe7690c3d8d7a43105c26b77c99cdbc62' ), // Sisal Activity Ball on Wooden Stand
        261 => array( 'kind' => 'size', 'options' => '9e1b86198656223a94539a3c1b470abe09ff0a334ba1b5693fd59fc1212c6ff5' ), // Wooden Playground Platform
        277 => array( 'kind' => 'size', 'options' => '483c392c2d1b5b7230c2ce9f8552e57ab7732a570c575d8883d1b5ac08cec592' ), // Wooden Exploration Tunnel
        291 => array( 'kind' => 'option', 'options' => '2a6dc1c2f5a0ecba21d384100ed704c35e5cbe01fbc650badcb78a6e7041e4ce' ), // Foldable Woven Hideaway
        301 => array( 'kind' => 'colour', 'options' => '964f7aaac62e840e31cdde489a01a5c9107cde082e9fa8d6647c129763e76c33' ), // Bath Box with Clear Lid
        334 => array( 'kind' => 'colour', 'options' => '2eb74b18d22c05bd5e50d13c89e58a6e8eb1586a428ba855ed7e5168f521f9f6' ), // Lidded Dry Supply Storage Container
        342 => array( 'kind' => 'option', 'options' => '5f29ec9e9a8ac15255dde16397c84b0dc005ecc89e7c2c00fd0e5171a171105d' ), // Stacking Ring Activity Toy
        344 => array( 'kind' => 'option', 'options' => '9dc1c74dfa286c45a174c0d001b36ab69213f64253cd524bd9df4803b4c89af6' ), // Arched Wooden Hideout
        347 => array( 'kind' => 'size', 'options' => '0be6b416809cac09e2990307e9c0547c48539713d91c2b51a04da9e66b1c326a' ), // Wooden Burrow Tunnel
        363 => array( 'kind' => 'size', 'options' => 'f4e27d0be3da0674381c722720c3da2b1ddc2605679ca4fec62b5f82985d57ea' ), // Wooden Platform and Ladder
        393 => array( 'kind' => 'option', 'options' => 'adda59c5cdc1a5105f40b90abd01cf406a8203bd6d692aec0040a8cf38f2ddbd' ), // Modular Folding Playpen
        394 => array( 'kind' => 'colour', 'options' => '2512d5a5aece6022da5e4bcb457e07cd6dcee8ce3bf41a2a79e9c458d2c2c7fb' ), // Habitat Drinking Bottle
        432 => array( 'kind' => 'option', 'options' => 'f09a11b75bc45e81d1b14de8cf9223da683b9fc0734c4b049af98f4eca5e85fc' ), // Peekaboo Wooden Hideout
        439 => array( 'kind' => 'colour', 'options' => '396a6651bf04be3b3092627274cec6a860ea8be0a3f3a8918d38ee709e2d62b9' ), // Bottle Cleaning Brush Set
        445 => array( 'kind' => 'colour', 'options' => 'fe7e7beeaa79456cdffbeeeabd9bfdcf649be6c12df35c78d61b1c59ffca5b3a' ), // Open Litter Tray
        449 => array( 'kind' => 'option', 'options' => 'd23678b3923d951c081fe163177e042dcc837e1db9f362b7a8bb7917ab366ef9' ), // Six-Room Hideout with Movable Roof
        491 => array( 'kind' => 'size', 'options' => '2ae6ac2344ba33000e6a4ec3cc3e1ddd64b0e86426da04968e7e01b8e9b438d8' ), // Bendable Wooden Bridge
        498 => array( 'kind' => 'option', 'options' => '35ffc55969463aa9a367c5d5a323b107abf3411c6537f0d970060438017cd7c8' ), // Wooden Activity Hideout
        521 => array( 'kind' => 'option', 'options' => '0a2b4ad995acc6f5a040c90e6daa08ca78405335b76027f3fd2889b714991a1a' ), // Wooden Hideout with Climbing Ladder
        552 => array( 'kind' => 'option', 'options' => '6d9abd71db934fd6005399786c2c5e9d5bdb05346854167d3e6bbe10895eba04' ), // Adjustable Habitat Platform
        622 => array( 'kind' => 'option', 'options' => '1e0c889852ddeaf7479b69a6a87064489a5f456f1de7f1641d2ce3e77211d13a' ), // Carrot Foraging Pocket Toy
        626 => array( 'kind' => 'option', 'options' => '0b3ca776eb21a35f49313867af9c03eeedd12b699fdfbc33be5534845e1c58c4' ), // Textured Chew Toys — 9 Pieces
        640 => array( 'kind' => 'colour', 'options' => '1d981c47aa5281fe0f9b9d1fbdd0167b2dc2ff49c1e1790f66a04f187633bc83' ), // Sand Sifting Scoop
        654 => array( 'kind' => 'option', 'options' => 'f20e57a10becca9661bfe1a8ad4c99e14e310c2d3bc464e8f15645ff572a6005' ), // Stainless Steel Litter Scoop
        677 => array( 'kind' => 'option', 'options' => '96c8c020152bc98b9b9622cf04eee1fe9dbb6a65d9951b93da1ae7ded0c6db0a' ), // Rotating Wooden Foraging Toy
        688 => array( 'kind' => 'option', 'options' => 'f5f2358111f458cd3334e75d327f7fbab0fc07644f0ffb40bc4fda25b57ef257' ), // Rainbow Stacking Cups — 8 Pieces
        707 => array( 'kind' => 'colour', 'options' => 'd16229621fa0fae9bc643a93d8d1fb291acd9c517f972ff2e3e06f2eeca636f3' ), // Treat-Dispensing Activity Ball
        719 => array( 'kind' => 'colour', 'options' => 'c32bae678c0571dcdd99497914ca40431e520b1724beb16aef3fbc69b418f783' ), // Digital Measuring Scoop
        730 => array( 'kind' => 'colour', 'options' => '290253f493adcf50c7485d15e7f6f900b7bb3f3b5d9d3d1ba4e294a60387b332' ), // Ceramic Resting Hide
        748 => array( 'kind' => 'option', 'options' => 'e693c818a4f5f695dafcae45af6ad5cf3107525d0ccdbf56b510645c4d8e27e8' ), // Long Wooden Bridge Ladder
        771 => array( 'kind' => 'colour', 'options' => '4b7e4ff2f31adc9abaf6e73db6336092c70cd5e5d0bb86356872e301a65b5b70' ), // Hanging Stainless Steel Feeding Cup
        788 => array( 'kind' => 'size', 'options' => '19f4f3d0cbc7ad1bab7d4ef4244cc988779bdf3459d1ccca0b0a43942b5df8fe' ), // Wooden Habitat Shelf
        789 => array( 'kind' => 'option', 'options' => 'd2a4b37cac0a57e42b1fe002a68c2449a7e41646eec1a92a0f0acd39fae589ce' ), // Magnetic Acrylic Bottle Holder
        804 => array( 'kind' => 'colour', 'options' => '6cf0ca44c8904d11b6b6de633019ecd8f99ce027e21c1eb12347f6e2d87392ab' ), // Wooden Chew Skewer
        810 => array( 'kind' => 'style', 'options' => '2822b80c655dc6e93da5ba783c280d47ef60e8b38248a4f2026b55d12db55936' ), // Foraging Snuffle Mat
        826 => array( 'kind' => 'colour', 'options' => '2eb74b18d22c05bd5e50d13c89e58a6e8eb1586a428ba855ed7e5168f521f9f6' ), // Digital Animal Weighing Scale
        844 => array( 'kind' => 'option', 'options' => 'b340df083d8945981b45405a7352a5b917a579908d5aed11a1898ecddc36cec9' ), // Wooden Foraging Puzzle Board
        847 => array( 'kind' => 'option', 'options' => '075a0b8fba0c16e96da340476d33499bd6de88c3aac3b4a2cf9bef33cc892767' ), // Double-Sided Grooming Brush
        875 => array( 'kind' => 'option', 'options' => 'a3e422ec695b5c99d38d909c7f41defdcd1b5ec6c5418cc11c7e8e387ccdd958' ), // Wooden Foraging Roller
        880 => array( 'kind' => 'colour', 'options' => '407dae035c460f6129018bffa1e1923a6900316cb8792310483d2aadaae60d2d' ), // Room Temperature & Humidity Monitor
        887 => array( 'kind' => 'colour', 'options' => 'd4ee5f50e8db187e4b761ba5933f743a55865e006528a7e426306a4293fb2d04' ), // Oval Silicone Feeding Mat
        922 => array( 'kind' => 'option', 'options' => 'e031cfc88718b7e013432b71a2ef51b512a9d4d8f84f387eb15a47e504c3b635' ), // Woven & Wooden Chew Toys
        923 => array( 'kind' => 'colour', 'options' => '2512d5a5aece6022da5e4bcb457e07cd6dcee8ce3bf41a2a79e9c458d2c2c7fb' ), // Drinking & Feeding Station
        945 => array( 'kind' => 'colour', 'options' => '3ac275abacd3889a435284148a9bee03c00b051ec62f77adb5a3a307cc0e678b' ), // Foldable Carrier Bag
        984 => array( 'kind' => 'option', 'options' => 'd23678b3923d951c081fe163177e042dcc837e1db9f362b7a8bb7917ab366ef9' ), // Rectangular Wooden Platform
        986 => array( 'kind' => 'size', 'options' => '64126e9b2dd6529a808461598bcaaece0e60564618563d921017068651e13247' ), // Handwoven Grass Tunnel
        991 => array( 'kind' => 'colour', 'options' => '3fdfae5a788d28b8e18e4283087363b75e8a1398a0bcf1b01decaed744651ecf' ), // Hanging Chew Toys — 3 Pieces
    );

    public static function enter_rest_request( $response, $handler, $request ) {
        if ( $request instanceof WP_REST_Request ) { self::$rest_requests[] = $request; }
        return $response;
    }

    public static function leave_rest_request( $response, $handler, $request ) {
        // An outer batch may catch a failed sub-request. Remove any abandoned
        // descendants as well, so its display context cannot leak afterwards.
        for ( $index = count( self::$rest_requests ) - 1; $index >= 0; $index-- ) {
            if ( self::$rest_requests[ $index ] === $request ) {
                array_splice( self::$rest_requests, $index );
                break;
            }
        }
        return $response;
    }

    private static function storefront_rest_context() {
        $request = end( self::$rest_requests );
        if ( ! $request instanceof WP_REST_Request ) { return false; }
        $route = $request->get_route();
        return is_string( $route ) && (bool) preg_match(
            '#^/wc/store/v1/(?:cart(?:/[a-zA-Z0-9_-]+)*|checkout(?:/[0-9]+)?|batch)/?$#D',
            $route
        );
    }

    public static function label( $label, $name, $context_product = false ) {
        $rest_context = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! empty( self::$rest_requests );
        $display_context = $rest_context ? self::storefront_rest_context()
            : ( is_product() || is_cart() || is_checkout() || is_account_page() );
        if ( is_admin() || wp_doing_cron() || ! $display_context
            || ! is_string( $name ) || 'color' !== sanitize_title( $name ) ) {
            return $label;
        }

        if ( ! $context_product ) {
            global $product;
            // Native dropdown templates omit the product argument. Never borrow
            // another product's context from a related-products loop.
            if ( ! is_product() || ! $product instanceof WC_Product
                || $product->get_id() !== get_queried_object_id() ) {
                return $label;
            }
            $context_product = $product;
        }
        if ( ! $context_product instanceof WC_Product ) {
            return $label;
        }
        if ( $context_product->is_type( 'variation' ) ) {
            $context_product = wc_get_product( $context_product->get_parent_id() );
        }
        if ( ! $context_product || ! $context_product->is_type( 'variable' ) ) {
            return $label;
        }

        $entry = self::MAP[ $context_product->get_id() ] ?? null;
        $attributes = $context_product->get_attributes();
        $attribute = $attributes['color'] ?? null;
        if ( ! $entry || ! $attribute instanceof WC_Product_Attribute
            || ! $attribute->get_variation() || $attribute->is_taxonomy() ) {
            return $label;
        }

        $options = array_map( 'strval', $attribute->get_options() );
        sort( $options, SORT_STRING );
        // Supplier option changes need another audit. Use a neutral label
        // rather than falsely carrying over a previous size/colour judgement.
        $kind = hash_equals( $entry['options'], hash( 'sha256', wp_json_encode( $options ) ) )
            ? $entry['kind'] : 'option';
        $labels = array(
            'size' => __( 'Size', 'petish' ),
            'colour' => __( 'Colour', 'petish' ),
            'style' => __( 'Style', 'petish' ),
            'option' => __( 'Option', 'petish' ),
        );
        return $labels[ $kind ];
    }
}

add_filter( 'rest_request_before_callbacks', array( 'Petish_Product_Option_Labels', 'enter_rest_request' ), PHP_INT_MIN, 3 );
add_filter( 'rest_request_after_callbacks', array( 'Petish_Product_Option_Labels', 'leave_rest_request' ), PHP_INT_MAX, 3 );
add_filter( 'woocommerce_attribute_label', array( 'Petish_Product_Option_Labels', 'label' ), 20, 3 );
