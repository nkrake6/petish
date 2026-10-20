<?php
defined('ABSPATH') || exit;

/** Read-only quote validation. Saving quotes is an explicit administrator operation. */
final class Petish_Supplier_Quotes {
    const OPTION = 'petish_supplier_shipping_quotes';
    const MAX_AGE = 604800; // Seven days, including an explicitly verified zero-freight quote.

    private static function error($code) {
        return new WP_Error('petish_shipping_' . $code, 'Shipping quote is unavailable.');
    }

    private static function id($value, $allow_zero = false) {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string) $value)) return false;
        $id = (int) $value;
        return $id > 0 || ($allow_zero && 0 === $id) ? $id : false;
    }

    private static function utc($value) {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|\+00:00)$/D', $value)) return false;
        $text = str_replace('Z', '+00:00', $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $text, new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d\TH:i:sP') === $text ? $date->getTimestamp() : false;
    }

    private static function url($value) {
        if (!is_string($value) || strlen($value) > 2000 || !filter_var($value, FILTER_VALIDATE_URL)) return false;
        $parts = wp_parse_url($value);
        return is_array($parts) && 'https' === ($parts['scheme'] ?? '') && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    private static function destination_value($value, $kind) {
        if (!is_string($value)) return '';
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return 'cities' === $kind ? strtolower($value) : strtoupper($value);
    }

    private static function attributes($product) {
        $result = array();
        foreach ($product->get_attributes('edit') as $key => $attribute) {
            if ($attribute instanceof WC_Product_Attribute) {
                $options = array();
                foreach ($attribute->get_options() as $option) {
                    if ($attribute->is_taxonomy()) {
                        $term = get_term((int) $option, $attribute->get_name());
                        if (!$term || is_wp_error($term)) return self::error('attribute');
                        $options[] = array('id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug);
                    } else {
                        $options[] = (string) $option;
                    }
                }
                usort($options, static function ($a, $b) { return strcmp(wp_json_encode($a), wp_json_encode($b)); });
                $result[(string) $key] = array('name' => $attribute->get_name(), 'options' => $options, 'variation' => $attribute->get_variation(), 'visible' => $attribute->get_visible());
            } elseif (is_scalar($attribute)) {
                $result[(string) $key] = (string) $attribute;
            } else {
                return self::error('attribute');
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private static function snapshot($product) {
        $attributes = self::attributes($product);
        if (is_wp_error($attributes)) return $attributes;
        $defaults = $product->get_default_attributes('edit');
        ksort($defaults, SORT_STRING);
        return array(
            'id' => $product->get_id(), 'parent_id' => $product->get_parent_id(),
            'type' => $product->get_type(), 'sku' => $product->get_sku('edit'),
            'attributes' => $attributes, 'defaults' => $defaults,
            'length' => $product->get_length('edit'), 'width' => $product->get_width('edit'),
            'height' => $product->get_height('edit'), 'weight' => $product->get_weight('edit'),
            'shipping_class' => $product->get_shipping_class_id('edit'), 'virtual' => $product->get_virtual('edit'),
            'supplier_url' => $product->get_meta('_petish_supplier_product_url', true, 'edit'),
        );
    }

    /** Hash shipping-relevant identity only; price, copy, photos and private pets are excluded. */
    public static function fingerprint($product) {
        if (!$product instanceof WC_Product || !$product->get_id() || !in_array($product->get_type(), array('simple', 'variation'), true)) return self::error('product');
        $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
        if (!$parent || ($product->is_type('variation') && !$parent->is_type('variable'))) return self::error('parent');
        if (!self::url($parent->get_meta('_petish_supplier_product_url', true, 'edit'))) return self::error('supplier_url');
        $product_data = self::snapshot($product);
        $parent_data = self::snapshot($parent);
        if (is_wp_error($product_data)) return $product_data;
        if (is_wp_error($parent_data)) return $parent_data;
        return hash('sha256', wp_json_encode(array(
            'schema_version' => 1, 'product' => $product_data, 'parent' => $parent_data,
            'dimension_unit' => get_option('woocommerce_dimension_unit'), 'weight_unit' => get_option('woocommerce_weight_unit'),
        )));
    }

    /**
     * A row requires parent product_id, variation_id (0 for simple), exact quantity,
     * expected_sku, expected_parent_sku, shipping_fingerprint, country, decimal-string
     * customer_shipping_ex_tax_aud, quote_utc, expires_utc, carrier, source_url, reference.
     * Optional destination_scope contains exact states/cities/postcodes allowlists.
     * A country-only row additionally requires country_scope_verified === true.
     * This validates evidence shape, not supplier truth; no network calls or writes occur.
     */
    public static function validate_quote($row, $now = null) {
        if (!is_array($row)) return self::error('row');
        $now = null === $now ? time() : (int) $now;
        $product_id = self::id($row['product_id'] ?? null);
        $variation_id = self::id($row['variation_id'] ?? null, true);
        $quantity = self::id($row['quantity'] ?? null);
        if (false === $product_id || false === $variation_id || false === $quantity || $quantity > 10000) return self::error('identity');
        foreach (array('expected_sku', 'expected_parent_sku', 'carrier', 'reference') as $key) {
            if (!isset($row[$key]) || !is_string($row[$key]) || '' === trim($row[$key]) || strlen($row[$key]) > 300) return self::error('evidence');
        }
        if (!isset($row['shipping_fingerprint']) || !is_string($row['shipping_fingerprint']) || !preg_match('/^[a-f0-9]{64}$/D', $row['shipping_fingerprint'])) return self::error('fingerprint');
        if (!self::url($row['source_url'] ?? null)) return self::error('source');
        if (!isset($row['country']) || !is_string($row['country']) || !preg_match('/^[A-Z]{2}$/D', $row['country'])) return self::error('country');
        if (function_exists('WC') && WC()->countries && !array_key_exists($row['country'], WC()->countries->get_countries())) return self::error('country');
        $amount = $row['customer_shipping_ex_tax_aud'] ?? null;
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,6})?$/D', $amount) || !is_finite((float) $amount)) return self::error('amount');
        $quoted = self::utc($row['quote_utc'] ?? null);
        $expiry = self::utc($row['expires_utc'] ?? null);
        if (false === $quoted || false === $expiry || $quoted > $now || $expiry <= $now || $expiry <= $quoted || $expiry - $quoted > self::MAX_AGE) return self::error('date');
        $scope = $row['destination_scope'] ?? array();
        if (!is_array($scope) || array_diff(array_keys($scope), array('states', 'cities', 'postcodes'))) return self::error('scope');
        $pinned = false;
        foreach (array('states', 'cities', 'postcodes') as $key) {
            $values = $scope[$key] ?? array();
            if (!is_array($values) || !array_is_list($values)) return self::error('scope');
            $normalized = array();
            foreach ($values as $value) {
                if (!is_string($value) || '' === trim($value) || strlen($value) > 100 || strpbrk($value, '*?') !== false) return self::error('scope');
                $normalized[] = self::destination_value($value, $key);
            }
            $scope[$key] = array_values(array_unique($normalized));
            $pinned = $pinned || !empty($scope[$key]);
        }
        if (!$pinned && true !== ($row['country_scope_verified'] ?? false)) return self::error('country_scope');
        $row['product_id'] = $product_id;
        $row['variation_id'] = $variation_id;
        $row['quantity'] = $quantity;
        $row['destination_scope'] = $scope;
        $row['customer_shipping_ex_tax_aud'] = rtrim(rtrim(sprintf('%.6F', (float) $amount), '0'), '.');
        return $row;
    }

    /** Read-only diagnostic validation, without making invalid rows usable. */
    public static function validate_book($book, $now = null) {
        if (!is_array($book) || 1 !== ($book['schema_version'] ?? null) || !isset($book['quotes']) || !is_array($book['quotes']) || !array_is_list($book['quotes']) || count($book['quotes']) > 10000) return self::error('schema');
        $result = array('quotes' => array(), 'errors' => array());
        foreach ($book['quotes'] as $index => $row) {
            $validated = self::validate_quote($row, $now);
            if (is_wp_error($validated)) $result['errors'][$index] = $validated->get_error_code();
            else $result['quotes'][] = $validated;
        }
        return $result;
    }

    private static function destination_matches($row, $destination) {
        if ($row['country'] !== strtoupper((string) ($destination['country'] ?? ''))) return false;
        foreach (array('states' => 'state', 'cities' => 'city', 'postcodes' => 'postcode') as $scope => $field) {
            if ($row['destination_scope'][$scope] && !in_array(self::destination_value($destination[$field] ?? '', $scope), $row['destination_scope'][$scope], true)) return false;
        }
        return true;
    }

    /** All shippable lines must have exactly one current quote; unrelated bad rows do not grant a rate. */
    public static function quote_package($package, $book = null, $now = null) {
        if (is_wp_error(Petish_Quote_Currency::context())) return self::error('currency');
        if (!is_array($package) || empty($package['contents']) || !is_array($package['contents']) || empty($package['destination']['country'])) return self::error('package');
        if (null === $book && class_exists('Petish_Shipping_Quote_Requests')) {
            $customer_quote = Petish_Shipping_Quote_Requests::quote_package($package, $now);
            if (!is_wp_error($customer_quote)) return $customer_quote;
        }
        $validated = self::validate_book(null === $book ? get_option(self::OPTION, array()) : $book, $now);
        if (is_wp_error($validated)) return $validated;
        $groups = array();
        foreach ($package['contents'] as $line) {
            if (!is_array($line) || !isset($line['data']) || !$line['data'] instanceof WC_Product) return self::error('line');
            $product = $line['data'];
            $product_id = self::id($line['product_id'] ?? null);
            $variation_id = self::id($line['variation_id'] ?? 0, true);
            $quantity = self::id($line['quantity'] ?? null);
            if (false === $product_id || false === $variation_id || false === $quantity || !$product->needs_shipping()) return self::error('line');
            if ($product->is_type('variation')) {
                if ($variation_id !== $product->get_id() || $product_id !== $product->get_parent_id()) return self::error('identity');
                $parent = wc_get_product($product_id);
            } elseif ($product->is_type('simple') && 0 === $variation_id && $product_id === $product->get_id()) {
                $parent = $product;
            } else return self::error('type');
            if (!$parent) return self::error('parent');
            $fingerprint = self::fingerprint($product);
            if (is_wp_error($fingerprint)) return $fingerprint;
            $key = $product_id . ':' . $variation_id;
            $identity = array('product_id' => $product_id, 'variation_id' => $variation_id, 'expected_sku' => $product->get_sku('edit'), 'expected_parent_sku' => $parent->get_sku('edit'), 'shipping_fingerprint' => $fingerprint);
            if (isset($groups[$key]) && $groups[$key]['identity'] !== $identity) return self::error('conflict');
            if (!isset($groups[$key])) $groups[$key] = array('identity' => $identity, 'quantity' => 0);
            $groups[$key]['quantity'] += $quantity;
        }
        $cost = 0.0;
        $matched = array();
        foreach ($groups as $group) {
            $candidates = array();
            foreach ($validated['quotes'] as $row) {
                if ($row['quantity'] !== $group['quantity'] || !self::destination_matches($row, $package['destination'])) continue;
                $same = true;
                foreach ($group['identity'] as $key => $value) if ($row[$key] !== $value) $same = false;
                if ($same) $candidates[] = $row;
            }
            if (1 !== count($candidates)) return self::error('unquoted');
            $matched[] = $candidates[0];
            $cost += (float) $candidates[0]['customer_shipping_ex_tax_aud'];
        }
        return array('cost' => rtrim(rtrim(sprintf('%.6F', $cost), '0'), '.'), 'quotes' => $matched, 'separate_parcels' => count($matched));
    }

    public static function cache_packages($packages) {
        if (!is_array($packages)) return $packages;
        foreach ($packages as &$package) {
            if (!is_array($package)) continue;
            $quote = self::quote_package($package);
            $package['petish_quote_cache'] = hash('sha256', wp_json_encode(array('mode'=>get_option('petish_shipping_mode', 'quote'), 'quote'=>is_wp_error($quote) ? array('error' => $quote->get_error_code()) : $quote, 'currency'=>Petish_Quote_Currency::fingerprint())));
        }
        unset($package);
        return $packages;
    }

    /** Also remove expired/changed cached rates for native callers that bypass cart-package filters. */
    public static function guard_cached_packages($packages) {
        if (!is_array($packages)) return $packages;
        foreach ($packages as &$package) {
            if (!is_array($package) || empty($package['rates'])) continue;
            $quote = null;
            foreach ($package['rates'] as $key => $rate) {
                if (!$rate instanceof WC_Shipping_Rate || 'petish_supplier_shipping' !== $rate->get_method_id()) continue;
                if ('automatic' === get_option('petish_shipping_mode', 'quote')) {
                    unset($package['rates'][$key]);
                    continue;
                }
                if (null === $quote) $quote = self::quote_package($package);
                $context = Petish_Quote_Currency::context();
                $cost = is_wp_error($quote) ? $quote : Petish_Quote_Currency::convert($quote['cost'], $context);
                $meta = $rate->get_meta_data();
                if (is_wp_error($cost) || is_wp_error($context) || ($meta['_petish_quote_currency'] ?? null) !== $context['code'] || ($meta['_petish_quote_fx_context'] ?? null) !== Petish_Quote_Currency::fingerprint($context) || abs((float) $cost - (float) $rate->get_cost()) > 0.000001) unset($package['rates'][$key]);
            }
        }
        unset($package);
        return $packages;
    }
}
