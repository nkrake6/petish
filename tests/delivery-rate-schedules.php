<?php
/** Offline behavior proof of the production shipping engine; no database or HTTP. */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    public function __construct(private string $code, private string $message = '') {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_url($value) { return parse_url($value); }
function add_filter(...$args) { $GLOBALS['hooks'][] = $args; }
function add_action(...$args) { $GLOBALS['hooks'][] = $args; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function get_woocommerce_currency() { return $GLOBALS['currency'] ?? 'AUD'; }
function get_woocommerce_currencies() { return array('AUD'=>'Australian dollar', 'USD'=>'US dollar', 'EUR'=>'Euro'); }
function wc_normalize_postcode($value) { return strtoupper(preg_replace('/\s+/', '', trim($value))); }
function wc_get_product($id) { return $GLOBALS['products'][(int)$id] ?? false; }
function update_option(...$args) { throw new RuntimeException('Unexpected settings write'); }
function update_post_meta(...$args) { throw new RuntimeException('Unexpected metadata write'); }
function wp_insert_post(...$args) { throw new RuntimeException('Unexpected post write'); }
function wp_remote_get(...$args) { throw new RuntimeException('Unexpected HTTP request'); }
function wp_remote_request(...$args) { throw new RuntimeException('Unexpected HTTP request'); }
function wp_mail(...$args) { throw new RuntimeException('Unexpected email'); }
function wc_create_order(...$args) { throw new RuntimeException('Unexpected order creation'); }
function apply_filters($name, $value, ...$args) { return $value; }
function wc_string_to_bool($value) { return $value === true || in_array($value, array('1', 'yes', 'true'), true); }
function absint($value) { return abs((int)$value); }
function __($value, ...$args) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
class WC_Shipping_Method {}
class WC_Validation {
    public static function is_postcode($value, $country) { return preg_match($country === 'AU' ? '/^[0-9]{4}$/D' : '/^[0-9]{5}$/D', $value) === 1; }
}
class FixtureCountries {
    public function get_countries() { return array('AU'=>'Australia', 'US'=>'United States', 'NZ'=>'New Zealand'); }
    public function get_shipping_countries() { return $GLOBALS['shipping_countries'] ?? array('AU'=>'Australia', 'US'=>'United States'); }
    public function get_states($country) { return $country === 'AU' ? array('VIC'=>'Victoria', 'NSW'=>'New South Wales') : ($country === 'US' ? array('CA'=>'California') : array()); }
}
function WC() { static $wc; if (!$wc) $wc = (object)array('countries'=>new FixtureCountries()); return $wc; }
class WC_Product {
    public array $values;
    public function __construct($id, $values = array()) {
        $this->values = array_replace(array('id'=>$id, 'parent'=>0, 'type'=>'simple', 'sku'=>'SKU-'.$id,
            'status'=>'publish', 'purchasable'=>true, 'stock'=>10000, 'managed'=>true, 'manager'=>$id,
            'length'=>'10', 'width'=>'5', 'height'=>'2', 'weight'=>'0.1', 'virtual'=>false,
            'supplier'=>'https://supplier.invalid/item/'.$id, 'attributes'=>array(), 'shipping_class'=>0), $values);
    }
    public function get_id() { return $this->values['id']; }
    public function get_parent_id() { return $this->values['parent']; }
    public function get_type() { return $this->values['type']; }
    public function is_type($types) { return in_array($this->get_type(), (array)$types, true); }
    public function get_sku(...$args) { return $this->values['sku']; }
    public function get_status(...$args) { return $this->values['status']; }
    public function is_purchasable() { return $this->values['purchasable']; }
    public function is_in_stock() { return $this->values['stock'] === null || $this->values['stock'] > 0; }
    public function has_enough_stock($quantity) {
        $owner = wc_get_product($this->get_stock_managed_by_id());
        return !$this->managing_stock() || !$owner || $owner->values['stock'] === null || $quantity <= $owner->values['stock'];
    }
    public function managing_stock() { return $this->values['managed']; }
    public function get_stock_managed_by_id() { return $this->values['manager']; }
    public function needs_shipping() { return !$this->values['virtual']; }
    public function get_virtual(...$args) { return $this->values['virtual']; }
    public function get_meta($key, ...$args) { return $key === '_petish_supplier_product_url' ? $this->values['supplier'] : ''; }
    public function get_attributes(...$args) { return $this->values['attributes']; }
    public function get_default_attributes(...$args) { return array(); }
    public function get_length(...$args) { return $this->values['length']; }
    public function get_width(...$args) { return $this->values['width']; }
    public function get_height(...$args) { return $this->values['height']; }
    public function get_weight(...$args) { return $this->values['weight']; }
    public function get_shipping_class_id(...$args) { return $this->values['shipping_class']; }
}
class WC_Shipping_Rate {
    public function __construct(private string $method, private string $cost, private array $meta = array()) {}
    public function get_method_id() { return $this->method; }
    public function get_cost() { return $this->cost; }
    public function get_meta_data() { return $this->meta; }
}
$GLOBALS['options'] = array('woocommerce_currency'=>'AUD', 'woocommerce_dimension_unit'=>'cm', 'woocommerce_weight_unit'=>'kg', 'petish_shipping_mode'=>'automatic');
$GLOBALS['products'] = array(1=>new WC_Product(1), 2=>new WC_Product(2),
    3=>new WC_Product(3, array('type'=>'variable', 'stock'=>3)),
    31=>new WC_Product(31, array('type'=>'variation', 'parent'=>3, 'manager'=>3)),
    32=>new WC_Product(32, array('type'=>'variation', 'parent'=>3, 'manager'=>3)));
$dir = dirname(__DIR__) . '/wordpress/wp-content/plugins/petish-fulfilment/includes/';
require $dir . 'class-petish-supplier-quotes.php';
require $dir . 'class-petish-quote-currency.php';
require $dir . 'class-petish-automatic-shipping.php';
$GLOBALS['now'] = strtotime('2026-10-23T01:00:00Z');
$GLOBALS['checks'] = array();
function check($name, $value) { $GLOBALS['checks'][$name] = $value === true; if ($value !== true) throw new RuntimeException($name); }
function line($id, $quantity = 1) {
    $p = wc_get_product($id);
    return array('product_id'=>$p->is_type('variation') ? $p->get_parent_id() : $id,
        'variation_id'=>$p->is_type('variation') ? $id : 0, 'quantity'=>$quantity, 'data'=>$p);
}
function package($lines = null, $destination = array()) {
    return array('contents'=>$lines ?? array(line(1)), 'destination'=>array_replace(array('country'=>'AU', 'state'=>'VIC', 'city'=>'Melbourne', 'postcode'=>'3083'), $destination));
}
function rule($id = 1, $quantity = 2, $amount = '7.25') {
    $p = wc_get_product($id); $parent = $p->is_type('variation') ? wc_get_product($p->get_parent_id()) : $p;
    return array('product_id'=>$parent->get_id(), 'variation_id'=>$p->is_type('variation') ? $id : 0,
        'expected_sku'=>$p->get_sku(), 'expected_parent_sku'=>$parent->get_sku(), 'shipping_fingerprint'=>Petish_Supplier_Quotes::fingerprint($p),
        'country'=>'AU', 'destination_scope'=>array('states'=>array('VIC'), 'postcodes'=>array('3083')),
        'supplier_product_url'=>$parent->get_meta('_petish_supplier_product_url'), 'service'=>'Tracked standard',
        'evidence_kind'=>'supplier_quote', 'evidence_reference'=>'Synthetic fixture quote; not supplier evidence',
        'observed_utc'=>gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['now']-3600), 'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['now']+3600),
        'evidence_reviewed'=>true, 'supplier_currency'=>'USD', 'free_shipping'=>$amount==='0',
        'pricing'=>array('type'=>'exact_quantity', 'quantity'=>$quantity, 'customer_shipping_ex_tax_aud'=>$amount, 'supplier_freight_total'=>'4.8'));
}
function tier($id = 1) {
    $r = rule($id); $r['evidence_kind'] = 'rate_card';
    $r['pricing'] = array('type'=>'first_additional', 'min_quantity'=>1, 'max_quantity'=>5,
        'first_customer_shipping_ex_tax_aud'=>'4.1', 'additional_customer_shipping_ex_tax_aud'=>'0.7',
        'supplier_first_freight'=>'2.5', 'supplier_additional_freight'=>'0.4');
    return $r;
}
function book($rules) { return array('schema_version'=>2, 'rules'=>$rules); }
function rate($rules, $lines = null, $destination = array(), $now = null) {
    return Petish_Automatic_Shipping::rate_package(package($lines, $destination), book($rules), $now ?? $GLOBALS['now']);
}
function rejects($rules, $lines = null, $destination = array(), $now = null) { return is_wp_error(rate($rules, $lines, $destination, $now)); }
function legacy($id = 1) {
    $r = rule($id, 1);
    return array_intersect_key(array_replace($r, array('per_unit_customer_shipping_ex_tax_aud'=>'4', 'free_shipping'=>false)),
        array_flip(array('product_id', 'variation_id', 'expected_sku', 'expected_parent_sku', 'shipping_fingerprint', 'country', 'destination_scope', 'per_unit_customer_shipping_ex_tax_aud', 'free_shipping', 'expires_utc')));
}

$v1 = array('schema_version'=>1, 'rules'=>array(legacy()));
$legacy = Petish_Automatic_Shipping::rate_package(package(array(line(1, 3))), $v1, $GLOBALS['now']);
check('legacy_per_unit_trial_unchanged', !is_wp_error($legacy) && $legacy['cost']==='12'
    && array_keys($legacy)===array('cost','revision','lines','matched_rule_indexes') && $legacy['revision']===hash('sha256', wp_json_encode($v1)));
check('legacy_validation_shape_unchanged', array_keys(Petish_Automatic_Shipping::validate_book($v1, $GLOBALS['now']))===array('rules','errors','revision'));
$GLOBALS['options'][Petish_Automatic_Shipping::OPTION] = $v1;
check('configured_legacy_book_remains_default', Petish_Automatic_Shipping::rate_package(package(array(line(1, 2))), null, $GLOBALS['now'])['cost']==='8');

$exact = rate(array(rule()), array(line(1, 2)));
check('exact_quote_is_total_not_per_unit', !is_wp_error($exact) && $exact['cost']==='7.25');
check('exact_quote_does_not_cover_one', rejects(array(rule()), array(line(1, 1))));
check('exact_quote_does_not_cover_three', rejects(array(rule()), array(line(1, 3))));
check('duplicate_cart_lines_aggregate_before_quantity_match', rate(array(rule()), array(line(1), line(1)))['cost']==='7.25');
check('fractional_cart_quantity_denied', rejects(array(rule()), array(line(1, '1.5'))));
check('quantity_over_limit_denied', rejects(array(tier()), array(line(1, 10001))));
check('exact_quantity_schedule_points_coexist', rate(array(rule(1, 1, '4'), rule(1, 2, '7.25')), array(line(1, 2)))['cost']==='7.25');
check('duplicate_matching_quotes_are_ambiguous', rejects(array(rule(), rule()), array(line(1, 2))));
check('overlapping_exact_and_tier_are_ambiguous', rejects(array(rule(), tier()), array(line(1, 2))));
check('mixed_supplier_products_sum_independent_totals', rate(array(rule(1, 2), rule(2, 1, '6.3')), array(line(1, 2), line(2)))['cost']==='13.55');
check('uncovered_mixed_supplier_blocks_whole_rate', rejects(array(rule()), array(line(1, 2), line(2))));
check('supplier_cost_does_not_replace_customer_amount', $exact['cost']==='7.25' && rule()['pricing']['supplier_freight_total']==='4.8');
check('explicit_customer_subsidy_allowed', rate(array(rule(1, 2, '0')), array(line(1, 2)))['cost']==='0');
$bad = rule(1, 2, '0'); unset($bad['free_shipping']);
check('zero_customer_amount_requires_explicit_free', rejects(array($bad), array(line(1, 2))));
$bad = rule(); $bad['pricing']['supplier_freight_total'] = '0';
check('zero_supplier_freight_does_not_make_customer_shipping_free', rate(array($bad), array(line(1, 2)))['cost']==='7.25');

check('first_unit_rate_card_price', rate(array(tier()), array(line(1)))['cost']==='4.1');
check('additional_units_use_explicit_rate_card_formula', rate(array(tier()), array(line(1, 4)))['cost']==='6.2');
check('rate_card_upper_bound_is_inclusive', rate(array(tier()), array(line(1, 5)))['cost']==='6.9');
check('rate_card_does_not_extrapolate_beyond_maximum', rejects(array(tier()), array(line(1, 6))));
$bad = tier(); $bad['evidence_kind'] = 'supplier_quote';
check('quantity_quote_cannot_claim_additional_unit_formula', rejects(array($bad)));
$micro = tier(); $micro['pricing']['first_customer_shipping_ex_tax_aud']='0.100001'; $micro['pricing']['additional_customer_shipping_ex_tax_aud']='0.000001';
check('fixed_decimal_micro_precision', rate(array($micro), array(line(1, 4)))['cost']==='0.100004');
check('mixed_small_decimal_totals_are_exact', rate(array(rule(1, 1, '0.1'), rule(2, 1, '0.2')), array(line(1), line(2)))['cost']==='0.3');

foreach (array(
    'review_not_confirmed'=>array('evidence_reviewed'=>false),
    'review_string_not_boolean'=>array('evidence_reviewed'=>'true'),
    'estimate_not_approved_evidence'=>array('evidence_kind'=>'estimate'),
    'missing_source_reference'=>array('evidence_reference'=>''),
    'missing_service'=>array('service'=>''),
    'insecure_supplier_url'=>array('supplier_product_url'=>'http://supplier.invalid/item/1'),
    'credential_bearing_supplier_url'=>array('supplier_product_url'=>'https://user:secret@supplier.invalid/item/1'),
    'supplier_url_no_longer_matches_mapping'=>array('supplier_product_url'=>'https://another.invalid/item/1'),
    'stale_fingerprint'=>array('shipping_fingerprint'=>str_repeat('b',64)),
    'wrong_sku'=>array('expected_sku'=>'WRONG'),
    'wrong_parent_sku'=>array('expected_parent_sku'=>'WRONG'),
    'expired_evidence'=>array('expires_utc'=>gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['now'])),
    'future_observation'=>array('observed_utc'=>gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['now']+1)),
    'overlong_evidence_window'=>array('expires_utc'=>gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['now']+604800)),
    'impossible_date'=>array('observed_utc'=>'2026-02-30T00:00:00Z'),
    'missing_expiry'=>array('expires_utc'=>''),
    'unsupported_supplier_currency'=>array('supplier_currency'=>'ZZZ'),
    'country_not_known'=>array('country'=>'ZZ'),
) as $name=>$changes) check($name, rejects(array(array_replace(rule(), $changes)), array(line(1, 2))));

