<?php
/** Functions only. Caller must first prove private staging/runtime isolation. */

function petish_order_fail() { throw new RuntimeException('Private staging order rehearsal failed.'); }

function petish_order_private_file($path, $write = false) {
    if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")) petish_order_fail();
    $parent = dirname($path);
    if (realpath($parent) !== $parent || is_link($parent) || (fileperms($parent) & 07777) !== 0700) petish_order_fail();
    if (file_exists($path) || is_link($path)) {
        $stat = lstat($path);
        if (is_link($path) || realpath($path) !== $path || !is_file($path) || $stat['nlink'] !== 1
            || ($stat['mode'] & 07777) !== 0600) petish_order_fail();
    } elseif (!$write) petish_order_fail();
}

function petish_order_write($path, $value) {
    petish_order_private_file($path, true);
    $temporary = dirname($path) . '/.rehearsal-' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = fopen($temporary, 'xb');
    if (!$handle || !chmod($temporary, 0600)) petish_order_fail();
    try {
        $raw = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) petish_order_fail();
    } finally { fclose($handle); }
    if (!rename($temporary, $path)) petish_order_fail();
}

function petish_order_context($context) {
    if (!is_array($context) || !preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D', $context['run_id'] ?? '')
        || wp_get_environment_type() !== 'staging' || !defined('PETISH_STAGING_GUARD_ACTIVE') || !PETISH_STAGING_GUARD_ACTIVE
        || !class_exists('WC_Payments') || !WC_Payments::mode()->is_dev() || !WC_Payments::mode()->is_test()
        || !is_string($context['root'] ?? null) || realpath(ABSPATH) !== $context['root']
        || parse_url(home_url(), PHP_URL_HOST) !== 'staging.petish.biz'
        || !is_string($context['work'] ?? null) || realpath($context['work']) !== $context['work']
        || (fileperms($context['work']) & 07777) !== 0700
        || !is_int($context['customer_id'] ?? null) || $context['customer_id'] < 1
        || !is_string($context['customer_email'] ?? null) || !str_ends_with(strtolower($context['customer_email']), '.invalid')) petish_order_fail();
    $user = get_user_by('id', $context['customer_id']);
    if (!$user || $user->user_email !== $context['customer_email'] || !in_array('customer', $user->roles, true)
        || in_array('administrator', $user->roles, true)) petish_order_fail();
    if (get_woocommerce_currency() !== 'AUD') petish_order_fail();
    return $context;
}

function petish_order_journal($context) {
    $path = $context['work'] . '/order-journal.json';
    petish_order_private_file($path);
    $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value) || ($value['run_id'] ?? null) !== $context['run_id']
        || ($value['customer_id'] ?? null) !== $context['customer_id']) petish_order_fail();
    return $value;
}

function petish_order_save($context, $journal) {
    petish_order_write($context['work'] . '/order-journal.json', $journal);
}

function petish_order_exclusive($context, $operation) {
    $path = $context['work'] . '/order-operation.lock';
    petish_order_private_file($path, true);
    $handle = fopen($path, 'c+b');
    if (!$handle || !chmod($path, 0600)) petish_order_fail();
    try {
        $opened = fstat($handle); $named = lstat($path);
        if (!$opened || !$named || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
            || $opened['nlink'] !== 1 || !flock($handle, LOCK_EX)) petish_order_fail();
        try { return $operation(); }
        finally { flock($handle, LOCK_UN); }
    } finally { fclose($handle); }
}

function petish_order_product() {
    $product = wc_get_product(246);
    if (!$product || (int)$product->get_parent_id() !== 242 || !$product->managing_stock()
        || !is_numeric($product->get_stock_quantity()) || (float)$product->get_price() <= 0) petish_order_fail();
    return $product;
}

