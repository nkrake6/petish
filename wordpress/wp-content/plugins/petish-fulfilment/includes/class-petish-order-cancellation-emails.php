<?php
/** Native transactional cancellation mail. Inclusion sends nothing and changes no settings. */
defined('ABSPATH') || exit;

final class Petish_Order_Cancellation_Emails {
    const OPTION = 'petish_cancellation_emails_enabled';
    const STAFF_EMAIL = 'orders@petish.biz';
    const LEASE_SECONDS = 300;
    const MAX_ATTEMPTS = 10;
    const MAX_BATCH = 20;

    public static function init() {
        add_action('petish_cancellation_request_event', array(__CLASS__, 'dispatch_pending'));
    }

    public static function enabled() {
        return class_exists('Petish_Order_Cancellation_Requests')
            && Petish_Order_Cancellation_Requests::enabled()
            && get_option(self::OPTION, 'no') === 'yes';
    }

    public static function policy_text() {
        return Petish_Cancellation_Policy::fee_text();
    }

    public static function request_text() {
        return Petish_Cancellation_Policy::request_text();
    }

    private static function error($code) {
        return new WP_Error('petish_cancellation_email_' . $code, 'The cancellation email operation could not be completed.');
    }

    private static function target($event_id, $audience) {
        return is_string($event_id) && preg_match('/\A[a-f0-9]{64}\z/D', $event_id) === 1
            && in_array($audience, array('customer', 'staff'), true);
    }

    private static function now($value) {
        return is_int($value) && $value > 0 ? $value : time();
    }

    private static function history(&$delivery, $status, $outcome, $now) {
        if (count($delivery['history']) >= 60) return false;
        $delivery['history'][] = array('status'=>$status, 'attempt_id'=>$delivery['attempt_id'], 'at'=>$now, 'outcome'=>$outcome);
        return true;
    }

    /** Validate a single native recipient; request fields never provide mail headers. */
    private static function recipient($record, $order, $audience) {
        if ($audience === 'staff') return self::STAFF_EMAIL;
        if (!$order instanceof WC_Order || (int)$order->get_id() !== $record['order_id']
            || (int)$order->get_customer_id('edit') !== $record['customer_id']) return self::error('owner_changed');
        $email = $order->get_billing_email('edit');
        if (!is_string($email) || $email !== trim($email) || strlen($email) > 254
            || preg_match('/[\x00-\x20\x7f]/', $email) || !is_email($email)) return self::error('recipient_invalid');
        return $email;
    }

    /** Staff-only presentation. This never creates a mail claim or invokes transport. */
    public static function preview($request_id, $event_id, $audience = 'customer') {
        if (!current_user_can('manage_woocommerce')) return self::error('permission');
        if (!self::target($event_id, $audience)) return self::error('input');
        $record = Petish_Order_Cancellation_Requests::get($request_id);
        if (is_wp_error($record)) return $record;
        if (!isset($record['events'][$event_id])) return self::error('event');
        return self::render($record, $record['events'][$event_id], $audience);
    }

    private static function state_label($state) {
        $labels = array(
            'requested'=>'Request received', 'reviewing'=>'Under review', 'awaiting_supplier'=>'Waiting for the supplier',
            'approved'=>'Cancellation approved', 'partially_approved'=>'Cancellation partly approved',
            'declined'=>'Cancellation could not be approved', 'closed'=>'Case closed',
        );
        return isset($labels[$state]) ? __($labels[$state], 'petish-fulfilment') : __('Cancellation update', 'petish-fulfilment');
    }