foreach (array('-1', '1e3', '1,000', '0.0000001', 3, '', '999999999999999999999') as $index=>$amount) {
    $bad = rule(); $bad['pricing']['customer_shipping_ex_tax_aud'] = $amount;
    check('reject_invalid_customer_money_'.$index, rejects(array($bad), array(line(1, 2))));
}
foreach (array('-1', '', 3) as $index=>$amount) {
    $bad = rule(); $bad['pricing']['supplier_freight_total'] = $amount;
    check('reject_invalid_supplier_money_'.$index, rejects(array($bad), array(line(1, 2))));
}
foreach (array(0, -1, '2.5', 10001) as $index=>$quantity) {
    $bad = rule(); $bad['pricing']['quantity'] = $quantity;
    check('reject_invalid_exact_quantity_'.$index, rejects(array($bad), array(line(1, 2))));
}
foreach (array(array('min_quantity'=>0), array('max_quantity'=>10001), array('min_quantity'=>5,'max_quantity'=>2)) as $index=>$changes) {
    $bad = tier(); $bad['pricing'] = array_replace($bad['pricing'], $changes);
    check('reject_invalid_tier_bounds_'.$index, rejects(array($bad)));
}
$bad = rule(); $bad['unexpected'] = true;
check('unknown_rule_fields_do_not_silently_override', rejects(array($bad), array(line(1, 2))));
$bad = rule(); $bad['pricing']['per_unit_customer_shipping_ex_tax_aud'] = '0';
check('unknown_pricing_fields_are_rejected', rejects(array($bad), array(line(1, 2))));
check('schema_two_rejects_legacy_fallback', rejects(array(rule(), legacy()), array(line(1, 2))));
$bad = rule(2, 1); $bad['expires_utc'] = gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['now']);
check('bad_unrelated_row_blocks_entire_schema_two_book', rejects(array(rule(), $bad), array(line(1, 2))));
check('wrong_state_not_covered', rejects(array(rule()), array(line(1, 2)), array('state'=>'NSW')));
check('wrong_postcode_not_covered', rejects(array(rule()), array(line(1, 2)), array('postcode'=>'3000')));
check('unknown_country_not_covered', rejects(array(rule()), array(line(1, 2)), array('country'=>'NZ')));
$country = rule(); $country['destination_scope'] = array();
check('unreviewed_countrywide_scope_is_rejected', rejects(array($country), array(line(1, 2))));
$country['country_scope_verified'] = true;
check('explicit_countrywide_scope_can_be_reviewed', rate(array($country), array(line(1, 2)), array('state'=>'NSW','postcode'=>'2000'))['cost']==='7.25');
$city = rule(); $city['destination_scope']['cities'] = array('melbourne');
check('city_scope_matches_normalised_exact_city', rate(array($city), array(line(1, 2)), array('city'=>' MELBOURNE '))['cost']==='7.25');
check('city_scope_does_not_expand_to_nearby_city', rejects(array($city), array(line(1, 2)), array('city'=>'Geelong')));
$wild = rule(); $wild['destination_scope']['postcodes'] = array('30*');
check('wildcard_scope_is_not_accepted', rejects(array($wild), array(line(1, 2))));

