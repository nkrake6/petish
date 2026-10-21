<?php
/**
 * Header destination and currency choices, stored only in the Woo session.
 *
 * Public-page markup contains no personal state or nonce. The uncached native
 * Woo AJAX endpoint supplies both, so a cached header cannot mix shoppers.
 */

defined( 'ABSPATH' ) || exit;

final class Petish_Market_Selector {
    const CURRENCY_KEY = 'petish_market_currency';
    const NONCE_KEY = 'petish_market_nonce_token';
    // Reviewed local sprite coverage, independent of platform emoji fonts.
    const FLAG_CODES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW';

    public static function init() {
        add_action( 'presscore_render_header_element-search', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 45 );
        add_action( 'wc_ajax_petish_market_state', array( __CLASS__, 'state' ) );
        add_action( 'wc_ajax_petish_set_market', array( __CLASS__, 'save' ) );
        add_filter( 'wcpay_multi_currency_override_selected_currency', array( __CLASS__, 'currency_override' ), 20 );
        add_action( 'woocommerce_init', array( __CLASS__, 'refresh_currency_cache' ), 100 );
        // Store API loads the Woo session lazily, after woocommerce_init. Reset
        // before restoring cart prices so cached AUD cannot label USD totals.
        add_action( 'woocommerce_load_cart_from_session', array( __CLASS__, 'refresh_currency_cache' ), 0 );
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'refresh_currency_cache' ), 0 );
    }

    private static function multi_currency() {
        return function_exists( 'WC_Payments_Multi_Currency' ) ? WC_Payments_Multi_Currency() : null;
    }

    /** Read the native enabled list; this never activates a currency. */
    public static function currencies() {
        $service = self::multi_currency();
        $currencies = array();
        if ( $service && is_callable( array( $service, 'get_enabled_currencies' ) ) ) {
            foreach ( $service->get_enabled_currencies() as $code => $currency ) {
                $code = strtoupper( (string) $code );
                if ( preg_match( '/^[A-Z]{3}$/D', $code ) && is_object( $currency ) && is_callable( array( $currency, 'get_name' ) ) ) {
                    $currencies[ $code ] = wp_strip_all_tags( $currency->get_name() );
                }
            }
            return $currencies;
        }
        $code = strtoupper( (string) get_option( 'woocommerce_currency', 'AUD' ) );
        $names = get_woocommerce_currencies();
        if ( isset( $names[ $code ] ) ) {
            $currencies[ $code ] = $names[ $code ];
        }
        return $currencies;
    }

    /** Supported WooPayments override; deliberately avoids its user-meta setter. */
    public static function currency_override( $override ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return $override;
        }
        $code = WC()->session->get( self::CURRENCY_KEY );
        return is_string( $code ) && isset( self::currencies()[ $code ] ) ? $code : $override;
    }

    public static function refresh_currency_cache() {
        $service = self::multi_currency();
        if ( $service && WC()->session && WC()->session->get( self::CURRENCY_KEY ) ) {
            // The native frontend cache can be primed before Woo loads its session.
            $service->get_frontend_currencies()->selected_currency_changed();
        }
    }

    public static function assets() {
        if ( ! function_exists( 'WC' ) || is_admin() ) {
            return;
        }
        $path = get_stylesheet_directory();
        $url = get_stylesheet_directory_uri();
        wp_enqueue_style( 'petish-market-selector', $url . '/assets/css/market-selector.css', array( 'petish-design' ), filemtime( $path . '/assets/css/market-selector.css' ) );
        wp_enqueue_script( 'petish-market-selector', $url . '/assets/js/market-selector.js', array(), filemtime( $path . '/assets/js/market-selector.js' ), true );
        wp_localize_script( 'petish-market-selector', 'petishMarket', array(
            'stateUrl' => WC_AJAX::get_endpoint( 'petish_market_state' ),
            'saveUrl' => WC_AJAX::get_endpoint( 'petish_set_market' ),
            'flagSprite' => self::flag_sprite(),
            'flagCodes' => explode( ' ', self::FLAG_CODES ),
        ) );
    }

    /** The7 calls this directly after its search widget, including mobile copies. */
    public static function render() {
        if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
            return;
        }
        $base = wc_get_base_location();
        $code = (string) get_option( 'woocommerce_currency', 'AUD' );
        $classes = function_exists( 'presscore_get_mini_widget_class' )
            ? presscore_get_mini_widget_class( 'header-elements-search', 'petish-market-selector' )
            : array( 'petish-market-selector' );
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-petish-market>
            <button type="button" class="petish-market__trigger" aria-label="Location and currency" aria-expanded="false" aria-haspopup="dialog" disabled>
                <svg class="petish-market__flag" aria-hidden="true" focusable="false" width="22" height="22" viewBox="0 0 512 512"><use href="<?php echo esc_url( self::flag_sprite() . '#' . self::flag( $base['country'] ) ); ?>"/></svg>
                <span class="petish-market__code"><?php echo esc_html( $code ); ?></span>
                <svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12"><path d="m3 4.5 3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <section class="petish-market__panel" role="dialog" aria-label="Location and currency" hidden>
                <div class="petish-market__heading"><h2>Location &amp; currency</h2><button type="button" class="petish-market__close" aria-label="Close location and currency"><span aria-hidden="true">&times;</span></button></div>
                <form class="petish-market__form" method="post">
                    <label><span>Deliver to</span><select name="country" required autocomplete="shipping country"></select></label>
                    <label><span>Currency</span><select name="currency" required></select></label>
                    <p class="petish-market__help">Enter your delivery address at checkout to check available delivery options and charges.</p>
                    <p class="petish-market__status" role="status" aria-live="polite"></p>
                    <button type="submit" class="petish-market__apply">Apply</button>
                </form>
            </section>
            <noscript><a href="<?php echo esc_url( wc_get_cart_url() ); ?>">Delivery location</a></noscript>
        </div>
        <?php
    }

    private static function flag_sprite() {
        $asset = '/assets/images/ui/country-flags.svg';
        return get_stylesheet_directory_uri() . $asset . '?ver=' . filemtime( get_stylesheet_directory() . $asset );
    }

    public static function flag( $country ) {
        return is_string( $country ) && preg_match( '/^[A-Z]{2}$/D', $country )
            && false !== strpos( ' ' . self::FLAG_CODES . ' ', ' ' . $country . ' ' )
            ? 'flag-' . strtolower( $country ) : 'flag-globe';
    }

    private static function no_cache() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        header( 'Cache-Control: no-store, private, max-age=0' );
        header( 'Vary: Cookie', false );
        header( 'X-Content-Type-Options: nosniff' );
    }

    private static function same_origin() {
        if ( 'cross-site' === strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) ) ) {
            return false;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( ! is_string( $origin ) || '' === $origin ) {
            return '' === $origin;
        }
        $expected = wp_parse_url( home_url() );
        $actual = wp_parse_url( $origin );
        return is_array( $actual ) && isset( $actual['scheme'], $actual['host'] )
            && strtolower( $actual['scheme'] ) === strtolower( $expected['scheme'] )
            && strtolower( $actual['host'] ) === strtolower( $expected['host'] )
            && (int) ( $actual['port'] ?? ( 'https' === $actual['scheme'] ? 443 : 80 ) ) === (int) ( $expected['port'] ?? ( 'https' === $expected['scheme'] ? 443 : 80 ) );
    }

    private static function prepare_session() {
        if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
            return false;
        }
        if ( ! WC()->session || ! WC()->customer || ! WC()->cart ) {
            wc_load_cart();
        }
        return WC()->session && WC()->customer && WC()->cart;
    }

    private static function nonce_action() {
        $token = WC()->session->get( self::NONCE_KEY );
        return is_string( $token ) && 32 === strlen( $token ) ? 'petish_set_market_' . $token : '';
    }

    public static function state() {
        self::no_cache();
        if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::same_origin() || ! self::prepare_session() ) {
            wp_send_json_error( array( 'message' => 'Your location choices could not be loaded. Refresh and try again.' ), 400 );
        }
        if ( ! self::nonce_action() ) {
            WC()->session->set( self::NONCE_KEY, wp_generate_password( 32, false, false ) );
        }
        WC()->session->set_customer_session_cookie( true );
        $countries = WC()->countries->get_shipping_countries();
        $base = wc_get_base_location();
        $country = WC()->customer->get_shipping_country();
        if ( ! isset( $countries[ $country ] ) ) {
            $country = isset( $countries[ $base['country'] ] ) ? $base['country'] : (string) array_key_first( $countries );
        }
        $currencies = self::currencies();
        if ( ! $countries || ! $currencies ) {
            wp_send_json_error( array( 'message' => 'Location choices are temporarily unavailable. Please try again later.' ), 503 );
        }
        $service = self::multi_currency();
        $currency = $service ? $service->get_selected_currency()->get_code() : get_option( 'woocommerce_currency', 'AUD' );
        if ( ! isset( $currencies[ $currency ] ) ) {
            $currency = (string) array_key_first( $currencies );
        }
        wp_send_json_success( array(
            'country' => $country,
            'currency' => $currency,
            'countries' => $countries,
            'currencies' => $currencies,
            'nonce' => wp_create_nonce( self::nonce_action() ),
        ) );
    }

    /** Persist only the session-backed delivery object and session currency. */
    public static function apply_choices( $country, $currency ) {
        $countries = WC()->countries->get_shipping_countries();
        if ( ! is_string( $country ) || ! isset( $countries[ $country ] ) || ! is_string( $currency ) || ! isset( self::currencies()[ $currency ] ) ) {
            return new WP_Error( 'invalid_market', 'Choose an available delivery country and currency.' );
        }
        $customer = new WC_Customer( get_current_user_id(), true );
        $store = $customer->get_data_store();
        if ( ! $store || 'WC_Customer_Data_Store_Session' !== $store->get_current_class_name() || (int) $customer->get_id() !== get_current_user_id() ) {
            return new WP_Error( 'invalid_session', 'Your checkout session could not be updated safely. Refresh and try again.' );
        }
        if ( $country !== $customer->get_shipping_country() ) {
            $customer->set_shipping_country( $country );
            foreach ( array( 'state', 'postcode', 'city', 'address_1', 'address_2' ) as $field ) {
                $customer->{ 'set_shipping_' . $field }( '' );
            }
            $customer->set_calculated_shipping( false );
        }
        // Calling this exact native datastore avoids generic customer-save hooks
        // and cannot overwrite the account's saved billing or delivery address.
        $store->update( $customer );
        // Woo captured the original customer object when it registered shutdown.
        // Its stale snapshot must not overwrite the explicit session update.
        if ( WC()->customer instanceof WC_Customer ) {
            remove_action( 'shutdown', array( WC()->customer, 'save' ), 10 );
        }
        WC()->customer = $customer;
        WC()->session->set( self::CURRENCY_KEY, $currency );
        foreach ( array_keys( WC()->session->get_session_data() ) as $key ) {
            if ( 0 === strpos( $key, 'shipping_for_package_' ) ) {
                WC()->session->__unset( $key );
            }
        }
        WC()->shipping()->reset_shipping();
        $service = self::multi_currency();
        if ( $service ) {
            $service->get_frontend_currencies()->selected_currency_changed();
            $service->recalculate_cart();
        } else {
            WC()->cart->calculate_totals();
        }
        return true;
    }

    public static function save() {
        self::no_cache();
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::same_origin() || ! self::prepare_session() ) {
            wp_send_json_error( array( 'message' => 'Your choices could not be saved. Refresh and try again.' ), 400 );
        }
        $nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! self::nonce_action() || ! wp_verify_nonce( $nonce, self::nonce_action() ) ) {
            wp_send_json_error( array( 'message' => 'This form has expired. Refresh and try again.' ), 403 );
        }
        $country = isset( $_POST['country'] ) && is_string( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : '';
        $currency = isset( $_POST['currency'] ) && is_string( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';
        $result = self::apply_choices( $country, $currency );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        WC()->session->set_customer_session_cookie( true );
        // Complete persistence before the browser reloads, rather than relying
        // on a shutdown write racing the next personalized page request.
        WC()->session->save_data();
        wp_send_json_success( array( 'message' => 'Your location and currency have been updated.' ) );
    }
}

Petish_Market_Selector::init();
