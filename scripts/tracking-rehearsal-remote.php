<?php
/** Native AST evaluation. Caller must first prove private staging isolation. */

function petish_tracking_fail() {
    throw new RuntimeException('Private staging tracking rehearsal failed.');
}

function petish_tracking_check($context, &$checks, $name, $value, $required = true) {
    if (!is_bool($value) || !preg_match('/\Atracking_[a-z_]+\z/D', $name)) petish_tracking_fail();
    $checks[$name] = $value;
    if (is_callable($context['mark'] ?? null)) ($context['mark'])($name, $value);
    if ($required && !$value) petish_tracking_fail();
}

function petish_tracking_context($context) {
    if (PHP_SAPI !== 'cli' || !is_array($context)
        || !preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D', $context['run_id'] ?? '')
        || !defined('PETISH_STAGING_GUARD_ACTIVE') || PETISH_STAGING_GUARD_ACTIVE !== true
        || wp_get_environment_type() !== 'staging' || parse_url(home_url(), PHP_URL_HOST) !== 'staging.petish.biz'
        || !defined('WCPAY_DEV_MODE') || WCPAY_DEV_MODE !== true
        || !class_exists('WC_Payments') || !WC_Payments::mode()->is_dev() || !WC_Payments::mode()->is_test()
        || !is_string($context['root'] ?? null) || realpath(ABSPATH) !== $context['root']
        || !is_string($context['work'] ?? null) || realpath($context['work']) !== $context['work']
        || is_link($context['work']) || (fileperms($context['work']) & 07777) !== 0700
        || !is_int($context['customer_id'] ?? null) || $context['customer_id'] < 1
        || !is_string($context['customer_email'] ?? null)
        || !filter_var($context['customer_email'], FILTER_VALIDATE_EMAIL)
        || !str_ends_with(strtolower($context['customer_email']), '.invalid')
        || !is_callable($context['with_captured_mail'] ?? null)) petish_tracking_fail();
    $customer = get_user_by('id', $context['customer_id']);
    if (!$customer || $customer->user_email !== $context['customer_email']
        || !in_array('customer', (array)$customer->roles, true)
        || in_array('administrator', (array)$customer->roles, true)
        || get_woocommerce_currency() !== 'AUD') petish_tracking_fail();
    return $context;
}

function petish_tracking_scoped_order($context, $id) {
    if (!is_int($id) || $id < 1) petish_tracking_fail();
    $order = wc_get_order($id);
    if (!$order || $order->get_created_via() !== 'petish-tracking-rehearsal'
        || $order->get_meta('_petish_tracking_rehearsal', true) !== $context['run_id']
        || (int)$order->get_customer_id() !== $context['customer_id']
        || $order->get_billing_email() !== $context['customer_email']
        || $order->get_payment_method() !== '' || $order->get_transaction_id() !== '') petish_tracking_fail();
    foreach ($order->get_items() as $item) {
        if ((int)$item->get_product_id() !== 0 || (int)$item->get_variation_id() !== 0) petish_tracking_fail();
    }
    return $order;
}

function petish_tracking_capture_snapshot($path) {
    if (!is_string($path) || is_link($path) || realpath($path) !== $path) petish_tracking_fail();
    $stat = lstat($path);
    if (!$stat || !is_file($path) || $stat['nlink'] !== 1 || ($stat['mode'] & 07777) !== 0600) petish_tracking_fail();
    return ['device'=>$stat['dev'], 'inode'=>$stat['ino'], 'offset'=>$stat['size']];
}

/** Read only messages marked for this run and synthetic order; never return other mail. */
function petish_tracking_captured($context, $snapshot, $id, $type) {
    $path = $context['capture_file'];
    clearstatcache(true, $path);
    $now = petish_tracking_capture_snapshot($path);
    if ($now['device'] !== $snapshot['device'] || $now['inode'] !== $snapshot['inode']
        || $now['offset'] < $snapshot['offset'] || $now['offset'] - $snapshot['offset'] > 16777216) petish_tracking_fail();
    $handle = fopen($path, 'rb');
    if (!$handle) petish_tracking_fail();
    $out = [];
    try {
        if (fseek($handle, $snapshot['offset']) !== 0) petish_tracking_fail();
        while (($line = fgets($handle, 4194305)) !== false) {
            if (strlen($line) > 4194304 || !str_ends_with($line, "\n")) petish_tracking_fail();
            $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            $headers = $record['headers'] ?? null;
            if (!is_string($headers)) continue;
            foreach (['X-Petish-Tracking-Rehearsal'=>$context['run_id'], 'X-Petish-Tracking-Order'=>(string)$id,
                'X-Petish-Tracking-Email'=>$type] as $header=>$value) {
                if (!preg_match('/(?:\A|[\r\n])' . preg_quote($header . ': ' . $value, '/') . '(?:\r?\n|\z)/D', $headers)) continue 2;
            }
            if (!is_string($record['to'] ?? null) || !is_string($record['message'] ?? null)
                || !is_string($record['subject'] ?? null)) petish_tracking_fail();
            preg_match_all('/[A-Z0-9.!#$%&\'\*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,63}/i', $record['to'], $addresses);
            if (array_map('strtolower', $addresses[0]) !== [strtolower($context['customer_email'])]) petish_tracking_fail();
            $out[] = $record;
        }
    } finally { fclose($handle); }
    return $out;
}