    /** Only the core's immutable, public event payload is used in customer messages. */
    private static function render($record, $event, $audience) {
        try {
            $payload = $event['payload'];
            if (!is_array($payload) || $payload['request_id'] !== $record['id'] || $payload['order_id'] !== $record['order_id']
                || !is_string($payload['order_number']) || $payload['order_number'] === '' || strlen($payload['order_number']) > 100
                || preg_match('/[\x00-\x1f\x7f]/', $payload['order_number']) || !is_array($payload['items']) || !$payload['items']) return self::error('render_failed');
            $reference = $payload['order_number'];
            $received = $event['kind'] === 'received';
            $label = self::state_label($payload['state']);
            $heading = $received ? __('We received your cancellation request', 'petish-fulfilment') : __('Your cancellation request update', 'petish-fulfilment');
            $subject = $audience === 'staff'
                ? ($received ? 'Action required: Petish cancellation request' : 'Petish cancellation case: ' . $label) . ' — order #' . $reference
                : 'Petish: ' . ($received ? 'cancellation request received' : $label) . ' — order #' . $reference;
            $body = '<p style="margin:0 0 18px;color:#526077;font-size:13px;">' . esc_html__('Order', 'petish-fulfilment')
                . ' <strong style="color:#14213D;">#' . esc_html($reference) . '</strong></p>';
            if ($audience === 'staff') {
                $heading = $received ? __('Cancellation request needs review', 'petish-fulfilment') : __('Cancellation case updated', 'petish-fulfilment');
                if (in_array($payload['state'],array('requested','reviewing','awaiting_supplier'),true)) {
                    $body .= '<p><strong>' . esc_html__('Hold supplier processing for the requested quantities while this request is reviewed.', 'petish-fulfilment') . '</strong></p>'
                        . '<p>' . esc_html__('This request has not automatically stopped DSers or the supplier. Check whether a supplier order exists, confirm which quantities can be cancelled, and process any approved refund through the original payment provider.', 'petish-fulfilment') . '</p>';
                } elseif ($payload['state'] === 'closed') {
                    $body .= '<p>' . esc_html__('This cancellation case has been closed. Review the recorded supplier and payment checks in the private case log.', 'petish-fulfilment') . '</p>';
                } else {
                    $body .= '<p>' . esc_html__('Review the saved decision and complete any outstanding supplier and refund actions. This message does not itself cancel a supplier order or return funds.', 'petish-fulfilment') . '</p>';
                }
            } elseif ($received) {
                $body .= '<p>' . esc_html__('We have recorded your cancellation request. Our team will check the items below and email you with the outcome.', 'petish-fulfilment') . '</p>'
                    . '<p><strong>' . esc_html__('Your order has not yet been confirmed cancelled.', 'petish-fulfilment') . '</strong></p>';
            } else {
                $body .= '<p><strong>' . esc_html($label) . '</strong></p>';
                if (in_array($payload['state'], array('approved','partially_approved'), true)) {
                    $body .= '<p>' . esc_html__('The approved quantities are shown below. Approval of this request does not itself return a payment. Any approved refund will be handled through your original payment method, with a separate refund confirmation.', 'petish-fulfilment') . '</p>';
                } elseif ($payload['state'] === 'awaiting_supplier') {
                    $body .= '<p>' . esc_html__('We are checking cancellation with the supplier. We will update you when the outcome is known.', 'petish-fulfilment') . '</p>';
                } elseif ($payload['state'] === 'closed') {
                    $body .= '<p>' . esc_html__('Our review of this request is complete. Please refer to the decision below and any separate payment-provider refund confirmation for payment details.', 'petish-fulfilment') . '</p>';
                }
            }
            $body .= '<table width="100%" cellspacing="0" cellpadding="0" style="width:100%;margin:20px 0;border-collapse:collapse;border:1px solid #E6DFF0;"><thead><tr>'
                . '<th scope="col" style="padding:12px;text-align:left;background:#F5F0FF;">' . esc_html__('Requested item', 'petish-fulfilment') . '</th>'
                . '<th scope="col" style="padding:12px;text-align:left;background:#F5F0FF;">' . esc_html__('Quantity', 'petish-fulfilment') . '</th>';
            $has_decision = in_array($payload['state'], array('approved','partially_approved','declined','closed'), true);
            if ($has_decision) $body .= '<th scope="col" style="padding:12px;text-align:left;background:#F5F0FF;">' . esc_html__('Approved', 'petish-fulfilment') . '</th>';
            $body .= '</tr></thead><tbody>';
            foreach ($payload['items'] as $line_id=>$item) {
                if (!is_array($item) || !is_string($item['name']) || !is_int($item['quantity']) || $item['quantity'] < 1) return self::error('render_failed');
                $body .= '<tr><td style="padding:12px;border-top:1px solid #E6DFF0;overflow-wrap:anywhere;">' . esc_html($item['name']) . '</td>'
                    . '<td style="padding:12px;border-top:1px solid #E6DFF0;">' . (int)$item['quantity'] . '</td>';
                if ($has_decision) $body .= '<td style="padding:12px;border-top:1px solid #E6DFF0;">' . (int)($payload['accepted_items'][$line_id] ?? 0) . '</td>';
                $body .= '</tr>';
            }
            $body .= '</tbody></table>';
            if (is_string($payload['public_message'] ?? null) && $payload['public_message'] !== '') {
                $body .= '<p>' . nl2br(esc_html($payload['public_message']), false) . '</p>';
            }
            if ($audience === 'staff' && is_string($payload['reason'] ?? null) && $payload['reason'] !== '') {
                $body .= '<p><strong>' . esc_html__('Customer reason:', 'petish-fulfilment') . '</strong><br>' . nl2br(esc_html($payload['reason']), false) . '</p>';
            }
            if ($audience === 'customer') {
                if (in_array($payload['state'],array('requested','reviewing','awaiting_supplier'),true)) $body .= '<p>' . esc_html(self::request_text()) . '</p>';
                $body .= '<p style="font-size:13px;color:#526077;">' . esc_html(self::policy_text()) . '</p>';
                $url = wc_get_endpoint_url('view-order', $record['order_id'], wc_get_page_permalink('myaccount'));
                $button = __('View your order and request', 'petish-fulfilment');
            } else {
                $url = admin_url('admin.php?page=petish-cancellation-requests&case_id=' . $record['id']);
                $button = __('Review cancellation request', 'petish-fulfilment');
            }
            $body .= '<p style="margin:24px 0 8px;"><a class="button" href="' . esc_url($url) . '" style="display:inline-block;padding:13px 20px;background:#6532A1;color:#FFFFFF;border-radius:8px;font-weight:700;text-decoration:none;">'
                . esc_html($button) . '</a></p>';
            if ($audience === 'customer') {
                $faq = Petish_Cancellation_Policy::cancellation_faq_html();
                if (!is_string($faq) || $faq === '') return self::error('render_failed');
                $body .= $faq;
            }
            $mailer = WC()->mailer();
            if (!$mailer || !is_callable(array($mailer,'wrap_message'))) return self::error('render_failed');
            $html = $mailer->wrap_message($heading, $body);
            if (!is_string($html) || $html === '') return self::error('render_failed');
            if (function_exists('petish_email_branding_styles')) {
                $css = petish_email_branding_styles('');
                if (!is_string($css)) return self::error('render_failed');
                $style = '<style type="text/css">' . $css . '</style>';
                $html = stripos($html,'</head>') !== false ? str_ireplace('</head>', $style . '</head>', $html) : $style . $html;
            }
            return array('subject'=>$subject, 'html'=>$html);
        } catch (Throwable $ignored) {
            return self::error('render_failed');
        }
    }

