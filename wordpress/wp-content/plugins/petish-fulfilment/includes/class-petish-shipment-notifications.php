<?php
/** Durable notification transitions. No sender, scheduler or hooks are installed here. */
defined('ABSPATH') || exit;

final class Petish_Shipment_Notifications {
    const OPTION = 'petish_shipment_notifications_enabled';
    const LEASE_SECONDS = 300;
    const MAX_ATTEMPTS = 20;
    private static $sender_id = null;
    private static $sender = null;

    public static function enabled() {
        return Petish_Shipment_Ledger::enabled() && get_option(self::OPTION,'no') === 'yes';
    }
    /** A trusted approved integration must explicitly select one adapter per request. */
    public static function configure_sender($sender_id,callable$callback) {
        if(!is_string($sender_id)||preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/D',$sender_id)!==1)return Petish_Shipment_Ledger::error('sender');
        if(self::$sender!==null)return self::$sender_id===$sender_id&&self::$sender===$callback?true:Petish_Shipment_Ledger::error('sender_conflict');
        self::$sender_id=$sender_id;self::$sender=$callback;return true;
    }
    public static function sender_ready() { return self::$sender!==null; }
    private static function target($event_id) {
        return is_string($event_id)&&preg_match('/\A[a-f0-9]{64}\z/D',$event_id)===1;
    }
    private static function error($code) { return Petish_Shipment_Ledger::error($code); }
    private static function history(&$event,$status,$code,$now) {
        if(count($event['history'])>=50)return false;
        $event['history'][]=array('attempt_id'=>$event['attempt_id'],'sender_id'=>$event['sender_id'],'status'=>$status,'outcome_code'=>$code,'at'=>$now);
        return true;
    }
    private static function expire(&$state,$now) {
        foreach($state['outbox']as&$event)if($event['status']==='sending'&&$event['lease_until']<=$now){
            $event['status']='uncertain';$event['outcome_code']='lease_expired';$event['updated_at']=$now;
            if(!self::history($event,'uncertain','lease_expired',$now))return self::error('attempt_limit');
        }unset($event);return true;
    }
    /** Only explicit reconciliation writes stale claims; reads stay side-effect free. */
    public static function reconcile($order_id,$now=null) {
        return Petish_Shipment_Ledger::locked($order_id,static function($state,$order,$now){
            $expired=self::expire($state,$now);if(is_wp_error($expired))return$expired;
            return array('state'=>$state,'result'=>$state);
        },$now);
    }
    private static function current($state,$event) {
        $parcel=$state['parcels'][$event['parcel_key']]??null;
        if(!$parcel)return false;$payload=$event['payload']['shipment'];unset($payload['revision']);
        if(Petish_Shipment_Ledger::hash($payload)!==$parcel['payload_hash'])return false;
        if($event['payload']['dispatch_state']==='dispatched'){
            if(count($event['payload']['parcels'])!==count($state['parcels']))return false;
            foreach($event['payload']['parcels']as$key=>$snapshot){unset($snapshot['revision']);if(!isset($state['parcels'][$key])||Petish_Shipment_Ledger::hash($snapshot)!==$state['parcels'][$key]['payload_hash'])return false;}
        }
        return true;
    }
    /** Persist the claim before calling transport outside the database lock. */
    public static function send($order_id,$event_id,$now=null) {
        if(!self::enabled())return self::error('disabled');
        if(self::$sender===null)return self::error('sender_unconfigured');
        if(!self::target($event_id))return self::error('input');
        $sender_id=self::$sender_id;$transport=self::$sender;
        $claim=Petish_Shipment_Ledger::locked($order_id,static function($state,$order,$now)use($event_id,$sender_id){
            if(!isset($state['outbox'][$event_id]))return self::error('event');
            $expired=self::expire($state,$now);if(is_wp_error($expired))return$expired;
            $event=&$state['outbox'][$event_id];
            if($event['status']!=='pending')return array('state'=>$state,'result'=>self::error('status'));
            if(!self::current($state,$event)){
                $event['status']='superseded';$event['outcome_code']='superseded';$event['updated_at']=$now;
                return array('state'=>$state,'result'=>self::error('status'));
            }
            $coverage=Petish_Shipment_Ledger::coverage($order,$state['parcels'],$state['required_items']);if(is_wp_error($coverage))return$coverage;
            if($event['payload']['dispatch_state']==='dispatched'&&$coverage!=='dispatched')return self::error('order_changed');
            if($event['attempts']>=self::MAX_ATTEMPTS||count($event['history'])>=48)return self::error('attempt_limit');
            $event['status']='sending';$event['sender_id']=$sender_id;$event['attempt_id']=bin2hex(random_bytes(32));
            $event['claimed_at']=$now;$event['lease_until']=$now+self::LEASE_SECONDS;$event['attempts']++;
            $event['outcome_code']='claimed';$event['updated_at']=$now;
            self::history($event,'sending','claimed',$now);
            return array('state'=>$state,'result'=>array('order'=>$order,'event'=>$event));
        },$now);
        if(is_wp_error($claim))return$claim;
        try{$outcome=$transport($claim['order'],$claim['event']);}catch(Throwable$ignored){$outcome=array('status'=>'uncertain');}
        // A generic false/timeout cannot prove that SMTP did not accept a message.
        $status=is_array($outcome)&&array_keys($outcome)===array('status')?$outcome['status']:null;
        if(!in_array($status,array('submitted','not_sent','uncertain'),true))$status='uncertain';
        return Petish_Shipment_Ledger::locked($order_id,static function($state,$order,$finished)use($event_id,$claim,$status,$sender_id){
            if(!isset($state['outbox'][$event_id]))return self::error('changed');$event=&$state['outbox'][$event_id];
            if(!in_array($event['status'],array('sending','uncertain'),true)||$event['attempt_id']!==$claim['event']['attempt_id']||$event['sender_id']!==$sender_id)return self::error('changed');
            $finished=max($finished,$event['claimed_at']);
            $event['status']=$status==='submitted'?'submitted':($status==='not_sent'?'failed':'uncertain');
            $event['outcome_code']=$status==='submitted'?'accepted':($status==='not_sent'?'not_sent':'ambiguous');
            $event['updated_at']=$finished;if($status==='submitted')$event['submitted_at']=$finished;
            if(!self::history($event,$event['status'],$event['outcome_code'],$finished))return self::error('attempt_limit');
            return array('state'=>$state,'result'=>$event);
        },$now);
    }
    /** Reset only a definitively unsent event; this does not itself send mail. */
    public static function retry($order_id,$event_id,$now=null) {
        if(!current_user_can('manage_woocommerce'))return self::error('permission');
        if(!self::enabled())return self::error('disabled');
        if(self::$sender===null)return self::error('sender_unconfigured');
        if(!self::target($event_id))return self::error('input');
        return Petish_Shipment_Ledger::locked($order_id,static function($state,$order,$now)use($event_id){
            if(!isset($state['outbox'][$event_id]))return self::error('event');$event=&$state['outbox'][$event_id];
            if($event['status']!=='failed'||!self::current($state,$event))return self::error('status');
            if($event['attempts']>=self::MAX_ATTEMPTS||count($event['history'])>=47)return self::error('attempt_limit');
            $event['status']='pending';$event['outcome_code']='retry_requested';$event['updated_at']=$now;
            self::history($event,'pending','retry_requested',$now);
            return array('state'=>$state,'result'=>$event);
        },$now);
    }
    /** Staff must establish the previous attempt's actual outcome before resolving it. */
    public static function resolve_uncertain($order_id,$event_id,$resolution,$now=null) {
        if(!current_user_can('manage_woocommerce'))return self::error('permission');
        if(!Petish_Shipment_Ledger::enabled())return self::error('disabled');
        if(!self::target($event_id)||!in_array($resolution,array('confirmed_submitted','confirmed_not_sent'),true))return self::error('input');
        return Petish_Shipment_Ledger::locked($order_id,static function($state,$order,$now)use($event_id,$resolution){
            if(!isset($state['outbox'][$event_id]))return self::error('event');$event=&$state['outbox'][$event_id];
            if($event['status']!=='uncertain')return self::error('status');
            if(count($event['history'])>=49)return self::error('attempt_limit');
            $event['status']=$resolution==='confirmed_submitted'?'submitted':'failed';$event['outcome_code']=$resolution;$event['updated_at']=$now;
            if($resolution==='confirmed_submitted')$event['submitted_at']=$now;
            self::history($event,$event['status'],$resolution,$now);
            return array('state'=>$state,'result'=>$event);
        },$now);
    }
    /**
     * Staff-only, read-only customer presentation; never invokes mail or changes state.
     *
     * Shared admin-preview API: render_preview(int $order_id, string $event_id)
     * returns array('subject' => string, 'html' => string), or a WP_Error.
     * It does not need either feature flag or a configured sender. The caller must
     * display this document in an isolated preview, not insert it into admin markup.
     * Parcel data and quantities come only from this event's immutable snapshot.
     */
    public static function render_preview($order_id,$event_id) {
        if(!current_user_can('manage_woocommerce'))return self::error('permission');
        if(!self::target($event_id))return self::error('input');$state=Petish_Shipment_Ledger::read($order_id);
        if(is_wp_error($state))return$state;if(!isset($state['outbox'][$event_id]))return self::error('event');
        try{
            $order=wc_get_order($order_id);
            if(!$order instanceof WC_Order||(int)$order->get_id()!==$order_id)return self::error('order');
            $event=$state['outbox'][$event_id];$correction=$event['type']==='tracking_correction';$full=$event['payload']['dispatch_state']==='dispatched';
            $subject=$correction?'Your Petish tracking details have changed':($full?'Your Petish order has been dispatched':'Part of your Petish order is on its way');
            $reference=(string)$order->get_order_number();
            // The order reference is the only order-specific field we display. No
            // addresses, email, order keys, supplier IDs or internal line IDs appear.
            if($reference===''||strlen($reference)>100||preg_match('/[\x00-\x1f\x7f]/',$reference))return self::error('renderer');
            $body='<p style="margin:0 0 18px; color:#526077; font-size:13px;">'.esc_html__('Order','petish-fulfilment').' <strong style="color:#14213D;">#'.esc_html($reference).'</strong></p>';
            $intro=$correction?'We have updated the tracking details for a parcel in your order. Use the tracking links in this update for the revised details.':($full?'Good news — all physical items in your order have been dispatched. Your parcels may arrive separately.':'Part of your order is on its way. We will send another update when more items are dispatched.');
            $body.='<p style="margin:0 0 24px; color:#14213D; font-size:15px; line-height:1.65;">'.esc_html__($intro,'petish-fulfilment').'</p>';
            $parcels=$full?$event['payload']['parcels']:array($event['parcel_key']=>$event['payload']['shipment']);$number=0;
            foreach($parcels as$key=>$parcel){
                $number++;$quantity=array_sum($parcel['items']);
                $label=count($parcels)===1?'Your parcel':'Parcel '.$number;
                $quantity_label=$quantity===1?'1 item at dispatch':$quantity.' items at dispatch';
                $dispatched=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$parcel['dispatched_utc'],new DateTimeZone('UTC'));
                $body.='<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%; margin:0 0 20px; border:1px solid #E6DFF0; border-radius:10px; border-collapse:separate; background-color:#FFFFFF;"><tr><td style="padding:20px;">'
                    .'<h2 style="margin:0 0 8px; color:#14213D; font-size:18px; line-height:1.35;">'.esc_html($label).'</h2>';
                if($correction&&$key===$event['parcel_key'])$body.='<p style="margin:0 0 12px; color:#176074; font-size:12px; font-weight:700;">'.esc_html__('Updated tracking','petish-fulfilment').'</p>';
                $body.='<p style="margin:0 0 12px; color:#526077; font-size:13px;">'.esc_html($quantity_label).' &middot; '.esc_html__('Dispatched','petish-fulfilment').' '.esc_html($dispatched->format('j M Y')).' (UTC)</p>'
                    .'<p style="margin:0 0 4px; color:#14213D; font-size:15px; font-weight:700;">'.esc_html($parcel['carrier']).'</p>'
                    .'<p style="margin:0 0 18px; color:#526077; font-size:14px; overflow-wrap:anywhere; word-break:break-all;">'.esc_html__('Tracking number:','petish-fulfilment').' '.esc_html($parcel['tracking_number']).'</p>'
                    .'<p style="margin:0;"><a class="button" href="'.esc_url($parcel['tracking_url']).'" style="display:inline-block; padding:12px 18px; border:1px solid #6532A1; border-radius:8px; background-color:#6532A1; color:#FFFFFF; font-size:14px; font-weight:700; line-height:1.4; text-decoration:none;">'.esc_html__('Track this parcel','petish-fulfilment').'</a></p>'
                    .'</td></tr></table>';
            }
            if($full&&$correction)$body.='<p style="margin:0 0 16px; color:#14213D;">'.esc_html__('All physical items in your order had been dispatched at the time of this update.','petish-fulfilment').'</p>';
            $body.='<p style="margin:0; color:#526077; font-size:13px; line-height:1.65;">'.esc_html__('Tracking can take time to show the carrier\'s first scan. Dispatch information does not confirm delivery. Please follow each parcel separately for carrier updates.','petish-fulfilment').'</p>';
            $mailer=WC()->mailer();if(!$mailer||!is_callable(array($mailer,'wrap_message')))return self::error('renderer');$html=$mailer->wrap_message($subject,$body);
            // Match the existing Petish account-email pipeline without constructing
            // an email class, registering hooks, or invoking its delivery methods.
            if(function_exists('petish_email_branding_styles')){
                $css=petish_email_branding_styles('');if(!is_string($css))return self::error('renderer');
                $style='<style type="text/css">'.$css.'</style>';
                $html=stripos($html,'</head>')!==false?str_ireplace('</head>',$style.'</head>',$html):$style.$html;
            }
        }
        catch(Throwable$ignored){return self::error('renderer');}
        if(!is_string($html)||$html==='')return self::error('renderer');
        return array('subject'=>$subject,'html'=>$html);
    }
}
