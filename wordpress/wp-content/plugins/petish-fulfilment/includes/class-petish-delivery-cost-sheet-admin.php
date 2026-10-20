<?php
defined('ABSPATH') || exit;

/** Private, ephemeral CSV previews. There is deliberately no rate-book save action. */
final class Petish_Delivery_Cost_Sheet_Admin {
    const SLUG = 'petish-delivery-cost-sheet';
    const ACTION = 'petish_delivery_cost_template';
    const NONCE = 'petish_delivery_cost_sheet';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'download'));
    }
    public static function menu() {
        add_submenu_page('woocommerce', 'Delivery cost sheet', 'Delivery cost sheet', 'manage_woocommerce', self::SLUG, array(__CLASS__, 'render'));
    }
    public static function authorized($nonce = null) {
        return current_user_can('manage_woocommerce') && ($nonce === null || (is_string($nonce) && wp_verify_nonce($nonce, self::NONCE)));
    }
    private static function deny() { wp_die(esc_html__('You cannot access this delivery cost sheet.', 'petish-fulfilment'), '', array('response'=>403)); }
    private static function identity($product) {
        if (!$product instanceof WC_Product || !$product->is_type(array('simple','variation')) || !$product->needs_shipping()) return null;
        $variation = $product->is_type('variation');
        $parent = $variation ? wc_get_product($product->get_parent_id()) : $product;
        if (!$parent || 'publish' !== $parent->get_status('edit') || 'publish' !== $product->get_status('edit')
            || !$parent->is_visible() || ($variation && (!$parent->is_type('variable') || !$product->variation_is_visible()))
            || !$product->is_purchasable() || !$product->is_in_stock() || !$product->has_enough_stock(1)) return null;
        $fingerprint = Petish_Supplier_Quotes::fingerprint($product);
        if (is_wp_error($fingerprint) || !$product->get_sku('edit') || !$parent->get_sku('edit')) return null;
        $maximum = 10000;
        if ($product->managing_stock() && !$product->backorders_allowed()) {
            $manager = wc_get_product($product->get_stock_managed_by_id());
            if (!$manager || !is_numeric($manager->get_stock_quantity('edit'))) return null;
            $maximum = max(0, min(10000, (int)floor((float)$manager->get_stock_quantity('edit'))));
        }
        $label = wp_strip_all_tags($parent->get_name());
        if ($variation) $label .= ' — ' . html_entity_decode(wp_strip_all_tags(wc_get_formatted_variation($product, true, true, false)), ENT_QUOTES, 'UTF-8');
        preg_match('/^.{0,120}/us', $label, $label_parts);
        return array('product_id'=>$parent->get_id(),'variation_id'=>$variation ? $product->get_id() : 0,
            'product_label'=>$label_parts[0] ?? '',
            'expected_sku'=>$product->get_sku('edit'),'expected_parent_sku'=>$parent->get_sku('edit'),
            'supplier_product_url'=>$parent->get_meta('_petish_supplier_product_url', true, 'edit'),
            'shipping_fingerprint'=>$fingerprint,'available'=>true,'maximum_quantity'=>$maximum);
    }
    /** Read only the identities referenced by this upload, not a full private catalogue export. */
    private static function catalogue($rows) {
        $catalogue = array();
        foreach ($rows as $row) {
            $id = $row['variation_id'] !== '0' ? $row['variation_id'] : $row['product_id'];
            if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id) || isset($catalogue[(int)$id])) continue;
            $identity = self::identity(wc_get_product((int)$id));
            if ($identity) $catalogue[(int)$id] = $identity;
        }
        return $catalogue;
    }
    private static function countries() {
        $result = array();
        foreach (WC()->countries->get_shipping_countries() as $code => $name) {
            $fields = WC()->countries->get_address_fields($code, 'shipping_');
            $result[$code] = array('states'=>WC()->countries->get_states($code),
                'state_required'=>!empty($fields['shipping_state']['required']),
                'postcode_required'=>!empty($fields['shipping_postcode']['required']));
        }
        return $result;
    }
    public static function download() {
        if (!self::authorized(isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '')) { self::deny(); return; }
        $batch = isset($_GET['batch']) && is_scalar($_GET['batch']) ? (string)$_GET['batch'] : '1';
        if (!preg_match('/^[1-9][0-9]{0,3}$/D', $batch)) wp_die('Choose a catalogue batch between 1 and 9999.', '', array('response'=>400));
        $query = new WP_Query(array('post_type'=>array('product','product_variation'),'post_status'=>'publish',
            'posts_per_page'=>Petish_Delivery_Cost_Sheet::MAX_ROWS,'paged'=>(int)$batch,'orderby'=>'ID','order'=>'ASC',
            'fields'=>'ids','no_found_rows'=>true,'update_post_term_cache'=>false));
        $rows = array();
        foreach ($query->posts as $id) {
            $identity = self::identity(wc_get_product($id));
            if (!$identity) continue;
            $rows[] = array_intersect_key($identity, array_flip(Petish_Delivery_Cost_Sheet::COLUMNS));
        }
        if (!$rows) wp_die('This catalogue batch has no available physical products/options. Try an earlier batch.', '', array('response'=>400));
        try { $csv = Petish_Delivery_Cost_Sheet::csv($rows); }
        catch (InvalidArgumentException $error) { wp_die(esc_html($error->getMessage()), '', array('response'=>400)); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="petish-delivery-cost-template-' . (int)$batch . '.csv"');
        echo $csv;
        exit;
    }
    private static function preview_upload() {
        if (!self::authorized(isset($_POST['_wpnonce']) ? wp_unslash($_POST['_wpnonce']) : '')) throw new InvalidArgumentException('Your form expired or you do not have access. Refresh the page and try again.');
        $file = $_FILES['petish_cost_csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name']) || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > Petish_Delivery_Cost_Sheet::MAX_BYTES) throw new InvalidArgumentException('Choose a CSV file of at most 1 MiB.');
        $contents = file_get_contents($file['tmp_name'], false, null, 0, Petish_Delivery_Cost_Sheet::MAX_BYTES + 1);
        $rows = Petish_Delivery_Cost_Sheet::parse($contents);
        $results = Petish_Delivery_Cost_Sheet::preview($rows, self::catalogue($rows), self::countries(), get_woocommerce_currencies());
        foreach ($results as &$result) {
            if ($result['status'] !== 'invalid' && $result['input']['postcode'] !== ''
                && !WC_Validation::is_postcode($result['input']['postcode'], $result['input']['country'])) {
                $result = array('row'=>$result['row'],'status'=>'invalid','error'=>'The postcode is invalid for this destination country.');
            }
        }
        unset($result);
        return $results;
    }
    private static function money($value) {
        return $value === null ? 'Unknown' : 'A$' . number_format((float)$value, 2, '.', ',');
    }
    public static function render() {
        if (!self::authorized()) { self::deny(); return; }
        nocache_headers();
        $results = null; $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try { $results = self::preview_upload(); }
            catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
        }
        echo '<div class="wrap"><h1>Delivery cost sheet</h1><p>Compare supplier costs and Petish delivery charges before deciding what to offer. This preview does not change prices, delivery rates or product availability.</p>';
        echo '<div class="notice notice-info inline"><p><strong>Each row is one independent product, quantity and destination scenario.</strong> Freight is the supplier’s total for that quantity; product cost is per unit. Do not add scenario rows together as a mixed-cart quote. The fixed payment fee applies once per scenario.</p></div>';
        echo '<h2>1. Download current product identities</h2><p>The template includes current published, available physical products/options and their supplier mapping. Costs, destinations and evidence are blank. Each numbered catalogue batch scans up to 500 product records; variable parent records are omitted. Download the next batch for later records.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(self::NONCE);
        echo '<label for="petish-cost-batch">Catalogue batch </label><input type="number" id="petish-cost-batch" name="batch" value="1" min="1" max="9999" step="1" class="small-text"> ';
        submit_button('Download CSV template', 'secondary', 'submit', false);
        echo '</form><h2>2. Record the supplier offer and your assumptions</h2><ul style="list-style:disc;padding-left:24px">';
        echo '<li>Keep the product identity columns unchanged. Enter the exact country, state, postcode, quantity and shipping service. A row does not establish wider country or quantity coverage.</li>';
        echo '<li>Use <code>estimate</code>, <code>supplier_quote</code> or <code>rate_card</code>. Record the source/reference and exact UTC observation/expiry; validity is at most seven days. These labels describe your source, not independent verification.</li>';
        echo '<li>Record supplier product and freight amounts in their original currency. The FX rate is <strong>AUD per one unit of supplier currency</strong>, with its own UTC observation within seven days. AUD-to-AUD is 1. Promotions, minimum spend and first-order conditions belong in <code>promotion_note</code>.</li>';
        echo '<li>Retail product and customer delivery revenue are <strong>excluding tax, in AUD</strong>. Customer tax is the total tax charged for the scenario. Payment percentage applies to the full amount charged including tax, plus the fixed fee.</li>';
        echo '<li>Include all other supplier/import/fulfilment costs in <code>other_cost_aud</code> under a consistent tax treatment. The worksheet does not infer recoverable tax, duties or fee contracts. Enter zero only when that assumption is deliberate; blank costs remain unknown.</li>';
        echo '<li><code>freight_buffer_percent</code> is an internal allowance, not a customer fee. <code>target_margin_percent</code> is optional. Contribution is after the entered costs and payment fees; it is <strong>not net profit</strong> and excludes unentered overheads, returns and losses.</li></ul>';
        echo '<h2>3. Preview the cost sheet</h2><p>Maximum 500 rows / 1 MiB, UTF-8 CSV. Uploads are processed for this response only. Keep your working CSV privately; no costs are saved to WordPress or sent to a supplier.</p>';
        if ($error) echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html($error) . '</p></div>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field(self::NONCE);
        echo '<label for="petish-cost-csv">Completed CSV </label><input type="file" id="petish-cost-csv" name="petish_cost_csv" accept=".csv,text/csv" required>';
        submit_button('Preview costs');
        echo '</form>';
        if ($results !== null) self::results($results);
        echo '<p><strong>Live delivery settings are unchanged.</strong> The existing AU/VIC/3083 trial remains separate. This page cannot publish a rate book or approve a free-delivery badge.</p></div>';
    }
    private static function results($results) {
        echo '<h2>Cost preview</h2><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>CSV row / product</th><th>Route / source</th><th>Supplier goods</th><th>Supplier freight</th><th>Customer delivery</th><th>Fees / allowance</th><th>Contribution / margin</th><th>Result</th></tr></thead><tbody>';
        foreach ($results as $result) {
            if ($result['status'] === 'invalid') {
                echo '<tr><td>' . (int)$result['row'] . '</td><td colspan="7"><strong>Invalid:</strong> ' . esc_html($result['error']) . '</td></tr>';
                continue;
            }
            $r = $result['input']; $t = $result['totals'];
            $route = implode(' / ', array_filter(array($r['country'],$r['state'],$r['postcode']))) . ' · Qty ' . $r['quantity'];
            $source = array('estimate'=>'Estimate only','supplier_quote'=>'Supplier quote recorded','rate_card'=>'Rate card recorded')[$r['evidence_kind']];
            echo '<tr><td>' . (int)$result['row'] . '<br><strong>' . esc_html($r['product_label']) . '</strong><br>' . esc_html($r['expected_sku']) . '</td><td>' . esc_html($route) . '<br>' . esc_html($source) . '<br>' . esc_html($r['service']);
            if ($r['promotion_note'] !== '') echo '<br><strong>Conditions:</strong> ' . esc_html($r['promotion_note']);
            echo '</td><td>' . esc_html(self::money($t['supplier_goods_aud'])) . '</td><td>' . esc_html(self::money($t['supplier_freight_aud']));
            if ($result['supplier_zero_freight_entered']) echo '<br>Zero entered for this source and quantity';
            echo '</td><td>' . esc_html(self::money($r['customer_shipping_ex_tax_aud'] === '' ? null : $r['customer_shipping_ex_tax_aud']));
            if ($result['customer_free_delivery_entered']) echo '<br>Petish customer fee: zero';
            echo '</td><td>Payment: ' . esc_html(self::money($t['payment_fees_aud'])) . '<br>Freight allowance: ' . esc_html(self::money($t['freight_buffer_aud'])) . '</td>';
            echo '<td>' . esc_html(self::money($t['contribution_aud'])) . '<br>' . esc_html($result['margin_percent'] === null ? 'Margin unknown' : $result['margin_percent'] . '%') . '</td><td>';
            if ($result['status'] === 'incomplete') echo '<strong>Incomplete</strong><br>Missing: ' . esc_html(implode(', ', $result['missing']));
            elseif ((float)$t['contribution_aud'] < 0) echo '<strong>Negative contribution</strong>';
            else echo 'Calculation complete';
            if ($result['target_met'] !== null) echo '<br>' . ($result['target_met'] ? 'Entered margin target met' : 'Below entered margin target');
            echo '<br>Preview only — not activated</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