    /** Explicitly reconcile an abandoned claim; an expired lease never authorizes a resend. */
    public static function reconcile($request_id, $now = null) {
        if (!Petish_Order_Cancellation_Requests::enabled()) return self::error('disabled');
        return Petish_Order_Cancellation_Requests::locked($request_id, static function($record,$order,$time) {
            foreach ($record['events'] as &$event) {
                foreach ($event['deliveries'] as &$delivery) {
                    if ($delivery['status'] === 'sending' && is_int($delivery['claimed_at']) && $delivery['claimed_at'] + self::LEASE_SECONDS <= $time) {
                        if (!self::history($delivery, 'uncertain', 'lease_expired', $time)) return self::error('attempt_limit');
                        $delivery['status'] = 'uncertain';
                        $delivery['finished_at'] = $time;
                    }
                }
                unset($delivery);
            }
            unset($event);
            return array('record'=>$record, 'result'=>$record);
        }, self::now($now));
    }

    /** Hook target and controlled pending recovery. Only pending slots are submitted. */
    public static function dispatch_pending($request_id, $now = null) {
        if (!self::enabled()) return self::error('disabled');
        $record = self::reconcile($request_id, $now);
        if (is_wp_error($record)) return $record;
        $outcomes = array();
        foreach ($record['events'] as $event_id=>$event) {
            foreach (array('customer','staff') as $audience) {
                if (count($outcomes) >= self::MAX_BATCH) break 2;
                if (($event['deliveries'][$audience]['status'] ?? null) !== 'pending') continue;
                $outcomes[] = self::send($request_id, $event_id, $audience, $now);
            }
        }
        return $outcomes;
    }

