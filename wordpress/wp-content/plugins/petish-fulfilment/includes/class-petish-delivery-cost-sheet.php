<?php
defined('ABSPATH') || exit;

/** An in-memory costing worksheet. It never saves a rate or contacts a supplier. */
final class Petish_Delivery_Cost_Sheet {
    const MAX_BYTES = 1048576;
    const MAX_ROWS = 500;
    const SCALE = 1000000;
    const MAX_MICROS = 100000000000000; // A$100 million per scenario, well below integer overflow.
    const MAX_AGE = 604800;
    const COLUMNS = array(
        'product_id','variation_id','product_label','expected_sku','expected_parent_sku','supplier_product_url','shipping_fingerprint',
        'country','state','postcode','quantity','service','evidence_kind','evidence_reference','observed_utc','expires_utc',
        'supplier_currency','supplier_to_aud_rate','fx_observed_utc','supplier_unit_cost','supplier_freight_total',
        'retail_unit_ex_tax_aud','customer_shipping_ex_tax_aud','customer_tax_total_aud','other_cost_aud',
        'payment_fee_percent','payment_fee_fixed_aud','freight_buffer_percent','target_margin_percent','promotion_note',
    );
    private const MONEY = array('supplier_unit_cost','supplier_freight_total','retail_unit_ex_tax_aud',
        'customer_shipping_ex_tax_aud','customer_tax_total_aud','other_cost_aud','payment_fee_fixed_aud');
    private const PERCENT = array('payment_fee_percent','freight_buffer_percent','target_margin_percent');