function petish_order_mail_text($value) {
    return trim(preg_replace('/\s+/u', ' ', strtolower(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
}

function petish_order_mail_addresses($value) {
    if (!is_string($value)) return [];
    preg_match_all('/[A-Z0-9.!#$%&\'\*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,63}/i', $value, $matches);
    $addresses = [];
    foreach ($matches[0] as $address) if (filter_var($address, FILTER_VALIDATE_EMAIL)) $addresses[] = strtolower($address);
    $addresses = array_values(array_unique($addresses)); sort($addresses);
    return $addresses;
}

function petish_order_admin_recipients() {
    foreach (WC()->mailer()->get_emails() as $email) {
        if (($email->id ?? null) === 'new_order' && is_callable([$email, 'get_recipient'])) {
            $recipients = petish_order_mail_addresses($email->get_recipient());
            if (!$recipients) petish_order_fail();
            return $recipients;
        }
    }
    petish_order_fail();
}

function petish_rehearsal_prepare($context) {
    $context = petish_order_context($context);
    return petish_order_exclusive($context, static function () use ($context) { return petish_order_prepare_locked($context); });
}

function petish_order_prepare_locked($context) {
    $context = petish_order_context($context);
    $journal_path = $context['work'] . '/order-journal.json';
    $target = $context['root'] . '/wp-content/mu-plugins/petish-rehearsal-fixture.php';
    if (file_exists($journal_path) || is_link($journal_path) || file_exists($target) || is_link($target)) petish_order_fail();
    $product = petish_order_product();
    $stock = (float)$product->get_stock_quantity();
    if ($stock < 1) petish_order_fail();
    $parent = wc_get_product(242);
    $product_label = $parent ? petish_order_mail_text($parent->get_name()) : '';
    if (strlen($product_label) < 3) petish_order_fail();
    $capture = ($context['capture_file'] ?? ($context['private'] . '/captured-mail.jsonl'));
    petish_order_private_file($capture);
    $capture_stat = stat($capture);
    $template_path = $context['fixture_template'] ?? (__DIR__ . '/staging-rehearsal-fixture.php');
    if (!is_file($template_path) || is_link($template_path)) petish_order_fail();
    $source = file_get_contents($template_path);
    if (substr_count($source, "'__PETISH_REHEARSAL_CONFIG_PATH__'") !== 1) petish_order_fail();
    $fixture_path = $context['work'] . '/fixture.json';
    $source = str_replace("'__PETISH_REHEARSAL_CONFIG_PATH__'", var_export($fixture_path, true), $source);
    $highest = wc_get_orders(['limit' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids']);
    $cart_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
    $journal = ['version' => 1, 'run_id' => $context['run_id'], 'customer_id' => $context['customer_id'],
        'started' => time(), 'baseline_stock' => $stock, 'unit_price' => (string)$product->get_price(),
        'prices_include_tax' => wc_prices_include_tax(),
        'product_label' => $product_label, 'admin_recipients' => petish_order_admin_recipients(),
        'max_prior_order' => $highest ? (int)max($highest) : 0, 'capture_file' => $capture,
        'capture_device' => $capture_stat['dev'], 'capture_inode' => $capture_stat['ino'],
        'capture_offset' => $capture_stat['size'], 'fixture_path' => $target, 'fixture_sha256' => hash('sha256', $source),
        'cart_key' => $cart_key, 'cart_existed' => metadata_exists('user', $context['customer_id'], $cart_key),
        'cart_before' => get_user_meta($context['customer_id'], $cart_key, true),
        'phase' => 'preparing', 'refund_started' => false, 'order_id' => null, 'cleaned' => false];
    petish_order_save($context, $journal);
    petish_order_write($context['work'] . '/email-events.jsonl', '');
    petish_order_write($context['work'] . '/stock-events.jsonl', '');
    petish_order_write($fixture_path, ['run_id' => $context['run_id'], 'customer_id' => $context['customer_id'],
        'variation_id' => 246, 'quantity' => 1, 'country' => 'AU', 'postcode' => '3083', 'currency' => 'AUD',
        'shipping_ex_tax' => 6, 'started' => $journal['started'], 'expires' => time() + 7200,
        'email_events' => $context['work'] . '/email-events.jsonl', 'stock_events' => $context['work'] . '/stock-events.jsonl']);
    $temporary = dirname($target) . '/.petish-rehearsal-' . $context['run_id'] . '.tmp';
    if (file_exists($temporary) || is_link($temporary)) petish_order_fail();
    $handle = fopen($temporary, 'xb');
    if (!$handle) petish_order_fail();
    try {
        if (!chmod($temporary, 0644) || fwrite($handle, $source) !== strlen($source) || !fflush($handle)) petish_order_fail();
    } finally { fclose($handle); }
    if (file_exists($target) || !rename($temporary, $target)) petish_order_fail();
    $journal['phase'] = 'prepared';
    petish_order_save($context, $journal);
    return ['prepared' => true, 'customer_id' => $context['customer_id'], 'customer_email' => $context['customer_email'],
        'variation_id' => 246, 'parent_id' => 242, 'quantity' => 1, 'shipping_method_id' => 'petish_rehearsal_shipping:' . $context['run_id'],
        'shipping_simulated' => true, 'baseline_stock' => $stock, 'started' => $journal['started']];
}

function petish_order_scoped($context, $journal, $id) {
    if (!is_int($id) || $id <= $journal['max_prior_order'] || in_array($id, [2139, 2140], true)) petish_order_fail();
    $order = wc_get_order($id);
    if (!$order || !function_exists('petish_rehearsal_fixture_order')
        || !petish_rehearsal_fixture_order($order, petish_rehearsal_fixture_config())
        || $order->get_payment_method() !== 'woocommerce_payments' || $order->get_meta('_wcpay_mode', true) !== 'test'
        || $order->get_currency() !== 'AUD' || $order->get_billing_email() !== $context['customer_email']) petish_order_fail();
    $items = $order->get_items('line_item'); $item = reset($items);
    $line_amount = (float)$item->get_total() + (!empty($journal['prices_include_tax']) ? (float)$item->get_total_tax() : 0);
    if (abs($line_amount - (float)$journal['unit_price']) > 0.02) petish_order_fail();
    return $order;
}

function petish_order_events($context, $kind, $id) {
    $path = $context['work'] . '/' . $kind . '-events.jsonl';
    petish_order_private_file($path);
    $events = [];
    $handle = fopen($path, 'rb');
    try {
        while (($line = fgets($handle, 1048577)) !== false) {
            if (strlen($line) > 1048576 || !str_ends_with($line, "\n")) petish_order_fail();
            $entry = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            if (($entry['run_id'] ?? null) === $context['run_id'] && ($entry['order_id'] ?? null) === $id) $events[] = $entry;
        }
    } finally { fclose($handle); }
    return $events;
}

function petish_order_captured($context, $journal, $id) {
    $path = $journal['capture_file'];
    petish_order_private_file($path);
    $stat = stat($path);
    if ($stat['dev'] !== $journal['capture_device'] || $stat['ino'] !== $journal['capture_inode']
        || $stat['size'] < $journal['capture_offset']) petish_order_fail();
    $captured = [];
    $handle = fopen($path, 'rb');
    try {
        if (fseek($handle, $journal['capture_offset']) !== 0) petish_order_fail();
        while (($line = fgets($handle, 4194305)) !== false) {
            if (strlen($line) > 4194304 || !str_ends_with($line, "\n")) petish_order_fail();
            // The guard's inspected schema has a string headers field. Only
            // Exact header markers identify the message; private content checks
            // then verify its recipient and native order details.
            $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            $headers = $record['headers'] ?? null;
            if (!is_string($headers)) continue;
            if (!preg_match('/(?:\A|[\r\n])X-Petish-Rehearsal: ' . preg_quote($context['run_id'], '/') . '(?:\r?\n|\z)/D', $headers)
                || !preg_match('/(?:\A|[\r\n])X-Petish-Rehearsal-Order: ' . $id . '(?:\r?\n|\z)/D', $headers)) continue;
            if (preg_match('/(?:\A|[\r\n])X-Petish-Rehearsal-Email: (new_order|failed_order|customer_failed_order|customer_processing_order|customer_note|customer_completed_order|customer_refunded_order)(?:\r?\n|\z)/D', $headers, $match)) {
                petish_order_email_contents($context, $journal, $id, $match[1], $record);
                $captured[] = $match[1];
            }
        }
    } finally { fclose($handle); }
    return $captured;
}

function petish_order_email_contents($context, $journal, $id, $type, $record) {
    if (!is_string($record['to'] ?? null) || !is_string($record['subject'] ?? null)
        || !is_string($record['message'] ?? null)) petish_order_fail();
    $subject = petish_order_mail_text($record['subject']);
    $body = petish_order_mail_text($record['message']);
    if ($subject === '' || $body === '' || !preg_match('/(?<![0-9])' . $id . '(?![0-9])/', $body)) petish_order_fail();
    $actual = petish_order_mail_addresses($record['to']);
    $expected = $type === 'new_order' || $type === 'failed_order' ? ($journal['admin_recipients'] ?? []) : [strtolower($context['customer_email'])];
    if (!$expected || $actual !== $expected) petish_order_fail();
    $label = $journal['product_label'] ?? '';
    if (!is_string($label) || strlen($label) < 3 || !str_contains($body, $label)) petish_order_fail();
    $order = wc_get_order($id);
    if (!$order || $order->get_currency() !== 'AUD') petish_order_fail();
    // Compare the native currency/amount fragment after HTML/space normalization,
    // rather than relying on a whole template or one literal decimal format.
    $money = petish_order_mail_text(wc_price($order->get_total(), ['currency' => $order->get_currency()]));
    if ($money === '' || !str_contains(preg_replace('/\s+/u', '', $body), preg_replace('/\s+/u', '', $money))) petish_order_fail();
    if ($type === 'customer_note') {
        foreach (['staging rehearsal ' . $context['run_id'], 'synthetic tracking demonstration only',
            'no parcel or supplier order', 'https://tracking.invalid/rehearsal/' . $context['run_id']] as $marker) {
            if (!str_contains($body, $marker)) petish_order_fail();
        }
    }
}

function petish_order_provider($context, $order, $refunded) {
    if (!is_callable($context['provider_probe'] ?? null)) petish_order_fail();
    $proof = ($context['provider_probe'])($order);
    $amount = (int)round((float)$order->get_total() * 100);
    if (!is_array($proof) || ($proof['livemode'] ?? null) !== false || ($proof['paid'] ?? null) !== true
        || ($proof['captured'] ?? null) !== true || ($proof['amount_minor'] ?? null) !== $amount
        || ($proof['order_reference_verified'] ?? null) !== true || ($proof['currency'] ?? null) !== 'aud'
        || !is_int($proof['amount_refunded_minor'] ?? null)) petish_order_fail();
    if ($refunded && ($proof['amount_refunded_minor'] !== $amount || ($proof['refund_succeeded'] ?? null) !== true)) petish_order_fail();
    if (!$refunded && $proof['amount_refunded_minor'] !== 0) petish_order_fail();
    return $proof;
}

function petish_order_emails($context, $journal, $id, $required) {
    $events = petish_order_events($context, 'email', $id);
    $ids = array_column($events, 'email_id');
    foreach ($required as $type) if (!in_array($type, $ids, true)) petish_order_fail();
    $captured = petish_order_captured($context, $journal, $id);
    foreach ($required as $type) if (!in_array($type, $captured, true)) petish_order_fail();
    return count($events);
}

function petish_rehearsal_verify($context, $browser) {
    $context = petish_order_context($context);
    return petish_order_exclusive($context, static function () use ($context, $browser) { return petish_order_verify_locked($context, $browser); });
}

function petish_order_verify_locked($context, $browser) {
    $context = petish_order_context($context);
    $journal = petish_order_journal($context);
    if (!is_array($browser) || !is_int($browser['order_id'] ?? null)) petish_order_fail();
    $order = petish_order_scoped($context, $journal, $browser['order_id']);
    // Persist the exact fresh QA identity before any provider request or later
    // acceptance assertion can fail, so an uncertain result remains recoverable.
    $journal['order_id'] = $order->get_id();
    $journal['phase'] = $order->is_paid() || $order->get_transaction_id() ? 'payment_observed' : 'order_observed';
    petish_order_save($context, $journal);
    if (($browser['decline_verified'] ?? null) !== true
        || ($browser['paid_receipt_verified'] ?? null) !== true || ($browser['payment_attempts'] ?? null) !== 2
        || !is_int($browser['order_id'] ?? null) || !is_string($browser['order_key'] ?? null)) petish_order_fail();
    if (!hash_equals($order->get_order_key(), $browser['order_key']) || !$order->is_paid()
        || !$order->get_date_paid() || !$order->get_transaction_id() || !$order->has_status('processing')) petish_order_fail();
    petish_order_provider($context, $order, false);
    if ((float)petish_order_product()->get_stock_quantity() !== (float)$journal['baseline_stock'] - 1.0) petish_order_fail();
    $failed = false; $paid = false;
    $declined_id = $browser['declined_order_id'] ?? $order->get_id();
    if (!is_int($declined_id)) petish_order_fail();
    $declined_order = petish_order_scoped($context, $journal, $declined_id);
    if ($declined_id !== $order->get_id() && (!$declined_order->has_status('failed') || $declined_order->is_paid()
        || $declined_order->get_transaction_id())) petish_order_fail();
    foreach (petish_order_events($context, 'stock', $declined_id) as $event) {
        if (($event['event'] ?? '') === 'failed' && (float)($event['stock'] ?? -1) === (float)$journal['baseline_stock']) $failed = true;
    }
    foreach (petish_order_events($context, 'stock', $order->get_id()) as $event) {
        if (in_array($event['event'] ?? '', ['processing', 'payment_complete'], true)
            && (float)($event['stock'] ?? -1) === (float)$journal['baseline_stock'] - 1.0) $paid = true;
    }
    if (!$failed || !$paid) petish_order_fail();
    $emails = petish_order_emails($context, $journal, $order->get_id(), ['new_order', 'customer_processing_order']);
    $journal['phase'] = 'paid_verified'; $journal['order_id'] = $order->get_id();
    $journal['amount_minor'] = (int)round((float)$order->get_total() * 100);
    petish_order_save($context, $journal);
    return ['paid_test_order_verified' => true, 'decline_stock_unchanged' => true, 'paid_stock_reduced_once' => true,
        'provider_test_payment_verified' => true, 'native_order_emails_captured' => true, 'email_event_count' => $emails,
        'native_email_contents_verified' => true];
}

function petish_rehearsal_refund($context, $browser = []) {
    $context = petish_order_context($context);
    return petish_order_exclusive($context, static function () use ($context, $browser) {
        return petish_order_refund_locked($context, $browser);
    });
}

function petish_order_refund_locked($context, $browser = []) {
    $context = petish_order_context($context);
    $journal = petish_order_journal($context);
    $id = $journal['order_id'] ?? null;
    $order = petish_order_scoped($context, $journal, $id);
    if (!in_array($journal['phase'], ['payment_observed', 'paid_verified', 'completion_started', 'refund_started', 'refunded_verified'], true)) petish_order_fail();
    if ($journal['refund_started']) {
        // Never issue a second refund after a timeout or stale provider UI.
        petish_order_provider($context, $order, true);
        if (abs((float)$order->get_total_refunded() - (float)$order->get_total()) > 0.001) petish_order_fail();
    } else {
        if (!is_callable($context['with_provider'] ?? null)) petish_order_fail();
        petish_order_provider($context, $order, false);
        $journal['phase'] = 'completion_started'; petish_order_save($context, $journal);
        $marker = 'STAGING REHEARSAL ' . $context['run_id'];
        $exists = false;
        foreach (wc_get_order_notes(['order_id' => $id, 'type' => 'customer', 'limit' => 100]) as $note) {
            if (str_contains($note->content, $marker)) $exists = true;
        }
        if (!$exists) $order->add_order_note($marker . ': synthetic tracking demonstration only; no parcel or supplier order. https://tracking.invalid/rehearsal/' . $context['run_id'], true);
        if (!$order->has_status('completed')) $order->update_status('completed', $marker . ' — test completion, no shipment.', true);
        petish_order_emails($context, $journal, $id, ['new_order', 'customer_processing_order', 'customer_note', 'customer_completed_order']);
        $lines = [];
        foreach ($order->get_items(['line_item', 'shipping', 'fee']) as $item_id => $item) {
            $taxes = $item->get_taxes();
            $lines[$item_id] = ['qty' => is_a($item, 'WC_Order_Item_Product') ? $item->get_quantity() : 0,
                'refund_total' => $item->get_total(), 'refund_tax' => $taxes['total'] ?? []];
        }
        $journal['refund_started'] = true; $journal['phase'] = 'refund_started'; petish_order_save($context, $journal);
        $arguments = ['order_id' => $id, 'amount' => $order->get_total(),
            'reason' => $marker . ' — full native TEST refund; no real money.',
            'refund_payment' => true, 'restock_items' => true, 'line_items' => $lines];
        $refund = ($context['with_provider'])(static function () use ($arguments) { return wc_create_refund($arguments); });
        if (is_wp_error($refund) || !is_a($refund, 'WC_Order_Refund')) petish_order_fail();
        $refund->update_meta_data('_petish_rehearsal_run', $context['run_id']); $refund->save();
        $journal['refund_id'] = $refund->get_id(); petish_order_save($context, $journal);
    }
    $order = petish_order_scoped($context, $journal, $id);
    petish_order_provider($context, $order, true);
    if (!$order->has_status('refunded') || abs((float)$order->get_total_refunded() - (float)$order->get_total()) > 0.001
        || (float)petish_order_product()->get_stock_quantity() !== (float)$journal['baseline_stock']) petish_order_fail();
    petish_order_emails($context, $journal, $id, ['new_order', 'customer_processing_order', 'customer_note', 'customer_completed_order', 'customer_refunded_order']);
    $journal['phase'] = 'refunded_verified'; petish_order_save($context, $journal);
    return ['native_test_refund_verified' => true, 'provider_full_test_refund_verified' => true,
        'completed_email_captured' => true, 'refund_email_captured' => true, 'stock_restored_to_baseline' => true,
        'synthetic_tracking_note_only' => true, 'supplier_action_performed' => false,
        'native_email_contents_verified' => true, 'customer_note_email_captured' => true];
}

function petish_rehearsal_cleanup($context) {
    $context = petish_order_context($context);
    return petish_order_exclusive($context, static function () use ($context) { return petish_order_cleanup_locked($context); });
}

function petish_order_cleanup_locked($context) {
    $context = petish_order_context($context);
    $journal = petish_order_journal($context);
    $uncertain = in_array($journal['phase'], ['payment_observed', 'paid_verified', 'completion_started', 'refund_started'], true);
    if (!$uncertain && $journal['phase'] !== 'refunded_verified') {
        $fresh = wc_get_orders(['customer_id' => $context['customer_id'], 'date_created' => '>=' . $journal['started'],
            'limit' => 100, 'orderby' => 'ID', 'order' => 'DESC']);
        foreach ($fresh as $candidate) {
            if ($candidate->get_id() <= $journal['max_prior_order'] || $candidate->get_meta('_petish_rehearsal_run', true) !== $context['run_id']) continue;
            if ($candidate->is_paid() || $candidate->get_transaction_id()) {
                $journal['order_id'] = $candidate->get_id(); $journal['phase'] = 'payment_observed';
                petish_order_save($context, $journal); $uncertain = true; break;
            }
        }
    }
    if ($uncertain) {
        return ['shipping_fixture_removed' => false, 'qa_cart_cleared' => false,
            'order_refunded_before_cleanup' => false, 'recovery_required' => true];
    }
    $target = $journal['fixture_path'];
    if ($target !== $context['root'] . '/wp-content/mu-plugins/petish-rehearsal-fixture.php') petish_order_fail();
    if (file_exists($target) || is_link($target)) {
        if (is_link($target) || hash_file('sha256', $target) !== $journal['fixture_sha256'] || !unlink($target)) petish_order_fail();
    }
    if ($journal['cart_existed']) update_user_meta($context['customer_id'], $journal['cart_key'], $journal['cart_before']);
    else delete_user_meta($context['customer_id'], $journal['cart_key']);
    if (class_exists('WC_Session_Handler')) {
        $session = new WC_Session_Handler(); $session->delete_session((string)$context['customer_id']);
    }
    $journal['cleaned'] = true; petish_order_save($context, $journal);
    return ['shipping_fixture_removed' => true, 'qa_cart_cleared' => true,
        'order_refunded_before_cleanup' => $journal['phase'] === 'refunded_verified', 'recovery_required' => false];
}