    private static function send($request_id, $event_id, $audience, $now) {
        if (!self::enabled() || !self::target($event_id, $audience)) return self::error('disabled');
        $claim = Petish_Order_Cancellation_Requests::locked($request_id, static function($record,$order,$time) use($event_id,$audience) {
            if (!isset($record['events'][$event_id]['deliveries'][$audience])) return self::error('event');
            $delivery = &$record['events'][$event_id]['deliveries'][$audience];
            if ($delivery['status'] !== 'pending') return self::error('status');
            if ($delivery['attempts'] >= self::MAX_ATTEMPTS || count($delivery['history']) >= 57) return self::error('attempt_limit');
            $delivery['status'] = 'sending';
            $delivery['attempts']++;
            $delivery['attempt_id'] = bin2hex(random_bytes(32));
            $delivery['claimed_at'] = $time;
            $delivery['finished_at'] = null;
            self::history($delivery, 'sending', 'claimed', $time);
            return array('record'=>$record, 'result'=>array('record'=>$record, 'event'=>$record['events'][$event_id],
                'recipient'=>self::recipient($record,$order,$audience), 'attempt_id'=>$delivery['attempt_id']));
        }, self::now($now));
        if (is_wp_error($claim)) return $claim;

        $status = 'not_sent'; $outcome = 'render_failed';
        if (is_wp_error($claim['recipient'])) {
            $outcome = $claim['recipient']->get_error_code() === 'petish_cancellation_email_owner_changed' ? 'owner_changed' : 'recipient_invalid';
        } elseif (!self::enabled()) {
            $outcome = 'sending_disabled';
        } else {
            $preview = self::render($claim['record'], $claim['event'], $audience);
            if (!is_wp_error($preview)) {
                if (!self::enabled()) {
                    $outcome = 'sending_disabled';
                } else {
                    try {
                        $accepted = wp_mail($claim['recipient'], $preview['subject'], $preview['html'], array('Content-Type: text/html; charset=UTF-8'));
                        $status = $accepted === true ? 'submitted' : 'uncertain';
                        $outcome = $accepted === true ? 'accepted' : 'ambiguous';
                    } catch (Throwable $ignored) {
                        $status = 'uncertain'; $outcome = 'ambiguous';
                    }
                }
            }
        }
        return Petish_Order_Cancellation_Requests::locked($request_id, static function($record,$order,$time) use($event_id,$audience,$claim,$status,$outcome) {
            $delivery = &$record['events'][$event_id]['deliveries'][$audience];
            if (!in_array($delivery['status'],array('sending','uncertain'),true) || $delivery['attempt_id'] !== $claim['attempt_id']) return self::error('changed');
            if (!self::history($delivery,$status,$outcome,$time)) return self::error('attempt_limit');
            $delivery['status'] = $status;
            $delivery['finished_at'] = $time;
            return array('record'=>$record, 'result'=>array('event_id'=>$event_id, 'audience'=>$audience, 'status'=>$status));
        }, self::now($now));
    }

    public static function delivery_summary($request_id) {
        if (!current_user_can('manage_woocommerce')) return self::error('permission');
        $record = Petish_Order_Cancellation_Requests::get($request_id);
        if (is_wp_error($record)) return $record;
        $summary = array();
        foreach ($record['events'] as $id=>$event) {
            $summary[$id] = array('kind'=>$event['kind'], 'created_at'=>$event['created_at'], 'deliveries'=>$event['deliveries']);
        }
        return $summary;
    }

    /** A safe pre-transport failure can be queued again; this does not send mail. */
    public static function queue_retry($request_id,$event_id,$audience,$now=null) {
        if (!current_user_can('manage_woocommerce')) return self::error('permission');
        if (!Petish_Order_Cancellation_Requests::enabled()) return self::error('disabled');
        if (!self::target($event_id,$audience)) return self::error('input');
        return Petish_Order_Cancellation_Requests::locked($request_id, static function($record,$order,$time) use($event_id,$audience) {
            if (!isset($record['events'][$event_id]['deliveries'][$audience])) return self::error('event');
            $delivery = &$record['events'][$event_id]['deliveries'][$audience];
            if ($delivery['status'] !== 'not_sent') return self::error('status');
            if ($delivery['attempts'] >= self::MAX_ATTEMPTS || count($delivery['history']) >= 57) return self::error('attempt_limit');
            self::history($delivery,'pending','retry_requested',$time);
            $delivery['status'] = 'pending';
            return array('record'=>$record, 'result'=>array('event_id'=>$event_id,'audience'=>$audience,'status'=>'pending'));
        }, self::now($now));
    }

    /** A staff confirmation is required; ambiguity is never an automatic retry signal. */
    public static function resolve_uncertain($request_id,$event_id,$audience,$resolution,$confirmed=false,$now=null) {
        if (!current_user_can('manage_woocommerce')) return self::error('permission');
        if (!Petish_Order_Cancellation_Requests::enabled()) return self::error('disabled');
        if (!self::target($event_id,$audience) || $confirmed !== true || !in_array($resolution,array('confirmed_submitted','confirmed_not_sent'),true)) return self::error('input');
        return Petish_Order_Cancellation_Requests::locked($request_id, static function($record,$order,$time) use($event_id,$audience,$resolution) {
            if (!isset($record['events'][$event_id]['deliveries'][$audience])) return self::error('event');
            $delivery = &$record['events'][$event_id]['deliveries'][$audience];
            if ($delivery['status'] !== 'uncertain') return self::error('status');
            if (count($delivery['history']) >= 59) return self::error('attempt_limit');
            $status = $resolution === 'confirmed_submitted' ? 'submitted' : 'not_sent';
            self::history($delivery,$status,$resolution,$time);
            $delivery['status'] = $status;
            $delivery['finished_at'] = $time;
            return array('record'=>$record, 'result'=>array('event_id'=>$event_id,'audience'=>$audience,'status'=>$status));
        }, self::now($now));
    }
}
