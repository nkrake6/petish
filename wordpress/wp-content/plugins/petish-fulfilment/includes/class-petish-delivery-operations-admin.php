<?php
defined('ABSPATH') || exit;

/** Staff-only, request-local rate previews and explicitly controlled shipment actions. */
final class Petish_Delivery_Operations_Admin {
    const SLUG = 'petish-delivery-operations';
    const NONCE = 'petish_delivery_operations';
    const TEMPLATE_ACTION = 'petish_delivery_rate_template';
    const MAX_BYTES = 1048576;
    const MAX_ROWS = 500;
    const COLUMNS = array('product_id','variation_id','product_label','expected_sku','expected_parent_sku','supplier_product_url','shipping_fingerprint',
        'country','states','cities','postcodes','country_scope_verified','service','evidence_kind','evidence_reference','observed_utc','expires_utc',
        'evidence_reviewed','supplier_currency','free_shipping','pricing_type','quantity','min_quantity','max_quantity',
        'customer_shipping_ex_tax_aud','supplier_freight_total','first_customer_shipping_ex_tax_aud','additional_customer_shipping_ex_tax_aud','supplier_first_freight','supplier_additional_freight');

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::TEMPLATE_ACTION, array(__CLASS__, 'download'));
    }
    public static function menu() {
        add_submenu_page('woocommerce', 'Delivery operations', 'Delivery operations', 'manage_woocommerce', self::SLUG, array(__CLASS__, 'render'));
    }
    public static function authorized($nonce = null, $action = null) {
        return current_user_can('manage_woocommerce') && ($nonce === null || (is_string($nonce) && wp_verify_nonce($nonce, $action ?? self::NONCE)));
    }
    private static function deny() {
        wp_die(esc_html__('You cannot access delivery operations.', 'petish-fulfilment'), '', array('response'=>403));
    }
    private static function positive_id($value, $zero = false) {
        if ((!is_string($value) && !is_int($value)) || !preg_match($zero ? '/^(0|[1-9][0-9]{0,9})$/D' : '/^[1-9][0-9]{0,9}$/D', (string)$value)) return false;
        return (int)$value;
    }
    private static function text($value, $maximum = 2000) {
        if (!is_string($value) || strlen($value) > $maximum || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) throw new InvalidArgumentException('A CSV field is invalid or too long.');
        return trim($value);
    }
    public static function csv_cell($value) {
        $value = (string)$value;
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'" . $value : $value;
    }
    public static function csv($rows) {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) throw new InvalidArgumentException('Export at most 500 rows.');
        $stream = fopen('php://temp', 'w+');
        if (!$stream) throw new InvalidArgumentException('The CSV could not be prepared.');
        try {
            fputcsv($stream, self::COLUMNS, ',', '"', '');
            foreach ($rows as $row) {
                $values = array();
                foreach (self::COLUMNS as $column) $values[] = self::csv_cell($row[$column] ?? '');
                fputcsv($stream, $values, ',', '"', '');
                if (ftell($stream) > self::MAX_BYTES) throw new InvalidArgumentException('The CSV exceeds 1 MiB.');
            }
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }
    public static function parse_csv($contents) {
        if (!is_string($contents) || $contents === '' || strlen($contents) > self::MAX_BYTES || preg_match('//u', $contents) !== 1 || str_contains($contents, "\0")) throw new InvalidArgumentException('Upload a UTF-8 CSV of at most 1 MiB.');
        $stream = fopen('php://temp', 'w+');
        if (!$stream) throw new InvalidArgumentException('The CSV could not be read.');
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $contents)); rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($header) || count($header) !== count(self::COLUMNS) || count(array_unique($header)) !== count($header) || array_diff(self::COLUMNS, $header)) throw new InvalidArgumentException('Keep every heading from the downloaded template exactly as supplied.');
            $rows = array();
            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($values === array(null)) continue;
                if (count($rows) >= self::MAX_ROWS || count($values) !== count($header)) throw new InvalidArgumentException('Use at most 500 rows with the supplied columns.');
                $row = array_combine($header, $values);
                foreach ($row as &$value) $value = self::text($value);
                unset($value); $rows[] = $row;
            }
            if (!$rows) throw new InvalidArgumentException('The CSV has no data rows.');
            return $rows;
        } finally { fclose($stream); }
    }
    /** Shared by downloads and preview identity checks; no catalogue writes. */
    public static function product_identity($product) {
        if (!$product instanceof WC_Product || !$product->is_type(array('simple','variation')) || !$product->needs_shipping()) return null;
        $variation = $product->is_type('variation');
        $parent = $variation ? wc_get_product($product->get_parent_id()) : $product;
        if (!$parent || $parent->get_status('edit') !== 'publish' || $product->get_status('edit') !== 'publish' || !$parent->is_visible()
            || ($variation && (!$parent->is_type('variable') || !$product->variation_is_visible())) || !$product->is_purchasable() || !$product->is_in_stock() || !$product->has_enough_stock(1)) return null;
        $fingerprint = Petish_Supplier_Quotes::fingerprint($product);
        $source = $parent->get_meta('_petish_supplier_product_url', true, 'edit');
        if (is_wp_error($fingerprint) || !$product->get_sku('edit') || !$parent->get_sku('edit') || !is_string($source) || $source === '') return null;
        $label = wp_strip_all_tags($parent->get_name());
        if ($variation) $label .= ' — ' . html_entity_decode(wp_strip_all_tags(wc_get_formatted_variation($product, true, true, false)), ENT_QUOTES, 'UTF-8');
        preg_match('/^.{0,120}/us', $label, $match);
        return array('product_id'=>$parent->get_id(),'variation_id'=>$variation ? $product->get_id() : 0,'product_label'=>$match[0] ?? '',
            'expected_sku'=>$product->get_sku('edit'),'expected_parent_sku'=>$parent->get_sku('edit'),'supplier_product_url'=>$source,'shipping_fingerprint'=>$fingerprint);
    }
    public static function download() {
        if (!self::authorized(isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '')) { self::deny(); return; }
        $batch = self::positive_id($_GET['batch'] ?? '1');
        if ($batch === false || $batch > 9999) wp_die('Choose a catalogue batch between 1 and 9999.', '', array('response'=>400));
        $query = new WP_Query(array('post_type'=>array('product','product_variation'),'post_status'=>'publish','posts_per_page'=>self::MAX_ROWS,
            'paged'=>$batch,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','no_found_rows'=>true,'update_post_term_cache'=>false));
        $rows = array();
        foreach ($query->posts as $id) { $identity = self::product_identity(wc_get_product($id)); if ($identity) $rows[] = $identity; }
        if (!$rows) wp_die('This batch has no available supplier-mapped physical products/options. Try an earlier batch.', '', array('response'=>400));
        try { $csv = self::csv($rows); }
        catch (InvalidArgumentException $ignored) { wp_die('The template could not be prepared.', '', array('response'=>400)); return; }
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="petish-delivery-rate-template-' . $batch . '.csv"');
        echo $csv; exit;
    }
    private static function list_cell($value) {
        if ($value === '') return array();
        $values = explode('|', $value);
        if (count($values) > 1000) throw new InvalidArgumentException('A destination list is too long.');
        foreach ($values as &$item) { $item = trim($item); if ($item === '') throw new InvalidArgumentException('Destination lists cannot contain empty entries.'); }
        unset($item); return $values;
    }
    private static function boolean_cell($value) {
        if ($value === '' || $value === 'false') return false;
        if ($value === 'true') return true;
        throw new InvalidArgumentException('Use true, false or blank for confirmation fields.');
    }
    private static function rule($row) {
        $parent = self::positive_id($row['product_id']); $variation = self::positive_id($row['variation_id'], true);
        $quantity = self::positive_id($row['quantity']);
        if ($parent === false || $variation === false || $quantity === false || $quantity > 10000) throw new InvalidArgumentException('Use current numeric product IDs and a whole preview quantity between 1 and 10,000.');
        $product = wc_get_product($variation ?: $parent); $identity = self::product_identity($product);
        if (!$identity || $identity['product_id'] !== $parent || $identity['variation_id'] !== $variation) throw new InvalidArgumentException('This product/option is unavailable. Download a fresh template.');
        foreach (array('expected_sku','expected_parent_sku','supplier_product_url','shipping_fingerprint') as $field) {
            if ($row[$field] !== $identity[$field] && $row[$field] !== self::csv_cell($identity[$field])) throw new InvalidArgumentException('The product identity, supplier mapping or shipping specification changed. Download a fresh template.');
        }
        $pricing = array('type'=>$row['pricing_type']);
        if ($row['pricing_type'] === 'exact_quantity') {
            $pricing += array('quantity'=>$quantity,'customer_shipping_ex_tax_aud'=>$row['customer_shipping_ex_tax_aud'],'supplier_freight_total'=>$row['supplier_freight_total']);
            foreach (array('min_quantity','max_quantity','first_customer_shipping_ex_tax_aud','additional_customer_shipping_ex_tax_aud','supplier_first_freight','supplier_additional_freight') as $field) if ($row[$field] !== '') throw new InvalidArgumentException('An exact-quantity row cannot contain first/additional-unit fields.');
        } elseif ($row['pricing_type'] === 'first_additional') {
            $minimum = self::positive_id($row['min_quantity']); $maximum = self::positive_id($row['max_quantity']);
            if ($minimum === false || $maximum === false || $minimum > $quantity || $quantity > $maximum || $maximum > 10000) throw new InvalidArgumentException('The preview quantity must be inside the explicitly supported quantity range.');
            $pricing += array('min_quantity'=>$minimum,'max_quantity'=>$maximum,'first_customer_shipping_ex_tax_aud'=>$row['first_customer_shipping_ex_tax_aud'],
                'additional_customer_shipping_ex_tax_aud'=>$row['additional_customer_shipping_ex_tax_aud'],'supplier_first_freight'=>$row['supplier_first_freight'],'supplier_additional_freight'=>$row['supplier_additional_freight']);
            if ($row['customer_shipping_ex_tax_aud'] !== '' || $row['supplier_freight_total'] !== '') throw new InvalidArgumentException('A first/additional-unit row cannot also specify an exact-quantity total.');
        } else throw new InvalidArgumentException('Choose exact_quantity or first_additional.');
        $rule = array_intersect_key($identity, array_flip(array('product_id','variation_id','expected_sku','expected_parent_sku','supplier_product_url','shipping_fingerprint')));
        $rule += array('country'=>$row['country'],'destination_scope'=>array('states'=>self::list_cell($row['states']),'cities'=>self::list_cell($row['cities']),'postcodes'=>self::list_cell($row['postcodes'])),
            'country_scope_verified'=>self::boolean_cell($row['country_scope_verified']),'service'=>$row['service'],'evidence_kind'=>$row['evidence_kind'],
            'evidence_reference'=>$row['evidence_reference'],'observed_utc'=>$row['observed_utc'],'expires_utc'=>$row['expires_utc'],
            'evidence_reviewed'=>self::boolean_cell($row['evidence_reviewed']),'supplier_currency'=>$row['supplier_currency'],'free_shipping'=>self::boolean_cell($row['free_shipping']),'pricing'=>$pricing);
        return array($rule,$identity,$product,$quantity);
    }
    /** Results stay in this response. No drafts, rates, approvals or quotes are persisted. */
    public static function preview_csv($contents, $now = null) {
        if (!self::authorized()) throw new InvalidArgumentException('You cannot preview delivery rates.');
        if (!class_exists('Petish_Automatic_Shipping')) throw new InvalidArgumentException('WooCommerce delivery pricing is unavailable.');
        $rows = self::parse_csv($contents); $rules = array(); $prepared = array(); $results = array();
        foreach ($rows as $index=>$row) {
            try { $prepared[$index] = self::rule($row); $rules[] = $prepared[$index][0]; }
            catch (InvalidArgumentException $error) { $results[$index] = array('row'=>$index + 2,'status'=>'invalid','error'=>$error->getMessage()); }
        }
        $book = array('schema_version'=>2,'rules'=>$rules); $validated = Petish_Automatic_Shipping::validate_book($book,$now);
        if (is_wp_error($validated)) throw new InvalidArgumentException('The rate schedule could not be validated.');
        $position = 0;
        foreach ($prepared as $index=>$entry) {
            [$rule,$identity,$product,$quantity] = $entry; $key = $position++;
            if (isset($validated['errors'][$key]) || !isset($validated['rules'][$key])) {
                $results[$index] = array('row'=>$index + 2,'status'=>'invalid','error'=>'The supplier evidence, scope or pricing is invalid: ' . ($validated['errors'][$key] ?? 'invalid_rule')); continue;
            }
            $scope = $rule['destination_scope'];
            $package = array('contents'=>array(array('data'=>$product,'product_id'=>$identity['product_id'],'variation_id'=>$identity['variation_id'],'quantity'=>$quantity)),
                'destination'=>array('country'=>$rule['country'],'state'=>$scope['states'][0] ?? '','city'=>$scope['cities'][0] ?? '','postcode'=>$scope['postcodes'][0] ?? ''));
            // Each row is an independent scenario; no rate is inferred for another row or basket.
            $rate = Petish_Automatic_Shipping::rate_package($package,array('schema_version'=>2,'rules'=>array($rule)),$now);
            if (is_wp_error($rate)) { $results[$index] = array('row'=>$index + 2,'status'=>'invalid','error'=>'This scenario has no available delivery rate: ' . $rate->get_error_code()); continue; }
            $results[$index] = array('row'=>$index + 2,'status'=>'valid','identity'=>$identity,'rule'=>$validated['rules'][$key],'quantity'=>$quantity,'cost'=>$rate['cost']);
        }
        ksort($results); return array_values($results);
    }
    private static function upload() {
        if (!self::authorized(isset($_POST['_wpnonce']) ? wp_unslash($_POST['_wpnonce']) : '')) throw new InvalidArgumentException('Your form expired or you do not have access. Refresh the page.');
        $file = $_FILES['petish_rate_csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > self::MAX_BYTES
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Choose an uploaded CSV of at most 1 MiB.');
        $contents = file_get_contents($file['tmp_name'],false,null,0,self::MAX_BYTES + 1);
        return self::preview_csv($contents);
    }
    private static function event_id($value) { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D',$value) ? $value : false; }
    private static function operation_nonce($operation,$order,$event) { return self::NONCE . ':' . $operation . ':' . $order . ':' . $event; }
    private static function notifications_enabled() { return get_option('petish_shipment_ledger_enabled','no') === 'yes' && get_option('petish_shipment_notifications_enabled','no') === 'yes'; }
    /** Every mutation requires both UI authorization and the ledger's independent state checks. */
    public static function notification_action($post) {
        if (!is_array($post) || !self::authorized()) return new WP_Error('petish_operations_permission','You cannot manage shipment notifications.');
        $order = self::positive_id($post['order_id'] ?? null); $event = self::event_id($post['event_id'] ?? null); $operation = $post['petish_operation'] ?? null;
        if ($order === false || $event === false || !in_array($operation,array('retry','reconcile','confirmed_submitted','confirmed_not_sent'),true)
            || !self::authorized($post['_wpnonce'] ?? '',self::operation_nonce($operation,$order,$event))) return new WP_Error('petish_operations_request','The action expired or is invalid. Refresh the order view.');
        if (!class_exists('Petish_Shipment_Ledger') || !class_exists('Petish_Shipment_Notifications')) return new WP_Error('petish_operations_unavailable','Shipment operations are unavailable.');
        $ledger = Petish_Shipment_Ledger::read($order);
        if (is_wp_error($ledger)) return new WP_Error('petish_operations_order','The shipment ledger is unavailable for this order.');
        $entry = $ledger['outbox'][$event] ?? null;
        if (!is_array($entry)) return new WP_Error('petish_operations_event','Choose an existing notification.');
        if ($operation === 'reconcile') {
            if (($entry['status'] ?? null) !== 'sending' || !is_int($entry['lease_until'] ?? null) || $entry['lease_until'] > time()) return new WP_Error('petish_operations_status','Only an expired sending attempt may be reconciled.');
            return Petish_Shipment_Notifications::reconcile($order);
        }
        if ($operation === 'retry') {
            if (($entry['status'] ?? null) !== 'failed') return new WP_Error('petish_operations_status','Only a definitively unsent, failed notification may be retried.');
            if (!self::notifications_enabled()) return new WP_Error('petish_operations_disabled','Notification sending is disabled. This action cannot enable it.');
            return Petish_Shipment_Notifications::retry($order,$event);
        }
        if (($entry['status'] ?? null) !== 'uncertain' || ($post['confirm_resolution'] ?? null) !== 'yes') return new WP_Error('petish_operations_confirmation','Review the delivery evidence and explicitly confirm this resolution.');
        return Petish_Shipment_Notifications::resolve_uncertain($order,$event,$operation);
    }
    private static function page_url($tab) { return admin_url('admin.php?page=' . self::SLUG . '&tab=' . $tab); }
    public static function render() {
        if (!self::authorized()) { self::deny(); return; }
        nocache_headers();
        $tab = isset($_GET['tab']) && $_GET['tab'] === 'notifications' ? 'notifications' : 'rates';
        echo '<div class="wrap"><h1>Delivery operations</h1><nav class="nav-tab-wrapper" aria-label="Delivery operations">';
        foreach (array('rates'=>'Rate preview','notifications'=>'Shipment notifications') as $key=>$label) echo '<a class="nav-tab' . ($tab === $key ? ' nav-tab-active' : '') . '" href="' . esc_url(self::page_url($key)) . '">' . esc_html($label) . '</a>';
        echo '</nav>';
        if ($tab === 'rates') self::rates_page(); else self::notifications_page();
        echo '</div>';
    }
    private static function rates_page() {
        echo '<p>Preview supplier-supported delivery schedules before deciding what to offer. This tool does not save costs, change active delivery rates, approve supplier-free delivery or contact a supplier.</p>';
        echo '<h2>1. Download current product identities</h2><p>Each batch scans 500 catalogue records and includes available supplier-mapped physical products/options. Destination, evidence, quantities and amounts start blank.</p><form method="get" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::TEMPLATE_ACTION) . '">';
        wp_nonce_field(self::NONCE);
        echo '<label for="petish-rate-batch">Catalogue batch </label><input id="petish-rate-batch" name="batch" type="number" min="1" max="9999" value="1" class="small-text"> ';
        submit_button('Download rate template','secondary','submit',false); echo '</form>';
        echo '<h2>2. Record reviewed supplier coverage</h2><ul style="list-style:disc;padding-left:24px"><li>Keep identity columns unchanged. Use <strong>exact_quantity</strong> for an actual quoted quantity and total; never divide its freight and assume it applies to other quantities.</li><li>Use <strong>first_additional</strong> only for a reviewed rate card with explicit first/additional-unit charges and quantity bounds. Enter the quantity you want to preview inside that range.</li><li>Country uses a two-letter code. Separate explicitly supported states, cities or postcodes with <strong>|</strong>. Blank scope lists require explicit country-wide evidence and <strong>country_scope_verified=true</strong>.</li><li>Record the service, supplier currency, source reference and exact UTC observation/expiry (YYYY-MM-DDTHH:MM:SSZ). Validity is at most seven days. Estimates cannot be activated. Enter <strong>evidence_reviewed=true</strong> only after checking the source.</li><li>Supplier freight stays in its original currency; customer charges are excluding tax in AUD. Blank is unknown. Explicit zero customer delivery also requires <strong>free_shipping=true</strong>; this does not approve a supplier-free-delivery badge.</li></ul>';
        $results = null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try { $results = self::upload(); }
            catch (InvalidArgumentException $error) { echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html($error->getMessage()) . '</p></div>'; }
        }
        echo '<h2>3. Preview the independent scenarios</h2><p>UTF-8 CSV, at most 500 rows / 1 MiB. Each row previews one product, quantity and destination; rows are not added together as a combined supplier parcel.</p><form method="post" enctype="multipart/form-data">';
        wp_nonce_field(self::NONCE); echo '<label for="petish-rate-csv">Completed CSV </label><input type="file" id="petish-rate-csv" name="petish_rate_csv" accept=".csv,text/csv" required>';
        submit_button('Preview delivery rates'); echo '</form>';
        if ($results !== null) {
            echo '<h2>Rate preview</h2><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>CSV row / product</th><th>Coverage</th><th>Service / evidence</th><th>Customer delivery, ex-tax AUD</th><th>Result</th></tr></thead><tbody>';
            foreach ($results as $result) {
                echo '<tr><td>' . (int)$result['row'];
                if ($result['status'] !== 'valid') { echo '</td><td colspan="4">' . esc_html($result['error']) . '</td></tr>'; continue; }
                $rule = $result['rule']; $identity = $result['identity']; $scope = $rule['destination_scope'];
                echo '<br><strong>' . esc_html($identity['product_label']) . '</strong><br>' . esc_html($identity['expected_sku']) . '</td><td>' . esc_html($rule['country']);
                foreach (array('states','cities','postcodes') as $field) if (!empty($scope[$field])) echo '<br>' . esc_html(implode(', ',$scope[$field]));
                echo '<br>Quantity ' . (int)$result['quantity'] . '</td><td>' . esc_html($rule['service']) . '<br>' . esc_html($rule['evidence_kind']) . '<br>Expires ' . esc_html($rule['expires_utc']) . '</td><td>A$' . esc_html($result['cost']) . '</td><td>Valid independent preview<br>Not saved or activated</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<p><strong>Active checkout delivery remains unchanged.</strong> Publishing a reviewed rate book is a separate controlled step after supplier test evidence is available.</p>';
    }
    private static function notification_form($operation,$order,$event,$label,$confirmation = false) {
        echo '<form method="post" style="margin:8px 0"><input type="hidden" name="petish_operation" value="' . esc_attr($operation) . '"><input type="hidden" name="order_id" value="' . (int)$order . '"><input type="hidden" name="event_id" value="' . esc_attr($event) . '">';
        wp_nonce_field(self::operation_nonce($operation,$order,$event));
        if ($confirmation) {
            $text = $operation === 'confirmed_not_sent'
                ? 'I confirmed the sending process has stopped and provider evidence shows this message was not submitted.'
                : 'I checked provider evidence and confirm this message was already submitted.';
            echo '<label><input type="checkbox" name="confirm_resolution" value="yes" required> ' . esc_html($text) . '</label><br>';
        }
        submit_button($label,'secondary','submit',false); echo '</form>';
    }
    private static function notifications_page() {
        $order_id = self::positive_id(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['order_id'] ?? null) : ($_GET['order_id'] ?? null));
        echo '<p>Inspect recorded parcels and notification attempts. A tracking number means dispatch information, not carrier-confirmed delivery. Customer addresses and message bodies are not shown here.</p>';
        if (!self::notifications_enabled()) echo '<div class="notice notice-info inline"><p>Notification sending is disabled. These controls cannot enable a supplier adapter or send mail while it is disabled.</p></div>';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $result = self::notification_action(wp_unslash($_POST));
            if (is_wp_error($result)) {
                $code = $result->get_error_code();
                $messages = array('petish_operations_permission'=>'You cannot manage shipment notifications.',
                    'petish_operations_request'=>'The action expired or is invalid. Refresh the order view.',
                    'petish_operations_unavailable'=>'Shipment operations are unavailable.',
                    'petish_operations_order'=>'The shipment ledger is unavailable for this order.',
                    'petish_operations_event'=>'Choose an existing notification.',
                    'petish_operations_status'=>'Only a definitively unsent, failed notification may be retried.',
                    'petish_operations_disabled'=>'Notification sending is disabled. This action cannot enable it.',
                    'petish_operations_confirmation'=>'Review the sending evidence and explicitly confirm this resolution.',
                    'petish_shipment_sender_unconfigured'=>'A verified notification sender has not been connected. No message was sent.',
                    'petish_shipment_disabled'=>'Notification sending is disabled.',
                    'petish_shipment_lock'=>'This order is busy. Refresh its status before trying again.',
                    'petish_shipment_status'=>'The notification status changed. Refresh the order view.');
                echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html($messages[$code] ?? 'The action could not be completed. Refresh the ledger and review its current state before trying again.') . '</p></div>';
            }
            else echo '<div class="notice notice-success inline"><p>Notification action recorded. Check the updated status below; SMTP submission is not proof of inbox delivery.</p></div>';
        }
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="tab" value="notifications"><label for="petish-shipment-order">Order ID </label><input type="number" id="petish-shipment-order" name="order_id" min="1" value="' . ($order_id === false ? '' : (int)$order_id) . '"> ';
        submit_button('View shipment log','secondary','submit',false); echo '</form>';
        if ($order_id === false) return;
        if (!class_exists('Petish_Shipment_Ledger')) { echo '<p>Shipment records are unavailable.</p>'; return; }
        $ledger = Petish_Shipment_Ledger::read($order_id);
        if (is_wp_error($ledger)) { echo '<p>The shipment ledger could not be read for this order.</p>'; return; }
        echo '<h2>Order ' . (int)$order_id . '</h2>';
        $order = wc_get_order($order_id);
        $coverage = $order ? Petish_Shipment_Ledger::coverage($order,$ledger['parcels'] ?? array(),$ledger['required_items'] ?? null) : null;
        if ($coverage === 'dispatched') echo '<p><strong>All physical item quantities are allocated to dispatched parcels.</strong> This does not mean the carrier has delivered them.</p>';
        elseif ($coverage === 'partial') echo '<p><strong>Partially dispatched:</strong> physical item quantities are still outstanding.</p>';
        else echo '<p><strong>Coverage needs review:</strong> the current order quantities could not be reconciled with these parcels.</p>';
        if ($order && is_callable(array($order,'get_edit_order_url'))) echo '<p><a href="' . esc_url($order->get_edit_order_url()) . '">Open native order</a></p>';
        echo '<h3>Parcels</h3><table class="widefat striped"><thead><tr><th>Supplier shipment</th><th>Carrier / tracking</th><th>Dispatched</th><th>Order item quantities</th></tr></thead><tbody>';
        foreach ($ledger['parcels'] ?? array() as $parcel) {
            $shipment = $parcel['shipment'];
            echo '<tr><td>' . esc_html($shipment['supplier_id'] . ' / ' . $shipment['shipment_id']) . '</td><td>' . esc_html($shipment['carrier']) . '<br>' . esc_html($shipment['tracking_number']) . '</td><td>' . esc_html($shipment['dispatched_utc']) . '</td><td>';
            foreach ($shipment['items'] as $line=>$quantity) echo 'Item ' . (int)$line . ': ' . (int)$quantity . '<br>';
            echo '</td></tr>';
        }
        echo '</tbody></table><h3>Notification history</h3><p>Submitted means the configured sender accepted the attempt. Uncertain attempts require evidence review before any retry; an expired sending lease is not permission to send again. Queueing a retry records a pending attempt; a connected, approved sender must process the queue.</p><table class="widefat striped"><thead><tr><th>Event</th><th>Status</th><th>Attempts / sender</th><th>Operator action</th></tr></thead><tbody>';
        foreach ($ledger['outbox'] ?? array() as $id=>$event) {
            $status = $event['status'] ?? '';
            echo '<tr><td>' . esc_html($event['type'] ?? '') . '<br><small>' . esc_html($id) . '</small></td><td>' . esc_html($status) . '<br>' . esc_html($event['outcome_code'] ?? '') . '</td><td>' . (int)($event['attempts'] ?? 0) . '<br>' . esc_html($event['sender_id'] ?? '') . '</td><td>';
            if ($status === 'failed' && self::notifications_enabled()) self::notification_form('retry',$order_id,$id,'Queue confirmed unsent retry');
            elseif ($status === 'sending' && is_int($event['lease_until'] ?? null) && $event['lease_until'] <= time()) self::notification_form('reconcile',$order_id,$id,'Reconcile expired attempt (does not send)');
            elseif ($status === 'uncertain') {
                self::notification_form('confirmed_submitted',$order_id,$id,'Confirm already submitted',true);
                self::notification_form('confirmed_not_sent',$order_id,$id,'Confirm not sent',true);
            } else echo 'No automatic retry';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
