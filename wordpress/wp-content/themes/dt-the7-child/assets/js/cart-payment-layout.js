/* Native Blocks own eligibility, mounting and payment events. Presentation only. */
(function () {
    'use strict';
    if (!document.body.classList.contains('petish-cart-payment-layout')) return;

    // PHP selects the Cart Google Pay display provider before native assets.
    // This script only groups existing native hosts; no readiness inference,
    // payment registration, SDK mutation or client-side duplicate hiding.
    var frame = 0;
    var stopped = false;

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

    function update() {
        frame = 0;
        if (stopped) return;
        var carts = document.querySelectorAll('.wc-block-cart');
        carts.forEach(function (cart) {
            var sidebar = cart.querySelector('.wc-block-cart__sidebar');
            if (!sidebar) return;
            var paypal = cart.querySelector('[id="express-payment-method-ppcp-gateway-paypal"]');
            var paylater = cart.querySelector('[id="express-payment-method-ppcp-gateway-paylater"]');
            var message = sidebar.querySelector(':scope > .ppcp-messages[data-block-name="woocommerce-paypal-payments/cart-paylater-messages"]');
            var hasPayPal = visible(paypal);
            var hasPayLater = visible(paylater);
            var hasMessage = !!message && Array.from(message.querySelectorAll('iframe')).some(visible);
            [['petish-cart-has-paypal', hasPayPal], ['petish-cart-has-paylater', hasPayLater], ['petish-cart-has-paypal-message', hasMessage]].forEach(function (entry) {
                if (sidebar.classList.contains(entry[0]) !== entry[1]) sidebar.classList.toggle(entry[0], entry[1]);
            });
        });
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
        if (unsubscribe) unsubscribe();
        if (frame) cancelAnimationFrame(frame);
    }, { once: true });
    schedule();
}());
