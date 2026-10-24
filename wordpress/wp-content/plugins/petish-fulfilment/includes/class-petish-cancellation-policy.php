<?php
/** Shared customer policy for the FAQ page, request form and transactional emails. */
defined('ABSPATH') || exit;

final class Petish_Cancellation_Policy {
    const RETURN_DAYS = 14;
    const SHORTCODE = 'petish_faqs';
    const STYLE_HANDLE = 'petish-cancellation-faq';

    public static function init() {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_style'));
    }

    public static function request_text() {
        return __('We will make every reasonable effort to review your request promptly and cancel unfulfilled items where possible. If your order has already been processed or sent to a supplier, we will request cancellation from the supplier. Cancellation is not guaranteed until we confirm the outcome.', 'petish-fulfilment');
    }

    public static function fee_text() {
        return __('For voluntary change-of-mind cancellations and returns, applicable payment-processing costs that cannot be recovered from the payment provider may be deducted where permitted by law. We will explain any proposed deduction and confirm the refund amount before processing it. The amount, if any, depends on the payment method and costs actually retained by the provider.', 'petish-fulfilment');
    }

    public static function returns_text() {
        return __('If items have already been dispatched, a return may be needed instead of cancellation. Contact us within 14 days after delivery to request a change-of-mind return. Items must be unused, complete and in their original packaging. You are responsible for change-of-mind return postage. Contact Petish first for authorisation and the correct return address; do not use an address on the parcel or send items directly to a supplier unless we instruct you to do so.', 'petish-fulfilment');
    }

    public static function refund_text() {
        return __('Once cancellation is confirmed, or an authorised return has been received and assessed, we will process the approved refund to the original payment method, less any disclosed deductions permitted by law. We will confirm how delivery charges are treated as part of the refund assessment. Your payment provider determines how long the refund takes to appear in your account.', 'petish-fulfilment');
    }

    public static function rights_text() {
        return __('This is our policy for voluntary change-of-mind requests. It does not exclude your rights under Australian Consumer Law or other applicable consumer laws. Faulty, damaged or incorrect items are assessed separately. This policy’s processing-fee and return-postage conditions do not apply where they would limit a statutory entitlement.', 'petish-fulfilment');
    }

    public static function change_of_mind_paragraphs() {
        return array(self::request_text(), self::returns_text(), self::fee_text(), self::refund_text(), self::rights_text());
    }

    /** Structured plain text; escape at the web/email boundary. */
    public static function faqs() {
        return array(
            'request-cancellation' => array(
                'question' => __('How do I request cancellation of an order?', 'petish-fulfilment'),
                'paragraphs' => array(
                    __('Sign in to the account used for your purchase, open My account → Orders, select the order and choose Request cancellation. Select the items and quantities you want us to review, then submit your request. If the option is unavailable, or you checked out as a guest, email orders@petish.biz with your order number.', 'petish-fulfilment'),
                    __('The acknowledgement confirms that your request has been received. It does not confirm that an order has been cancelled, a supplier has stopped processing it or a refund has been issued.', 'petish-fulfilment'),
                ),
            ),
            'change-of-mind' => array(
                'question' => __('What is your change-of-mind cancellation and return policy?', 'petish-fulfilment'),
                'paragraphs' => self::change_of_mind_paragraphs(),
            ),
            'partial-cancellation' => array(
                'question' => __('Can I cancel only part of an order?', 'petish-fulfilment'),
                'paragraphs' => array(
                    __('Yes, you can request review of selected items and quantities. We will check each part of the order with the relevant supplier. Some items may be cancellable while others have already been processed or dispatched. We will explain the outcome and any proposed refund before processing it.', 'petish-fulfilment'),
                ),
            ),
            'after-request' => array(
                'question' => __('What happens after I submit a cancellation request?', 'petish-fulfilment'),
                'paragraphs' => array(
                    __('We record the request and send an acknowledgement to the email address on your order. Our team reviews fulfilment progress, contacts the supplier where needed and updates you on the decision. You can also check the request on your order page. A request does not itself stop an order or return a payment.', 'petish-fulfilment'),
                ),
            ),
            'processing-fees' => array(
                'question' => __('Will a payment-processing fee be deducted?', 'petish-fulfilment'),
                'paragraphs' => array(self::fee_text(), __('Any proposed deduction is reviewed for your particular order and disclosed before the refund. Mandatory consumer-law remedies are handled separately from voluntary change-of-mind requests.', 'petish-fulfilment')),
            ),
            'return-instructions' => array(
                'question' => __('Where should I send a return?', 'petish-fulfilment'),
                'paragraphs' => array(
                    __('Contact orders@petish.biz first, quoting your order number and the items concerned. We will confirm return authorisation, instructions and the correct address. Keep your postage receipt and tracking details. For a voluntary change-of-mind return, you pay the return postage.', 'petish-fulfilment'),
                ),
            ),
            'refund-timing' => array(
                'question' => __('When will I receive my refund?', 'petish-fulfilment'),
                'paragraphs' => array(self::refund_text(), __('A cancellation-request acknowledgement is separate from a refund confirmation. Contact us if you need help understanding an update or a payment-provider confirmation.', 'petish-fulfilment')),
            ),
            'faulty-items' => array(
                'question' => __('What if an item is faulty, damaged or incorrect?', 'petish-fulfilment'),
                'paragraphs' => array(
                    __('Contact orders@petish.biz with your order number and a description of the problem. Photos can help us understand the issue. We will assess the appropriate remedy and return arrangements under the applicable consumer law. The 14-day change-of-mind window and unused/original-packaging conditions do not restrict statutory claims.', 'petish-fulfilment'),
                    self::rights_text(),
                ),
            ),
            'delivery-estimates' => array(
                'question' => __('How long will delivery take?', 'petish-fulfilment'),
                'paragraphs' => array(
                    __('Delivery estimates depend on the product, supplier, destination and available delivery service. Use the product and checkout information for your order, and contact us if you need clarification. Estimates are approximate; we do not apply one fixed delivery range to every product or destination.', 'petish-fulfilment'),
                ),
            ),
            'separate-parcels' => array(
                'question' => __('Will all my items arrive together?', 'petish-fulfilment'),
                'paragraphs' => array(
                    __('Items from different suppliers, or different parts of an order, may travel separately. Follow each parcel’s tracking details where available. A dispatch record or tracking number does not by itself confirm delivery. Contact us if you need help with an outstanding item.', 'petish-fulfilment'),
                ),
            ),
            'contact' => array(
                'question' => __('How can I contact Petish about my order?', 'petish-fulfilment'),
                'paragraphs' => array(__('Email orders@petish.biz and include your order number so we can locate the right order and help you.', 'petish-fulfilment')),
            ),
        );
    }

