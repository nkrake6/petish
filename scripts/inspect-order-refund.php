<?php
/** Exact-order, database-only inspection. Never loads WordPress or a gateway SDK. */
const PETISH_ORDER_REFUND_FORMAT = 'petish-order-refund-inspection-v1';
const PETISH_ORDER_REFUND_SCHEMA = [
    'strings' => ['format','status','storage','generated_utc','order_status','created_gmt','modified_gmt','order_currency','store_currency','intent_currency','multi_currency_default_currency','payment_gateway','payment_method_type','payment_mode','capture_status_cached','refund_status_cached'],
    'integers' => ['order_id','refund_count','note_count','note_payment_succeeded_count','note_refund_succeeded_count','note_refund_pending_count','note_refund_failed_count','note_insufficient_balance_count','note_auth_cancelled_count','note_fee_breakdown_count','note_application_fee_refunded_count','note_dispute_count'],
    'amounts' => ['order_total','order_shipping','order_shipping_tax','order_tax','order_discount','order_fees','refund_total','remaining_recorded_amount','original_shipping_items_total','original_shipping_items_tax','refunded_shipping_total','refunded_shipping_tax','refunded_tax_total','unallocated_refund_amount'],
    'nullable_amounts' => ['transaction_fee_cached','merchant_net_cached'],
    'booleans' => ['shipping_totals_verified','refund_allocation_complete','has_charge_id','has_intent_id','has_payment_transaction_id','settlement_verified','original_capture_amount_verified','dual_store_checked','notes_truncated'],
    'nullable_booleans' => ['remaining_matches_unrefunded_shipping'],
    'refund_strings' => ['created_gmt','currency'],
    'refund_integers' => ['id'],
    'refund_amounts' => ['amount','shipping_amount','shipping_tax_amount','tax_amount','unallocated_amount'],
    'refund_booleans' => ['allocation_complete','provider_refund_id_present','provider_transaction_id_present'],
    'refund_nullable_booleans' => ['refunded_payment_flag'],
];

final class Petish_Order_Refund_Error extends RuntimeException {}
function petish_order_refund_fail($code) { throw new Petish_Order_Refund_Error($code); }
function petish_order_refund_selector($value) {
    if (!is_array($value) || array_diff(array_keys($value), ['schema','order_id','expected_billing_first_name'])
        || ($value['schema'] ?? null) !== 1 || !is_int($value['order_id'] ?? null) || $value['order_id'] < 1 || $value['order_id'] > 2147483647)
        petish_order_refund_fail('input');
    if (array_key_exists('expected_billing_first_name', $value) && (!is_string($value['expected_billing_first_name'])
        || strlen($value['expected_billing_first_name']) > 320 || !preg_match('/\A[^\x00-\x1f\x7f]{1,80}\z/uD', $value['expected_billing_first_name'])
        || trim($value['expected_billing_first_name']) === '')) petish_order_refund_fail('input');
    return $value;
}
/** Decimal integer arithmetic: source values are already major currency units. */
function petish_order_refund_units($value, $nullable = false) {
    if ($nullable && ($value === null || $value === '')) return null;
    if ((!is_string($value) && !is_int($value)) || !preg_match('/\A(-?)([0-9]{1,9})(?:\.([0-9]{1,8}))?\z/D', (string)$value, $m))
        petish_order_refund_fail('amount');
    $units = (int)$m[2] * 100000000 + (int)str_pad($m[3] ?? '', 8, '0');
    return $m[1] === '-' ? -$units : $units;
}
function petish_order_refund_money($value) {
    if ($value === null) return null;
    if (!is_int($value) || abs($value) > 99999999999999999) petish_order_refund_fail('amount');
    $absolute = abs($value); $fraction = rtrim(str_pad((string)($absolute % 100000000), 8, '0', STR_PAD_LEFT), '0');
    return ($value < 0 ? '-' : '') . intdiv($absolute, 100000000) . '.' . str_pad($fraction, 2, '0');
}
function petish_order_refund_currency($value) { return is_string($value) && preg_match('/\A[A-Za-z]{3}\z/D', $value) ? strtoupper($value) : 'unknown'; }
function petish_order_refund_enum($value, $allowed, $fallback = 'unknown') { return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback; }
function petish_order_refund_date($value) {
    if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value)) petish_order_refund_fail('date');
    return $value;
}
function petish_order_refund_status($value) {
    $value = is_string($value) && str_starts_with($value, 'wc-') ? substr($value, 3) : $value;
    return petish_order_refund_enum($value, ['pending','processing','on-hold','completed','cancelled','refunded','failed','checkout-draft'], 'other');
}

