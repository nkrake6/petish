<?php
/** Owner-only native order tracking. Inclusion registers no hook and changes no data. */
defined('ABSPATH') || exit;

final class Petish_Shipment_Customer_View {
    const OPTION = 'petish_shipment_customer_tracking_enabled';
    const STYLE_HANDLE = 'petish-shipment-tracking';

    public static function init() {
        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'render'), 20);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_style'));
    }

    public static function enabled() { return get_option(self::OPTION, 'no') === 'yes'; }

    private static function id($value) {
        if (!is_int($value) && !is_string($value)) return false;
        $value = (string)$value;
        if (!preg_match('/\A[1-9][0-9]*\z/D', $value) || strlen($value) > strlen((string)PHP_INT_MAX)
            || (strlen($value) === strlen((string)PHP_INT_MAX) && strcmp($value, (string)PHP_INT_MAX) > 0)) return false;
        return (int)$value;
    }

    /** Scope the fresh native order lookup to the exact order the customer is viewing. */
    private static function owned_order() {
        if (!self::enabled() || is_admin() || !is_user_logged_in() || !class_exists('WC_Order') || !function_exists('wc_get_order')) return null;
        $user = get_current_user_id();
        if (!is_int($user) || $user < 1) return null;
        if (is_account_page() && is_wc_endpoint_url('view-order')) $id = self::id(get_query_var('view-order'));
        elseif (is_order_received_page() && is_wc_endpoint_url('order-received')) $id = self::id(get_query_var('order-received'));
        else return null;
        if ($id === false) return null;
        $order = wc_get_order($id);
        return $order instanceof WC_Order && $order->get_type() === 'shop_order' && $order->get_id() === $id
            && $order->get_customer_id('edit') === $user ? $order : null;
    }

    public static function enqueue_style() {
        try {
            if (!self::owned_order()) return;
            wp_enqueue_style(self::STYLE_HANDLE, plugin_dir_url(dirname(__FILE__)) . 'assets/shipment-tracking.css', array(), '0.1.0');
        } catch (Throwable $ignored) { /* No customer-facing diagnostics or fallback data. */ }
    }

    /** A second URL boundary keeps a malformed record from producing an unsafe link. */
    private static function tracking_url($value) {
        if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) return null;
        $decoded = $value;
        for ($i=0; $i<4; $i++) {
            $decoded = rawurldecode($decoded);
            if (preg_match('/[\x00-\x1f\x7f]/', $decoded)) return null;
        }
        $parts = wp_parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443) || !is_string($parts['host'] ?? null)
            || filter_var($parts['host'], FILTER_VALIDATE_IP)
            || !preg_match('/\A(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}\z/D', $parts['host'])
            || preg_match('/(?:\A|\.)(?:localhost|local|internal|invalid)\z/iD', $parts['host'])) return null;
        return $value;
    }

    /** Names come from saved order lines, never the current catalogue or supplier payload. */
    private static function parcels($order, $state) {
        $names = array();
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product || !is_int($item->get_id()) || $item->get_id() < 1) continue;
            $name = $item->get_name('edit');
            if (is_string($name) && strlen($name) <= 2000 && preg_match('//u', $name) === 1) $names[$item->get_id()] = $name;
        }
        $parcels = array();
        foreach ($state['parcels'] as $parcel) {
            $shipment = $parcel['shipment'] ?? null;
            if (!is_array($shipment) || !is_string($shipment['carrier'] ?? null) || !is_string($shipment['tracking_number'] ?? null)
                || !is_string($shipment['dispatched_utc'] ?? null) || !is_array($shipment['items'] ?? null) || !$shipment['items']) return null;
            $url = self::tracking_url($shipment['tracking_url'] ?? null);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $shipment['dispatched_utc'], new DateTimeZone('UTC'));
            if (!$url || !$date || $date->format('Y-m-d\TH:i:s\Z') !== $shipment['dispatched_utc']) return null;
            $items = array();
            foreach ($shipment['items'] as $id=>$quantity) {
                if (!is_int($id) || $id < 1 || !is_int($quantity) || $quantity < 1 || $quantity > 10000) return null;
                $items[] = array('name'=>$names[$id] ?? __('Order item', 'petish-fulfilment'), 'quantity'=>$quantity);
            }
            $parcels[] = array('carrier'=>$shipment['carrier'], 'tracking_number'=>$shipment['tracking_number'], 'url'=>$url,
                'dispatched_utc'=>$shipment['dispatched_utc'], 'date'=>$date->getTimestamp(), 'items'=>$items);
        }
        return $parcels;
    }

    public static function render($order) {
        try {
            if (!$order instanceof WC_Order || !is_int($order->get_id()) || $order->get_id() < 1) return;
            $native = self::owned_order();
            if (!$native || $native->get_id() !== $order->get_id() || !class_exists('Petish_Shipment_Ledger')) return;
            $state = Petish_Shipment_Ledger::read($native->get_id());
            if (is_wp_error($state) || !is_array($state) || !is_array($state['parcels'] ?? null) || !$state['parcels']) return;
            $parcels = self::parcels($native, $state);
            if (!$parcels) return;
            $coverage = Petish_Shipment_Ledger::coverage($native, $state['parcels'], $state['required_items'] ?? null);
            $heading = wp_unique_id('petish-shipment-heading-');
            $summary = $coverage === 'dispatched' ? __('All physical items have been dispatched.', 'petish-fulfilment')
                : ($coverage === 'partial' ? __('Part of your order has been dispatched. Some items are still awaiting a dispatch update.', 'petish-fulfilment')
                : __('Your recorded parcels are shown below. We are checking the remaining shipment details.', 'petish-fulfilment'));
            $html = '<section class="petish-shipment-tracking" aria-labelledby="' . esc_attr($heading) . '"><h2 id="' . esc_attr($heading) . '">'
                . esc_html__('Shipment updates', 'petish-fulfilment') . '</h2><p class="petish-shipment-tracking__summary">' . esc_html($summary) . '</p><p class="petish-shipment-tracking__help">'
                . esc_html__('These are dispatch records. Open the carrier link for the latest tracking updates.', 'petish-fulfilment') . '</p><div class="petish-shipment-tracking__parcels">';
            foreach ($parcels as $index=>$parcel) {
                $label = sprintf(__('Parcel %d', 'petish-fulfilment'), $index + 1);
                $card_heading = $heading . '-' . ($index + 1);
                $html .= '<article class="petish-shipment-tracking__parcel" aria-labelledby="' . esc_attr($card_heading) . '"><p class="petish-shipment-tracking__label">' . esc_html($label)
                    . '</p><h3 id="' . esc_attr($card_heading) . '">' . esc_html($parcel['carrier']) . '</h3><dl><dt>' . esc_html__('Tracking number', 'petish-fulfilment')
                    . '</dt><dd class="petish-shipment-tracking__number">' . esc_html($parcel['tracking_number']) . '</dd><dt>' . esc_html__('Dispatched', 'petish-fulfilment') . '</dt><dd><time datetime="'
                    . esc_attr($parcel['dispatched_utc']) . '">' . esc_html(wp_date(get_option('date_format', 'j M Y'), $parcel['date'], wp_timezone())) . '</time></dd></dl><ul class="petish-shipment-tracking__items">';
                foreach ($parcel['items'] as $item) $html .= '<li><span>' . esc_html($item['name']) . '</span><span class="petish-shipment-tracking__quantity">'
                    . esc_html(sprintf(__('Quantity: %d', 'petish-fulfilment'), $item['quantity'])) . '</span></li>';
                $html .= '</ul><a class="petish-shipment-tracking__link" href="' . esc_url($parcel['url'], array('https')) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Track parcel', 'petish-fulfilment') . '<span class="petish-shipment-tracking__sr-only"> — ' . esc_html($label . ', ' . $parcel['carrier']) . ' ('
                    . esc_html__('opens in a new tab', 'petish-fulfilment') . ')</span></a></article>';
            }
            echo $html . '</div></section>';
        } catch (Throwable $ignored) { /* Failed reads never disclose private ledger diagnostics. */ }
    }
}
