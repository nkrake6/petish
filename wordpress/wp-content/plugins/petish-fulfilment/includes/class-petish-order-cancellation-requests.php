<?php
/** Private cancellation requests. No supplier, payment, stock or order-status mutation. */
defined('ABSPATH') || exit;

final class Petish_Order_Cancellation_Requests {
    const OPTION = 'petish_cancellation_requests_enabled';
    const POST_TYPE = 'petish_cancel_req';
    const META = '_petish_cancellation_request_v1';
    const ORDER_META = '_petish_cancel_order_id';
    const CUSTOMER_META = '_petish_cancel_customer_id';
    const STATE_META = '_petish_cancel_state';
    const CREATED_META = '_petish_cancel_created_at';
    const STATES = array('requested','reviewing','awaiting_supplier','approved','partially_approved','declined','closed');
    const REQUEST_STATUSES = array('pending','on-hold','processing','completed','failed');
    const MAX_CASES = 100;
    const MAX_EVENTS = 100;
    private static $held = array();

    public static function init() { add_action('init', array(__CLASS__, 'register')); }
    public static function register() {
        register_post_type(self::POST_TYPE, array(
            'label'=>'Cancellation requests','public'=>false,'publicly_queryable'=>false,
            'exclude_from_search'=>true,'show_in_rest'=>false,'query_var'=>false,'rewrite'=>false,
            'show_ui'=>false,'show_in_menu'=>false,'supports'=>array(),'map_meta_cap'=>false,
            'capabilities'=>array('edit_post'=>'manage_woocommerce','read_post'=>'manage_woocommerce',
                'delete_post'=>'manage_woocommerce','edit_posts'=>'manage_woocommerce','edit_others_posts'=>'manage_woocommerce',
                'publish_posts'=>'manage_woocommerce','read_private_posts'=>'manage_woocommerce','delete_posts'=>'manage_woocommerce',
                'create_posts'=>'do_not_allow'),
        ));
    }
    public static function enabled() { return get_option(self::OPTION, 'no') === 'yes'; }
    public static function error($code, $message = '') {
        return new WP_Error('petish_cancel_' . $code, $message ?: 'The cancellation request could not be completed.');
    }
    private static function id($value) { return is_int($value) && $value > 0; }
    private static function exact($value, $keys) {
        return is_array($value) && count($value) === count($keys) && !array_diff(array_keys($value), $keys);
    }
    private static function text($value, $maximum, $blank = true) {
        if (!is_string($value) || strlen($value) > $maximum || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) return false;
        $value = trim(wp_strip_all_tags($value));
        return $blank || $value !== '' ? $value : false;
    }
    private static function order($id) {
        if (!self::id($id) || !function_exists('wc_get_order')) return self::error('order');
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order' || (int)$order->get_id() !== $id) return self::error('order');
        $order->read_meta_data(true);
        return $order;
    }
    /** Ownership is independent of cancellation eligibility, preserving terminal-order history. */
    public static function customer_order($id) {
        if (!self::enabled()) return self::error('disabled');
        $user = get_current_user_id();
        if (!is_user_logged_in() || !self::id($user)) return self::error('permission');
        $order = self::order($id);
        if (is_wp_error($order) || (int)$order->get_customer_id('edit') !== $user) return self::error('permission');
        return $order;
    }
    public static function available_items($order) {
        if (!$order instanceof WC_Order) return self::error('order');
        $result = array();
        foreach ($order->get_items('line_item') as $item) {
            $id = (int)$item->get_id(); $quantity = $item->get_quantity();
            $refunded = $order->get_qty_refunded_for_item($id);
            if ($id < 1 || isset($result[$id]) || !is_numeric($quantity) || (float)$quantity !== (float)(int)$quantity
                || $quantity < 1 || $quantity > 10000 || !is_numeric($refunded)
                || (float)$refunded !== (float)(int)$refunded || $refunded > 0 || abs($refunded) > $quantity) return self::error('items');
            $name = self::text($item->get_name('edit'), 1000, false);
            if ($name === false) return self::error('items');
            $result[$id] = array('name'=>$name,'quantity'=>(int)$quantity,'refunded_quantity'=>abs((int)$refunded),
                'available_quantity'=>(int)$quantity - abs((int)$refunded));
            if (count($result) > 100) return self::error('items');
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }
    public static function can_request($order) {
        if (!$order instanceof WC_Order || !in_array($order->get_status('edit'), self::REQUEST_STATUSES, true)) return false;
        $items = self::available_items($order);
        if (is_wp_error($items)) return false;
        foreach ($items as $item) if ($item['available_quantity'] > 0) return true;
        return false;
    }
    private static function select($order, $selected) {
        $available = self::available_items($order);
        if (is_wp_error($available)) return $available;
        if (!is_array($selected) || !$selected || count($selected) > 100) return self::error('items', 'Choose at least one item and a valid quantity.');
        $result = array();
        foreach ($selected as $id=>$quantity) {
            if (!self::id($id) || !is_int($quantity) || $quantity < 1 || $quantity > 10000 || !isset($available[$id])
                || $quantity > $available[$id]['available_quantity']) return self::error('items', 'The requested quantities are no longer available. Refresh this order.');
            $result[$id] = array('name'=>$available[$id]['name'],'quantity'=>$quantity);
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }
    private static function with_order_lock($order_id, $operation, $now = null) {
        global $wpdb;
        if (!self::id($order_id) || !is_object($wpdb) || !defined('DB_NAME')) return self::error('lock');
        $name = 'petish-cancel-' . substr(hash('sha256', DB_NAME . '|' . $order_id), 0, 48);
        if (isset(self::$held[$name])) return self::error('lock');
        $acquired = false;
        try {
            if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) !== '1') return self::error('lock');
            $acquired = true; self::$held[$name] = true;
            $order = self::order($order_id);
            $now = is_int($now) && $now > 0 ? $now : time();
            return is_wp_error($order) ? $order : $operation($order, $now);
        } catch (Throwable $ignored) { return self::error('save'); }
        finally {
            if ($acquired) {
                $released = false;
                try { $released = (string)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)) === '1'; } catch (Throwable $ignored) {}
                unset(self::$held[$name]);
                if (!$released) return self::error('lock');
            }
        }
    }
    private static function ids_for_order($order_id) {
        return get_posts(array('post_type'=>self::POST_TYPE,'post_status'=>'private','fields'=>'ids',
            'numberposts'=>self::MAX_CASES + 1,'orderby'=>'ID','order'=>'DESC','suppress_filters'=>false,
            'meta_key'=>self::ORDER_META,'meta_value'=>(string)$order_id));
    }
    private static function record_items($items) {
        if (!is_array($items) || !$items || count($items) > 100) return false;
        foreach ($items as $id=>$item) if (!self::id($id) || !self::exact($item,array('name','quantity'))
            || self::text($item['name'],1000,false) !== $item['name'] || !is_int($item['quantity']) || $item['quantity'] < 1 || $item['quantity'] > 10000) return false;
        $sorted = $items; ksort($sorted,SORT_NUMERIC);
        return $sorted === $items;
    }
    private static function valid($record) {
        $keys = array('schema_version','id','order_id','customer_id','order_number','currency','items','reason','state','decision','created_at','updated_at','revision','history','events');
        if (!self::exact($record,$keys) || $record['schema_version'] !== 1 || !self::id($record['id']) || !self::id($record['order_id'])
            || !self::id($record['customer_id']) || self::text($record['order_number'],100,false) !== $record['order_number']
            || !is_string($record['currency']) || preg_match('/\A[A-Z]{3}\z/D',$record['currency']) !== 1 || !self::record_items($record['items'])
            || self::text($record['reason'],1000) !== $record['reason'] || !in_array($record['state'],self::STATES,true)
            || !is_int($record['created_at']) || $record['created_at'] < 1 || !is_int($record['updated_at']) || $record['updated_at'] < $record['created_at']
            || !is_int($record['revision']) || $record['revision'] < 1 || !is_array($record['history']) || !array_is_list($record['history']) || count($record['history']) > 100
            || !is_array($record['events']) || !$record['events'] || count($record['events']) > self::MAX_EVENTS || strlen(wp_json_encode($record)) > 2097152) return false;
        if ($record['decision'] !== null) {
            $decision = $record['decision'];
            if (!self::exact($decision,array('accepted_items','public_message','supplier_checked','private_reference'))
                || !is_array($decision['accepted_items']) || array_keys($decision['accepted_items']) !== array_keys($record['items'])
                || self::text($decision['public_message'],1000) !== $decision['public_message'] || $decision['supplier_checked'] !== true
                || self::text($decision['private_reference'],1000,false) !== $decision['private_reference']) return false;
            foreach ($decision['accepted_items'] as $id=>$quantity) if (!is_int($quantity) || $quantity < 0 || $quantity > $record['items'][$id]['quantity']) return false;
        }
        foreach ($record['history'] as $history) {
            if (!self::exact($history,array('at','actor_id','action','state','private_note')) || !is_int($history['at']) || $history['at'] < $record['created_at']
                || !self::id($history['actor_id']) || !in_array($history['action'],array('created','progress','decision','closed'),true)
                || !in_array($history['state'],self::STATES,true) || self::text($history['private_note'],1000) !== $history['private_note']) return false;
        }
        foreach ($record['events'] as $id=>$event) {
            if (!is_string($id) || preg_match('/\A[a-f0-9]{64}\z/D',$id) !== 1
                || !self::exact($event,array('id','kind','created_at','payload','payload_hash','deliveries')) || $event['id'] !== $id
                || !in_array($event['kind'],array('received','progress','decision','closed'),true) || !is_int($event['created_at']) || $event['created_at'] < $record['created_at']
                || !is_array($event['payload']) || hash('sha256',wp_json_encode($event['payload'])) !== $event['payload_hash']
                || !self::exact($event['deliveries'],array('customer','staff'))) return false;
            $payload = $event['payload'];
            if (!self::exact($payload,array('request_id','order_id','order_number','customer_id','currency','items','reason','state','public_message','accepted_items'))
                || $payload['request_id'] !== $record['id'] || $payload['order_id'] !== $record['order_id'] || $payload['customer_id'] !== $record['customer_id']
                || $payload['order_number'] !== $record['order_number'] || $payload['currency'] !== $record['currency'] || $payload['items'] !== $record['items']
                || $payload['reason'] !== $record['reason'] || !in_array($payload['state'],self::STATES,true)
                || self::text($payload['public_message'],1000) !== $payload['public_message'] || !is_array($payload['accepted_items'])) return false;
            foreach ($payload['accepted_items'] as $line=>$quantity) if (!isset($record['items'][$line]) || !is_int($quantity) || $quantity < 0 || $quantity > $record['items'][$line]['quantity']) return false;
            foreach ($event['deliveries'] as $slot) {
                if (!self::exact($slot,array('status','attempts','attempt_id','claimed_at','finished_at','history'))
                    || !in_array($slot['status'],array('pending','sending','submitted','uncertain','not_sent'),true)
                    || !is_int($slot['attempts']) || $slot['attempts'] < 0 || $slot['attempts'] > 20 || !is_array($slot['history']) || !array_is_list($slot['history']) || count($slot['history']) > 60) return false;
                if ($slot['attempt_id'] !== null && (!is_string($slot['attempt_id']) || preg_match('/\A[a-f0-9]{64}\z/D',$slot['attempt_id']) !== 1)) return false;
                foreach (array('claimed_at','finished_at') as $field) if ($slot[$field] !== null && (!is_int($slot[$field]) || $slot[$field] < 1)) return false;
                if (in_array($slot['status'],array('sending','submitted','uncertain'),true)
                    && ($slot['attempts'] < 1 || $slot['attempt_id'] === null || $slot['claimed_at'] === null)) return false;
                if ($slot['status'] === 'submitted' && ($slot['finished_at'] === null || $slot['finished_at'] < $slot['claimed_at'])) return false;
                foreach ($slot['history'] as $entry) {
                    if (!self::exact($entry,array('status','attempt_id','at','outcome'))
                        || !in_array($entry['status'],array('pending','sending','submitted','uncertain','not_sent'),true)
                        || !is_int($entry['at']) || $entry['at'] < $record['created_at']
                        || !in_array($entry['outcome'],array('claimed','accepted','ambiguous','lease_expired','recipient_invalid','render_failed','owner_changed','sending_disabled','confirmed_submitted','confirmed_not_sent','retry_requested'),true)
                        || ($entry['attempt_id'] !== null && (!is_string($entry['attempt_id']) || preg_match('/\A[a-f0-9]{64}\z/D',$entry['attempt_id']) !== 1))) return false;
                }
            }
        }
        return true;
    }
    /** Internal/staff read; no endpoint is registered here. Customer callers use owner methods. */
    public static function get($id) {
        if (!self::id($id)) return self::error('state');
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE || $post->post_status !== 'private') return self::error('state');
        $copies = get_post_meta($id,self::META,false);
        if (count($copies) !== 1) return self::error('state');
        $record = $copies[0];
        foreach (array(self::ORDER_META,self::CUSTOMER_META,self::CREATED_META) as $index)
            if (count(get_post_meta($id,$index,false)) !== 1) return self::error('state');
        if (!self::valid($record) || $record['id'] !== $id || (int)$post->post_author !== $record['customer_id']
            || (int)get_post_meta($id,self::ORDER_META,true) !== $record['order_id'] || (int)get_post_meta($id,self::CUSTOMER_META,true) !== $record['customer_id']
            || (int)get_post_meta($id,self::CREATED_META,true) !== $record['created_at']) return self::error('state');
        return $record;
    }
    private static function save($record) {
        if (!self::valid($record)) return self::error('state');
        // WordPress unslashes metadata input; preserve literal customer/staff text.
        update_post_meta($record['id'], self::META, wp_slash($record));
        // State is a staff queue index, never the authority used for idempotency or customer data.
        update_post_meta($record['id'], self::STATE_META, $record['state']);
        if (get_post_meta($record['id'], self::STATE_META, true) !== $record['state']) return self::error('save');
        $saved = self::get($record['id']);
        return is_wp_error($saved) || $saved !== $record ? self::error('save') : $saved;
    }
    /** Shared serialization for request state and delivery slots. No native order fields are saved. */
    public static function locked($id, callable $operation, $now = null) {
        $initial = self::get($id);
        if (is_wp_error($initial)) return $initial;
        return self::with_order_lock($initial['order_id'], static function($order,$now) use ($id,$operation) {
            // The pre-lock lookup may have populated an older object-cache entry.
            wp_cache_delete($id, 'post_meta');
            wp_cache_delete($id, 'posts');
            $record = self::get($id);
            if (is_wp_error($record)) return $record;
            $result = $operation($record,$order,max($now,$record['updated_at']));
            if (is_wp_error($result)) return $result;
            if (!is_array($result) || !array_key_exists('record',$result) || !array_key_exists('result',$result)) return self::error('state');
            if ($result['record'] !== $record) {
                $next = $result['record'];
                if (!self::valid($next)) return self::error('state');
                foreach (array('id','order_id','customer_id','order_number','currency','items','reason','created_at') as $field)
                    if (($next[$field] ?? null) !== $record[$field]) return self::error('state');
                foreach ($record['events'] as $event_id=>$event) {
                    if (!isset($next['events'][$event_id])) return self::error('state');
                    foreach (array('id','kind','created_at','payload','payload_hash') as $field)
                        if ($next['events'][$event_id][$field] !== $event[$field]) return self::error('state');
                }
                $saved = self::save($next);
                if (is_wp_error($saved)) return $saved;
            } elseif (get_post_meta($id, self::STATE_META, true) !== $record['state']) {
                // A repeated staff action can repair an interrupted derived-index write
                // without changing the decision or creating another notification event.
                update_post_meta($id, self::STATE_META, $record['state']);
                if (get_post_meta($id, self::STATE_META, true) !== $record['state']) return self::error('save');
            }
            return $result['result'];
        }, $now);
    }
    private static function event(&$record, $kind, $message, $now) {
        if (count($record['events']) >= self::MAX_EVENTS) return self::error('limit');
        $payload = array('request_id'=>$record['id'],'order_id'=>$record['order_id'],'order_number'=>$record['order_number'],
            'customer_id'=>$record['customer_id'],'currency'=>$record['currency'],'items'=>$record['items'],'reason'=>$record['reason'],
            'state'=>$record['state'],'public_message'=>$message,'accepted_items'=>$record['decision']['accepted_items'] ?? array());
        $id = hash('sha256', $record['id'] . '|' . $record['revision'] . '|' . $kind);
        $slot = array('status'=>'pending','attempts'=>0,'attempt_id'=>null,'claimed_at'=>null,'finished_at'=>null,'history'=>array());
        $record['events'][$id] = array('id'=>$id,'kind'=>$kind,'created_at'=>$now,'payload'=>$payload,
            'payload_hash'=>hash('sha256',wp_json_encode($payload)),'deliveries'=>array('customer'=>$slot,'staff'=>$slot));
        return true;
    }
    public static function create($order_id, array $items, $reason = '') {
        if (!self::enabled()) return self::error('disabled');
        $reason = self::text($reason,1000);
        if ($reason === false) return self::error('reason', 'Keep the reason to 1,000 characters.');
        $owner = self::customer_order($order_id);
        if (is_wp_error($owner)) return $owner;
        $result = self::with_order_lock($order_id, static function($order,$now) use ($items,$reason) {
            if ((int)$order->get_customer_id('edit') !== get_current_user_id() || !is_user_logged_in()) return self::error('permission');
            if (!self::can_request($order)) return self::error('status', 'This order cannot receive a new cancellation request.');
            $selected = self::select($order,$items);
            if (is_wp_error($selected)) return $selected;
            $ids = self::ids_for_order((int)$order->get_id());
            if (count($ids) >= self::MAX_CASES) return self::error('limit');
            foreach ($ids as $id) {
                $old = self::get((int)$id);
                if (is_wp_error($old)) return $old;
                if ($old['order_id'] !== (int)$order->get_id() || $old['customer_id'] !== get_current_user_id()) return self::error('state');
                if ($old['state'] !== 'closed') { $old['reused'] = true; return $old; }
            }
            $recent = get_posts(array('post_type'=>self::POST_TYPE,'post_status'=>'private','fields'=>'ids','numberposts'=>5,
                'suppress_filters'=>false,'meta_query'=>array('relation'=>'AND',array('key'=>self::CUSTOMER_META,'value'=>(string)get_current_user_id()),
                    array('key'=>self::CREATED_META,'value'=>$now - DAY_IN_SECONDS,'compare'=>'>=','type'=>'NUMERIC'))));
            if (count($recent) >= 5) return self::error('limit', 'Please contact Petish before making another cancellation request today.');
            $number = self::text((string)$order->get_order_number(),100,false); $currency = $order->get_currency('edit');
            if ($number === false || !is_string($currency) || !preg_match('/\A[A-Z]{3}\z/D',$currency)) return self::error('order');
            $id = wp_insert_post(array('post_type'=>self::POST_TYPE,'post_status'=>'private','post_author'=>get_current_user_id(),
                'post_title'=>'Cancellation request','meta_input'=>array(self::ORDER_META=>(int)$order->get_id(),self::CUSTOMER_META=>get_current_user_id(),self::CREATED_META=>$now)),true);
            if (is_wp_error($id) || !self::id($id)) return self::error('save');
            $record = array('schema_version'=>1,'id'=>$id,'order_id'=>(int)$order->get_id(),'customer_id'=>get_current_user_id(),
                'order_number'=>$number,'currency'=>$currency,'items'=>$selected,'reason'=>$reason,'state'=>'requested','decision'=>null,
                'created_at'=>$now,'updated_at'=>$now,'revision'=>1,'history'=>array(array('at'=>$now,'actor_id'=>get_current_user_id(),'action'=>'created','state'=>'requested','private_note'=>'')),'events'=>array());
            self::event($record,'received','',$now);
            $saved = self::save($record);
            if (is_wp_error($saved)) {
                // This newly owned, unsent record alone may be removed after a failed initial save.
                $post = get_post($id);
                if ($post && $post->post_type === self::POST_TYPE && (int)$post->post_author === get_current_user_id()) wp_delete_post($id,true);
                return $saved;
            }
            $saved['reused'] = false;
            return $saved;
        });
        if (!is_wp_error($result) && !$result['reused']) do_action('petish_cancellation_request_event',$result['id']);
        return $result;
    }
    public static function public_snapshot($record) {
        if (is_array($record) && isset($record['reused']) && is_bool($record['reused'])) unset($record['reused']);
        if (!self::valid($record)) return self::error('state');
        $public = array_intersect_key($record,array_flip(array('id','order_id','order_number','currency','items','reason','state','created_at','updated_at','revision')));
        $public['accepted_items'] = $record['decision']['accepted_items'] ?? array();
        $events = array_values($record['events']); $last = end($events);
        $public['public_message'] = $last['payload']['public_message'];
        return $public;
    }
    public static function customer_requests($order_id) {
        $order = self::customer_order($order_id);
        if (is_wp_error($order)) return $order;
        $records = array();
        foreach (self::ids_for_order($order_id) as $id) {
            $record = self::get((int)$id);
            if (is_wp_error($record) || $record['order_id'] !== $order_id || $record['customer_id'] !== get_current_user_id()) return self::error('state');
            $records[] = $record;
        }
        return $records;
    }
    public static function query($filters = array()) {
        if (!current_user_can('manage_woocommerce')) return self::error('permission');
        if (!is_array($filters)) return self::error('state');
        $state = $filters['state'] ?? 'open'; $page = $filters['page'] ?? 1; $limit = $filters['per_page'] ?? 20;
        if (!in_array($state,array_merge(array('open','all'),self::STATES),true) || !is_int($page) || $page < 1 || $page > 10000
            || !is_int($limit) || $limit < 1 || $limit > 100) return self::error('state');
        $meta = array('relation'=>'AND');
        if ($state === 'open') $meta[] = array('relation'=>'OR',array('key'=>self::STATE_META,'value'=>'closed','compare'=>'!='),array('key'=>self::STATE_META,'compare'=>'NOT EXISTS'));
        elseif ($state !== 'all') $meta[] = array('key'=>self::STATE_META,'value'=>$state);
        if (isset($filters['order_id'])) {
            if (!self::id($filters['order_id'])) return self::error('order');
            $meta[] = array('key'=>self::ORDER_META,'value'=>(string)$filters['order_id']);
        }
        $query = new WP_Query(array('post_type'=>self::POST_TYPE,'post_status'=>'private','fields'=>'ids','posts_per_page'=>$limit,
            'paged'=>$page,'orderby'=>'ID','order'=>'DESC','meta_query'=>$meta,'no_found_rows'=>false));
        $records = array();
        foreach ($query->posts as $id) { $record = self::get((int)$id); if (is_wp_error($record)) return $record; $records[] = $record; }
        return array('records'=>$records,'total'=>(int)$query->found_posts,'pages'=>(int)$query->max_num_pages,'page'=>$page);
    }
    public static function pending_count() {
        $result = self::query(array('state'=>'open','per_page'=>1));
        return is_wp_error($result) ? $result : $result['total'];
    }
    private static function staff_change($id, $change, $expected_revision = null) {
        if (!self::enabled()) return self::error('disabled');
        if (!current_user_can('manage_woocommerce') || !self::id(get_current_user_id())) return self::error('permission');
        if ($expected_revision !== null && (!is_int($expected_revision) || $expected_revision < 1)) return self::error('revision');
        $result = self::locked($id, static function($record,$order,$now) use ($change,$expected_revision) {
            if ($expected_revision !== null && $record['revision'] !== $expected_revision) return self::error('revision', 'This request changed. Refresh the case before saving your decision.');
            return $change($record,$order,$now);
        });
        if (!is_wp_error($result) && is_array($result) && empty($result['unchanged'])) do_action('petish_cancellation_request_event',$id);
        return $result;
    }
    public static function transition($id, $state, $data = array()) {
        if (!in_array($state,array('reviewing','awaiting_supplier'),true)) return self::error('state');
        $note = self::text($data['private_note'] ?? '',1000); $message = self::text($data['public_message'] ?? '',1000);
        if ($note === false || $message === false) return self::error('reason');
        return self::staff_change($id, static function($record,$order,$now) use ($state,$note,$message) {
            if (!in_array($record['state'],array('requested','reviewing','awaiting_supplier'),true)) return self::error('state');
            if ($record['state'] === $state) return array('record'=>$record,'result'=>array('unchanged'=>true) + $record);
            if (count($record['history']) >= 100) return self::error('limit');
            $record['state'] = $state; $record['revision']++; $record['updated_at'] = $now;
            $record['history'][] = array('at'=>$now,'actor_id'=>get_current_user_id(),'action'=>'progress','state'=>$state,'private_note'=>$note);
            $event = self::event($record,'progress',$message,$now);
            return is_wp_error($event) ? $event : array('record'=>$record,'result'=>$record);
        }, $data['expected_revision'] ?? null);
    }
    public static function record_decision($id, $accepted, $public_message, $private_reference, $supplier_checked, $expected_revision = null) {
        $message = self::text($public_message,1000); $reference = self::text($private_reference,1000,false);
        if (!is_array($accepted) || $message === false || $reference === false || $supplier_checked !== true) return self::error('decision', 'Check the supplier position and record private evidence before confirming a decision.');
        ksort($accepted,SORT_NUMERIC);
        return self::staff_change($id, static function($record,$order,$now) use ($accepted,$message,$reference) {
            if ($record['state'] === 'closed' || array_keys($accepted) !== array_keys($record['items']) || count($record['history']) >= 100) return self::error('state');
            $total = 0; $requested = 0;
            foreach ($accepted as $line=>$quantity) {
                if (!is_int($quantity) || $quantity < 0 || $quantity > $record['items'][$line]['quantity']) return self::error('items');
                $total += $quantity; $requested += $record['items'][$line]['quantity'];
            }
            $decision = array('accepted_items'=>$accepted,'public_message'=>$message,'supplier_checked'=>true,'private_reference'=>$reference);
            $state = $total === $requested ? 'approved' : ($total > 0 ? 'partially_approved' : 'declined');
            if ($record['decision'] === $decision && $record['state'] === $state) return array('record'=>$record,'result'=>array('unchanged'=>true) + $record);
            $record['state'] = $state; $record['decision'] = $decision; $record['revision']++; $record['updated_at'] = $now;
            $record['history'][] = array('at'=>$now,'actor_id'=>get_current_user_id(),'action'=>'decision','state'=>$state,'private_note'=>$reference);
            $event = self::event($record,'decision',$message,$now);
            return is_wp_error($event) ? $event : array('record'=>$record,'result'=>$record);
        }, $expected_revision);
    }
    public static function close($id, $data = array()) {
        $note = self::text($data['private_note'] ?? '',1000);
        if ($note === false || ($data['supplier_checked'] ?? null) !== true || ($data['refund_handled'] ?? null) !== true) return self::error('confirmation', 'Confirm the supplier and refund handling before closing this case.');
        return self::staff_change($id, static function($record,$order,$now) use ($note) {
            if ($record['state'] === 'closed') return array('record'=>$record,'result'=>array('unchanged'=>true) + $record);
            if (!in_array($record['state'],array('approved','partially_approved','declined'),true) || $record['decision'] === null || count($record['history']) >= 100) return self::error('state');
            $record['state'] = 'closed'; $record['revision']++; $record['updated_at'] = $now;
            $record['history'][] = array('at'=>$now,'actor_id'=>get_current_user_id(),'action'=>'closed','state'=>'closed','private_note'=>$note);
            $event = self::event($record,'closed',$record['decision']['public_message'],$now);
            return is_wp_error($event) ? $event : array('record'=>$record,'result'=>$record);
        }, $data['expected_revision'] ?? null);
    }
}
