<?php
/** Owner-only cancellation requests. No status, payment or supplier action occurs here. */
defined('ABSPATH') || exit;

final class Petish_Order_Cancellation_Customer {
    const POST_ACTION = 'petish_order_cancellation_request';
    const STYLE_HANDLE = 'petish-order-cancellation';
    const ANCHOR = 'cancel-request';

    public static function init() {
        add_filter('woocommerce_my_account_my_orders_actions', array(__CLASS__, 'order_actions'), 10, 2);
        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'render'), 30);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_style'));
        add_action('admin_post_' . self::POST_ACTION, array(__CLASS__, 'submit'));
    }

    public static function enabled() {
        return class_exists('Petish_Order_Cancellation_Requests') && Petish_Order_Cancellation_Requests::enabled();
    }

    private static function error($code) {
        return new WP_Error('petish_cancel_customer_' . $code, 'The cancellation request could not be submitted.');
    }

    private static function id($value) {
        if (!is_int($value) && !is_string($value)) return false;
        $value = (string)$value;
        if (!preg_match('/\A[1-9][0-9]*\z/D', $value) || strlen($value) > strlen((string)PHP_INT_MAX)
            || (strlen($value) === strlen((string)PHP_INT_MAX) && strcmp($value, (string)PHP_INT_MAX) > 0)) return false;
        return (int)$value;
    }

    public static function nonce_action($order_id) { return self::POST_ACTION . ':' . $order_id; }

    private static function owned_order($order_id) {
        if (!self::enabled() || !is_user_logged_in() || self::id($order_id) === false) return null;
        $order = Petish_Order_Cancellation_Requests::customer_order($order_id);
        return $order instanceof WC_Order && $order->get_type() === 'shop_order' && $order->get_id() === $order_id ? $order : null;
    }

    private static function page_order() {
        if (!self::enabled() || is_admin() || !is_user_logged_in() || !is_account_page() || !is_wc_endpoint_url('view-order')) return null;
        $id = self::id(get_query_var('view-order'));
        return $id === false ? null : self::owned_order($id);
    }

    private static function url($order) { return $order->get_view_order_url() . '#' . self::ANCHOR; }

    /** Replace the list action only; native payment/expiry handlers remain untouched. */
    public static function order_actions($actions, $order) {
        if (!is_array($actions) || !$order instanceof WC_Order || !self::enabled() || is_admin() || !is_user_logged_in() || !is_account_page()) return $actions;
        try {
            $native = self::owned_order($order->get_id());
            if (!$native) return $actions;
            $requests = Petish_Order_Cancellation_Requests::customer_requests($native->get_id());
            if (is_wp_error($requests) || !is_array($requests)) return $actions;
            if (!$requests && !Petish_Order_Cancellation_Requests::can_request($native)) return $actions;
            unset($actions['cancel']);
            $actions['petish-cancellation'] = array(
                'url'=>self::url($native),
                'name'=>$requests ? __('View cancellation request', 'petish-fulfilment') : __('Request cancellation', 'petish-fulfilment'),
                'aria-label'=>sprintf($requests ? __('View cancellation request for order %s', 'petish-fulfilment') : __('Request cancellation for order %s', 'petish-fulfilment'), $native->get_order_number()),
            );
        } catch (Throwable $ignored) { /* Keep the native account usable on a failed read. */ }
        return $actions;
    }

    public static function enqueue_style() {
        try {
            if (!self::page_order()) return;
            wp_enqueue_style(self::STYLE_HANDLE, plugin_dir_url(dirname(__FILE__)) . 'assets/order-cancellation.css', array(), '0.1.0');
        } catch (Throwable $ignored) { /* Do not expose private read diagnostics. */ }
    }

    /** Validation boundary shared by the POST endpoint and native verification. */
    public static function handle_request($post) {
        if (!self::enabled()) return self::error('disabled');
        if (!is_user_logged_in() || !is_array($post)) return self::error('permission');
        $order_id = self::id($post['order_id'] ?? null);
        if ($order_id === false) return self::error('order');
        $nonce = $post['_wpnonce'] ?? null;
        if (!is_string($nonce) || !wp_verify_nonce($nonce, self::nonce_action($order_id))) return self::error('nonce');
        $order = self::owned_order($order_id);
        if (!$order) return self::error('permission');
        if (($post['confirm_request'] ?? null) !== 'yes') return self::error('confirmation');
        $selected = $post['selected'] ?? null; $quantities = $post['quantities'] ?? null;
        if (!is_array($selected) || !array_is_list($selected) || !$selected || count($selected) > 100
            || !is_array($quantities) || count($quantities) > 100) return self::error('items');
        $items = array();
        foreach ($selected as $raw_id) {
            $id = self::id($raw_id);
            if ($id === false || isset($items[$id])) return self::error('items');
            $quantity = self::id($quantities[$id] ?? null);
            if ($quantity === false || $quantity > 10000) return self::error('items');
            $items[$id] = $quantity;
        }
        $reason = $post['reason'] ?? '';
        if (!is_string($reason) || strlen($reason) > 1000 || preg_match('//u', $reason) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)) return self::error('reason');
        return Petish_Order_Cancellation_Requests::create($order_id, $items, $reason);
    }

    private static function notice($result) {
        if (!is_wp_error($result)) return array('success', __('Your cancellation request has been recorded. You can follow its progress on this page.', 'petish-fulfilment'));
        $code = $result->get_error_code();
        if (str_ends_with($code, '_items')) return array('error', __('Choose at least one order item and a valid remaining quantity, then try again.', 'petish-fulfilment'));
        if (str_ends_with($code, '_confirmation')) return array('error', __('Confirm that you understand this is a cancellation request, then try again.', 'petish-fulfilment'));
        if (str_ends_with($code, '_reason')) return array('error', __('Keep the optional reason to 1,000 characters or fewer.', 'petish-fulfilment'));
        if (str_ends_with($code, '_active')) return array('notice', __('An open cancellation request already exists for this order. Its current status is shown below.', 'petish-fulfilment'));
        return array('error', __('The request could not be submitted. Refresh this order and try again, or contact orders@petish.biz with your order number.', 'petish-fulfilment'));
    }

    public static function submit() {
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Submit a cancellation request from your order page.', 'petish-fulfilment'), '', array('response'=>405));
            return;
        }
        $post = wp_unslash($_POST);
        $result = self::handle_request($post);
        list($type, $message) = self::notice($result);
        // WordPress admin-post is an admin request, so Woo may not have loaded
        // its frontend session. Use only the native session needed for the PRG
        // notice; do not load the cart or alter the customer's order.
        if (function_exists('WC') && WC() && !WC()->session && is_callable(array(WC(), 'initialize_session'))) WC()->initialize_session();
        wc_add_notice($message, $type);
        $id = is_array($post) ? self::id($post['order_id'] ?? null) : false;
        $order = $id === false ? null : self::owned_order($id);
        wp_safe_redirect($order ? self::url($order) : wc_get_account_endpoint_url('orders'), 303);
        exit;
    }

    private static function request_text() {
        if (class_exists('Petish_Cancellation_Policy')) return Petish_Cancellation_Policy::request_text();
        if (class_exists('Petish_Order_Cancellation_Emails') && is_callable(array('Petish_Order_Cancellation_Emails', 'request_text'))) return Petish_Order_Cancellation_Emails::request_text();
        return __('We will review whether the selected items can be cancelled. Where possible, we will stop unfulfilled items; if an order has been sent to a supplier, we will request cancellation. Cancellation is not guaranteed. Items already dispatched may need to follow our returns process.', 'petish-fulfilment');
    }

    private static function policy_text() {
        if (class_exists('Petish_Cancellation_Policy')) return Petish_Cancellation_Policy::fee_text();
        if (class_exists('Petish_Order_Cancellation_Emails') && is_callable(array('Petish_Order_Cancellation_Emails', 'policy_text'))) return Petish_Order_Cancellation_Emails::policy_text();
        return __('For voluntary change-of-mind cancellations, a payment-processing fee may be deducted where permitted. We will explain any proposed deduction and refund amount before processing. Your rights under Australian Consumer Law are not affected.', 'petish-fulfilment');
    }

    private static function state_label($state) {
        $labels = array('requested'=>'Request received', 'reviewing'=>'Under review', 'awaiting_supplier'=>'Awaiting supplier response',
            'approved'=>'Cancellation approved', 'partially_approved'=>'Part of your request approved', 'declined'=>'Unable to approve cancellation', 'closed'=>'Case closed');
        return isset($labels[$state]) ? __($labels[$state], 'petish-fulfilment') : __('Under review', 'petish-fulfilment');
    }

    private static function request_card($record) {
        $public = Petish_Order_Cancellation_Requests::public_snapshot($record);
        if (is_wp_error($public) || !is_array($public) || !is_array($public['items'] ?? null)) return '';
        $state = $public['state'] ?? 'requested';
        $html = '<article class="petish-order-cancellation__case"><div class="petish-order-cancellation__case-heading"><h3>' . esc_html__('Cancellation request', 'petish-fulfilment') . '</h3><span class="petish-order-cancellation__status">' . esc_html(self::state_label($state)) . '</span></div>';
        if (is_int($public['created_at'] ?? null) && $public['created_at'] > 0) $html .= '<p class="petish-order-cancellation__date">' . esc_html__('Requested', 'petish-fulfilment') . ' ' . esc_html(wp_date(get_option('date_format', 'j M Y'), $public['created_at'], wp_timezone())) . '</p>';
        $html .= '<ul class="petish-order-cancellation__items">';
        foreach ($public['items'] as $id=>$item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !is_int($item['quantity'] ?? null)) continue;
            $html .= '<li><span>' . esc_html($item['name']) . '</span><span>' . esc_html(sprintf(__('Requested quantity: %d', 'petish-fulfilment'), $item['quantity'])) . '</span></li>';
        }
        $html .= '</ul>';
        if (is_string($public['reason'] ?? null) && $public['reason'] !== '') $html .= '<p class="petish-order-cancellation__message"><strong>' . esc_html__('Your reason:', 'petish-fulfilment') . '</strong> ' . esc_html($public['reason']) . '</p>';
        if (is_string($public['public_message'] ?? null) && $public['public_message'] !== '') $html .= '<p class="petish-order-cancellation__message">' . esc_html($public['public_message']) . '</p>';
        if (in_array($state, array('approved','partially_approved','closed'), true) && is_array($public['accepted_items'] ?? null) && $public['accepted_items']) {
            $html .= '<p class="petish-order-cancellation__decision-title">' . esc_html__('Approved quantities', 'petish-fulfilment') . '</p><ul class="petish-order-cancellation__items">';
            foreach ($public['accepted_items'] as $id=>$quantity) if (is_int($quantity) && $quantity > 0 && is_string($public['items'][$id]['name'] ?? null)) $html .= '<li><span>' . esc_html($public['items'][$id]['name']) . '</span><span>' . esc_html(sprintf(__('Quantity: %d', 'petish-fulfilment'), $quantity)) . '</span></li>';
            $html .= '</ul><p class="petish-order-cancellation__help">' . esc_html__('Any approved refund is handled separately through the original payment method. Refer to the separate refund confirmation for payment details.', 'petish-fulfilment') . '</p>';
        }
        if (!in_array($state, array('approved','partially_approved','declined','closed'), true)) $html .= '<p class="petish-order-cancellation__help">' . esc_html__('This request is being reviewed. The order has not been cancelled or refunded by submitting it.', 'petish-fulfilment') . '</p>';
        return $html . '</article>';
    }

    public static function render($order) {
        try {
            if (!$order instanceof WC_Order) return;
            $native = self::page_order();
            if (!$native || $native->get_id() !== $order->get_id()) return;
            $requests = Petish_Order_Cancellation_Requests::customer_requests($native->get_id());
            if (is_wp_error($requests) || !is_array($requests)) return;
            $can_request = Petish_Order_Cancellation_Requests::can_request($native);
            if (!$requests && !$can_request) return;
            $active = false; $cards = '';
            foreach ($requests as $record) {
                if (($record['state'] ?? null) !== 'closed') $active = true;
                $cards .= self::request_card($record);
            }
            $html = '<section class="petish-order-cancellation" id="' . self::ANCHOR . '" aria-labelledby="petish-cancel-heading"><h2 id="petish-cancel-heading">' . esc_html__('Cancellation requests', 'petish-fulfilment') . '</h2>' . $cards;
            if ($active) $html .= '<p class="petish-order-cancellation__help">' . esc_html__('An open request already exists for this order. Please wait for its review, or contact orders@petish.biz with your order number if you need to change it.', 'petish-fulfilment') . '</p>';
            elseif ($can_request) {
                $items = Petish_Order_Cancellation_Requests::available_items($native);
                if (is_wp_error($items) || !is_array($items)) return;
                $html .= '<div class="petish-order-cancellation__panel"><h3>' . esc_html__('Request cancellation', 'petish-fulfilment') . '</h3><p>' . esc_html(self::request_text()) . '</p><p class="petish-order-cancellation__policy">' . esc_html(self::policy_text()) . '</p><p class="petish-order-cancellation__help"><a href="' . esc_url(home_url('/faqs/#change-of-mind')) . '">' . esc_html__('Read our cancellation and change-of-mind returns FAQ', 'petish-fulfilment') . '</a></p>';
                $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::POST_ACTION) . '"><input type="hidden" name="order_id" value="' . (int)$native->get_id() . '">';
                $html .= wp_nonce_field(self::nonce_action($native->get_id()), '_wpnonce', true, false);
                $html .= '<fieldset class="petish-order-cancellation__selection"><legend>' . esc_html__('Choose the items and quantities you want to cancel', 'petish-fulfilment') . '</legend>';
                foreach ($items as $id=>$item) {
                    if (!is_int($id) || $id < 1 || !is_array($item) || !is_int($item['available_quantity'] ?? null) || $item['available_quantity'] < 1 || !is_string($item['name'] ?? null)) continue;
                    $input = 'petish-cancel-item-' . $id;
                    $html .= '<div class="petish-order-cancellation__row"><label class="petish-order-cancellation__choice" for="' . esc_attr($input) . '"><input type="checkbox" id="' . esc_attr($input) . '" name="selected[]" value="' . (int)$id . '"><span>' . esc_html($item['name']) . '<small>' . esc_html(sprintf(__('Available to request: %d', 'petish-fulfilment'), $item['available_quantity'])) . '</small></span></label><label class="petish-order-cancellation__quantity" for="' . esc_attr($input . '-quantity') . '"><span>' . esc_html__('Quantity', 'petish-fulfilment') . '</span><input type="number" id="' . esc_attr($input . '-quantity') . '" name="quantities[' . (int)$id . ']" min="1" max="' . (int)$item['available_quantity'] . '" step="1" value="' . (int)$item['available_quantity'] . '" inputmode="numeric"></label></div>';
                }
                $html .= '</fieldset><label class="petish-order-cancellation__reason" for="petish-cancel-reason"><span>' . esc_html__('Reason (optional)', 'petish-fulfilment') . '</span><textarea id="petish-cancel-reason" name="reason" rows="3" maxlength="1000" placeholder="' . esc_attr__('Tell us what you would like us to review.', 'petish-fulfilment') . '"></textarea></label><label class="petish-order-cancellation__confirm"><input type="checkbox" name="confirm_request" value="yes" required><span>' . esc_html__('I understand this is a request and cancellation is subject to review.', 'petish-fulfilment') . '</span></label><button class="petish-order-cancellation__submit" type="submit">' . esc_html__('Send cancellation request', 'petish-fulfilment') . '</button></form></div>';
            }
            echo $html . '</section>';
        } catch (Throwable $ignored) { /* Failed reads never expose private case data. */ }
    }
}