final class Petish_Order_Refund_DB {
    private $connection;
    public function __construct() {
        if (!function_exists('mysqli_init')) petish_order_refund_fail('database_driver');
        $host = getenv('PETISH_DB_HOST'); $socket = null; $port = 3306;
        if (!is_string($host) || $host === '' || preg_match('/[\x00-\x20]/', $host)) petish_order_refund_fail('database_configuration');
        $offset = strpos($host, ':/');
        if ($offset !== false) { $socket = substr($host, $offset + 1); $host = substr($host, 0, $offset); }
        if (preg_match('/\A\[([^\]]+)\](?::([0-9]+))?\z/D', $host, $match)) {
            if (!filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) petish_order_refund_fail('database_configuration');
            $host = $match[1]; $port = isset($match[2]) ? (int)$match[2] : 3306;
        } elseif (preg_match('/\A([A-Za-z0-9_.-]+):([0-9]+)\z/D', $host, $match)) {
            $host = $match[1]; $port = (int)$match[2];
        } elseif (!preg_match('/\A[A-Za-z0-9_.-]+\z/D', $host) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) petish_order_refund_fail('database_configuration');
        $raw = trim((string)getenv('PETISH_DB_CLIENT_FLAGS'));
        if (preg_match('/\A[0-9]+\z/D', $raw)) $flags = (int)$raw;
        elseif ($raw === 'MYSQLI_CLIENT_SSL' && defined('MYSQLI_CLIENT_SSL')) $flags = MYSQLI_CLIENT_SSL;
        else petish_order_refund_fail('database_configuration');
        if ($port < 1 || $port > 65535 || (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT') && ($flags & MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT))) petish_order_refund_fail('database_configuration');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->connection = mysqli_init();
        $this->connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        if (defined('MYSQLI_CLIENT_SSL') && ($flags & MYSQLI_CLIENT_SSL)) $this->connection->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
        $this->connection->real_connect($host, getenv('PETISH_DB_USER'), getenv('PETISH_DB_PASSWORD'), getenv('PETISH_DB_NAME'), $port, $socket, $flags);
        $this->connection->set_charset('utf8mb4');
    }
    public function rows($sql, $params = []) {
        if (!preg_match('/\ASELECT\b/', $sql) || preg_match('/\b(?:OUTFILE|DUMPFILE|FOR UPDATE|LOCK IN SHARE MODE)\b/i', $sql) || str_contains($sql, ';')) petish_order_refund_fail('query');
        $statement = $this->connection->prepare($sql);
        try {
            if ($params) { $values = array_map('strval', $params); $statement->bind_param(str_repeat('s', count($values)), ...$values); }
            $statement->execute(); $result = $statement->get_result(); $rows = []; $bytes = 0;
            while ($row = $result->fetch_assoc()) {
                if (count($rows) >= 1001 || ($bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR))) > 2097152) petish_order_refund_fail('bounds');
                $rows[] = $row;
            }
            $result->free(); return $rows;
        } finally { $statement->close(); }
    }
    public function begin() {
        $this->connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->connection->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    }
    public function close() { if ($this->connection) { $this->connection->rollback(); $this->connection->close(); $this->connection = null; } }
}

