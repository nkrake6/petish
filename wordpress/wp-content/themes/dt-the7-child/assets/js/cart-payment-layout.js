/* Native Blocks own eligibility, mounting and payment events. Presentation only. */
(function () {
    'use strict';
    if (!document.body.classList.contains('petish-cart-payment-layout')) return;

    // PHP selects the Cart Google Pay display provider before native assets.
    // This script only groups existing native hosts; no readiness inference,
    // payment registration, SDK mutation or client-side duplicate hiding.
    var frame = 0;
    var stopped = false;
    var resizeTargets = [];
    var resizeObserver = window.ResizeObserver ? new ResizeObserver(schedule) : null;

    function visible(element) {
        if (!element || !element.isConnected) return false;
        var bounds = element.getBoundingClientRect();
        if (bounds.width < 1 || bounds.height < 1) return false;
        for (var node = element; node && node.nodeType === 1; node = node.parentElement) {
            var style = getComputedStyle(node);
            if (node.hidden || node.getAttribute('aria-hidden') === 'true'
                || style.display === 'none' || style.visibility !== 'visible'
                || Number(style.opacity) === 0) return false;
        }
        return true;
    }

    function emptyNativeExpressNotice(node) {
        if (node.matches('.wc-block-components-notices')) return node.childNodes.length === 0;
        if (!node.matches('.wc-block-components-notices__snackbar.wc-block-components-notice-snackbar-list')) return false;
        return node.childNodes.length === 0 || (node.childNodes.length === 1
            && node.firstElementChild && node.firstElementChild.tagName === 'DIV'
            && node.firstElementChild.childNodes.length === 0);
    }

    function nativeBnplFlow(sidebar) {
        var summary = sidebar.querySelector(':scope > .wp-block-woocommerce-cart-order-summary-block');
        var slot = summary && summary.querySelector(':scope > .wc-block-components-totals-wrapper.slot-wrapper');
        var meta = slot && slot.querySelector(':scope > .wc-block-components-order-meta');
        var bnpl = meta && meta.querySelector(':scope > .wc-block-components-bnpl-wrapper');
        var payments = sidebar.querySelector(':scope > .wc-block-cart__payment-options');
        var express = payments && payments.querySelector(':scope > .wc-block-components-express-payment--cart');
        var content = express && express.querySelector(':scope > .wc-block-components-express-payment__content');
        var methods = content && content.querySelector(':scope > .wc-block-components-express-payment__event-buttons');
        var wallet = methods && methods.querySelector(':scope > #express-payment-method-woocommerce_payments_express_checkout_googlePay, :scope > #express-payment-method-ppcp-googlepay');

        // Keep the entire native OrderMeta slot intact. A mixed fill, an extra
        // wrapper or a payment notice retains its normal native layout.
        return summary && slot && meta && bnpl && methods && wallet
            && slot.children.length === 1 && meta.children.length === 1
            && express.children.length === 1
            && Array.from(content.children).every(function (node) { return node === methods || emptyNativeExpressNotice(node); })
            && visible(bnpl) && visible(wallet) ? {slot: slot, bnpl: bnpl} : null;
    }

    function update() {
        frame = 0;
        if (stopped) return;
        var carts = document.querySelectorAll('.wc-block-cart');
        var nextResizeTargets = [];
        carts.forEach(function (cart) {
            var sidebar = cart.querySelector('.wc-block-cart__sidebar');
            if (!sidebar) return;
            var paypal = cart.querySelector('[id="express-payment-method-ppcp-gateway-paypal"]');
            var paylater = cart.querySelector('[id="express-payment-method-ppcp-gateway-paylater"]');
            var message = sidebar.querySelector(':scope > .ppcp-messages[data-block-name="woocommerce-paypal-payments/cart-paylater-messages"]');
            var hasPayPal = visible(paypal);
            var hasPayLater = visible(paylater);
            // SDK v5 exposes an iframe; v6 exposes a native custom-element
            // host whose internal iframe can be inside a closed shadow root.
            var messageHosts = message ? Array.from(message.querySelectorAll('iframe, paypal-message')).slice(0, 4) : [];
            var hasMessage = messageHosts.some(visible);
            var bnplFlow = nativeBnplFlow(sidebar);
            var flowClass = 'petish-cart-native-bnpl-order';
            if (sidebar.classList.contains(flowClass) !== !!bnplFlow) sidebar.classList.toggle(flowClass, !!bnplFlow);
            nextResizeTargets.push(sidebar);
            if (bnplFlow) nextResizeTargets.push(bnplFlow.slot, bnplFlow.bnpl);
            if (message) nextResizeTargets.push(message);
            messageHosts.forEach(function (host) { nextResizeTargets.push(host); });
            [['petish-cart-has-paypal', hasPayPal], ['petish-cart-has-paylater', hasPayLater], ['petish-cart-has-paypal-message', hasMessage]].forEach(function (entry) {
                if (sidebar.classList.contains(entry[0]) !== entry[1]) sidebar.classList.toggle(entry[0], entry[1]);
            });
        });
        if (resizeObserver && (resizeTargets.length !== nextResizeTargets.length
            || nextResizeTargets.some(function (node, index) { return node !== resizeTargets[index]; }))) {
            resizeObserver.disconnect();
            resizeTargets = nextResizeTargets;
            resizeTargets.forEach(function (node) { resizeObserver.observe(node); });
        }
    }

    function schedule() {
        if (!frame && !stopped) frame = requestAnimationFrame(update);
    }

    var observer = new MutationObserver(function (records) {
        if (records.some(function (record) {
            return record.type === 'childList' || (record.target.closest && record.target.closest('.wc-block-cart'));
        })) schedule();
    });
    observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style', 'hidden', 'aria-hidden', 'src'] });
    var unsubscribe = window.wp && wp.data && typeof wp.data.subscribe === 'function'
        ? wp.data.subscribe(schedule) : null;
    window.addEventListener('resize', schedule, { passive: true });
    document.addEventListener('focusout', schedule);
    document.addEventListener('visibilitychange', schedule);
    document.addEventListener('load', schedule, true);
    window.addEventListener('pagehide', function (event) {
        if (event.persisted) return;
        stopped = true;
        observer.disconnect();
        if (resizeObserver) resizeObserver.disconnect();
        if (unsubscribe) unsubscribe();
        if (frame) cancelAnimationFrame(frame);
    }, { once: true });
    schedule();
}());