$before = wc_get_product(1)->values;
wc_get_product(1)->values['weight'] = '3';
check('fresh_evidence_can_match_updated_specifications', !rejects(array(rule(1, 2)), array(line(1, 2))));
// Build evidence before changing specifications, as a stale stored book would do.
wc_get_product(1)->values = $before; $stored = rule(); wc_get_product(1)->values['weight'] = '3';
check('stored_fingerprint_rejects_later_weight_change', rejects(array($stored), array(line(1, 2))));
wc_get_product(1)->values = $before;
$stored = rule(); wc_get_product(1)->values['supplier'] = 'https://new-supplier.invalid/item/1';
check('stored_mapping_rejects_later_supplier_change', rejects(array($stored), array(line(1, 2))));
wc_get_product(1)->values = $before;
wc_get_product(1)->values['stock'] = 1;
check('current_stock_blocks_old_quantity_quote', rejects(array(rule()), array(line(1, 2))));
wc_get_product(1)->values = $before;
check('variants_sharing_parent_stock_are_aggregated', rejects(array(rule(31, 2), rule(32, 2)), array(line(31, 2), line(32, 2))));
check('variant_uses_parent_supplier_mapping', rate(array(rule(31, 1)), array(line(31)))['cost']==='7.25');
$parent_before = wc_get_product(3)->values; $stored = rule(31, 1); wc_get_product(3)->values['width'] = '8';
check('parent_specification_change_invalidates_variation', rejects(array($stored), array(line(31))));
wc_get_product(3)->values = $parent_before;