    private static function error($message) { throw new InvalidArgumentException($message); }
    private static function text($value, $limit = 500) {
        if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) self::error('Invalid or oversized text field.');
        return trim($value);
    }
    private static function id($value, $zero = false) {
        if (!is_string($value) || !preg_match($zero ? '/^(0|[1-9][0-9]{0,9})$/D' : '/^[1-9][0-9]{0,9}$/D', $value)) self::error('Use numeric product IDs and a positive whole quantity.');
        return (int)$value;
    }
    private static function utc($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) self::error('Dates must use YYYY-MM-DDTHH:MM:SSZ.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) self::error('A date is invalid.');
        return $date->getTimestamp();
    }
    private static function amount($value, $maximum = self::MAX_MICROS) {
        if ($value === '') return null;
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,6})?$/D', $value)) self::error('Amounts must be non-negative decimals, without currency symbols or thousands separators.');
        $parts = explode('.', $value, 2);
        $micros = ((int)$parts[0]) * self::SCALE + (int)str_pad($parts[1] ?? '', 6, '0');
        if ($micros > $maximum) self::error('An amount is outside the worksheet limits.');
        return $micros;
    }
    private static function bounded($value) {
        if (!is_int($value) || $value < 0 || $value > self::MAX_MICROS) self::error('The scenario total exceeds the worksheet limits.');
        return $value;
    }
    /** Fixed decimal arithmetic, rounded half up at six decimal places. */
    private static function multiply($amount, $numerator, $denominator = self::SCALE) {
        if ($amount === null || $numerator === null) return null;
        $whole = intdiv($amount, $denominator);
        if ($whole && $numerator > intdiv(PHP_INT_MAX, $whole)) self::error('The scenario total exceeds the worksheet limits.');
        $remainder = $amount % $denominator;
        if ($remainder && $numerator > intdiv(PHP_INT_MAX - intdiv($denominator, 2), $remainder)) self::error('The scenario total exceeds the worksheet limits.');
        $first = $whole * $numerator;
        $second = intdiv($remainder * $numerator + intdiv($denominator, 2), $denominator);
        if ($first > self::MAX_MICROS - $second) self::error('The scenario total exceeds the worksheet limits.');
        return self::bounded($first + $second);
    }
    private static function sum(...$amounts) {
        if (in_array(null, $amounts, true)) return null;
        $total = 0;
        foreach ($amounts as $amount) {
            if ($amount > self::MAX_MICROS - $total) self::error('The scenario total exceeds the worksheet limits.');
            $total += $amount;
        }
        return $total;
    }
    private static function quantity_amount($amount, $quantity) {
        if ($amount === null) return null;
        if ($amount > intdiv(self::MAX_MICROS, $quantity)) self::error('The scenario total exceeds the worksheet limits.');
        return $amount * $quantity;
    }
    public static function decimal($micros) {
        if ($micros === null) return null;
        $sign = $micros < 0 ? '-' : '';
        $micros = abs($micros);
        return $sign . rtrim(rtrim(intdiv($micros, self::SCALE) . '.' . str_pad((string)($micros % self::SCALE), 6, '0', STR_PAD_LEFT), '0'), '.');
    }
    /** Spreadsheet-safe text. Identity validation also recognizes this exact escaped form. */
    public static function csv_cell($value) {
        $value = (string)$value;
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'" . $value : $value;
    }
    public static function csv($rows) {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) self::error('Export at most 500 rows at a time.');
        $stream = fopen('php://temp', 'w+');
        if (!$stream) self::error('The worksheet could not be prepared.');
        try {
            fputcsv($stream, self::COLUMNS, ',', '"', '');
            foreach ($rows as $row) {
                $values = array();
                foreach (self::COLUMNS as $column) $values[] = self::csv_cell($row[$column] ?? '');
                fputcsv($stream, $values, ',', '"', '');
                if (ftell($stream) > self::MAX_BYTES) self::error('The worksheet exceeds 1 MiB.');
            }
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }
    public static function parse($contents) {
        if (!is_string($contents) || $contents === '' || strlen($contents) > self::MAX_BYTES
            || preg_match('//u', $contents) !== 1 || str_contains($contents, "\0")) self::error('Upload a UTF-8 CSV of at most 1 MiB.');
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $stream = fopen('php://temp', 'w+');
        if (!$stream) self::error('The worksheet could not be read.');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($header) || count($header) !== count(self::COLUMNS) || count(array_unique($header)) !== count($header)
                || array_diff(self::COLUMNS, $header)) self::error('Use the downloaded template and keep every column heading exactly as supplied.');
            $rows = array();
            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($values === array(null)) continue;
                if (count($rows) >= self::MAX_ROWS) self::error('Preview at most 500 rows at a time.');
                if (count($values) !== count($header)) self::error('A CSV row has the wrong number of columns.');
                $rows[] = array_combine($header, $values);
            }
            if (!$rows) self::error('The worksheet has no data rows.');
            return $rows;
        } finally { fclose($stream); }
    }
    /** Trusted catalogue facts are supplied by the admin adapter; CSV cannot approve an identity. */
    private static function validated($row, $catalogue, $countries, $currencies, $now) {
        if (!is_array($row) || count($row) !== count(self::COLUMNS) || array_diff(self::COLUMNS, array_keys($row))) self::error('Invalid worksheet columns.');
        foreach ($row as $key => $value) $row[$key] = self::text($value, in_array($key, array('supplier_product_url','evidence_reference'), true) ? 2000 : 500);
        $parent = self::id($row['product_id']);
        $variation = self::id($row['variation_id'], true);
        $quantity = self::id($row['quantity']);
        if ($quantity > 10000) self::error('Quantity must be between 1 and 10,000.');
        $identity = $catalogue[$variation ?: $parent] ?? null;
        if (!is_array($identity) || ($identity['product_id'] ?? null) !== $parent || ($identity['variation_id'] ?? null) !== $variation
            || ($identity['available'] ?? false) !== true || $quantity > ($identity['maximum_quantity'] ?? 0)) self::error('The selected product/option is unavailable in this quantity. Download a fresh template.');
        // Labels aid the worksheet reader; current server identity remains authoritative.
        $row['product_label'] = self::text($identity['product_label'] ?? ('Product #' . ($variation ?: $parent)));
        foreach (array('expected_sku','expected_parent_sku','supplier_product_url','shipping_fingerprint') as $key) {
            $expected = $identity[$key] ?? null;
            if (!is_string($expected) || $expected === '' || ($row[$key] !== $expected && $row[$key] !== self::csv_cell($expected))) self::error('The product, supplier mapping or shipping specification changed. Download a fresh template.');
            $row[$key] = $expected;
        }
        $row['country'] = strtoupper($row['country']);
        $row['state'] = strtoupper($row['state']);
        $country = $countries[$row['country']] ?? null;
        if (!is_array($country)) self::error('Choose a country currently offered by the store.');
        if (($country['state_required'] ?? false) && $row['state'] === '') self::error('Enter the destination state or region.');
        if ($row['state'] !== '' && !empty($country['states']) && !isset($country['states'][$row['state']])) self::error('The destination state is invalid for this country.');
        if (($country['postcode_required'] ?? false) && $row['postcode'] === '') self::error('Enter the destination postcode.');
        if ($row['postcode'] !== '' && (strlen($row['postcode']) > 32 || !preg_match('/^[\p{L}\p{N} -]+$/uD', $row['postcode']))) self::error('The destination postcode is invalid.');
        if (!in_array($row['evidence_kind'], array('estimate','supplier_quote','rate_card'), true)) self::error('Evidence kind must be estimate, supplier_quote or rate_card.');
        if ($row['service'] === '' || $row['evidence_reference'] === '') self::error('Record the shipping service and a private source/evidence reference.');
        $observed = self::utc($row['observed_utc']);
        $expires = self::utc($row['expires_utc']);
        if ($observed > $now || $expires <= $now || $expires <= $observed || $expires - $observed > self::MAX_AGE) self::error('Supplier evidence must be current, with an expiry no more than seven days after observation.');
        $row['supplier_currency'] = strtoupper($row['supplier_currency']);
        if (!isset($currencies[$row['supplier_currency']])) self::error('Use a recognized supplier currency code.');
        $amounts = array();
        foreach (self::MONEY as $key) $amounts[$key] = self::amount($row[$key]);
        foreach (self::PERCENT as $key) $amounts[$key] = self::amount($row[$key], 100 * self::SCALE);
        $fx = self::amount($row['supplier_to_aud_rate'], 10000 * self::SCALE);
        if ($row['supplier_currency'] === 'AUD') {
            if ($fx !== null && $fx !== self::SCALE) self::error('AUD to AUD must use an exchange rate of 1.');
            $fx = self::SCALE;
        } else {
            if ($fx !== null && $fx <= 0) self::error('The exchange rate must be positive AUD per unit of supplier currency.');
            if ($fx !== null || $row['fx_observed_utc'] !== '') {
                $fx_date = self::utc($row['fx_observed_utc']);
                if ($fx_date > $now || $now - $fx_date > self::MAX_AGE) self::error('Record an exchange-rate observation within the last seven days.');
            }
        }
        return array($row, $amounts, $fx, $quantity);
    }
    /** Every row is an independent scenario; these totals are never combined into a cart. */
    public static function preview($rows, $catalogue, $countries, $currencies, $now = null) {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) self::error('Preview at most 500 rows at a time.');
        $now = $now ?? time();
        $results = array();
        foreach ($rows as $index => $raw) {
            try {
                list($row, $a, $fx, $quantity) = self::validated($raw, $catalogue, $countries, $currencies, $now);
                $goods = self::multiply(self::quantity_amount($a['supplier_unit_cost'], $quantity), $fx);
                $freight = self::multiply($a['supplier_freight_total'], $fx);
                $buffer = self::multiply($freight, $a['freight_buffer_percent'], 100 * self::SCALE);
                $revenue = self::sum(self::quantity_amount($a['retail_unit_ex_tax_aud'], $quantity), $a['customer_shipping_ex_tax_aud']);
                $customer_total = self::sum($revenue, $a['customer_tax_total_aud']);
                $fees = self::sum(self::multiply($customer_total, $a['payment_fee_percent'], 100 * self::SCALE), $a['payment_fee_fixed_aud']);
                $cost = self::sum($goods, $freight, $buffer, $a['other_cost_aud'], $fees);
                $contribution = $revenue === null || $cost === null ? null : $revenue - $cost;
                $gap = $freight === null || $a['customer_shipping_ex_tax_aud'] === null ? null : $a['customer_shipping_ex_tax_aud'] - $freight;
                $margin = $contribution === null || !$revenue ? null : number_format(100 * $contribution / $revenue, 2, '.', '');
                $target_met = $contribution === null || !$revenue || $a['target_margin_percent'] === null ? null : $contribution >= self::multiply($revenue, $a['target_margin_percent'], 100 * self::SCALE);
                $missing = array();
                foreach (array_merge(self::MONEY, array('payment_fee_percent','freight_buffer_percent')) as $key) if ($a[$key] === null) $missing[] = $key;
                if ($fx === null) $missing[] = 'supplier_to_aud_rate';
                $totals = array('supplier_goods_aud'=>$goods,'supplier_freight_aud'=>$freight,'freight_buffer_aud'=>$buffer,
                    'revenue_ex_tax_aud'=>$revenue,'customer_total_aud'=>$customer_total,'payment_fees_aud'=>$fees,
                    'modelled_cost_aud'=>$cost,'contribution_aud'=>$contribution,'customer_shipping_minus_supplier_freight_aud'=>$gap);
                $results[] = array('row'=>$index + 2,'status'=>$missing ? 'incomplete' : 'complete','input'=>$row,
                    'missing'=>$missing,'totals'=>array_map(array(__CLASS__,'decimal'), $totals),'margin_percent'=>$margin,
                    'target_met'=>$target_met,'supplier_zero_freight_entered'=>$a['supplier_freight_total'] === 0,
                    'customer_free_delivery_entered'=>$a['customer_shipping_ex_tax_aud'] === 0);
            } catch (InvalidArgumentException $error) {
                $results[] = array('row'=>$index + 2,'status'=>'invalid','error'=>$error->getMessage());
            }
        }
        return $results;
    }
}
