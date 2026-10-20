<?php
/** Whole-unit customer retail prices after native currency and destination tax. */
defined( 'ABSPATH' ) || exit;

final class Petish_Product_Retail_Rounding {
    const RULE = 'destination-unit-ceil-v2-raw-fx';
    const TAX_PROBE = 1000000.0;
    private static $calculating = false;
    private static $raw_inputs = array();

    public static function init() {
        foreach ( array( 'woocommerce_product_get_', 'woocommerce_product_variation_get_' ) as $prefix ) {
            foreach ( array( 'price', 'regular_price', 'sale_price' ) as $field ) {
                add_filter( $prefix . $field, array( __CLASS__, 'capture_input' ), 98, 2 );
                add_filter( $prefix . $field, array( __CLASS__, 'price' ), 110, 2 );
            }
        }
        add_filter( 'woocommerce_variation_prices', array( __CLASS__, 'variation_prices' ), 110, 3 );
        add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'prices_hash' ), 110, 3 );
        add_filter( 'woocommerce_variable_product_taxes_influence_price', array( __CLASS__, 'separate_display_cache' ), 110 );
    }

    /** Preserve the pre-conversion value, including earlier dynamic price filters. */
    public static function capture_input( $price, $product ) {
        if ( ! self::$calculating && $product instanceof WC_Product ) {
            $key = spl_object_id( $product ) . ':' . current_filter();
            unset( self::$raw_inputs[ $key ] );
            $context = self::context();
            if ( $context && is_numeric( $price ) ) {
                self::$raw_inputs[ $key ] = array( 'price' => $price, 'currency' => $context['code'] );
            }
        }
        return $price;
    }

    /** Follow WooPayments' server-rendered storefront context, including WC Ajax/Store API. */
    private static function context() {
        if ( is_admin() || defined( 'DOING_CRON' ) || 'AUD' !== get_option( 'woocommerce_currency' )
            || ! class_exists( 'WC_Payments_Features' ) || ! WC_Payments_Features::is_customer_multi_currency_enabled()
            || ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
            return null;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST
            && ( ! class_exists( '\WCPay\MultiCurrency\Utils' ) || ! \WCPay\MultiCurrency\Utils::is_store_api_request() ) ) {
            return null;
        }
        $multi = WC_Payments_Multi_Currency();
        if ( ! $multi || ! $multi->is_initialized() || 'speed' !== $multi->get_rendering_mode() ) {
            return null;
        }
        $currency = $multi->get_selected_currency();
        $code = $currency->get_code();
        $enabled = $multi->get_enabled_currencies();
        if ( ! preg_match( '/^[A-Z]{3}$/D', $code ) || ! isset( $enabled[ $code ] ) || get_woocommerce_currency() !== $code ) {
            return null;
        }
        return array( 'multi' => $multi, 'currency' => $currency, 'code' => $code );
    }

    /**
     * Native tax calculations without presentation rounding. Using a two-decimal
     * displayed price as the divisor would make quantity totals drift by cents.
     */
    private static function display_factor( $product ) {
        if ( ! $product->is_taxable() ) {
            return 1.0;
        }
        // Native tax calculation rounds at internal precision; a large probe
        // avoids magnifying a rounded tax-on-one into a false extra whole unit.
        $probe = self::TAX_PROBE;
        if ( 'incl' !== get_option( 'woocommerce_tax_display_shop' ) ) {
            return (float) wc_get_price_excluding_tax( $product, array( 'price' => $probe, 'qty' => 1 ) ) / $probe;
        }
        $customer = WC()->customer;
        $exempt = $customer && $customer->get_is_vat_exempt();
        $rates = WC_Tax::get_rates( $product->get_tax_class() );
        if ( ! wc_prices_include_tax() ) {
            return $exempt ? 1.0 : ( $probe + array_sum( WC_Tax::calc_tax( $probe, $rates, false ) ) ) / $probe;
        }
        $base_rates = WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
        $adjust = apply_filters( 'woocommerce_adjust_non_base_location_prices', true );
        if ( $exempt ) {
            $remove = $adjust ? $base_rates : $rates;
            return ( $probe - array_sum( WC_Tax::calc_tax( $probe, $remove, true ) ) ) / $probe;
        }
        if ( $adjust && $rates !== $base_rates ) {
            $net = $probe - array_sum( WC_Tax::calc_tax( $probe, $base_rates, true ) );
            return ( $net + array_sum( WC_Tax::calc_tax( $net, $rates, false ) ) ) / $probe;
        }
        return 1.0;
    }

    /** Replace native pre-tax ceiling with one destination ceiling; never save a property. */
    public static function price( $price, $product ) {
        $key = $product instanceof WC_Product ? spl_object_id( $product ) . ':' . current_filter() : '';
        $captured = self::$raw_inputs[ $key ] ?? null;
        unset( self::$raw_inputs[ $key ] );
        if ( self::$calculating || ! $product instanceof WC_Product || '' === $price || ! is_numeric( $price )
            || ! is_finite( (float) $price ) || (float) $price <= 0 ) {
            return $price;
        }
        $context = self::context();
        if ( ! $context || ! $captured || $captured['currency'] !== $context['code']
            || ! $context['multi']->get_compatibility()->should_convert_product_price( $product ) ) {
            return $price;
        }
        self::$calculating = true;
        try {
            $expected_native = $context['multi']->get_price( $captured['price'], 'product' );
            if ( abs( (float) $price - $expected_native ) > 0.0000001 ) {
                return $price; // A later third-party discount needs its own integration.
            }
            $converted = $context['multi']->get_raw_conversion( (float) $captured['price'], $context['code'], 'AUD' );
            // Explicit input avoids requesting/converting the product price again.
            $displayed = (float) wc_get_price_to_display( $product, array( 'price' => $converted, 'qty' => 1 ) );
            if ( ! is_finite( $displayed ) || $displayed <= 0 ) {
                return $price;
            }
            $factor = self::display_factor( $product );
            if ( ! is_finite( $factor ) || $factor <= 0 ) {
                return $price;
            }
            // Ceiling must precede minor-unit presentation rounding (especially JPY).
            $target = max( 1.0, ceil( $converted * $factor - 0.0000001 ) );
            $adjusted = $target / $factor;
            $check = (float) wc_get_price_to_display( $product, array( 'price' => $adjusted, 'qty' => 1 ) );
            if ( ! is_finite( $check ) || abs( $check - $target ) > 0.000001 ) {
                return $price; // A non-linear third-party price rule needs its own integration.
            }
            return wc_format_decimal( $adjusted, 12, true );
        } finally {
            self::$calculating = false;
        }
    }

    /**
     * WooPayments converts native range arrays after tax display, while getters
     * convert before tax display. Use the final getters so both routes agree.
     */
    public static function variation_prices( $prices, $parent, $for_display ) {
        if ( ! self::context() || ! $parent instanceof WC_Product_Variable || ! is_array( $prices ) ) {
            return $prices;
        }
        foreach ( array_keys( $prices['price'] ?? array() ) as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $parent->get_id() ) {
                continue;
            }
            $values = array(
                'price' => $variation->get_price(),
                'regular_price' => $variation->get_regular_price(),
                'sale_price' => $variation->get_sale_price(),
            );
            // Preserve WooCommerce's native non-sale/scheduled-sale array semantics.
            if ( $values['sale_price'] === $values['regular_price'] || $values['sale_price'] !== $values['price'] ) {
                $values['sale_price'] = $values['regular_price'];
            }
            foreach ( $values as $field => $value ) {
                if ( isset( $prices[ $field ] ) && array_key_exists( $variation_id, $prices[ $field ] ) ) {
                    $prices[ $field ][ $variation_id ] = '' !== $value && $for_display
                        ? (string) wc_get_price_to_display( $variation, array( 'price' => $value, 'qty' => 1 ) ) : $value;
                }
            }
        }
        foreach ( array( 'price', 'regular_price', 'sale_price' ) as $field ) {
            if ( isset( $prices[ $field ] ) && is_array( $prices[ $field ] ) ) {
                asort( $prices[ $field ], SORT_NUMERIC );
            }
        }
        return $prices;
    }

    public static function separate_display_cache( $separate ) {
        return self::context() ? true : $separate;
    }

    public static function prices_hash( $hash, $product, $for_display ) {
        $context = self::context();
        if ( ! $context ) {
            return $hash;
        }
        $customer = WC()->customer;
        $classes = array( $product->get_tax_class( 'unfiltered' ) );
        if ( $product instanceof WC_Product_Variable ) {
            foreach ( $product->get_children() as $child_id ) {
                $variation = wc_get_product( $child_id );
                if ( $variation instanceof WC_Product_Variation ) {
                    $classes[] = $variation->get_tax_class( 'unfiltered' );
                }
            }
        }
        $classes = array_unique( $classes );
        sort( $classes, SORT_STRING );
        $rates = array();
        foreach ( $classes as $class ) {
            $rates[ $class ] = array( 'base' => WC_Tax::get_base_tax_rates( $class ), 'destination' => WC_Tax::get_rates( $class ) );
        }
        $hash['petish_whole_retail'] = array(
            'rule' => self::RULE, 'currency' => $context['code'],
            'raw_fx' => $context['multi']->get_raw_conversion( 1.0, $context['code'], 'AUD' ),
            'rounding' => $context['currency']->get_rounding(), 'charm' => $context['currency']->get_charm(),
            'for_display' => (bool) $for_display,
            'taxable_address' => $customer ? $customer->get_taxable_address() : null,
            'vat_exempt' => $customer ? $customer->get_is_vat_exempt() : false,
            'tax_rates' => $rates, 'tax_enabled' => wc_tax_enabled(), 'prices_include_tax' => wc_prices_include_tax(),
            'shop_tax_display' => get_option( 'woocommerce_tax_display_shop' ),
            'cart_tax_display' => get_option( 'woocommerce_tax_display_cart' ),
            'tax_round_at_subtotal' => get_option( 'woocommerce_tax_round_at_subtotal' ),
            'adjust_non_base' => apply_filters( 'woocommerce_adjust_non_base_location_prices', true ),
        );
        return $hash;
    }
}

Petish_Product_Retail_Rounding::init();