    public static function enqueue_style() {
        global $post;
        if (!is_singular('page') || !$post instanceof WP_Post || !has_shortcode($post->post_content, self::SHORTCODE)) return;
        wp_enqueue_style(self::STYLE_HANDLE, plugin_dir_url(dirname(__FILE__)) . 'assets/cancellation-faq.css', array(), '0.8.0');
    }

    /** Native shortcode page. No queries, state changes or email calls. */
    public static function render($attributes = array(), $content = null, $tag = '') {
        $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        $html = '<section class="petish-faqs" aria-labelledby="petish-order-faqs"><h2 id="petish-order-faqs">' . esc_html__('Cancellations, returns and order FAQs', 'petish-fulfilment') . '</h2>';
        $html .= '<p class="petish-faqs__intro">' . esc_html__('Help with orders, cancellations, returns and delivery.', 'petish-fulfilment') . '</p>';
        foreach (self::faqs() as $id => $faq) {
            $html .= '<details class="petish-faqs__item" id="' . esc_attr($id) . '"' . ($id === 'change-of-mind' ? ' open' : '') . '><summary>' . esc_html($faq['question']) . '</summary><div class="petish-faqs__answer">';
            foreach ($faq['paragraphs'] as $paragraph) $html .= '<p>' . esc_html($paragraph) . '</p>';
            if ($id === 'request-cancellation') $html .= '<p><a href="' . esc_url($account_url) . '">' . esc_html__('Open My account', 'petish-fulfilment') . '</a></p>';
            if ($id === 'delivery-estimates') $html .= '<p><a href="' . esc_url(home_url('/delivery-shipping/')) . '">' . esc_html__('Delivery & shipping information', 'petish-fulfilment') . '</a></p>';
            if (in_array($id, array('contact', 'return-instructions', 'faulty-items'), true)) $html .= '<p><a href="mailto:orders@petish.biz">orders@petish.biz</a></p>';
            $html .= '</div></details>';
        }
        return $html . '</section>';
    }

    /** Full change-of-mind reference appended after each customer email's case details. */
    public static function cancellation_faq_html() {
        $html = '<section class="petish-cancellation-email-faq" style="margin:28px 0 0;padding-top:22px;border-top:1px solid #E6DFF0;">';
        $html .= '<h2 style="margin:0 0 12px;color:#14213D;font:700 18px/1.4 Arial,Helvetica,sans-serif;">' . esc_html__('Change-of-mind cancellation FAQ', 'petish-fulfilment') . '</h2>';
        $html .= '<p style="margin:0 0 12px;color:#14213D;font-size:14px;font-weight:700;">' . esc_html__('What happens if I change my mind?', 'petish-fulfilment') . '</p>';
        foreach (self::change_of_mind_paragraphs() as $paragraph) $html .= '<p style="margin:0 0 12px;color:#526077;font-size:13px;line-height:1.65;">' . esc_html($paragraph) . '</p>';
        $html .= '<p style="margin:0;font-size:13px;"><a href="' . esc_url(home_url('/delivery-shipping/#change-of-mind')) . '" style="color:#6532A1;text-decoration:underline;">' . esc_html__('Delivery, cancellations and returns FAQ', 'petish-fulfilment') . '</a></p>';
        return $html . '</section>';
    }
}
