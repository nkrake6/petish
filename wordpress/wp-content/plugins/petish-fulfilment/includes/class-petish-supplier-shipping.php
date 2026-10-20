<?php
defined('ABSPATH') || exit;

final class Petish_Supplier_Shipping_Method extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id = 'petish_supplier_shipping';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Tracked supplier delivery', 'petish-fulfilment');
        $this->method_description = __('Available only for the products, quantities and destinations covered by current reviewed quotes.', 'petish-fulfilment');
        $this->supports = array('shipping-zones', 'instance-settings', 'instance-settings-modal');
        $this->instance_form_fields = array(
            'enabled' => array('title' => __('Enable', 'petish-fulfilment'), 'type' => 'checkbox', 'label' => __('Enable this delivery method', 'petish-fulfilment'), 'default' => 'yes'),
            'title' => array('title' => __('Title', 'petish-fulfilment'), 'type' => 'text', 'default' => __('Tracked supplier delivery', 'petish-fulfilment')),
        );
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = trim(wp_strip_all_tags($this->get_option('title', __('Tracked supplier delivery', 'petish-fulfilment'))));
        if ('' === $this->title) $this->title = __('Tracked supplier delivery', 'petish-fulfilment');
        $this->tax_status = 'taxable';
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function calculate_shipping($package = array()) {
        if ('automatic' === get_option('petish_shipping_mode', 'quote')) return;
        if ('yes' !== $this->enabled || wc_string_to_bool(apply_filters('woocommerce_shipping_prices_include_tax', false))) return;
        $quote = Petish_Supplier_Quotes::quote_package($package);
        if (is_wp_error($quote)) return;
        $context = Petish_Quote_Currency::context();
        $cost = Petish_Quote_Currency::convert($quote['cost'], $context);
        if (is_wp_error($context) || is_wp_error($cost)) return;
        $this->add_rate(array(
            'id' => $this->get_rate_id(), 'label' => $this->title,
            'cost' => $cost, 'calc_tax' => 'per_order',
            'package' => $package, 'price_decimals' => 'AUD' === $context['code'] ? 6 : $context['decimals'],
            'meta_data' => array('_petish_quote_currency'=>$context['code'], '_petish_quote_fx_context'=>Petish_Quote_Currency::fingerprint($context)),
        ));
    }
}