$aud = Petish_Automatic_Shipping::rate_args(package(array(line(1, 2))), book(array(rule())), $GLOBALS['now'], array('code'=>'AUD','rate'=>'1','decimals'=>2));
check('aud_native_args_keep_precision_and_private_cache_meta', $aud['cost']==='7.25' && $aud['price_decimals']===6 && isset($aud['meta_data']['_petish_auto_revision']));
$usd = Petish_Automatic_Shipping::rate_args(package(array(line(1, 2))), book(array(rule())), $GLOBALS['now'], array('code'=>'USD','rate'=>'0.65','decimals'=>2));
check('foreign_checkout_conversion_is_native_and_once', $usd['cost']==='4.71' && $usd['price_decimals']===2);
$GLOBALS['currency'] = 'USD';
check('unsupported_native_currency_context_is_unavailable', is_wp_error(Petish_Automatic_Shipping::rate_args(package(array(line(1, 2))), book(array(rule())), $GLOBALS['now'])));
$GLOBALS['currency'] = 'AUD';
$GLOBALS['options']['woocommerce_currency'] = 'USD';
check('non_aud_base_currency_is_rejected', rejects(array(rule()), array(line(1, 2))));
$GLOBALS['options']['woocommerce_currency'] = 'AUD';

$cache_book = book(array(rule())); $GLOBALS['options'][Petish_Automatic_Shipping::OPTION] = $cache_book;
$p = package(array(line(1, 2)));
$args = Petish_Automatic_Shipping::rate_args($p, null, $GLOBALS['now']);
$p['rates'] = array('petish'=>new WC_Shipping_Rate(Petish_Automatic_Shipping::METHOD, $args['cost'], $args['meta_data']));
check('fresh_cached_rate_is_preserved', count(Petish_Automatic_Shipping::guard_cached_packages(array($p), $GLOBALS['now'])[0]['rates'])===1);
check('cached_rate_does_not_survive_evidence_expiry', Petish_Automatic_Shipping::guard_cached_packages(array($p), $GLOBALS['now']+3601)[0]['rates']===array());
$GLOBALS['options'][Petish_Automatic_Shipping::OPTION]['rules'][0]['pricing']['customer_shipping_ex_tax_aud'] = '9';
check('changed_reviewed_book_invalidates_cached_amount', Petish_Automatic_Shipping::guard_cached_packages(array($p), $GLOBALS['now'])[0]['rates']===array());
$GLOBALS['options'][Petish_Automatic_Shipping::OPTION] = $cache_book;
$q = $p; $q['destination']['postcode'] = '3000';
check('changed_destination_invalidates_cached_rate', Petish_Automatic_Shipping::guard_cached_packages(array($q), $GLOBALS['now'])[0]['rates']===array());
$q = $p; $q['contents'][0]['quantity'] = 1;
check('changed_quantity_invalidates_cached_exact_quote', Petish_Automatic_Shipping::guard_cached_packages(array($q), $GLOBALS['now'])[0]['rates']===array());
$GLOBALS['options']['petish_shipping_mode'] = 'quote';
check('inactive_automatic_mode_rejects_old_cached_rate', Petish_Automatic_Shipping::guard_cached_packages(array($p), $GLOBALS['now'])[0]['rates']===array());
$GLOBALS['options']['petish_shipping_mode'] = 'automatic';

$large_rules = array(); $large_lines = array();
for ($id = 100; $id < 200; $id++) {
    $GLOBALS['products'][$id] = new WC_Product($id);
    $r = tier($id); $r['pricing']['max_quantity'] = 10000;
    $r['pricing']['first_customer_shipping_ex_tax_aud'] = '9999999';
    $r['pricing']['additional_customer_shipping_ex_tax_aud'] = '9999999';
    $large_rules[] = $r; $large_lines[] = line($id, 10000);
}
check('large_mixed_order_cannot_overflow_integer_money', rejects($large_rules, $large_lines));
echo json_encode(array('status'=>'success','checks'=>$GLOBALS['checks']), JSON_PRETTY_PRINT)."\n";