function petish_tracking_email_matches($record, $numbers) {
    $html = $record['message'] ?? '';
    if (!is_string($html) || !str_contains($html, 'combined-light-v2.png')
        || !str_contains(strtolower($html), '#6532a1')
        || !str_contains($html, 'Petish tracking rehearsal item')) return false;
    foreach ($numbers as $number) {
        if (!str_contains($html, $number)
            || !str_contains($html, 'https://tracking.petish.invalid/parcel/' . $number)) return false;
    }
    return true;
}

function petish_tracking_request($context, $id, $method, $parameters = [], $tracking_id = null) {
    petish_tracking_scoped_order($context, $id);
    $route = '/wc-ast/v3/orders/' . $id . '/shipment-trackings';
    if ($tracking_id !== null) {
        if (!is_string($tracking_id) || !preg_match('/\A[a-f0-9]{32}\z/D', $tracking_id)) petish_tracking_fail();
        $route .= '/' . $tracking_id;
    }
    $request = new WP_REST_Request($method, $route);
    $request->set_body_params($parameters);
    return ($context['with_captured_mail'])(static function() use ($request) { return rest_do_request($request); });
}

function petish_tracking_items($context, $id) {
    petish_tracking_scoped_order($context, $id);
    $items = ast_get_tracking_items($id);
    if (!is_array($items)) petish_tracking_fail();
    foreach ($items as $item) {
        if (!is_array($item) || !is_string($item['tracking_id'] ?? null)
            || !preg_match('/\A[a-f0-9]{32}\z/D', $item['tracking_id'])
            || !is_string($item['tracking_number'] ?? null)) petish_tracking_fail();
    }
    return array_values($items);
}

function petish_tracking_payload($number, $status) {
    return ['tracking_provider'=>'Petish staging carrier', 'custom_tracking_provider'=>'Petish staging carrier',
        'tracking_number'=>$number, 'custom_tracking_link'=>'https://tracking.petish.invalid/parcel/' . $number,
        'date_shipped'=>'2026-10-20', 'status_shipped'=>$status, 'replace_tracking'=>0];
}

function petish_tracking_cleanup_order($context, &$id, &$order, &$checks) {
    // A storage hook can throw after inserting the order but before save()
    // returns its ID. Recover that same in-memory, tagged object only.
    if ($id === null && $order instanceof WC_Order && (int)$order->get_id() > 0) $id = (int)$order->get_id();
    if ($id === null || $id < 1) return;
    $owned = petish_tracking_scoped_order($context, $id);
    $owned->delete(true);
    $removed = wc_get_order($id) === false;
    petish_tracking_check($context, $checks, 'tracking_synthetic_order_removed', $removed);
    // Finally and shutdown share these references. Never delete twice or reuse
    // a now-unrelated ID after the scoped fixture has gone.
    $id = null;
    $order = null;
}

