<?php
/** Actual cancellation mail transitions with private transport/storage doubles. */
define('ABSPATH', __DIR__ . '/fixture/');
$GLOBALS['options'] = array(); $GLOBALS['manager'] = true; $GLOBALS['writes'] = 0;
$GLOBALS['mail_calls'] = array(); $GLOBALS['mail_result'] = true; $GLOBALS['inside_lock'] = false;
class WP_Error { public function __construct(private $code, private $message='') {} public function get_error_code() { return $this->code; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function current_user_can($cap) { return $cap === 'manage_woocommerce' && $GLOBALS['manager']; }
function __($value,$domain='') { return $value; }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_html__($value,$domain='') { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function wc_get_page_permalink($page) { return 'https://shop.example.test/my-account/'; }
function wc_get_endpoint_url($endpoint,$value,$url) { return $url . $endpoint . '/' . $value . '/'; }
function admin_url($path) { return 'https://shop.example.test/wp-admin/' . $path; }
function add_action($name,$callback) { $GLOBALS['hooks'][$name] = $callback; }
function petish_email_branding_styles($css) { return $css . '/* actual shared style hook used */ body{color:#14213D}'; }
class Petish_Cancellation_Policy {
    public static function fee_text() { return 'Staff reviews any permitted processing-fee deduction before processing. Your rights under Australian Consumer Law are not affected.'; }
    public static function request_text() { return 'We review unfulfilled items and request supplier cancellation where possible. Cancellation is not guaranteed.'; }
    public static function cancellation_faq_html() { return '<section id="canonical-cancellation-faq"><h2>Change-of-mind FAQ</h2><p>Contact us within 14 days after delivery. Keep items unused and complete in their original packaging. Contact Petish first for authorised return instructions. Customer pays voluntary change-of-mind return postage.</p></section>'; }
}
class WC_Order {
    public $owner = 31; public $email = 'owner@example.test';
    public function get_id() { return 100; }
    public function get_customer_id($context='view') { return $this->owner; }
    public function get_billing_email($context='view') { return $this->email; }
}
$GLOBALS['order'] = new WC_Order();
function WC() { return new class { public function mailer() { return new class {
    public function wrap_message($heading,$body) {
        if ($GLOBALS['renderer_fail'] ?? false) throw new RuntimeException('PRIVATE_RENDER_DIAGNOSTIC');
        if ($GLOBALS['disable_during_render'] ?? false) $GLOBALS['options']['petish_cancellation_emails_enabled'] = 'no';
        return '<!doctype html><html><head><title>Petish</title></head><body><header>Petish animal logo</header><h1>' . esc_html($heading) . '</h1>' . $body . '<footer>Petish support</footer></body></html>';
    }
}; } }; }
class Petish_Order_Cancellation_Requests {
    public static function enabled() { return get_option('petish_cancellation_requests_enabled','no') === 'yes'; }
    public static function get($id) {
        $GLOBALS['reads'] = ($GLOBALS['reads'] ?? 0) + 1;
        return $id === 9 ? $GLOBALS['record'] : new WP_Error('missing_request');
    }
    public static function locked($id,callable $callback,$now=null) {
        if ($id !== 9) return new WP_Error('missing_request');
        if ($GLOBALS['inside_lock']) return new WP_Error('reentrant_lock');
        $GLOBALS['inside_lock'] = true;
        try {
            $before = $GLOBALS['record'];
            $result = $callback($before,$GLOBALS['order'],$now ?? time());
            if (is_wp_error($result)) return $result;
            if ($result['record'] !== $before) {
                if ($GLOBALS['fail_before_save'] ?? false) return new WP_Error('save_failed');
                $GLOBALS['record'] = $result['record']; $GLOBALS['writes']++;
                if ($GLOBALS['fail_after_save'] ?? false) return new WP_Error('save_failed');
            }
            return $result['result'];
        } finally { $GLOBALS['inside_lock'] = false; }
    }
}
require __DIR__ . '/../wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-order-cancellation-emails.php';
$checks = array();
function check($name,$value) { global $checks; $checks[$name] = $value === true; if (!$value) throw new RuntimeException('Failed: ' . $name); }
function code($value,$suffix) { return is_wp_error($value) && $value->get_error_code() === 'petish_cancellation_email_' . $suffix; }
function slot() { return array('status'=>'pending','attempts'=>0,'attempt_id'=>null,'claimed_at'=>null,'finished_at'=>null,'history'=>array()); }
function fixture() {
    $payload = array('request_id'=>9,'order_id'=>100,'order_number'=>'P-100','customer_id'=>31,'currency'=>'AUD',
        'items'=>array(11=>array('name'=>'Willow <script>bad()</script> bridge','quantity'=>2),12=>array('name'=>'Foraging mat','quantity'=>1)),
        'reason'=>'Please <b>cancel</b> these items.','state'=>'requested','public_message'=>'','accepted_items'=>array());
    $GLOBALS['record'] = array('id'=>9,'order_id'=>100,'customer_id'=>31,'private_reference'=>'PRIVATE_SUPPLIER_REFERENCE',
        'private_note'=>'PRIVATE_STAFF_NOTE','events'=>array(str_repeat('a',64)=>array('id'=>str_repeat('a',64),'kind'=>'received','created_at'=>1700000000,
            'payload'=>$payload,'payload_hash'=>hash('sha256',json_encode($payload)),'deliveries'=>array('customer'=>slot(),'staff'=>slot()))));
    $GLOBALS['order']->owner = 31; $GLOBALS['order']->email = 'owner@example.test';
    $GLOBALS['mail_calls'] = array(); $GLOBALS['mail_result'] = true; $GLOBALS['writes'] = 0; $GLOBALS['manager'] = true;
    unset($GLOBALS['mail_throw'],$GLOBALS['renderer_fail'],$GLOBALS['during_mail'],$GLOBALS['fail_before_save'],$GLOBALS['fail_after_save'],$GLOBALS['disable_during_render']);
}
function wp_mail($recipient,$subject,$body,$headers=array()) {
    check('transport_outside_order_lock', $GLOBALS['inside_lock'] === false);
    $audience = $recipient === 'orders@petish.biz' ? 'staff' : 'customer';
    $event_id = $GLOBALS['current_event'] ?? str_repeat('a',64);
    check('durable_claim_before_transport', $GLOBALS['record']['events'][$event_id]['deliveries'][$audience]['status'] === 'sending');
    $GLOBALS['mail_calls'][] = array('to'=>$recipient,'subject'=>$subject,'body'=>$body,'headers'=>$headers);
    if (isset($GLOBALS['during_mail'])) { $callback=$GLOBALS['during_mail']; unset($GLOBALS['during_mail']); $callback(); }
    if ($GLOBALS['mail_throw'] ?? false) throw new RuntimeException('PRIVATE_SMTP_DIAGNOSTIC');
    return $GLOBALS['mail_result'];
}
fixture(); $event = str_repeat('a',64); $now = 1700000100;
check('include_installs_nothing', empty($GLOBALS['hooks']) && $GLOBALS['writes'] === 0 && !$GLOBALS['mail_calls']);
check('default_off', !Petish_Order_Cancellation_Emails::enabled() && code(Petish_Order_Cancellation_Emails::dispatch_pending(9),'disabled'));
$GLOBALS['options']['petish_cancellation_emails_enabled'] = true;
check('literal_yes_only', !Petish_Order_Cancellation_Emails::enabled());
$GLOBALS['options']['petish_cancellation_emails_enabled'] = 'yes';
check('request_flag_also_required', !Petish_Order_Cancellation_Emails::enabled());
Petish_Order_Cancellation_Emails::init();
check('only_core_event_hook', array_keys($GLOBALS['hooks']) === array('petish_cancellation_request_event'));
$GLOBALS['reads'] = 0; $GLOBALS['manager'] = false;
check('guest_preview_denied_before_lookup', code(Petish_Order_Cancellation_Emails::preview(9,$event),'permission') && $GLOBALS['reads'] === 0);
check('guest_summary_denied', code(Petish_Order_Cancellation_Emails::delivery_summary(9),'permission'));
$GLOBALS['manager'] = true;
check('malformed_target_denied', code(Petish_Order_Cancellation_Emails::preview(9,"$event\n"),'input') && code(Petish_Order_Cancellation_Emails::preview(9,$event,'other'),'input'));
$before = $GLOBALS['record']; $preview = Petish_Order_Cancellation_Emails::preview(9,$event);
check('staff_preview_works_with_flags_off', !is_wp_error($preview) && str_contains($preview['html'],'Petish animal logo'));
check('preview_is_read_only', $GLOBALS['record'] === $before && !$GLOBALS['mail_calls'] && $GLOBALS['writes'] === 0);
check('shared_css_inserted', str_contains($preview['html'],'actual shared style hook used'));
check('acknowledges_request_not_cancellation', str_contains($preview['html'],'We have recorded your cancellation request') && str_contains($preview['html'],'has not yet been confirmed cancelled'));
check('customer_subject_identifies_request', str_contains($preview['subject'],'cancellation request received') && str_contains($preview['subject'],'order #P-100'));
check('canonical_policy_delegated', Petish_Order_Cancellation_Emails::policy_text() === Petish_Cancellation_Policy::fee_text() && Petish_Order_Cancellation_Emails::request_text() === Petish_Cancellation_Policy::request_text());
check('canonical_faq_at_customer_bottom', str_contains($preview['html'],Petish_Cancellation_Policy::cancellation_faq_html()) && strpos($preview['html'],'canonical-cancellation-faq') > strpos($preview['html'],'View your order and request') && strpos($preview['html'],'canonical-cancellation-faq') < strpos($preview['html'],'<footer>'));
check('item_escaping', str_contains($preview['html'],'Willow &lt;script&gt;bad()&lt;/script&gt; bridge') && !str_contains($preview['html'],'<script>bad()'));
check('customer_privacy', !str_contains($preview['html'],'PRIVATE_') && !str_contains($preview['html'],'owner@example.test') && !str_contains($preview['html'],'Please &lt;b&gt;'));
check('no_flat_or_automatic_deduction', !str_contains($preview['html'],'$10') && !str_contains($preview['html'],'non-refundable'));
$staff = Petish_Order_Cancellation_Emails::preview(9,$event,'staff');
check('staff_actionable_hold', str_contains($staff['html'],'Hold supplier processing') && str_contains($staff['html'],'has not automatically stopped DSers') && str_contains($staff['html'],'petish-cancellation-requests'));
check('staff_received_subject_requests_action', str_starts_with($staff['subject'],'Action required: Petish cancellation request'));
check('staff_link_selects_actual_admin_case', str_contains($staff['html'],'page=petish-cancellation-requests&amp;case_id=9') && !str_contains($staff['html'],'&amp;request_id='));
check('staff_reason_escaped', str_contains($staff['html'],'Please &lt;b&gt;cancel&lt;/b&gt;') && !str_contains($staff['html'],'Please <b>cancel'));
check('staff_no_private_fields_or_customer_faq', !str_contains($staff['html'],'PRIVATE_') && !str_contains($staff['html'],'canonical-cancellation-faq'));
$GLOBALS['options']['petish_cancellation_requests_enabled'] = 'yes';
$outcomes = Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('customer_and_staff_submitted', count($outcomes) === 2 && array_column($outcomes,'status') === array('submitted','submitted'));
check('fixed_staff_and_native_customer_recipient', array_column($GLOBALS['mail_calls'],'to') === array('owner@example.test','orders@petish.biz'));
check('html_transport_headers_local', $GLOBALS['mail_calls'][0]['headers'] === array('Content-Type: text/html; charset=UTF-8'));
$sent = count($GLOBALS['mail_calls']); Petish_Order_Cancellation_Emails::dispatch_pending(9,$now+1);
check('submitted_never_replayed', count($GLOBALS['mail_calls']) === $sent);
$summary = Petish_Order_Cancellation_Emails::delivery_summary(9);
check('delivery_summary_no_body_or_recipient', $summary[$event]['deliveries']['customer']['status'] === 'submitted' && !str_contains(json_encode($summary),'owner@example.test') && !str_contains(json_encode($summary),'PRIVATE_'));

fixture(); $GLOBALS['mail_result'] = false;
Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('generic_false_uncertain', $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'uncertain' && $GLOBALS['record']['events'][$event]['deliveries']['staff']['status'] === 'uncertain');
$sent = count($GLOBALS['mail_calls']); Petish_Order_Cancellation_Emails::dispatch_pending(9,$now+1);
check('uncertain_not_automatically_retried', count($GLOBALS['mail_calls']) === $sent && code(Petish_Order_Cancellation_Emails::queue_retry(9,$event,'customer'),'status'));
check('resolution_requires_evidence_confirmation', code(Petish_Order_Cancellation_Emails::resolve_uncertain(9,$event,'customer','confirmed_not_sent'),'input'));
$GLOBALS['manager'] = false;
check('customer_cannot_resolve_or_retry', code(Petish_Order_Cancellation_Emails::resolve_uncertain(9,$event,'customer','confirmed_not_sent',true),'permission') && code(Petish_Order_Cancellation_Emails::queue_retry(9,$event,'customer'),'permission'));
$GLOBALS['manager'] = true;
check('confirmed_unsent_resolves_to_safe_failure', Petish_Order_Cancellation_Emails::resolve_uncertain(9,$event,'customer','confirmed_not_sent',true,$now+2)['status'] === 'not_sent');
check('queue_retry_does_not_send', Petish_Order_Cancellation_Emails::queue_retry(9,$event,'customer',$now+3)['status'] === 'pending' && count($GLOBALS['mail_calls']) === $sent);
$GLOBALS['mail_result'] = true; Petish_Order_Cancellation_Emails::dispatch_pending(9,$now+4);
check('explicit_retry_submits_once', count($GLOBALS['mail_calls']) === $sent+1 && $GLOBALS['record']['events'][$event]['deliveries']['customer']['attempts'] === 2);
check('confirmed_submitted_never_requeued', Petish_Order_Cancellation_Emails::resolve_uncertain(9,$event,'staff','confirmed_submitted',true,$now+5)['status'] === 'submitted' && code(Petish_Order_Cancellation_Emails::queue_retry(9,$event,'staff'),'status'));

fixture(); $GLOBALS['mail_throw'] = true; Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('exceptions_uncertain_without_raw_diagnostics', $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'uncertain' && !str_contains(json_encode($GLOBALS['record']),'PRIVATE_SMTP_DIAGNOSTIC'));
fixture(); $GLOBALS['order']->email = "valid@example.test\r\nBcc: attacker@example.test";
Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('recipient_injection_blocked_before_transport', $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'not_sent' && array_column($GLOBALS['mail_calls'],'to') === array('orders@petish.biz'));
fixture(); $GLOBALS['order']->owner = 99; Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('changed_order_owner_blocks_customer_delivery', $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'not_sent' && array_column($GLOBALS['mail_calls'],'to') === array('orders@petish.biz'));
fixture(); $GLOBALS['renderer_fail'] = true; Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('renderer_failure_definitively_unsent', !$GLOBALS['mail_calls'] && $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'not_sent');
fixture(); $GLOBALS['disable_during_render'] = true; Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('sender_switch_rechecked_after_rendering', !$GLOBALS['mail_calls'] && $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'not_sent');
$GLOBALS['options']['petish_cancellation_emails_enabled'] = 'yes';
fixture(); $GLOBALS['fail_before_save'] = true; Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('failed_claim_never_calls_transport', !$GLOBALS['mail_calls'] && $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'pending');
fixture(); $GLOBALS['fail_after_save'] = true; Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('interrupted_persisted_claim_not_sent_twice', !$GLOBALS['mail_calls'] && $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'sending');
unset($GLOBALS['fail_after_save']); $GLOBALS['options']['petish_cancellation_emails_enabled'] = 'no';
Petish_Order_Cancellation_Emails::reconcile(9,$now+301);
check('abandoned_claim_reconciles_with_sender_disabled', !$GLOBALS['mail_calls'] && $GLOBALS['record']['events'][$event]['deliveries']['customer']['status'] === 'uncertain');
$GLOBALS['options']['petish_cancellation_emails_enabled'] = 'yes';
fixture(); $GLOBALS['during_mail'] = static function() use($now) { Petish_Order_Cancellation_Emails::dispatch_pending(9,$now+1); };
Petish_Order_Cancellation_Emails::dispatch_pending(9,$now);
check('concurrent_dispatch_claims_each_slot_once', count($GLOBALS['mail_calls']) === 2 && array_column($GLOBALS['mail_calls'],'to') === array('owner@example.test','orders@petish.biz'));

fixture(); $received = $GLOBALS['record']['events'][$event];
foreach (array('reviewing','awaiting_supplier','approved','partially_approved','declined','closed') as $index=>$state) {
    $next = $received; $next['id'] = hash('sha256',$state); $next['kind'] = in_array($state,array('approved','partially_approved','declined'),true) ? 'decision' : ($state === 'closed' ? 'closed' : 'progress');
    $next['payload']['state'] = $state; $next['payload']['public_message'] = 'Update <em>only</em>'; $next['payload']['accepted_items'] = array(11=>1,12=>0);
    $GLOBALS['record']['events'][$next['id']] = $next;
    $result = Petish_Order_Cancellation_Emails::preview(9,$next['id']);
    check('customer_update_' . $state, !is_wp_error($result) && str_contains($result['html'],'canonical-cancellation-faq') && str_contains($result['html'],'Update &lt;em&gt;only&lt;/em&gt;') && !str_contains($result['html'],'PRIVATE_'));
    if ($state === 'partially_approved') check('approval_is_not_payment_confirmation', str_contains($result['html'],'does not itself return a payment') && str_contains($result['html'],'separate refund confirmation'));
    if ($state === 'closed') {
        $closed_staff = Petish_Order_Cancellation_Emails::preview(9,$next['id'],'staff');
        check('closed_case_does_not_restart_review_or_hold', !str_contains($result['html'],Petish_Cancellation_Policy::request_text()) && str_contains($closed_staff['html'],'case has been closed') && !str_contains($closed_staff['html'],'Hold supplier processing'));
    }
}
$historical = Petish_Order_Cancellation_Emails::preview(9,$event);
check('historical_event_keeps_received_snapshot', str_contains($historical['html'],'not yet been confirmed cancelled') && !str_contains($historical['html'],'Update &lt;em&gt;'));
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT) . "\n";
