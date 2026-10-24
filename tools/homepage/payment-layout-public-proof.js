/* Evaluate on authenticated staging product/Cart pages. Read-only, finite output. */
function petishPaymentLayoutPublicProof() {
    'use strict';
    var bounded = function (value, limit) { return Number.isFinite(value) ? Math.max(0, Math.min(limit, Math.round(value))) : 0; };
    function facts(root, selector) {
        var nodes = root ? Array.from(root.querySelectorAll(selector)).slice(0, 8) : [];
        var shown = nodes.filter(function (node) {
            var rect = node.getBoundingClientRect(), style = getComputedStyle(node);
            return !node.hidden && style.display !== 'none' && style.visibility === 'visible' && rect.width > 0 && rect.height > 0;
        });
        var node = shown[0], rect = node && node.getBoundingClientRect(), style = node && getComputedStyle(node);
        return { count: nodes.length, visible_count: shown.length,
            width: bounded(rect && rect.width, 4096), height: bounded(rect && rect.height, 4096),
            top: bounded(rect && rect.top + scrollY, 8192), max_width: bounded(style && parseFloat(style.maxWidth), 4096),
            font_size: bounded(style && parseFloat(style.fontSize), 128),
            iframe_count: bounded(nodes.reduce(function (count, item) { return count + item.querySelectorAll('iframe').length; }, 0), 16) };
    }
    var theme = document.documentElement.dataset.theme;
    var summary = document.querySelector('.single-product .summary'), cart = document.querySelector('.wc-block-cart');
    var base = '/wp-content/themes/dt-the7-child/assets/';
    var paths = { product_css: 'css/payment-layout.css', product_js: 'js/payment-layout.js',
        cart_css: 'css/cart-payment-layout.css', cart_typography_css: 'css/cart-typography.css', cart_js: 'js/cart-payment-layout.js' };
    var assets = {};
    Object.keys(paths).forEach(function (key) {
        var matching = Array.from(document.querySelectorAll('link[rel="stylesheet"][href],script[src]')).filter(function (node) {
            try { var url = new URL(node.href || node.src, location.href); return url.origin === location.origin && url.pathname === base + paths[key]; }
            catch (error) { return false; }
        });
        assets[key] = matching.length === 1;
        assets[key + '_content_version'] = matching.length === 1 && /^[a-f0-9]{16}$/.test(new URL(matching[0].href || matching[0].src, location.href).searchParams.get('ver') || '');
    });
    var nativeWallet = facts(cart, '#express-payment-method-woocommerce_payments_express_checkout_googlePay');
    var paypalWallet = facts(cart, '#express-payment-method-ppcp-googlepay');
    return { page: summary ? 'product' : cart ? 'cart' : 'other',
        theme: theme === 'light' || theme === 'dark' ? theme : 'unspecified',
        viewport_width: bounded(innerWidth, 4096), horizontal_overflow: document.documentElement.scrollWidth > innerWidth,
        assets: assets,
        product: { scoped_body_class: document.body.classList.contains('petish-product-payment-groups'),
            native_woo_configuration_present: !!window.wcpayStripeSiteMessaging,
            native_paypal_v5_configuration_present: !!window.PayPalCommerceGateway,
            native_paypal_v6_configuration_present: !!window.wc_ppcp_sdk_v6,
            woo_group: facts(summary, '.petish-product-payments'), wallet: facts(summary, '#wcpay-express-checkout-element'),
            installment_message: facts(summary, '#payment-method-message'), paypal_group: facts(summary, '.petish-product-paypal'),
            paypal_buttons: facts(summary, '#ppc-button-ppcp-gateway, #ppc-button-ppcp-gateway-v6'),
            paypal_message: facts(summary, '.petish-product-paypal > .ppcp-messages'),
            categories: facts(summary, '.product_meta .posted_in a'), tags: facts(summary, '.product_meta .tagged_as a') },
        cart: { scoped_body_class: document.body.classList.contains('petish-cart-payment-layout'),
            block_present: !!cart, woo_google_pay: nativeWallet, paypal_google_pay: paypalWallet,
            visible_google_pay_count: bounded(nativeWallet.visible_count + paypalWallet.visible_count, 16),
            paypal: facts(cart, '#express-payment-method-ppcp-gateway-paypal'),
            pay_later: facts(cart, '#express-payment-method-ppcp-gateway-paylater'),
            installment_message: facts(cart, '.wc-block-components-bnpl-wrapper'),
            paypal_message: facts(cart, '.wc-block-cart__sidebar > .ppcp-messages[data-block-name="woocommerce-paypal-payments/cart-paylater-messages"]'),
            names: facts(cart, '.wc-block-components-product-name'),
            prices: facts(cart, '.wc-block-cart-item__prices'), variations: facts(cart, '.wc-block-components-product-metadata'),
            line_totals: facts(cart, '.wc-block-cart-item__total'), quantity: facts(cart, '.wc-block-components-quantity-selector__input') } };
}