final class Petish_Order_Refund_Collector {
    private $db; private $prefix; private $tables; private $hpos;
    const META = ['_order_total','_order_currency','_order_shipping','_order_shipping_tax','_order_tax','_cart_discount','_payment_method',
        '_refund_amount','_refunded_payment','_wcpay_transaction_fee','_wcpay_net','_intent_id','_charge_id','_intention_status',
        '_wcpay_intent_currency','_wcpay_mode','_wcpay_payment_transaction_id','_wcpay_refund_status','_wcpay_refund_id',
        '_wcpay_refund_transaction_id','_wcpay_payment_method_details','_wcpay_multi_currency_order_default_currency'];
    public function __construct($db, $prefix) {
        if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $prefix)) petish_order_refund_fail('database_configuration');
        $this->db = $db; $this->prefix = $prefix;
    }
    private function rows($sql, $params = []) { return $this->db->rows($sql, $params); }
    private function table($suffix) { return '`' . $this->prefix . $suffix . '`'; }
    private function one($rows, $required = true) {
        if (count($rows) > 1 || ($required && count($rows) !== 1)) petish_order_refund_fail('storage_layout');
        return $rows[0] ?? null;
    }
    private function map($rows, $key, $value) {
        $out = [];
        foreach ($rows as $row) {
            if (!is_string($row[$key] ?? null) || !is_string($row[$value] ?? null) || strlen($row[$value]) > 32768) petish_order_refund_fail('metadata_value');
            if (array_key_exists($row[$key], $out)) {
                if ($out[$row[$key]] !== $row[$value]) petish_order_refund_fail('metadata_duplicate_conflict');
                continue; // Identical legacy duplicates contain one value, never an additional charge/refund.
            }
            $out[$row[$key]] = $row[$value];
        }
        return $out;
    }
    private function meta($id, $hpos) {
        $keys = "'" . implode("','", self::META) . "'";
        return $this->map($this->rows('SELECT meta_key,meta_value FROM ' . $this->table($hpos ? 'wc_orders_meta' : 'postmeta')
            . ' WHERE ' . ($hpos ? 'order_id' : 'post_id') . '=? AND meta_key IN (' . $keys . ') LIMIT 101', [$id]), 'meta_key', 'meta_value');
    }
    private function money($meta, $key, $default = '0') { return petish_order_refund_units($meta[$key] ?? $default); }
    private function record($id, $hpos, $refund = false) {
        $meta = $this->meta($id, $hpos);
        if ($hpos) {
            $row = $this->one($this->rows('SELECT id,type,status,currency,total_amount,tax_amount,payment_method,date_created_gmt,date_updated_gmt,parent_order_id FROM ' . $this->table('wc_orders') . ' WHERE id=? LIMIT 2', [$id]));
            $op = $this->one($this->rows('SELECT shipping_total_amount,shipping_tax_amount,discount_total_amount FROM ' . $this->table('wc_order_operational_data') . ' WHERE order_id=? LIMIT 2', [$id]));
            $record = ['id'=>(int)$row['id'],'type'=>$row['type'],'parent'=>(int)$row['parent_order_id'],'status'=>petish_order_refund_status($row['status']),
                'currency'=>petish_order_refund_currency($row['currency']),'total'=>petish_order_refund_units($row['total_amount']),
                'tax'=>petish_order_refund_units($row['tax_amount'] ?? '0'),'shipping'=>petish_order_refund_units($op['shipping_total_amount'] ?? '0'),
                'shipping_tax'=>petish_order_refund_units($op['shipping_tax_amount'] ?? '0'),'discount'=>petish_order_refund_units($op['discount_total_amount'] ?? '0'),
                'gateway'=>$row['payment_method'] ?? '', 'created'=>petish_order_refund_date($row['date_created_gmt']), 'modified'=>petish_order_refund_date($row['date_updated_gmt'])];
            foreach (['_order_total'=>'total','_order_shipping'=>'shipping','_order_shipping_tax'=>'shipping_tax','_order_tax'=>'tax','_cart_discount'=>'discount'] as $key=>$field)
                if (isset($meta[$key]) && $this->money($meta,$key) !== $record[$field]) petish_order_refund_fail('storage_drift');
            if (isset($meta['_order_currency']) && petish_order_refund_currency($meta['_order_currency']) !== $record['currency']) petish_order_refund_fail('storage_drift');
        } else {
            $row = $this->one($this->rows('SELECT ID,post_type,post_status,post_parent,post_date_gmt,post_modified_gmt FROM ' . $this->table('posts') . ' WHERE ID=? LIMIT 2', [$id]));
            if (!array_key_exists('_order_total', $meta)) petish_order_refund_fail('metadata_missing_order_total');
            if (!array_key_exists('_order_currency', $meta)) petish_order_refund_fail('metadata_missing_order_currency');
            $record = ['id'=>(int)$row['ID'],'type'=>$row['post_type'],'parent'=>(int)$row['post_parent'],'status'=>petish_order_refund_status($row['post_status']),
                'currency'=>petish_order_refund_currency($meta['_order_currency']),'total'=>$this->money($meta,'_order_total'),
                'tax'=>$this->money($meta,'_order_tax'),'shipping'=>$this->money($meta,'_order_shipping'),'shipping_tax'=>$this->money($meta,'_order_shipping_tax'),
                'discount'=>$this->money($meta,'_cart_discount'),'gateway'=>$meta['_payment_method'] ?? '',
                'created'=>petish_order_refund_date($row['post_date_gmt']),'modified'=>petish_order_refund_date($row['post_modified_gmt'])];
        }
        if ($record['id'] !== $id || $record['type'] !== ($refund ? 'shop_order_refund' : 'shop_order') || $record['currency'] === 'unknown') petish_order_refund_fail('storage_layout');
        if ($refund) {
            if (!isset($meta['_refund_amount'])) petish_order_refund_fail('metadata_missing_refund_amount');
            $record['amount'] = $this->money($meta,'_refund_amount');
            if ($record['amount'] < 0 || $record['total'] !== -$record['amount']) petish_order_refund_fail('storage_drift');
        } elseif ($record['total'] < 0) petish_order_refund_fail('amount');
        $record['meta'] = $meta; return $record;
    }
    private function refund_ids($id, $hpos) {
        $rows = $hpos ? $this->rows('SELECT id FROM ' . $this->table('wc_orders') . " WHERE parent_order_id=? AND type='shop_order_refund' ORDER BY id LIMIT 21", [$id])
            : $this->rows('SELECT ID AS id FROM ' . $this->table('posts') . " WHERE post_parent=? AND post_type='shop_order_refund' ORDER BY ID LIMIT 21", [$id]);
        if (count($rows) > 20) petish_order_refund_fail('bounds');
        return array_map(static fn($row)=>(int)$row['id'], $rows);
    }
    private function allocation($id) {
        $rows = $this->rows('SELECT i.order_item_id,i.order_item_type,m.meta_key,m.meta_value FROM ' . $this->table('woocommerce_order_items') . ' i LEFT JOIN '
            . $this->table('woocommerce_order_itemmeta') . " m ON m.order_item_id=i.order_item_id AND m.meta_key IN ('cost','total_tax','_line_total','_line_tax') WHERE i.order_id=? AND i.order_item_type IN ('line_item','shipping','fee') ORDER BY i.order_item_id,m.meta_id LIMIT 1001", [$id]);
        if (count($rows) > 1000) petish_order_refund_fail('bounds');
        $items = []; $total = 0; $shipping = 0; $shipping_tax = 0; $fees = 0;
        foreach ($rows as $row) {
            $key = $row['order_item_id'] . ':' . ($row['meta_key'] ?? 'missing');
            if (array_key_exists($key,$items)) {
                if ($items[$key] !== $row['meta_value']) petish_order_refund_fail('metadata_duplicate_conflict');
                continue; // The same stored allocation must not be counted twice.
            }
            $items[$key] = $row['meta_value'];
            if ($row['meta_key'] === null) continue;
            $amount = petish_order_refund_units($row['meta_value']);
            if ($row['order_item_type'] === 'shipping' && $row['meta_key'] === 'cost') { $shipping += $amount; $total += $amount; }
            elseif ($row['order_item_type'] === 'shipping' && $row['meta_key'] === 'total_tax') { $shipping_tax += $amount; $total += $amount; }
            elseif ($row['order_item_type'] !== 'shipping' && in_array($row['meta_key'], ['_line_total','_line_tax'], true)) {
                $total += $amount; if ($row['order_item_type'] === 'fee' && $row['meta_key'] === '_line_total') $fees += $amount;
            }
        }
        return ['total'=>$total,'shipping'=>$shipping,'shipping_tax'=>$shipping_tax,'fees'=>$fees];
    }
    private function notes($id) {
        $rows = $this->rows('SELECT comment_content FROM ' . $this->table('comments') . " WHERE comment_post_ID=? AND comment_type='order_note' ORDER BY comment_ID DESC LIMIT 101", [$id]);
        $counts = array_fill_keys(['payment_succeeded','refund_succeeded','refund_pending','refund_failed','insufficient_balance','auth_cancelled','fee_breakdown','application_fee_refunded','dispute'], 0);
        foreach (array_slice($rows,0,100) as $row) {
            if (!is_string($row['comment_content']) || strlen($row['comment_content']) > 32768) petish_order_refund_fail('bounds');
            $text = strtolower(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($row['comment_content']), ENT_QUOTES|ENT_HTML5, 'UTF-8')));
            // Merchant-entered refund reasons are not provider status evidence.
            $status_text = explode('reason:', $text, 2)[0];
            if (str_contains($text,'successfully charged') && str_contains($text,'payment')) $counts['payment_succeeded']++;
            if (preg_match('/\brefund\b.*(?:successfully processed|successfully refunded)/', $status_text)) $counts['refund_succeeded']++;
            if (preg_match('/\brefund\b.*\b(?:is pending|pending refund)\b/', $status_text)) $counts['refund_pending']++;
            if (preg_match('/\brefund\b.*\b(?:failed|unsuccessful|cancelled|canceled)\b/', $status_text)) $counts['refund_failed']++;
            if (str_contains($status_text,'insufficient_balance_for_refund') || (str_contains($status_text,'refund') && preg_match('/insufficient (?:funds|balance)/', $status_text))) $counts['insufficient_balance']++;
            if (preg_match('/payment authorization.*successfully.*cancel(?:led|ed)/', $text)) $counts['auth_cancelled']++;
            if ((str_contains($text,'fee') && str_contains($text,'net payout')) || str_contains($text,'fee details') || str_contains($row['comment_content'],'captured-event-details')) $counts['fee_breakdown']++;
            if (str_contains($text,'woopayments refunded its') && str_contains($text,'application fee')) $counts['application_fee_refunded']++;
            if (preg_match('/\bdisput(?:e|ed)\b/',$text)) $counts['dispute']++;
        }
        $out = ['note_count'=>min(count($rows),100),'notes_truncated'=>count($rows)>100];
        foreach ($counts as $key=>$count) $out['note_'.$key.'_count'] = $count;
        return $out;
    }
    public function collect($selector) {
        $selector = petish_order_refund_selector($selector); $id = $selector['order_id'];
        $suffixes = ['options','posts','postmeta','comments','woocommerce_order_items','woocommerce_order_itemmeta','wc_orders','wc_orders_meta','wc_order_operational_data','wc_order_addresses'];
        $names = array_map(fn($suffix)=>$this->prefix.$suffix,$suffixes);
        $found = $this->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',',array_fill(0,count($names),'?')) . ')', $names);
        $this->tables = [];
        foreach ($found as $row) { if (!in_array($row['TABLE_NAME'],$names,true) || strtoupper($row['ENGINE'] ?? '') !== 'INNODB') petish_order_refund_fail('storage_layout'); $this->tables[$row['TABLE_NAME']] = true; }
        foreach (array_slice($suffixes,0,6) as $required) if (!isset($this->tables[$this->prefix.$required])) petish_order_refund_fail('storage_layout');
        $options = $this->map($this->rows('SELECT option_name,option_value FROM ' . $this->table('options')
            . " WHERE option_name IN ('home','siteurl','woocommerce_custom_orders_table_enabled','woocommerce_custom_orders_table_data_sync_enabled','woocommerce_currency') LIMIT 6"), 'option_name','option_value');
        foreach (['home','siteurl'] as $key) {
            $url = isset($options[$key]) ? parse_url($options[$key]) : false;
            if (!is_array($url) || strtolower($url['host'] ?? '') !== 'petish.biz' || !in_array(strtolower($url['scheme'] ?? ''),['http','https'],true)
                || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
                || (isset($url['port']) && $url['port'] !== (strtolower($url['scheme']) === 'https' ? 443 : 80))) petish_order_refund_fail('origin_mismatch');
        }
        $flag = $options['woocommerce_custom_orders_table_enabled'] ?? 'no';
        if (!in_array($flag,['yes','no'],true) || !in_array($options['woocommerce_custom_orders_table_data_sync_enabled'] ?? 'no',['yes','no'],true)) petish_order_refund_fail('storage_configuration');
        $this->hpos = $flag === 'yes';
        $hpos_present = count(array_filter(array_slice($suffixes,6),fn($name)=>isset($this->tables[$this->prefix.$name]))) === 4;
        if ($this->hpos && !$hpos_present) petish_order_refund_fail('storage_layout');
        $minimal = $this->hpos ? $this->rows('SELECT id,type FROM '.$this->table('wc_orders').' WHERE id=? LIMIT 2',[$id])
            : $this->rows('SELECT ID,post_type FROM '.$this->table('posts').' WHERE ID=? LIMIT 2',[$id]);
        $minimal = $this->one($minimal,false);
        if (!$minimal || ($minimal[$this->hpos?'type':'post_type'] ?? '') !== 'shop_order') return ['format'=>PETISH_ORDER_REFUND_FORMAT,'status'=>'not_found','order_id'=>$id];
        if (isset($selector['expected_billing_first_name'])) {
            $name_rows = $this->hpos ? $this->rows('SELECT first_name AS name FROM '.$this->table('wc_order_addresses')." WHERE order_id=? AND address_type='billing' LIMIT 2",[$id])
                : $this->rows('SELECT meta_value AS name FROM '.$this->table('postmeta')." WHERE post_id=? AND meta_key='_billing_first_name' LIMIT 2",[$id]);
            $actual = count($name_rows) === 1 ? $name_rows[0]['name'] : null;
            $expected = trim($selector['expected_billing_first_name']);
            $matched = is_string($actual) && strlen($actual) <= 320 && preg_match('//u',$actual) === 1;
            if ($matched) $matched = function_exists('mb_convert_case') ? mb_convert_case(trim($actual),MB_CASE_FOLD,'UTF-8') === mb_convert_case($expected,MB_CASE_FOLD,'UTF-8') : preg_match('/\A'.preg_quote($expected,'/').'\z/iuD',trim($actual)) === 1;
            if (!$matched) return ['format'=>PETISH_ORDER_REFUND_FORMAT,'status'=>'identity_mismatch','order_id'=>$id];
        }
        $order = $this->record($id,$this->hpos); $ids = $this->refund_ids($id,$this->hpos); $dual = false;
        $compare_shadow = ($options['woocommerce_custom_orders_table_data_sync_enabled'] ?? 'no') === 'yes';
        $shadow = !$compare_shadow ? [] : ($this->hpos ? $this->rows('SELECT ID,post_type FROM '.$this->table('posts').' WHERE ID=? LIMIT 2',[$id])
            : ($hpos_present ? $this->rows('SELECT id,type FROM '.$this->table('wc_orders').' WHERE id=? LIMIT 2',[$id]) : []));
        $shadow = $this->one($shadow,false);
        if ($shadow && ($shadow[$this->hpos?'post_type':'type'] ?? '') === 'shop_order') {
            $other = $this->record($id,!$this->hpos);
            foreach (['id','type','parent','status','currency','total','tax','shipping','shipping_tax','discount','gateway'] as $key) if ($other[$key] !== $order[$key]) petish_order_refund_fail('storage_drift');
            if ($this->refund_ids($id,!$this->hpos) !== $ids) petish_order_refund_fail('storage_drift');
            $dual = true;
        }
        $original = $this->allocation($id); $refund_total = 0; $shipping_refund = 0; $shipping_tax_refund = 0; $tax_refund = 0; $unallocated = 0; $complete = true; $refunds = [];
        foreach ($ids as $refund_id) {
            $refund = $this->record($refund_id,$this->hpos,true);
            if ($refund['parent'] !== $id || $refund['currency'] !== $order['currency']) petish_order_refund_fail('storage_drift');
            if ($dual) {
                $other = $this->record($refund_id,!$this->hpos,true);
                foreach (['parent','currency','total','amount','tax','shipping','shipping_tax'] as $key) if ($other[$key] !== $refund[$key]) petish_order_refund_fail('storage_drift');
            }
            $allocation = $this->allocation($refund_id);
            if ($allocation['shipping'] > 0 || $allocation['shipping_tax'] > 0 || $allocation['total'] > 0) petish_order_refund_fail('storage_drift');
            $allocated = -$allocation['total']; $difference = $refund['amount'] - $allocated;
            $complete = $complete && $difference === 0; $unallocated += $difference;
            $refund_total += $refund['amount']; $shipping_refund -= $allocation['shipping']; $shipping_tax_refund -= $allocation['shipping_tax']; $tax_refund -= $refund['tax'];
            $flag = $refund['meta']['_refunded_payment'] ?? null;
            $flag = $flag === null ? null : (in_array($flag,['1','yes','true'],true) ? true : (in_array($flag,['0','no','false',''],true) ? false : null));
            $refunds[] = ['id'=>$refund_id,'created_gmt'=>$refund['created'],'currency'=>$refund['currency'],'amount'=>petish_order_refund_money($refund['amount']),
                'shipping_amount'=>petish_order_refund_money(-$allocation['shipping']),'shipping_tax_amount'=>petish_order_refund_money(-$allocation['shipping_tax']),
                'tax_amount'=>petish_order_refund_money(-$refund['tax']),'unallocated_amount'=>petish_order_refund_money($difference),'allocation_complete'=>$difference===0,
                'refunded_payment_flag'=>$flag,'provider_refund_id_present'=>!empty($refund['meta']['_wcpay_refund_id']),
                'provider_transaction_id_present'=>!empty($refund['meta']['_wcpay_refund_transaction_id'])];
        }
        $remaining = $order['total'] - $refund_total;
        if ($remaining < 0) petish_order_refund_fail('storage_drift');
        $verified_shipping = $original['shipping'] === $order['shipping'] && $original['shipping_tax'] === $order['shipping_tax'];
        $details = [];
        if (isset($order['meta']['_wcpay_payment_method_details'])) {
            $details = json_decode($order['meta']['_wcpay_payment_method_details'],true,16);
            if (!is_array($details)) $details = [];
        }
        $out = ['format'=>PETISH_ORDER_REFUND_FORMAT,'status'=>'success','storage'=>$this->hpos?'hpos':'legacy','generated_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
            'order_id'=>$id,'order_status'=>$order['status'],'created_gmt'=>$order['created'],'modified_gmt'=>$order['modified'],
            'order_currency'=>$order['currency'],'store_currency'=>petish_order_refund_currency($options['woocommerce_currency'] ?? null),
            'intent_currency'=>petish_order_refund_currency($order['meta']['_wcpay_intent_currency'] ?? null),
            'multi_currency_default_currency'=>petish_order_refund_currency($order['meta']['_wcpay_multi_currency_order_default_currency'] ?? null),
            'payment_gateway'=>petish_order_refund_enum($order['gateway'],['woocommerce_payments','woocommerce_payments_afterpay_clearpay','woocommerce_payments_klarna','stripe','ppcp-gateway','ppcp-credit-card-gateway','bacs','cod','cheque'],'other'),
            'payment_method_type'=>petish_order_refund_enum($details['type'] ?? null,['card','afterpay_clearpay','klarna','link','paypal','affirm','amazon_pay','au_becs_debit','sepa_debit']),
            'payment_mode'=>petish_order_refund_enum($order['meta']['_wcpay_mode'] ?? null,['prod','production','test']),
            'capture_status_cached'=>petish_order_refund_enum($order['meta']['_intention_status'] ?? null,['succeeded','canceled','requires_capture','processing','requires_payment_method','requires_confirmation','requires_action']),
            'refund_status_cached'=>petish_order_refund_enum($order['meta']['_wcpay_refund_status'] ?? null,['pending','successful','succeeded','failed','canceled']),
            'order_total'=>petish_order_refund_money($order['total']),'order_shipping'=>petish_order_refund_money($order['shipping']),
            // Native _order_tax / HPOS tax_amount is cart/item tax only. Shipping tax is separate.
            'order_shipping_tax'=>petish_order_refund_money($order['shipping_tax']),'order_tax'=>petish_order_refund_money($order['tax']),
            'order_discount'=>petish_order_refund_money($order['discount']),'order_fees'=>petish_order_refund_money($original['fees']),
            'refund_total'=>petish_order_refund_money($refund_total),'remaining_recorded_amount'=>petish_order_refund_money($remaining),
            'original_shipping_items_total'=>petish_order_refund_money($original['shipping']),'original_shipping_items_tax'=>petish_order_refund_money($original['shipping_tax']),
            'refunded_shipping_total'=>petish_order_refund_money($shipping_refund),'refunded_shipping_tax'=>petish_order_refund_money($shipping_tax_refund),
            'refunded_tax_total'=>petish_order_refund_money($tax_refund),'unallocated_refund_amount'=>petish_order_refund_money($unallocated),
            'shipping_totals_verified'=>$verified_shipping,'refund_allocation_complete'=>$complete,
            'remaining_matches_unrefunded_shipping'=>($verified_shipping&&$complete) ? count($refunds)>0 && $remaining>0 && $remaining === ($order['shipping']+$order['shipping_tax']-$shipping_refund-$shipping_tax_refund) : null,
            'transaction_fee_cached'=>petish_order_refund_money(petish_order_refund_units($order['meta']['_wcpay_transaction_fee'] ?? null,true)),
            'merchant_net_cached'=>petish_order_refund_money(petish_order_refund_units($order['meta']['_wcpay_net'] ?? null,true)),
            'has_charge_id'=>!empty($order['meta']['_charge_id']),'has_intent_id'=>!empty($order['meta']['_intent_id']),
            'has_payment_transaction_id'=>!empty($order['meta']['_wcpay_payment_transaction_id']),
            'settlement_verified'=>false,'original_capture_amount_verified'=>false,'dual_store_checked'=>$dual,'refund_count'=>count($refunds),'refunds'=>$refunds];
        return $out + $this->notes($id);
    }
}

