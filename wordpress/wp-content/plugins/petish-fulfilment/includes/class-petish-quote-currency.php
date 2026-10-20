<?php
defined('ABSPATH') || exit;

/** Quote records remain AUD; only the native shipping rate is converted. */
final class Petish_Quote_Currency {
    private static function error() { return new WP_Error('petish_shipping_currency', 'This checkout currency is not available for delivery quotes.'); }

    public static function context() {
        if ('AUD' !== get_option('woocommerce_currency')) return self::error();
        $code = get_woocommerce_currency();
        if ('AUD' === $code) return array('code'=>'AUD', 'rate'=>'1', 'decimals'=>2);
        // This version introduced the per-method exclusion that prevents a second conversion.
        if (!defined('WCPAY_VERSION_NUMBER') || version_compare(WCPAY_VERSION_NUMBER, '10.10.0', '<') || !function_exists('WC_Payments_Multi_Currency') || !class_exists('WCPay\\MultiCurrency\\MultiCurrency') || !WCPay\MultiCurrency\MultiCurrency::is_enabled()) return self::error();
        try {
            $multi = WC_Payments_Multi_Currency();
            $enabled = $multi->get_enabled_currencies();
            if (!preg_match('/^[A-Z]{3}$/D', $code) || !isset($enabled[$code]) || 'AUD' !== $multi->get_default_currency()->get_code() || $code !== $multi->get_selected_currency()->get_code()) return self::error();
            $rate = $multi->get_raw_conversion(1.0, $code, 'AUD');
            $format = WC_Payments::get_localization_service()->get_currency_format($code);
            $decimals = $format['num_decimals'] ?? null;
            if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate <= 0 || !is_numeric($decimals) || (int)$decimals != $decimals || (int)$decimals < 0 || (int)$decimals > 6) return self::error();
            return array('code'=>$code, 'rate'=>(string)$rate, 'decimals'=>(int)$decimals);
        } catch (Throwable $error) { return self::error(); }
    }

    /** No retail ceiling or charm pricing applies to an approved delivery amount. */
    public static function convert($amount, $context = null) {
        $context = null === $context ? self::context() : $context;
        if (is_wp_error($context) || !is_numeric($amount) || !is_finite((float)$amount) || (float)$amount < 0) return self::error();
        if ('AUD' === $context['code']) return (string)$amount;
        $value = (float)$amount * (float)$context['rate'];
        if (!is_finite($value)) return self::error();
        return number_format(round($value, $context['decimals'], PHP_ROUND_HALF_UP), $context['decimals'], '.', '');
    }

    public static function fingerprint($context = null) {
        $context = null === $context ? self::context() : $context;
        return is_wp_error($context) ? '' : hash('sha256', wp_json_encode($context));
    }

    public static function skip_native_shipping_conversion($convert, $method) {
        return self::converted_method($method) ? false : $convert;
    }

    private static function converted_method($method) {
        return $method instanceof Petish_Supplier_Shipping_Method || $method instanceof Petish_Automatic_Shipping_Method;
    }

    public static function rate_precision($args, $method) {
        if (self::converted_method($method)) {
            $context = self::context();
            if (!is_wp_error($context)) $args['price_decimals'] = 'AUD' === $context['code'] ? 6 : $context['decimals'];
        }
        return $args;
    }
}
