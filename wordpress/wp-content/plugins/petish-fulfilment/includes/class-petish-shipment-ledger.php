<?php
/** Private, opt-in shipment records. Inclusion installs no hooks or receiver. */
defined('ABSPATH') || exit;

final class Petish_Shipment_Ledger {
    const OPTION = 'petish_shipment_ledger_enabled';
    const META = '_petish_shipment_ledger_v1';
    const MAX_PARCELS = 100;
    const MAX_EVENTS = 500;
    const STATUSES = array('pending','sending','submitted','failed','uncertain','superseded');
    private static $held = array();

    public static function error($code) {
        return new WP_Error('petish_shipment_'.$code, 'The shipment operation could not be completed.');
    }
    public static function enabled() { return get_option(self::OPTION, 'no') === 'yes'; }
    private static function id($id) {
        return is_int($id) && $id > 0 && $id <= PHP_INT_MAX;
    }
    private static function identifier($value) {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/D', $value) === 1;
    }
    public static function hash($value) { return hash('sha256', wp_json_encode($value)); }
    private static function now($now) { return is_int($now) && $now > 0 ? $now : time(); }
    private static function order($id) {
        if (!self::id($id) || !function_exists('wc_get_order')) return self::error('order');
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order || (int)$order->get_id() !== $id) return self::error('order');
        $order->read_meta_data(true);
        return $order;
    }
    private static function empty_state($now) {
        return array('schema_version'=>1,'revision'=>0,'required_items'=>array(),'parcels'=>array(),'outbox'=>array(),'created_at'=>$now,'updated_at'=>$now);
    }
    private static function exact_keys($value, $keys) {
        return is_array($value) && count($value) === count($keys) && !array_diff(array_keys($value), $keys);
    }
    private static function url($value) {
        if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) return false;
        $decoded = $value;
        for ($i=0;$i<4;$i++) { $decoded=rawurldecode($decoded); if (preg_match('/[\x00-\x1f\x7f]/',$decoded)) return false; }
        $parts = wp_parse_url($value);
        return is_array($parts) && ($parts['scheme']??'') === 'https' && !isset($parts['user'],$parts['pass'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] === 443) && is_string($parts['host']??null)
            && !filter_var($parts['host'], FILTER_VALIDATE_IP)
            && preg_match('/\A(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}\z/D',$parts['host']) === 1
            && !preg_match('/(?:\A|\.)(?:localhost|local|internal|invalid)\z/iD',$parts['host']);
    }
    /** Accepted only from a trusted adapter; no request data is read here. */
    public static function normalize($shipment, $now=null) {
        $keys=array('supplier_id','shipment_id','revision','carrier','tracking_number','tracking_url','dispatched_utc','items');
        if (!self::exact_keys($shipment,$keys) || !self::identifier($shipment['supplier_id']) || !self::identifier($shipment['shipment_id'])
            || !is_int($shipment['revision']) || $shipment['revision']<1 || $shipment['revision']>1000000
            || !is_string($shipment['carrier']) || trim($shipment['carrier'])!==$shipment['carrier'] || $shipment['carrier']===''
            || strlen($shipment['carrier'])>100 || preg_match('/[\x00-\x1f\x7f<>]/',$shipment['carrier'])
            || !is_string($shipment['tracking_number']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D',$shipment['tracking_number'])!==1
            || !self::url($shipment['tracking_url']) || !is_array($shipment['items']) || !$shipment['items'] || count($shipment['items'])>100)
            return self::error('input');
        $utc=$shipment['dispatched_utc'];
        if (!is_string($utc) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$utc)!==1) return self::error('input');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$utc,new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z')!==$utc || $date->getTimestamp()>self::now($now)) return self::error('input');
        foreach ($shipment['items'] as $id=>$quantity) if (!is_int($id) || $id<1 || !is_int($quantity) || $quantity<1 || $quantity>10000) return self::error('input');
        ksort($shipment['items'],SORT_NUMERIC);$canonical=array();
        foreach($keys as$key)$canonical[$key]=$shipment[$key];
        return $canonical;
    }
    private static function required($order) {
        $required=array();
        foreach ($order->get_items('line_item') as $item) {
            $product=$item->get_product();
            if (!$product instanceof WC_Product) return self::error('order');
            if (!$product->needs_shipping()) continue;
            $id=(int)$item->get_id();$qty=$item->get_quantity();
            if ($id<1 || isset($required[$id]) || !is_numeric($qty) || (float)$qty!==(float)(int)$qty || $qty<1 || $qty>10000) return self::error('order');
            $required[$id]=(int)$qty;
        }
        if (!$required || count($required)>100) return self::error('order');
        ksort($required,SORT_NUMERIC);return $required;
    }
    /** Pin the first-record physical requirements; catalogue edits must not imply dispatch. */
    public static function coverage($order,$parcels,$required_items=null) {
        $required=self::required($order);if(is_wp_error($required))return $required;
        if($required_items!==null&&$required_items!==$required)return self::error('order_changed');
        return self::allocation($required,$parcels);
    }
    private static function allocation($required,$parcels) {
        $allocated=array_fill_keys(array_keys($required),0);
        foreach($parcels as$parcel)foreach($parcel['shipment']['items'] as$id=>$qty){
            if(!isset($required[$id]) || $qty>$required[$id]-$allocated[$id])return self::error('overallocated');
            $allocated[$id]+=$qty;
        }
        return $allocated===$required?'dispatched':'partial';
    }
    private static function state($order,$now) {
        $copies=0;
        foreach($order->get_meta_data()as$meta){$data=$meta->get_data();if(($data['key']??null)===self::META)$copies++;}
        if($copies>1)return self::error('state');
        $raw=$order->get_meta(self::META,true);
        return self::validate_state($raw,$order,$now);
    }
    private static function validate_state($raw,$order,$now) {
        if($raw==='' || $raw===null)return self::empty_state($now);
        if(!self::exact_keys($raw,array('schema_version','revision','required_items','parcels','outbox','created_at','updated_at')) || $raw['schema_version']!==1
            || !is_int($raw['revision']) || $raw['revision']<0 || !is_int($raw['created_at']) || !is_int($raw['updated_at'])
            || $raw['created_at']<1 || $raw['updated_at']<$raw['created_at'] || !is_array($raw['required_items']) || count($raw['required_items'])>100 || !is_array($raw['parcels']) || !is_array($raw['outbox'])
            || count($raw['parcels'])>self::MAX_PARCELS || count($raw['outbox'])>self::MAX_EVENTS || strlen(wp_json_encode($raw))>2097152) return self::error('state');
        foreach($raw['required_items']as$id=>$quantity)if(!is_int($id)||$id<1||!is_int($quantity)||$quantity<1||$quantity>10000)return self::error('state');
        $required_items=$raw['required_items'];ksort($required_items,SORT_NUMERIC);
        if($required_items!==$raw['required_items']||(!$raw['parcels'])!==(!$required_items))return self::error('state');
        foreach($raw['parcels'] as$key=>$parcel){
            if(!is_string($key) || !self::exact_keys($parcel,array('shipment','payload_hash','event_id')) || !is_array($parcel['shipment']))return self::error('state');
            $shipment=self::normalize($parcel['shipment'],max($now,$raw['updated_at']));
            if(is_wp_error($shipment) || $shipment!==$parcel['shipment'] || $key!==self::hash(array($shipment['supplier_id'],$shipment['shipment_id'])))return self::error('state');
            $payload=$shipment;unset($payload['revision']);
            if($parcel['payload_hash']!==self::hash($payload) || !is_string($parcel['event_id']) || !isset($raw['outbox'][$parcel['event_id']]))return self::error('state');
        }
        foreach($raw['outbox'] as$id=>$event){
            $keys=array('id','parcel_key','type','payload','payload_hash','status','attempts','sender_id','attempt_id','claimed_at','lease_until','submitted_at','outcome_code','history','created_at','updated_at');
            if(!is_string($id)||preg_match('/\A[a-f0-9]{64}\z/D',$id)!==1 || !self::exact_keys($event,$keys) || $event['id']!==$id
                || !isset($raw['parcels'][$event['parcel_key']]) || !in_array($event['type'],array('partial_dispatch','full_dispatch','tracking_correction'),true)
                || !in_array($event['status'],self::STATUSES,true) || !is_int($event['attempts']) || $event['attempts']<0 || $event['attempts']>20
                || !is_array($event['payload']) || self::hash($event['payload'])!==$event['payload_hash']
                || !is_array($event['history']) || !array_is_list($event['history']) || count($event['history'])>50
                || !is_int($event['created_at']) || !is_int($event['updated_at']) || $event['created_at']<1 || $event['updated_at']<$event['created_at'])return self::error('state');
            if(!self::exact_keys($event['payload'],array('order_id','shipment','dispatch_state','type','parcels')) || $event['payload']['order_id']!==(int)$order->get_id()
                || $event['payload']['type']!==$event['type'] || !in_array($event['payload']['dispatch_state'],array('partial','dispatched'),true))return self::error('state');
            $event_shipment=self::normalize($event['payload']['shipment'],max($now,$raw['updated_at']));
            if(is_wp_error($event_shipment)||$event_shipment!==$event['payload']['shipment']||$event['parcel_key']!==self::hash(array($event_shipment['supplier_id'],$event_shipment['shipment_id'])))return self::error('state');
            $content=$event_shipment;unset($content['revision']);
            if($id!==self::hash(array((int)$order->get_id(),$event['parcel_key'],$event_shipment['revision'],self::hash($content))))return self::error('state');
            if(!is_array($event['payload']['parcels'])||!$event['payload']['parcels']||count($event['payload']['parcels'])>self::MAX_PARCELS
                || ($event['payload']['parcels'][$event['parcel_key']]??null)!==$event_shipment)return self::error('state');
            foreach($event['payload']['parcels']as$snapshot_key=>$snapshot){
                $valid=self::normalize($snapshot,max($now,$raw['updated_at']));
                if(is_wp_error($valid)||$valid!==$snapshot||$snapshot_key!==self::hash(array($snapshot['supplier_id'],$snapshot['shipment_id'])))return self::error('state');
            }
            $snapshots=array();foreach($event['payload']['parcels']as$snapshot_key=>$snapshot)$snapshots[$snapshot_key]=array('shipment'=>$snapshot);
            if(self::allocation($required_items,$snapshots)!==$event['payload']['dispatch_state'])return self::error('state');
            foreach(array('claimed_at','lease_until','submitted_at')as$field)if($event[$field]!==null&&(!is_int($event[$field])||$event[$field]<1))return self::error('state');
            if($event['sender_id']!==null&&!self::identifier($event['sender_id']))return self::error('state');
            if($event['attempt_id']!==null&&(!is_string($event['attempt_id'])||preg_match('/\A[a-f0-9]{64}\z/D',$event['attempt_id'])!==1))return self::error('state');
            if(!in_array($event['outcome_code'],array('queued','claimed','accepted','not_sent','ambiguous','lease_expired','superseded','retry_requested','confirmed_submitted','confirmed_not_sent'),true))return self::error('state');
            if(in_array($event['status'],array('sending','submitted','failed','uncertain'),true)&&(!$event['attempt_id']||!$event['sender_id']||!$event['claimed_at']||!$event['lease_until']||$event['attempts']<1))return self::error('state');
            if($event['claimed_at']!==null&&($event['lease_until']===null||$event['lease_until']<=$event['claimed_at']))return self::error('state');
            if($event['status']==='submitted'&&($event['submitted_at']===null||$event['submitted_at']<$event['claimed_at']))return self::error('state');
            foreach($event['history']as$history){
                if(!self::exact_keys($history,array('attempt_id','sender_id','status','outcome_code','at')) || !is_string($history['attempt_id']) || preg_match('/\A[a-f0-9]{64}\z/D',$history['attempt_id'])!==1
                    || !self::identifier($history['sender_id']) || !in_array($history['status'],self::STATUSES,true) || !in_array($history['outcome_code'],array('claimed','accepted','not_sent','ambiguous','lease_expired','retry_requested','confirmed_submitted','confirmed_not_sent'),true)
                    || !is_int($history['at']) || $history['at']<$event['created_at'])return self::error('state');
            }
        }
        foreach($raw['parcels']as$key=>$parcel){
            $event=$raw['outbox'][$parcel['event_id']];$content=$event['payload']['shipment'];unset($content['revision']);
            if($event['parcel_key']!==$key||self::hash($content)!==$parcel['payload_hash'])return self::error('state');
        }
        if($raw['parcels']&&is_wp_error(self::allocation($required_items,$raw['parcels'])))return self::error('state');
        return $raw;
    }
    private static function save($order,$state,$now) {
        if($state['revision']>=PHP_INT_MAX)return self::error('state');
        $state['revision']++;$state['updated_at']=max($now,$state['updated_at']);
        try{
            $order->update_meta_data(self::META,$state);$order->save_meta_data();$order->read_meta_data(true);
            if($order->get_meta(self::META,true)!==$state)return self::error('save');
        }catch(Throwable$ignored){return self::error('save');}
        return $state;
    }
    /** Shared lock keeps a single metadata blob and the parcel/outbox change atomic. */
    public static function locked($order_id,callable$operation,$now=null,$require_enabled=true) {
        if($require_enabled&&!self::enabled())return self::error('disabled');
        if(!self::id($order_id))return self::error('order');$now=self::now($now);
        global$wpdb;
        if(!is_object($wpdb)||!defined('DB_NAME'))return self::error('lock');
        $name='petish-shipment-'.substr(hash('sha256',DB_NAME.'|'.$order_id),0,45);
        if(isset(self::$held[$name]))return self::error('lock');
        $acquired=false;
        try{
            if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$name))!=='1')return self::error('lock');$acquired=true;self::$held[$name]=true;
            $order=self::order($order_id);if(is_wp_error($order))return$order;
            $state=self::state($order,$now);if(is_wp_error($state))return$state;
            $now=max($now,$state['updated_at']);
            $result=$operation($state,$order,$now);if(is_wp_error($result))return$result;
            if(!is_array($result)||!array_key_exists('state',$result)||!array_key_exists('result',$result))return self::error('state');
            if($result['state']!==$state){
                $valid=self::validate_state($result['state'],$order,$now);if(is_wp_error($valid))return$valid;
                $saved=self::save($order,$valid,$now);if(is_wp_error($saved))return$saved;
            }
            return$result['result'];
        }catch(Throwable$ignored){return self::error('state');}
        finally{if($acquired){
            $released=false;try{$released=(string)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name))==='1';}catch(Throwable$ignored){}
            unset(self::$held[$name]);if(!$released)return self::error('lock');
        }}
    }
    /** Private staff/internal read. No public endpoint or side effect. */
    public static function read($order_id) {
        return self::locked($order_id,static fn($state,$order,$now)=>array('state'=>$state,'result'=>$state),null,false);
    }
    public static function record($order_id,array$shipment,$now=null) {
        $normalized=self::normalize($shipment,$now);if(is_wp_error($normalized))return$normalized;
        return self::locked($order_id,static function($state,$order,$now)use($normalized){
            if(!$state['parcels']){$required=self::required($order);if(is_wp_error($required))return$required;$state['required_items']=$required;}
            $key=self::hash(array($normalized['supplier_id'],$normalized['shipment_id']));$existing=$state['parcels'][$key]??null;
            $payload=$normalized;unset($payload['revision']);$hash=self::hash($payload);
            if($existing){
                if($normalized['revision']<$existing['shipment']['revision'])return self::error('stale_revision');
                if($normalized['revision']===$existing['shipment']['revision']){
                    if($hash!==$existing['payload_hash'])return self::error('revision_conflict');
                    $coverage=self::coverage($order,$state['parcels'],$state['required_items']);if(is_wp_error($coverage))return$coverage;
                    return array('state'=>$state,'result'=>array('changed'=>false,'parcel_key'=>$key,'event_id'=>$existing['event_id'],'dispatch_state'=>$coverage));
                }
            }elseif(count($state['parcels'])>=self::MAX_PARCELS)return self::error('limit');
            $event_id=$existing&&$hash===$existing['payload_hash']?$existing['event_id']:self::hash(array($order->get_id(),$key,$normalized['revision'],$hash));
            $state['parcels'][$key]=array('shipment'=>$normalized,'payload_hash'=>$hash,'event_id'=>$event_id);
            $coverage=self::coverage($order,$state['parcels'],$state['required_items']);if(is_wp_error($coverage))return$coverage;
            if(!$existing || $hash!==$existing['payload_hash']){
                if(count($state['outbox'])>=self::MAX_EVENTS)return self::error('limit');
                foreach($state['outbox']as&$old)if(($old['parcel_key']===$key||$old['payload']['dispatch_state']==='dispatched')&&in_array($old['status'],array('pending','failed'),true)){$old['status']='superseded';$old['outcome_code']='superseded';$old['updated_at']=$now;}unset($old);
                $type=$existing?'tracking_correction':($coverage==='dispatched'?'full_dispatch':'partial_dispatch');
                $snapshot=array();foreach($state['parcels']as$parcel_key=>$parcel)$snapshot[$parcel_key]=$parcel['shipment'];ksort($snapshot,SORT_STRING);
                $event_payload=array('order_id'=>(int)$order->get_id(),'shipment'=>$normalized,'dispatch_state'=>$coverage,'type'=>$type,'parcels'=>$snapshot);
                $state['outbox'][$event_id]=array('id'=>$event_id,'parcel_key'=>$key,'type'=>$type,'payload'=>$event_payload,'payload_hash'=>self::hash($event_payload),
                    'status'=>'pending','attempts'=>0,'sender_id'=>null,'attempt_id'=>null,'claimed_at'=>null,'lease_until'=>null,'submitted_at'=>null,'outcome_code'=>'queued','history'=>array(),'created_at'=>$now,'updated_at'=>$now);
            }
            return array('state'=>$state,'result'=>array('changed'=>true,'parcel_key'=>$key,'event_id'=>$event_id,'dispatch_state'=>$coverage));
        },$now);
    }
}
