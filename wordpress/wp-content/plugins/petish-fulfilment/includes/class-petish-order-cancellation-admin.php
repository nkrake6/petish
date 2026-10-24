<?php
/** Private staff cancellation review. No gateway or supplier operation is performed here. */
defined('ABSPATH') || exit;

final class Petish_Order_Cancellation_Admin {
    const SLUG = 'petish-cancellation-requests';
    const ACTION = 'petish_cancellation_staff_action';
    const PREVIEW_ACTION = 'petish_cancellation_email_preview';
    const SETTINGS_ACTION = 'petish_cancellation_staff_settings';
    const NONCE = 'petish_cancellation_staff';
    const OPERATIONS = array('reviewing','awaiting_supplier','record_decision','close','send_pending','reconcile','queue_retry','confirmed_submitted','confirmed_not_sent');
    const LABELS = array('requested'=>'Request received','reviewing'=>'Under review','awaiting_supplier'=>'Waiting for supplier','approved'=>'Approved — refund review required','partially_approved'=>'Partly approved — refund review required','declined'=>'Unable to cancel','closed'=>'Closed');

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'submit'));
        add_action('admin_post_' . self::PREVIEW_ACTION, array(__CLASS__, 'preview_email'));
        add_action('admin_post_' . self::SETTINGS_ACTION, array(__CLASS__, 'save_settings'));
        add_action('woocommerce_admin_order_data_after_order_details', array(__CLASS__, 'order_notice'));
    }

    public static function menu() {
        $label = 'Cancellation requests';
        if (self::authorized()) {
            $count = Petish_Order_Cancellation_Requests::pending_count();
            if (is_int($count) && $count > 0) $label .= ' <span class="awaiting-mod"><span class="pending-count">' . ($count > 999 ? '999+' : $count) . '</span></span>';
        }
        add_submenu_page('woocommerce', 'Cancellation requests', $label, 'manage_woocommerce', self::SLUG, array(__CLASS__, 'render'));
    }

    public static function authorized($nonce = null, $action = null) {
        return current_user_can('manage_woocommerce') && ($nonce === null || (is_string($nonce) && is_string($action) && wp_verify_nonce($nonce, $action)));
    }

    public static function nonce_action($operation, $case, $revision, $event = '', $audience = '') {
        return self::NONCE . ':' . $operation . ':' . $case . ':' . $revision . ':' . $event . ':' . $audience;
    }

    private static function error($code, $message = '') {
        return new WP_Error('petish_cancellation_admin_' . $code, $message ?: 'The cancellation case action could not be completed. Refresh the case and try again.');
    }

    private static function number($value, $zero = false) {
        if ((!is_int($value) && !is_string($value)) || !preg_match($zero ? '/\A(?:0|[1-9][0-9]{0,9})\z/D' : '/\A[1-9][0-9]{0,9}\z/D', (string)$value)) return false;
        return (int)$value;
    }

    private static function text($value, $maximum = 1000) {
        if (!is_string($value) || strlen($value) > $maximum || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) return false;
        return trim(wp_strip_all_tags($value));
    }

    private static function event($value) {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1 ? $value : false;
    }

    /** Reject invalid capability/nonce/revision before invoking a case mutation. */
    private static function request_context($post, $operation) {
        if (!is_array($post) || !self::authorized()) return self::error('permission');
        $id = self::number($post['case_id'] ?? null); $revision = self::number($post['revision'] ?? null);
        $event = $post['event_id'] ?? ''; $audience = $post['audience'] ?? '';
        if ($id === false || $revision === false || !is_string($event) || !is_string($audience)
            || !self::authorized($post['_wpnonce'] ?? '', self::nonce_action($operation, $id, $revision, $event, $audience))) return self::error('request');
        $record = Petish_Order_Cancellation_Requests::get($id);
        if (is_wp_error($record) || ($record['id'] ?? null) !== $id || ($record['revision'] ?? null) !== $revision) return self::error('stale');
        return $record;
    }

    public static function action_request($post) {
        if (!is_array($post) || !self::authorized()) return self::error('permission');
        $operation = $post['operation'] ?? null;
        if (!is_string($operation) || !in_array($operation, self::OPERATIONS, true)) return self::error('request');
        $record = self::request_context($post, $operation);
        if (is_wp_error($record)) return $record;
        $id = $record['id']; $revision = $record['revision'];
        $private = self::text($post['private_note'] ?? '');
        $public = self::text($post['public_message'] ?? '');
        if ($private === false || $public === false) return self::error('input');
        if (in_array($operation, array('reviewing','awaiting_supplier'), true)) {
            return Petish_Order_Cancellation_Requests::transition($id, $operation, array('private_note'=>$private,'public_message'=>$public,'expected_revision'=>$revision));
        }
        if ($operation === 'record_decision') {
            $reference = self::text($post['private_reference'] ?? '');
            $accepted = $post['accepted_qty'] ?? null; $quantities = array();
            if ($reference === false || $reference === '' || ($post['supplier_checked'] ?? null) !== 'yes'
                || !is_array($accepted) || count($accepted) !== count($record['items'])) return self::error('confirmation');
            foreach ($record['items'] as $line=>$item) {
                $quantity = self::number($accepted[$line] ?? null, true);
                if ($quantity === false || $quantity > $item['quantity']) return self::error('quantity');
                $quantities[$line] = $quantity;
            }
            foreach ($accepted as $line=>$quantity) if (!isset($record['items'][$line])) return self::error('quantity');
            return Petish_Order_Cancellation_Requests::record_decision($id, $quantities, $public, $reference, true, $revision);
        }
        if ($operation === 'close') {
            if (($post['supplier_checked'] ?? null) !== 'yes' || ($post['refund_handled'] ?? null) !== 'yes') return self::error('confirmation');
            return Petish_Order_Cancellation_Requests::close($id, array('supplier_checked'=>true,'refund_handled'=>true,'private_note'=>$private,'expected_revision'=>$revision));
        }
        if (!class_exists('Petish_Order_Cancellation_Emails')) return self::error('unavailable');
        if ($operation === 'send_pending') return Petish_Order_Cancellation_Emails::dispatch_pending($id);
        if ($operation === 'reconcile') return Petish_Order_Cancellation_Emails::reconcile($id);
        $event = self::event($post['event_id'] ?? null); $audience = $post['audience'] ?? null;
        if ($event === false || !in_array($audience, array('customer','staff'), true) || !isset($record['events'][$event]['deliveries'][$audience])) return self::error('event');
        if ($operation === 'queue_retry') return Petish_Order_Cancellation_Emails::queue_retry($id, $event, $audience);
        if (($post['confirm_resolution'] ?? null) !== 'yes') return self::error('confirmation');
        return Petish_Order_Cancellation_Emails::resolve_uncertain($id, $event, $audience, $operation, true);
    }

    public static function preview_request($post) {
        $record = self::request_context($post, 'preview');
        if (is_wp_error($record)) return $record;
        $event = self::event($post['event_id'] ?? null); $audience = $post['audience'] ?? null;
        if ($event === false || !in_array($audience, array('customer','staff'), true) || !isset($record['events'][$event]) || !class_exists('Petish_Order_Cancellation_Emails')) return self::error('event');
        $preview = Petish_Order_Cancellation_Emails::preview($record['id'], $event, $audience);
        if (is_wp_error($preview)) return $preview;
        if (!is_array($preview) || !is_string($preview['subject'] ?? null) || !is_string($preview['html'] ?? null) || $preview['html'] === '') return self::error('preview');
        return $preview + array('kind'=>$record['events'][$event]['kind'],'created_at'=>$record['events'][$event]['created_at'],'audience'=>$audience);
    }

    /** Only the two feature flags can be changed; saving never dispatches an event. */
    public static function settings_request($post) {
        if (!is_array($post) || !self::authorized()) return self::error('permission');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !self::authorized($post['_wpnonce'] ?? '', self::NONCE . ':settings')) return self::error('settings');
        $values = array();
        foreach (array('accept_requests','send_emails') as $key) {
            // A present null/array/bool must not be treated as an unchecked field.
            if (array_key_exists($key, $post) && $post[$key] !== 'yes') return self::error('settings');
            $values[$key] = array_key_exists($key, $post) ? 'yes' : 'no';
        }
        update_option(Petish_Order_Cancellation_Requests::OPTION, $values['accept_requests'], false);
        update_option(Petish_Order_Cancellation_Emails::OPTION, $values['send_emails'], false);
        if (get_option(Petish_Order_Cancellation_Requests::OPTION, 'no') !== $values['accept_requests']
            || get_option(Petish_Order_Cancellation_Emails::OPTION, 'no') !== $values['send_emails']) return self::error('settings');
        return $values;
    }

    public static function save_settings() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !self::authorized()) { self::deny(); return; }
        $result = self::settings_request(wp_unslash($_POST));
        wp_safe_redirect(add_query_arg('settings_notice', is_wp_error($result) ? 'not_saved' : 'saved', self::page_url()), 303);
        exit;
    }

    private static function deny() {
        wp_die(esc_html__('You cannot access cancellation requests.', 'petish-fulfilment'), '', array('response'=>403));
    }

    private static function page_url($case = null) {
        return add_query_arg(array_filter(array('page'=>self::SLUG,'case_id'=>$case), static fn($v)=>$v !== null), admin_url('admin.php'));
    }

    public static function submit() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !self::authorized()) { self::deny(); return; }
        $post = wp_unslash($_POST); $result = self::action_request($post);
        $id = self::number($post['case_id'] ?? null);
        wp_safe_redirect(add_query_arg('case_notice', is_wp_error($result) ? 'not_saved' : 'saved', self::page_url($id === false ? null : $id)), 303);
        exit;
    }

    public static function preview_email() {
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; frame-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { self::deny(); return; }
        $preview = self::preview_request(wp_unslash($_POST));
        if (is_wp_error($preview)) { self::deny(); return; }
        echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Petish cancellation email preview</title><style>body{margin:0;background:#f5f6fa;color:#14213d;font:16px/1.5 system-ui,sans-serif}header{padding:16px 24px;background:white;border-bottom:1px solid #d7dce5}h1{font-size:20px;margin:0 0 8px}p{margin:4px 0}iframe{display:block;border:0;width:100%;height:85vh;background:white}</style></head><body><header><h1>Staff preview — no email sent</h1><p>Audience: ' . esc_html($preview['audience']) . ' · Stored event: ' . esc_html($preview['kind']) . ' · ' . esc_html(gmdate('Y-m-d H:i:s', $preview['created_at'])) . ' UTC</p><p>Subject: ' . esc_html($preview['subject']) . '</p><p>This is the saved request-event snapshot. Previewing changes no case, payment or email delivery state.</p></header><iframe sandbox="" referrerpolicy="no-referrer" title="Cancellation email preview" srcdoc="' . esc_attr($preview['html']) . '"></iframe></body></html>';
        exit;
    }

    private static function form_start($operation, $record, $event = '', $audience = '', $preview = false) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($preview ? ' target="_blank" rel="noopener noreferrer"' : '') . ' style="margin:12px 0"><input type="hidden" name="action" value="' . esc_attr($preview ? self::PREVIEW_ACTION : self::ACTION) . '"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="case_id" value="' . (int)$record['id'] . '"><input type="hidden" name="revision" value="' . (int)$record['revision'] . '"><input type="hidden" name="event_id" value="' . esc_attr($event) . '"><input type="hidden" name="audience" value="' . esc_attr($audience) . '">';
        wp_nonce_field(self::nonce_action($operation, $record['id'], $record['revision'], $event, $audience));
    }

    private static function button($operation, $record, $label, $event = '', $audience = '', $confirmation = null) {
        self::form_start($operation, $record, $event, $audience);
        if ($confirmation !== null) echo '<label><input type="checkbox" name="confirm_resolution" value="yes" required> ' . esc_html($confirmation) . '</label><br>';
        submit_button($label, 'secondary', 'submit', false); echo '</form>';
    }

    public static function order_notice($order) {
        if (!self::authorized() || !$order instanceof WC_Order || !is_int($order->get_id()) || $order->get_id() < 1) return;
        $result = Petish_Order_Cancellation_Requests::query(array('order_id'=>$order->get_id(),'state'=>'open','page'=>1,'per_page'=>1));
        if (is_wp_error($result) || empty($result['records'])) return;
        echo '<div class="notice notice-warning inline" style="clear:both"><p><strong>Cancellation request awaiting staff action.</strong> Check this case before placing or processing supplier orders. This request does not automatically stop DSers or the supplier. <a href="' . esc_url(self::page_url($result['records'][0]['id'])) . '">Open cancellation case</a></p></div>';
    }

    public static function render() {
        if (!self::authorized()) { self::deny(); return; }
        nocache_headers();
        echo '<div class="wrap"><h1>Cancellation requests</h1><p>Review customer requests, confirm what can be stopped and record the outcome. This queue does not cancel supplier orders, change WooCommerce order status, calculate fee deductions or transfer refunds.</p>';
        if (!Petish_Order_Cancellation_Requests::enabled()) echo '<div class="notice notice-info inline"><p>New customer cancellation requests are disabled. Existing cases and private previews remain available to authorised staff.</p></div>';
        if (isset($_GET['settings_notice'])) echo '<div class="notice ' . ($_GET['settings_notice'] === 'saved' ? 'notice-success' : 'notice-error') . ' inline"><p>' . ($_GET['settings_notice'] === 'saved' ? 'Cancellation settings saved. Existing pending emails were not sent.' : 'Cancellation settings could not be saved. Check the switches and refresh before trying again.') . '</p></div>';
        if (isset($_GET['case_notice'])) echo '<div class="notice ' . ($_GET['case_notice'] === 'saved' ? 'notice-success' : 'notice-error') . ' inline"><p>' . ($_GET['case_notice'] === 'saved' ? 'The case action was recorded. Review its email delivery status below.' : 'The case action was not saved. Refresh the case, check its latest state and try again.') . '</p></div>';
        $id = self::number($_GET['case_id'] ?? null);
        if ($id !== false) {
            $record = Petish_Order_Cancellation_Requests::get($id);
            if (is_wp_error($record)) echo '<p>This cancellation case is unavailable.</p>';
            else self::case_page($record);
        } else { self::settings_form(); self::queue_page(); }
        echo '</div>';
    }

    private static function settings_form() {
        $accept = get_option(Petish_Order_Cancellation_Requests::OPTION, 'no') === 'yes';
        $send = get_option(Petish_Order_Cancellation_Emails::OPTION, 'no') === 'yes';
        echo '<h2>Cancellation request settings</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::SETTINGS_ACTION) . '">';
        wp_nonce_field(self::NONCE . ':settings');
        echo '<p><label><input type="checkbox" name="accept_requests" value="yes"' . ($accept ? ' checked' : '') . '> Accept customer cancellation requests</label></p><p><label><input type="checkbox" name="send_emails" value="yes"' . ($send ? ' checked' : '') . '> Send customer acknowledgements and staff alerts</label></p><p>Both switches default off. Sending requires both switches. Enabling email sends future case events and allows staff to send pending case emails explicitly; saving these settings does not send an existing pending message.</p><p>Requests still need staff review. These switches do not cancel supplier orders, issue refunds or verify email delivery.</p>';
        if (defined('PETISH_STAGING_GUARD_ACTIVE') && PETISH_STAGING_GUARD_ACTIVE) echo '<p><strong>Messages remain subject to the staging mail capture.</strong></p>';
        submit_button('Save cancellation settings', 'secondary', 'submit', false); echo '</form>';
    }

    private static function queue_page() {
        $state = $_GET['state'] ?? 'open';
        if (!is_string($state) || !in_array($state, array_merge(array('open','all'), array_keys(self::LABELS)), true)) $state = 'open';
        $page = self::number($_GET['paged'] ?? '1'); $order = self::number($_GET['order_id'] ?? null);
        $filters = array('state'=>$state,'page'=>$page === false ? 1 : min($page, 10000),'per_page'=>20);
        if ($order !== false) $filters['order_id'] = $order;
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><label for="petish-cancel-state">Case state </label><select id="petish-cancel-state" name="state">';
        foreach (array('open'=>'Open cases','all'=>'All cases') + self::LABELS as $value=>$label) echo '<option value="' . esc_attr($value) . '"' . ($state === $value ? ' selected' : '') . '>' . esc_html($label) . '</option>';
        echo '</select> <label for="petish-cancel-order">Order ID </label><input type="number" id="petish-cancel-order" name="order_id" min="1" value="' . ($order === false ? '' : (int)$order) . '"> ';
        submit_button('Filter cases', 'secondary', 'submit', false); echo '</form>';
        $result = Petish_Order_Cancellation_Requests::query($filters);
        if (is_wp_error($result)) { echo '<p>The cancellation queue could not be read.</p>'; return; }
        echo '<p>' . (int)$result['total'] . ' case(s). Open cases remain here until staff explicitly close them.</p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Case / order</th><th>Requested items</th><th>State</th><th>Requested</th></tr></thead><tbody>';
        foreach ($result['records'] as $record) {
            echo '<tr><td><a href="' . esc_url(self::page_url($record['id'])) . '">Case ' . (int)$record['id'] . '</a><br>Order #' . esc_html($record['order_number']) . '</td><td>';
            foreach ($record['items'] as $item) echo esc_html($item['name']) . ' × ' . (int)$item['quantity'] . '<br>';
            echo '</td><td>' . esc_html(self::LABELS[$record['state']] ?? $record['state']) . '</td><td>' . esc_html(gmdate('Y-m-d H:i', $record['created_at'])) . ' UTC</td></tr>';
        }
        if (!$result['records']) echo '<tr><td colspan="4">No matching cancellation requests.</td></tr>';
        echo '</tbody></table></div>';
        if ($result['page'] > 1) echo '<p><a href="' . esc_url(add_query_arg(array('state'=>$state,'order_id'=>$order === false ? '' : $order,'paged'=>$result['page'] - 1), self::page_url())) . '">Previous page</a></p>';
        if ($result['page'] < $result['pages']) echo '<p><a href="' . esc_url(add_query_arg(array('state'=>$state,'order_id'=>$order === false ? '' : $order,'paged'=>$result['page'] + 1), self::page_url())) . '">Next page</a></p>';
    }

    private static function case_page($record) {
        echo '<p><a href="' . esc_url(self::page_url()) . '">← Back to cancellation queue</a></p><h2>Case ' . (int)$record['id'] . ' · Order #' . esc_html($record['order_number']) . '</h2><p><strong>' . esc_html(self::LABELS[$record['state']] ?? $record['state']) . '</strong></p>';
        $order = wc_get_order($record['order_id']);
        if ($order instanceof WC_Order && (int)$order->get_id() === $record['order_id']) echo '<p><a class="button" href="' . esc_url($order->get_edit_order_url()) . '">Open WooCommerce order and refund controls</a></p>';
        echo '<div class="notice notice-warning inline"><p><strong>Check supplier processing before proceeding.</strong> A saved request is a staff warning; it does not place a hold in DSers or stop an already placed supplier order.</p></div><h3>Staff checklist</h3><ol><li>Prevent further supplier placement while this case is reviewed.</li><li>Check the exact requested lines and quantities with DSers and the supplier. Absence of tracking does not prove that an item is unfulfilled.</li><li>For processed orders, request supplier cancellation and retain its confirmation. Dispatched items may require an authorised return.</li><li>For change-of-mind returns, verify the actual delivery date and return condition; the WooCommerce completed date alone is not delivery evidence.</li><li>Explain any permitted processing-fee deduction and the exact proposed refund amount before issuing the refund. Statutory remedies remain protected.</li><li>Use the original payment gateway refund controls, then independently verify payment outcome and supplier outcome before closing this case.</li></ol>';
        if (class_exists('Petish_Order_Cancellation_Emails')) echo '<p>' . esc_html(Petish_Order_Cancellation_Emails::policy_text()) . '</p>';
        echo '<h3>Customer request</h3><p>Submitted ' . esc_html(gmdate('Y-m-d H:i:s', $record['created_at'])) . ' UTC · Last updated ' . esc_html(gmdate('Y-m-d H:i:s', $record['updated_at'])) . ' UTC</p><ul>';
        foreach ($record['items'] as $item) echo '<li>' . esc_html($item['name']) . ' × ' . (int)$item['quantity'] . '</li>';
        echo '</ul><p><strong>Customer reason:</strong> ' . nl2br(esc_html($record['reason'])) . '</p>';
        if ($record['decision'] !== null) {
            echo '<h3>Recorded decision</h3><ul>';
            foreach ($record['items'] as $line=>$item) echo '<li>' . esc_html($item['name']) . ': ' . (int)$record['decision']['accepted_items'][$line] . ' of ' . (int)$item['quantity'] . ' approved for cancellation</li>';
            echo '</ul><p><strong>Customer-visible message:</strong> ' . nl2br(esc_html($record['decision']['public_message'])) . '</p><p><strong>Private supplier reference:</strong> ' . nl2br(esc_html($record['decision']['private_reference'])) . '</p><p>A recorded cancellation decision does not itself issue a refund.</p>';
        }
        if ($record['state'] !== 'closed') self::review_forms($record);
        self::email_status($record);
        echo '<h3>Private staff history</h3><ul>';
        foreach ($record['history'] as $entry) echo '<li>' . esc_html(gmdate('Y-m-d H:i:s', $entry['at'])) . ' UTC · ' . esc_html($entry['action']) . ' · ' . esc_html(self::LABELS[$entry['state']] ?? $entry['state']) . ' · Staff/account ID ' . (int)$entry['actor_id'] . ($entry['private_note'] !== '' ? '<br>' . nl2br(esc_html($entry['private_note'])) : '') . '</li>';
        echo '</ul>';
    }

    private static function review_forms($record) {
        echo '<p>Public messages are included in customer updates. Private staff notes and supplier references remain internal.</p>';
        if (in_array($record['state'], array('requested','reviewing','awaiting_supplier'), true)) {
            echo '<h3>Review progress</h3>';
            foreach (array('reviewing'=>'Mark under review','awaiting_supplier'=>'Mark waiting for supplier') as $state=>$label) {
                if ($state === $record['state']) continue;
                self::form_start($state, $record);
                echo '<p><label>Customer-visible progress message<br><textarea name="public_message" rows="2" maxlength="1000" class="large-text"></textarea></label></p><p><label>Private staff note<br><textarea name="private_note" rows="2" maxlength="1000" class="large-text"></textarea></label></p>';
                submit_button($label, 'secondary', 'submit', false); echo '</form>';
            }
        }
        if ($record['state'] !== 'closed') {
            echo '<h3>Record fulfilment or return decision</h3><p>Enter the quantity confirmed for cancellation or authorised-return resolution. For returns, verify receipt and condition before approving the refund. Zero for every line records an unable-to-cancel decision. An updated decision retains the earlier event history. This neither marks an order cancelled nor issues money.</p>';
            self::form_start('record_decision', $record);
            foreach ($record['items'] as $line=>$item) echo '<p><label>' . esc_html($item['name']) . ' — approved cancellation quantity <input type="number" name="accepted_qty[' . (int)$line . ']" min="0" max="' . (int)$item['quantity'] . '" value="' . (int)($record['decision']['accepted_items'][$line] ?? 0) . '" required> / ' . (int)$item['quantity'] . '</label></p>';
            echo '<p><label>Customer-visible decision and any fee/refund proposal<br><textarea name="public_message" rows="4" maxlength="1000" class="large-text">' . esc_html($record['decision']['public_message'] ?? '') . '</textarea></label></p><p><label>Private supplier confirmation or authorised-return reference<br><textarea name="private_reference" rows="3" maxlength="1000" class="large-text" required>' . esc_html($record['decision']['private_reference'] ?? '') . '</textarea></label></p><p><label><input type="checkbox" name="supplier_checked" value="yes" required> I checked the actual supplier/fulfilment or received-return position for every selected line and confirmed the quantities above.</label></p>';
            submit_button('Record cancellation decision', 'primary', 'submit', false); echo '</form>';
        }
        if (in_array($record['state'], array('approved','partially_approved','declined'), true)) {
            echo '<h3>Close after independent checks</h3><p>Closure records staff review. It does not perform a gateway refund or prove that a manual refund record transferred funds.</p>';
            self::form_start('close', $record);
            echo '<p><label><input type="checkbox" name="supplier_checked" value="yes" required> I checked the final supplier/cancellation/return outcome.</label></p><p><label><input type="checkbox" name="refund_handled" value="yes" required> I verified the payment/refund outcome with the original payment provider, or confirmed no refund is due. Any permitted deduction was disclosed before processing.</label></p><p><label>Private closure note/reference<br><textarea name="private_note" rows="3" maxlength="1000" class="large-text"></textarea></label></p>';
            submit_button('Close reviewed case', 'secondary', 'submit', false); echo '</form>';
        }
    }

    private static function email_status($record) {
        echo '<h3>Customer and staff email delivery</h3><p>Customer acknowledgements and updates are separate from approval and payment. Submitted means the mail transport accepted the message; it does not prove inbox delivery.</p>';
        if (!class_exists('Petish_Order_Cancellation_Emails')) { echo '<p>Email controls are unavailable.</p>'; return; }
        if (!Petish_Order_Cancellation_Emails::enabled()) echo '<p><strong>Sending is disabled.</strong> Private previews remain available; these buttons cannot enable sending.</p>';
        self::button('send_pending', $record, 'Send pending case emails');
        self::button('reconcile', $record, 'Reconcile expired sending attempts');
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Event</th><th>Audience</th><th>Delivery</th><th>Review controls</th></tr></thead><tbody>';
        foreach ($record['events'] as $event=>$entry) foreach ($entry['deliveries'] as $audience=>$slot) {
            echo '<tr><td>' . esc_html($entry['kind']) . '<br>' . esc_html(gmdate('Y-m-d H:i:s', $entry['created_at'])) . ' UTC</td><td>' . esc_html($audience === 'staff' ? 'Petish orders team' : 'Customer') . '</td><td>' . esc_html($slot['status']) . '<br>Attempts: ' . (int)$slot['attempts'] . '</td><td>';
            self::form_start('preview', $record, $event, $audience, true); submit_button('Preview email', 'secondary', 'submit', false); echo '</form>';
            if ($slot['status'] === 'not_sent') self::button('queue_retry', $record, 'Queue confirmed-unsent retry', $event, $audience);
            if ($slot['status'] === 'uncertain') {
                self::button('confirmed_submitted', $record, 'Record confirmed submission', $event, $audience, 'I checked provider evidence and confirmed this message was already submitted.');
                self::button('confirmed_not_sent', $record, 'Record confirmed non-submission', $event, $audience, 'I confirmed the sending process stopped and provider evidence shows the message was not submitted.');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