function petish_tracking_rehearsal($context) {
    $context = petish_tracking_context($context);
    $checks = [];
    $id = null;
    $order = null;
    $prior_user = get_current_user_id();
    $header_filter = null;
    $capture = petish_tracking_capture_snapshot($context['capture_file']);
    $completed = false;
    $cleanup_needed = true;
    register_shutdown_function(static function() use ($context, &$id, &$order, &$checks, &$cleanup_needed) {
        if (!$cleanup_needed) return;
        try {
            petish_tracking_cleanup_order($context, $id, $order, $checks);
            $cleanup_needed = false;
        } catch (Throwable $ignored) {
            // Native wp_die()/exit and fatal shutdowns bypass finally. Publish
            // only this finite recovery flag if scoped cleanup cannot finish.
            petish_tracking_check($context, $checks, 'tracking_synthetic_order_removed', false, false);
        }
    });
    try {
        $server = rest_get_server();
        petish_tracking_check($context, $checks, 'tracking_native_plugin_loaded',
            class_exists('WC_Advanced_Shipment_Tracking_REST_API_Controller')
            && function_exists('ast_get_tracking_items'));
        // Existing WooCommerce administrator is used only inside this isolated CLI
        // request. No new login, API key, cookie, nonce or authentication token.
        $admins = get_users(['role'=>'administrator', 'number'=>1, 'fields'=>'ID', 'orderby'=>'ID', 'order'=>'ASC']);
        if (count($admins) !== 1 || !is_numeric($admins[0])) petish_tracking_fail();
        $admin_id = (int)$admins[0];
        $admin = get_user_by('id', $admin_id);
        if (!$admin || !in_array('administrator', (array)$admin->roles, true)) petish_tracking_fail();
        WC()->mailer();
        $order = new WC_Order();
        $order->set_created_via('petish-tracking-rehearsal');
        $order->update_meta_data('_petish_tracking_rehearsal', $context['run_id']);
        $order->set_customer_id($context['customer_id']);
        $order->set_billing_email($context['customer_email']);
        $order->set_billing_first_name('Alex'); $order->set_billing_last_name('Rehearsal');
        $order->set_billing_country('AU'); $order->set_billing_state('VIC');
        $order->set_shipping_country('AU'); $order->set_shipping_state('VIC');
        $order->set_currency('AUD');
        $order->set_payment_method(''); $order->set_transaction_id('');
        $line = new WC_Order_Item_Product();
        $line->set_name('Petish tracking rehearsal item');
        $line->set_product_id(0); $line->set_variation_id(0); $line->set_quantity(2);
        $line->set_subtotal('29.20'); $line->set_total('29.20');
        $order->add_item($line); $order->set_total('29.20');
        $order->set_status('processing');
        $id = (int)$order->save();
        petish_tracking_scoped_order($context, $id);
        petish_tracking_check($context, $checks, 'tracking_synthetic_order_created', $id > 0);

        $header_filter = static function($headers, $email_id, $email_order) use ($context, $id) {
            if (!$email_order instanceof WC_Order || (int)$email_order->get_id() !== $id
                || $email_order->get_meta('_petish_tracking_rehearsal', true) !== $context['run_id']
                || !in_array($email_id, ['customer_partial_shipped_order', 'customer_completed_order'], true)) return $headers;
            $extra = ['X-Petish-Tracking-Rehearsal: ' . $context['run_id'],
                'X-Petish-Tracking-Order: ' . $id, 'X-Petish-Tracking-Email: ' . $email_id];
            return is_array($headers) ? array_merge($headers, $extra)
                : rtrim((string)$headers, "\r\n") . "\r\n" . implode("\r\n", $extra) . "\r\n";
        };
        add_filter('woocommerce_email_headers', $header_filter, PHP_INT_MAX, 3);
        $route = '/wc-ast/v3/orders/(?P<order_id>[\d]+)/shipment-trackings';
        petish_tracking_check($context, $checks, 'tracking_native_rest_registered', isset($server->get_routes()[$route]));
        $first = petish_tracking_payload('PETISH-STAGING-PARCEL-ONE', 2);
        wp_set_current_user(0);
        $denied = petish_tracking_request($context, $id, 'POST', $first);
        petish_tracking_check($context, $checks, 'tracking_anonymous_denied',
            in_array($denied->get_status(), [401,403], true) && count(petish_tracking_items($context, $id)) === 0);
        wp_set_current_user($context['customer_id']);
        $denied = petish_tracking_request($context, $id, 'POST', $first);
        petish_tracking_check($context, $checks, 'tracking_customer_denied',
            in_array($denied->get_status(), [401,403], true) && count(petish_tracking_items($context, $id)) === 0);
        wp_set_current_user($admin_id);
        if (!current_user_can('manage_woocommerce')) petish_tracking_fail();
        $response = petish_tracking_request($context, $id, 'POST', $first);
        petish_tracking_check($context, $checks, 'tracking_first_parcel_partial',
            $response->get_status() === 201 && petish_tracking_scoped_order($context, $id)->has_status('partial-shipped')
            && count(petish_tracking_items($context, $id)) === 1);
        $partial = petish_tracking_captured($context, $capture, $id, 'customer_partial_shipped_order');
        petish_tracking_check($context, $checks, 'tracking_partial_email_branded',
            count($partial) === 1 && petish_tracking_email_matches($partial[0], [$first['tracking_number']]));

        // Characterize native replay behavior. Free AST may append and notify
        // twice. A passing rehearsal records that risk; it does not endorse it.
        $before_items = petish_tracking_items($context, $id);
        $response = petish_tracking_request($context, $id, 'POST', $first);
        $after_items = petish_tracking_items($context, $id);
        $duplicates = count($after_items) > count($before_items);
        petish_tracking_check($context, $checks, 'tracking_replay_duplicates_observed', $duplicates, false);
        $replayed = petish_tracking_captured($context, $capture, $id, 'customer_partial_shipped_order');
        petish_tracking_check($context, $checks, 'tracking_replay_email_repeated', count($replayed) > count($partial), false);
        if ($response->get_status() !== 201) petish_tracking_fail();
        if ($duplicates) {
            $original_ids = array_column($before_items, 'tracking_id');
            foreach ($after_items as $item) if (!in_array($item['tracking_id'], $original_ids, true)) {
                $removed = petish_tracking_request($context, $id, 'DELETE', [], $item['tracking_id']);
                if ($removed->get_status() !== 200) petish_tracking_fail();
            }
        }
        if (count(petish_tracking_items($context, $id)) !== 1) petish_tracking_fail();
        $second = petish_tracking_payload('PETISH-STAGING-PARCEL-TWO', 1);
        $response = petish_tracking_request($context, $id, 'POST', $second);
        $numbers = array_column(petish_tracking_items($context, $id), 'tracking_number'); sort($numbers);
        $expected = [$first['tracking_number'], $second['tracking_number']]; sort($expected);
        petish_tracking_check($context, $checks, 'tracking_two_parcels_preserved', $numbers === $expected);
        petish_tracking_check($context, $checks, 'tracking_final_parcel_completed',
            $response->get_status() === 201 && petish_tracking_scoped_order($context, $id)->has_status('completed'));
        $completed_mail = petish_tracking_captured($context, $capture, $id, 'customer_completed_order');
        petish_tracking_check($context, $checks, 'tracking_completed_email_branded',
            count($completed_mail) === 1 && petish_tracking_email_matches($completed_mail[0], $expected));

        wp_set_current_user($context['customer_id']);
        ob_start();
        try { do_action('woocommerce_view_order', $id); $widget = ob_get_contents(); }
        finally { ob_end_clean(); }
        $widget_ok = is_string($widget);
        foreach ($expected as $number) $widget_ok = $widget_ok && str_contains($widget, $number)
            && str_contains($widget, 'https://tracking.petish.invalid/parcel/' . $number);
        petish_tracking_check($context, $checks, 'tracking_account_widget_two_parcels', $widget_ok);
        petish_tracking_check($context, $checks, 'tracking_synthetic_carrier_link_preserved', $widget_ok);

        wp_set_current_user($admin_id);
        $before_blank = count(petish_tracking_items($context, $id));
        $blank = petish_tracking_request($context, $id, 'POST', petish_tracking_payload('', 0));
        $blank_accepted = $blank->get_status() === 201 && count(petish_tracking_items($context, $id)) > $before_blank;
        petish_tracking_check($context, $checks, 'tracking_blank_number_accepted', $blank_accepted, false);
        $order = petish_tracking_scoped_order($context, $id);
        petish_tracking_check($context, $checks, 'tracking_no_payment_performed',
            $order->get_payment_method() === '' && $order->get_transaction_id() === '' && (float)$order->get_total_refunded() === 0.0);
        $no_products = count($order->get_items()) === 1;
        foreach ($order->get_items() as $item) $no_products = $no_products && (int)$item->get_product_id() === 0 && (int)$item->get_variation_id() === 0;
        petish_tracking_check($context, $checks, 'tracking_no_product_stock_used', $no_products);
        $blocked = apply_filters('pre_http_request', false, ['method'=>'POST'], 'https://www.dsers.com/petish-tracking-rehearsal');
        petish_tracking_check($context, $checks, 'tracking_supplier_actions_blocked', is_wp_error($blocked));
        petish_tracking_check($context, $checks, 'tracking_external_dsers_push_verified', false, false);
        $completed = true;
    } finally {
        if ($header_filter !== null) remove_filter('woocommerce_email_headers', $header_filter, PHP_INT_MAX);
        wp_set_current_user($prior_user);
        petish_tracking_cleanup_order($context, $id, $order, $checks);
        $cleanup_needed = false;
    }
    if (!$completed) petish_tracking_fail();
    return $checks;
}
