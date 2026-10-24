/* Native financing typography and a separate, unchanged provider brand heading. */
(function () {
    'use strict';

    function cartPage() {
        return window.petishPaymentMessagePage === 'cart' ||
            !!(document.body && document.body.classList.contains('woocommerce-cart'));
    }

    function restoreNativeAppearance() {
        // Remove our former font-size customization once. WooPayments then
        // extracts its original main text and disclosure styles before mount.
        var scope = cartPage() ? 'cart' : 'product';
        var marker = 'petish-native-bnpl-appearance-' + scope;
        try {
            if (localStorage.getItem(marker) !== 'restored-v1') {
                var locations = scope === 'cart' ? ['bnpl_cart_block', 'bnpl_classic_cart'] : ['bnpl_product_page'];
                locations.forEach(function (location) {
                    localStorage.removeItem('wcpay_appearance_' + location);
                });
                localStorage.setItem(marker, 'restored-v1');
            }
        } catch (error) { /* Native rendering also works when storage is unavailable. */ }
    }

    function afterpayOnly() {
        var native = window.wcpayStripeSiteMessaging;
        // wp_localize_script serializes top-level PHP true as the string '1'.
        var initialized = native && (native.shouldInitializePMME === true || native.shouldInitializePMME === '1');
        // Native visibility remains the amount/variation/quantity eligibility
        // authority. Do not label a mixed Klarna/Affirm message as Afterpay.
        return !!(native && initialized &&
            native.country === 'AU' && native.currencyCode === 'AUD' &&
            Array.isArray(native.paymentMethods) && native.paymentMethods.length === 1 &&
            native.paymentMethods[0] === 'afterpay_clearpay');
    }

    function updateBadgeHeadings() {
        var eligible = afterpayOnly();
        var selector = '.petish-product-payments #payment-method-message, ' +
            'body.woocommerce-cart .cart_totals #payment-method-message, ' +
            'body.woocommerce-cart .wc-block-cart .wc-block-components-bnpl-wrapper';
        document.querySelectorAll(selector).forEach(function (message) {
            var frame = message.querySelector('iframe');
            var frameRect = frame && frame.getBoundingClientRect();
            var style = getComputedStyle(message);
            var asset = style.getPropertyValue('--petish-afterpay-badge-image').trim();
            var ready = eligible && asset && frame && frameRect.width > 0 && frameRect.height > 8 &&
                !message.querySelector('.pmme-loading') && style.display !== 'none' && style.visibility !== 'hidden';
            message.classList.toggle('petish-afterpay-brand-heading', !!ready);
        });
    }

    function observeNativeMessages() {
        updateBadgeHeadings();
        var scheduled = false;
        var observer = new MutationObserver(function () {
            if (scheduled) return;
            scheduled = true;
            requestAnimationFrame(function () {
                scheduled = false;
                updateBadgeHeadings();
            });
        });
        // Native Cart React and product variation updates may replace a message
        // host. Observe additions and native visibility/iframe-height updates;
        // never move, scale, clone or change payment/disclosure iframe content.
        var root = document.querySelector('.product .summary, .wp-block-woocommerce-cart, .wc-block-cart, .cart-collaterals') || document.body;
        observer.observe(root, {childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'hidden']});
    }

    // The page-only hint is emitted before this HEAD asset, ahead of the
    // product initializer and Cart React's cached-appearance initial state.
    if (window.petishPaymentMessagePage === 'product' || window.petishPaymentMessagePage === 'cart') {
        restoreNativeAppearance();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            restoreNativeAppearance();
            observeNativeMessages();
        }, {once: true});
    } else {
        restoreNativeAppearance();
        observeNativeMessages();
    }
})();
