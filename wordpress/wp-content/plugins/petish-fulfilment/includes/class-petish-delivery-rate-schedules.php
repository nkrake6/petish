<?php
defined('ABSPATH') || exit;

/** Reviewed, quantity-scoped delivery schedules. Validation never saves or fetches costs. */
final class Petish_Delivery_Rate_Schedules {
    const MAX_RULES = 500;
    const MAX_QUANTITY = 10000;
    const MAX_AGE = 604800;

    private static function error($code) {
        return new WP_Error('petish_automatic_schedule_' . $code, 'Delivery is not available for every item at this destination.');
    }

    private static function money($value) {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,6})?$/D', $value)) return false;
        $parts = explode('.', $value, 2);
        return ((int)$parts[0]) * 1000000 + (int)str_pad($parts[1] ?? '', 6, '0');
    }

    private static function decimal($value) {
        return rtrim(rtrim(intdiv($value, 1000000) . '.' . str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT), '0'), '.');
    }

    private static function quantity($value) {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,4}$/D', (string)$value)) return false;
        $number = (int)$value;
        return $number <= self::MAX_QUANTITY ? $number : false;
    }

    private static function utc($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D', $value)) return false;
        $canonical = str_replace('Z', '+00:00', $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical, new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d\TH:i:sP') === $canonical ? $date->getTimestamp() : false;
    }

    private static function text($value, $limit) {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $limit
            && preg_match('//u', $value) === 1 && !preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function city($value) {
        return is_string($value) && preg_match('//u', $value) === 1 ? strtolower(trim(preg_replace('/\s+/u', ' ', $value))) : '';
    }

    private static function source($value) {
        if (!is_string($value) || strlen($value) > 2000 || !filter_var($value, FILTER_VALIDATE_URL)) return false;
        $parts = wp_parse_url($value);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    /** Each amount is an explicit charge/cost, never a freight estimate divided by quantity. */
    private static function pricing($pricing, $kind, $free) {
        if (!is_array($pricing)) return self::error('pricing');
        $type = $pricing['type'] ?? null;
        if ($type === 'exact_quantity') {
            if (count($pricing) !== 4 || array_diff(array_keys($pricing), array('type', 'quantity', 'customer_shipping_ex_tax_aud', 'supplier_freight_total'))) return self::error('pricing_fields');
            $quantity = self::quantity($pricing['quantity'] ?? null);
            $customer = self::money($pricing['customer_shipping_ex_tax_aud'] ?? null);
            $supplier = self::money($pricing['supplier_freight_total'] ?? null);
            if ($quantity === false || $customer === false || $supplier === false) return self::error('pricing_amount');
            if ($customer === 0 && !$free) return self::error('zero_requires_explicit_free');
            return array('type'=>$type, 'quantity'=>$quantity, 'customer_shipping_ex_tax_aud'=>self::decimal($customer), 'supplier_freight_total'=>self::decimal($supplier));
        }
        if ($type !== 'first_additional' || $kind !== 'rate_card') return self::error('pricing_evidence');
        $keys = array('type', 'min_quantity', 'max_quantity', 'first_customer_shipping_ex_tax_aud', 'additional_customer_shipping_ex_tax_aud', 'supplier_first_freight', 'supplier_additional_freight');
        if (count($pricing) !== count($keys) || array_diff(array_keys($pricing), $keys)) return self::error('pricing_fields');
        $minimum = self::quantity($pricing['min_quantity'] ?? null);
        $maximum = self::quantity($pricing['max_quantity'] ?? null);
        if ($minimum === false || $maximum === false || $minimum > $maximum) return self::error('quantity_bounds');
        $normalized = array('type'=>$type, 'min_quantity'=>$minimum, 'max_quantity'=>$maximum);
        foreach (array_slice($keys, 3) as $key) {
            $amount = self::money($pricing[$key] ?? null);
            if ($amount === false) return self::error('pricing_amount');
            $normalized[$key] = self::decimal($amount);
        }
        if (!$free && self::money($normalized['first_customer_shipping_ex_tax_aud']) === 0
            && ($minimum === 1 || self::money($normalized['additional_customer_shipping_ex_tax_aud']) === 0)) return self::error('zero_requires_explicit_free');
        return $normalized;
    }

    public static function validate_rule($row, $now = null) {
        $now = $now ?? time();
        $keys = array('product_id', 'variation_id', 'expected_sku', 'expected_parent_sku', 'shipping_fingerprint', 'country', 'destination_scope',
            'supplier_product_url', 'service', 'evidence_kind', 'evidence_reference', 'observed_utc', 'expires_utc', 'evidence_reviewed', 'supplier_currency',
            'pricing', 'free_shipping', 'country_scope_verified');
        if (!is_int($now) || !is_array($row) || array_diff(array_keys($row), $keys)) return self::error('rule_fields');
        if (($row['evidence_reviewed'] ?? null) !== true || !in_array($row['evidence_kind'] ?? null, array('supplier_quote', 'rate_card'), true)) return self::error('unreviewed_evidence');
        foreach (array('free_shipping', 'country_scope_verified') as $flag) if (array_key_exists($flag, $row) && !is_bool($row[$flag])) return self::error('flags');
        if (!self::text($row['service'] ?? null, 200) || !self::text($row['evidence_reference'] ?? null, 2000)) return self::error('evidence_reference');
        if (!self::source($row['supplier_product_url'] ?? null)) return self::error('source');
        $currency = $row['supplier_currency'] ?? null;
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency) || !isset(get_woocommerce_currencies()[$currency])) return self::error('supplier_currency');
        $observed = self::utc($row['observed_utc'] ?? null);
        $expires = self::utc($row['expires_utc'] ?? null);
        if ($observed === false || $expires === false || $observed > $now || $expires <= $now || $expires <= $observed || $expires - $observed > self::MAX_AGE) return self::error('evidence_expiry');
        $scope = $row['destination_scope'] ?? null;
        if (!is_array($scope) || array_diff(array_keys($scope), array('states', 'cities', 'postcodes'))) return self::error('scope');
        $cities = $scope['cities'] ?? array();
        if (!is_array($cities) || !array_is_list($cities) || count($cities) > 1000) return self::error('scope');
        $normalized_cities = array();
        foreach ($cities as $city) {
            if (!self::text($city, 100) || strpbrk($city, '*?%') !== false) return self::error('scope');
            $normalized_cities[] = self::city($city);
        }
        $normalized_cities = array_values(array_unique($normalized_cities));
        sort($normalized_cities, SORT_STRING);
        // Reuse the existing identity and native country/state/postcode validation.
        $probe = array_intersect_key($row, array_flip(array('product_id', 'variation_id', 'expected_sku', 'expected_parent_sku', 'shipping_fingerprint', 'country', 'expires_utc')));
        $probe['destination_scope'] = array_intersect_key($scope, array_flip(array('states', 'postcodes')));
        $probe['per_unit_customer_shipping_ex_tax_aud'] = '1';
        $probe['expires_utc'] = gmdate('Y-m-d\TH:i:s\Z', $expires);
        $base = Petish_Automatic_Shipping::validate_rule($probe, $now);
        if (is_wp_error($base)) return $base;
        $base['destination_scope']['cities'] = $normalized_cities;
        if (!$base['destination_scope']['states'] && !$base['destination_scope']['cities'] && !$base['destination_scope']['postcodes'] && ($row['country_scope_verified'] ?? false) !== true) return self::error('country_scope_unverified');
        $product = wc_get_product($base['variation_id'] ?: $base['product_id']);
        $parent = $base['variation_id'] ? wc_get_product($base['product_id']) : $product;
        if (!$product instanceof WC_Product || !$parent instanceof WC_Product
            || $product->get_id() !== ($base['variation_id'] ?: $base['product_id'])
            || ($base['variation_id'] && (!$product->is_type('variation') || $product->get_parent_id() !== $base['product_id'] || !$parent->is_type('variable')))
            || (!$base['variation_id'] && !$product->is_type('simple'))
            || $product->get_sku('edit') !== $base['expected_sku'] || $parent->get_sku('edit') !== $base['expected_parent_sku']) return self::error('current_identity');
        if ($parent->get_meta('_petish_supplier_product_url', true, 'edit') !== $row['supplier_product_url']) return self::error('source_changed');
        $fingerprint = Petish_Supplier_Quotes::fingerprint($product);
        if (is_wp_error($fingerprint) || !hash_equals($base['shipping_fingerprint'], $fingerprint)) return self::error('specifications_changed');
        $free = ($row['free_shipping'] ?? false) === true;
        $pricing = self::pricing($row['pricing'] ?? null, $row['evidence_kind'], $free);
        if (is_wp_error($pricing)) return $pricing;
        unset($base['per_unit_customer_shipping_ex_tax_aud']);
        return array_merge($base, array('supplier_product_url'=>$row['supplier_product_url'], 'service'=>trim($row['service']),
            'evidence_kind'=>$row['evidence_kind'], 'evidence_reference'=>trim($row['evidence_reference']),
            'observed_utc'=>gmdate('Y-m-d\TH:i:s\Z', $observed), 'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z', $expires),
            'evidence_reviewed'=>true, 'supplier_currency'=>$currency, 'country_scope_verified'=>($row['country_scope_verified'] ?? false) === true,
            'pricing'=>$pricing, 'free_shipping'=>$free));
    }

    /** Diagnostics retain row indexes; any error prevents the entire v2 book from rating. */
    public static function validate_book($book, $now = null) {
        if (!is_array($book) || array_diff(array_keys($book), array('schema_version', 'rules')) || ($book['schema_version'] ?? null) !== 2
            || !is_array($book['rules'] ?? null) || !array_is_list($book['rules']) || count($book['rules']) > self::MAX_RULES) return self::error('schema');
        $result = array('schema_version'=>2, 'rules'=>array(), 'errors'=>array(), 'revision'=>hash('sha256', wp_json_encode($book)));
        foreach ($book['rules'] as $index=>$row) {
            $rule = self::validate_rule($row, $now);
            if (is_wp_error($rule)) $result['errors'][$index] = $rule->get_error_code();
            else $result['rules'][$index] = $rule;
        }
        return $result;
    }

    private static function covers_quantity($pricing, $quantity) {
        return $pricing['type'] === 'exact_quantity' ? $pricing['quantity'] === $quantity
            : $quantity >= $pricing['min_quantity'] && $quantity <= $pricing['max_quantity'];
    }

    private static function charge($pricing, $quantity) {
        if ($pricing['type'] === 'exact_quantity') return self::money($pricing['customer_shipping_ex_tax_aud']);
        $first = self::money($pricing['first_customer_shipping_ex_tax_aud']);
        $additional = self::money($pricing['additional_customer_shipping_ex_tax_aud']);
        if ($quantity > 1 && $additional > intdiv(PHP_INT_MAX - $first, $quantity - 1)) return false;
        return $first + ($quantity - 1) * $additional;
    }

    public static function rate_lines($lines, $destination, $validated, $now = null) {
        if (!is_array($validated) || ($validated['schema_version'] ?? null) !== 2 || !empty($validated['errors'])
            || !isset($validated['rules'], $validated['revision']) || !is_array($validated['rules']) || !is_array($lines) || !$lines || !is_array($destination)) return self::error('invalid_book');
        $country = is_string($destination['country'] ?? null) ? strtoupper(trim($destination['country'])) : '';
        if (!$country || !isset(WC()->countries->get_shipping_countries()[$country])) return self::error('destination');
        $total = 0;
        $matches = array();
        $rules = array();
        // A retained compiled book still needs fresh expiry and specification checks.
        foreach ($validated['rules'] as $index=>$row) {
            $rule = self::validate_rule($row, $now);
            if (is_wp_error($rule)) return self::error('invalid_book');
            $rules[$index] = $rule;
        }
        foreach ($lines as $line) {
            $quantity = self::quantity($line['quantity'] ?? null);
            if ($quantity === false) return self::error('quantity');
            $candidates = array();
            foreach ($rules as $index=>$rule) {
                if ($rule['country'] !== $country || !self::covers_quantity($rule['pricing'], $quantity)) continue;
                $same = true;
                foreach (array('product_id', 'variation_id', 'expected_sku', 'expected_parent_sku', 'shipping_fingerprint') as $field) if (($line[$field] ?? null) !== $rule[$field]) $same = false;
                foreach (array('states'=>'state', 'cities'=>'city', 'postcodes'=>'postcode') as $scope=>$field) {
                    $value = $destination[$field] ?? '';
                    $value = $scope === 'cities' ? self::city($value) : (is_string($value) ? ($scope === 'postcodes' ? wc_normalize_postcode($value) : strtoupper(trim(preg_replace('/\s+/u', ' ', $value)))) : '');
                    if ($rule['destination_scope'][$scope] && !in_array($value, $rule['destination_scope'][$scope], true)) $same = false;
                }
                if ($same) $candidates[$index] = $rule;
            }
            if (count($candidates) !== 1) return self::error($candidates ? 'ambiguous' : 'unassigned');
            $index = array_key_first($candidates);
            $charge = self::charge($candidates[$index]['pricing'], $quantity);
            if ($charge === false || $charge > PHP_INT_MAX - $total) return self::error('amount_overflow');
            $total += $charge;
            $matches[] = $index;
        }
        return array('cost'=>self::decimal($total), 'revision'=>$validated['revision'], 'lines'=>$lines, 'matched_rule_indexes'=>$matches);
    }
}
