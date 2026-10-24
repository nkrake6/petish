/* Product/Cart messaging appearance; provider payment flows stay native. */
(function () {
    'use strict';

    function cartPage() {
        return window.petishPaymentMessagePage === 'cart' ||
            !!(document.body && document.body.classList.contains('woocommerce-cart'));
    }

    function messageSize() {
        // Read the native PayPal message configuration. Never calculate amounts
        // or replace the wording/link/logo that either SDK chooses to display.
        var configuredV5 = window.PayPalCommerceGateway &&
            window.PayPalCommerceGateway.messages &&
            window.PayPalCommerceGateway.messages.style &&
            window.PayPalCommerceGateway.messages.style.text &&
            window.PayPalCommerceGateway.messages.style.text.size;
        var configuredV6 = window.wc_ppcp_sdk_v6 &&
            window.wc_ppcp_sdk_v6.messages &&
            window.wc_ppcp_sdk_v6.messages.style &&
            window.wc_ppcp_sdk_v6.messages.style.fontSize;
        // The Cart Pay Later block can override the location's native style.
        // Match that actual block setting when present, without changing it.
        var cartMessage = cartPage() && document.querySelector('.ppcp-messages[data-block-name="woocommerce-paypal-payments/cart-paylater-messages"][data-pp-style-text-size]');
        var blockSize = cartMessage && cartMessage.getAttribute('data-pp-style-text-size');
        var size = parseInt(blockSize || configuredV6 || configuredV5, 10);
        if (!Number.isFinite(size) || size < 10 || size > 20) size = 16;
        if (cartPage() && configuredV6) size = Math.min(size, 16);
        return size + 'px';
    }

    function refreshAppearanceCache() {
        if (cartPage()) {
            // Block Cart reads this cache in React's initial state. Invalidate
            // before its mount on each Cart HTML load, including an existing
            // shopper cache. Native refreshes within that page can reuse it.
            try {
                localStorage.removeItem('wcpay_appearance_bnpl_cart_block');
                localStorage.removeItem('wcpay_appearance_bnpl_classic_cart');
            } catch (error) { /* Native rendering also handles unavailable storage. */ }
            return;
        }
        // WooPayments only emits its appearance hook on a cache miss. Expire
        // this one product-message cache after a size/theme/layout change.
        var signature = 'v1|' + messageSize() + '|' + (document.documentElement.dataset.theme || 'light');
        try {
            if (localStorage.getItem('petish-product-message-appearance') !== signature) {
                localStorage.removeItem('wcpay_appearance_bnpl_product_page');
                localStorage.setItem('petish-product-message-appearance', signature);
            }
        } catch (error) { /* Storage can be unavailable; the native hook still works. */ }
    }

    document.addEventListener('wcpay_elements_appearance', function (event) {
        var detail = event.detail;
        if (!detail || !detail.appearance) return;
        var location = detail.elementsLocation;
        if (location !== 'bnpl_product_page' &&
            !(cartPage() && ['bnpl_cart_block', 'bnpl_classic_cart'].indexOf(location) !== -1)) return;
        var appearance = detail.appearance;
        appearance.variables = appearance.variables || {};
        appearance.variables.fontSizeBase = messageSize();
        appearance.variables.fontWeightNormal = '500';
        // These are WooPayments' own getAppearance text rules, not new SDK
        // selectors. Update them only when the native extractor supplied them.
        ['.Text', '.Text--redirect'].forEach(function (selector) {
            if (appearance.rules && appearance.rules[selector]) {
                appearance.rules[selector] = Object.assign({}, appearance.rules[selector], {fontSize: messageSize(), fontWeight: '500'});
            }
        });
    });

    if (window.petishPaymentMessagePage === 'cart') {
        // The PHP page hint exists before this HEAD script and provider code.
        refreshAppearanceCache();
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshAppearanceCache, {once: true});
    } else {
        refreshAppearanceCache();
    }
})();