function petish_order_refund_validate_report($report) {
    if (!is_array($report) || ($report['format'] ?? null) !== PETISH_ORDER_REFUND_FORMAT || !is_int($report['order_id'] ?? null) || $report['order_id'] < 1) petish_order_refund_fail('report');
    if (in_array($report['status'] ?? null,['not_found','identity_mismatch'],true)) {
        if (count($report) !== 3) petish_order_refund_fail('report');
        return $report;
    }
    $keys = ['refunds'];
    foreach (['strings','integers','amounts','nullable_amounts','booleans','nullable_booleans'] as $kind) $keys = array_merge($keys,PETISH_ORDER_REFUND_SCHEMA[$kind]);
    if ($report['status'] !== 'success' || count($report) !== count($keys) || array_diff(array_keys($report),$keys)) petish_order_refund_fail('report');
    foreach (PETISH_ORDER_REFUND_SCHEMA['strings'] as $key) if (!is_string($report[$key]) || strlen($report[$key]) > 80 || !preg_match('/\A[A-Za-z0-9_:. +\-]+\z/D',$report[$key])) petish_order_refund_fail('report');
    foreach (PETISH_ORDER_REFUND_SCHEMA['integers'] as $key) if (!is_int($report[$key]) || $report[$key] < 0 || $report[$key] > 2147483647) petish_order_refund_fail('report');
    foreach (array_merge(PETISH_ORDER_REFUND_SCHEMA['amounts'],PETISH_ORDER_REFUND_SCHEMA['nullable_amounts']) as $key) {
        if ($report[$key] === null && in_array($key,PETISH_ORDER_REFUND_SCHEMA['nullable_amounts'],true)) continue;
        if (!is_string($report[$key]) || petish_order_refund_money(petish_order_refund_units($report[$key])) !== $report[$key]) petish_order_refund_fail('report');
    }
    foreach (PETISH_ORDER_REFUND_SCHEMA['booleans'] as $key) if (!is_bool($report[$key])) petish_order_refund_fail('report');
    foreach (PETISH_ORDER_REFUND_SCHEMA['nullable_booleans'] as $key) if ($report[$key] !== null && !is_bool($report[$key])) petish_order_refund_fail('report');
    if ($report['settlement_verified'] !== false || $report['original_capture_amount_verified'] !== false || !is_array($report['refunds']) || count($report['refunds']) > 20 || $report['refund_count'] !== count($report['refunds'])) petish_order_refund_fail('report');
    $refund_keys = [];
    foreach (['refund_strings','refund_integers','refund_amounts','refund_booleans','refund_nullable_booleans'] as $kind) $refund_keys = array_merge($refund_keys,PETISH_ORDER_REFUND_SCHEMA[$kind]);
    foreach ($report['refunds'] as $refund) {
        if (!is_array($refund) || count($refund) !== count($refund_keys) || array_diff(array_keys($refund),$refund_keys)) petish_order_refund_fail('report');
        foreach (PETISH_ORDER_REFUND_SCHEMA['refund_strings'] as $key) if (!is_string($refund[$key]) || strlen($refund[$key]) > 30 || !preg_match('/\A[A-Z0-9: \-]+\z/D',$refund[$key])) petish_order_refund_fail('report');
        if (!is_int($refund['id']) || $refund['id'] < 1 || $refund['id'] > 2147483647) petish_order_refund_fail('report');
        foreach (PETISH_ORDER_REFUND_SCHEMA['refund_amounts'] as $key) if (!is_string($refund[$key]) || petish_order_refund_money(petish_order_refund_units($refund[$key])) !== $refund[$key]) petish_order_refund_fail('report');
        foreach (PETISH_ORDER_REFUND_SCHEMA['refund_booleans'] as $key) if (!is_bool($refund[$key])) petish_order_refund_fail('report');
        if ($refund['refunded_payment_flag'] !== null && !is_bool($refund['refunded_payment_flag'])) petish_order_refund_fail('report');
    }
    if (strlen(json_encode($report,JSON_THROW_ON_ERROR)) > 65536) petish_order_refund_fail('bounds');
    return $report;
}
function petish_order_refund_collect($selector) {
    $selector = petish_order_refund_selector($selector);
    $database = new Petish_Order_Refund_DB();
    try {
        $database->begin();
        return petish_order_refund_validate_report((new Petish_Order_Refund_Collector($database,getenv('PETISH_DB_PREFIX')))->collect($selector));
    } finally { $database->close(); }
}
function petish_order_refund_private_path($path, $exists) {
    if (!is_string($path) || !str_starts_with($path,'/') || realpath(dirname($path)) !== dirname($path) || (fileperms(dirname($path)) & 07777) !== 0700 || is_link($path)) petish_order_refund_fail('paths');
    if ($exists) {
        $stat = lstat($path);
        if (!$stat || !is_file($path) || $stat['nlink'] !== 1 || ($stat['mode'] & 07777) !== 0600 || $stat['size'] < 2 || $stat['size'] > 2048) petish_order_refund_fail('paths');
    } elseif (file_exists($path)) petish_order_refund_fail('paths');
}
function petish_order_refund_decode_selector($raw) {
    if (!is_string($raw) || strlen($raw) > 2048) petish_order_refund_fail('input');
    $selector = json_decode($raw,true,4,JSON_THROW_ON_ERROR);
    if (!is_array($selector)) petish_order_refund_fail('input');
    // Tokenize whole JSON strings first, so a quoted value cannot impersonate a
    // member name. Decoding each key also detects escaped duplicate spellings.
    $pattern = <<<'JSON_TOKENS'
~"(?:[^"\\]|\\.)*"|[{}\[\]:,]|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null~s
JSON_TOKENS;
    if (preg_match_all($pattern,$raw,$matches) === false) petish_order_refund_fail('input');
    $depth = 0; $keys = [];
    foreach ($matches[0] as $position=>$token) {
        if ($token === '{' || $token === '[') $depth++;
        elseif ($token === '}' || $token === ']') $depth--;
        elseif ($depth === 1 && str_starts_with($token,'"') && ($matches[0][$position+1] ?? '') === ':') {
            $key = json_decode($token,true,2,JSON_THROW_ON_ERROR);
            if (!is_string($key) || isset($keys[$key])) petish_order_refund_fail('input');
            $keys[$key] = true;
        }
    }
    if (count($keys) !== count($selector)) petish_order_refund_fail('input');
    return petish_order_refund_selector($selector);
}
function petish_order_refund_main($argv) {
    if (count($argv) !== 3) petish_order_refund_fail('input');
    petish_order_refund_private_path($argv[1],true); petish_order_refund_private_path($argv[2],false);
    $raw = file_get_contents($argv[1]);
    $selector = petish_order_refund_decode_selector($raw);
    $report = petish_order_refund_collect($selector);
    $output = fopen($argv[2],'x'); if (!$output) petish_order_refund_fail('paths');
    try { chmod($argv[2],0600); $json = json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); if (fwrite($output,$json) !== strlen($json)) petish_order_refund_fail('output'); fflush($output); }
    finally { fclose($output); }
}
if (!defined('PETISH_ORDER_REFUND_INSPECTION_LIBRARY')) {
    if (PHP_SAPI !== 'cli') exit(1);
    umask(0077); ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
    try { petish_order_refund_main($argv); }
    catch (Throwable $error) { fwrite(STDERR,($error instanceof Petish_Order_Refund_Error ? $error->getMessage() : 'inspection_failed')."\n"); exit(1); }
}
