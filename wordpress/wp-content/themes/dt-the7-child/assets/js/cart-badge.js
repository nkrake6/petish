/* Counts come from this visitor's live Woo session, never from cached page HTML. */
(function () {
    'use strict';
    const config = window.petishCartBadge;
    if (!config || !window.fetch || !window.jQuery) return;
    let endpoint, cart;
    try {
        endpoint = new URL(config.endpoint, location.href);
        cart = new URL(config.cartUrl, location.href);
        if (endpoint.origin !== location.origin || cart.origin !== location.origin) return;
    } catch (e) { return; }

    const links = '.wc-shopping-cart > a.wc-ico-cart, .main-nav a[href], .mobile-main-nav a[href], #primary-menu a[href], #mobile-menu a[href]';
    const storageKey = 'petish-cart-refresh:' + cart.pathname;
    let current = null, timer = null, request = null, revision = 0, subscribed = false;

    function isCartLink(link) {
        try {
            const url = new URL(link.href, location.href);
            return url.origin === cart.origin && url.pathname.replace(/\/$/, '') === cart.pathname.replace(/\/$/, '') && url.search === cart.search;
        } catch (e) { return false; }
    }

    function paint(badge) {
        const count = current && current.count;
        badge.hidden = !count;
        if (!current) return;
        badge.dataset.count = String(count);
        badge.dataset.wide = count >= 10 ? 'true' : 'false';
        const number = badge.querySelector('.petish-cart-badge__number');
        const label = badge.querySelector('.petish-cart-badge__label');
        const visible = count > 99 ? '99+' : String(count);
        if (number.textContent !== visible) number.textContent = visible;
        if (label.textContent !== current.label) label.textContent = current.label;
    }

    function attach() {
        document.querySelectorAll(links).forEach(function (link) {
            if (!isCartLink(link)) return;
            let badge = link.querySelector('.petish-cart-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'petish-cart-badge';
                badge.hidden = true;
                badge.setAttribute('role', 'status');
                badge.setAttribute('aria-live', 'polite');
                badge.setAttribute('aria-atomic', 'true');
                const number = document.createElement('span');
                number.className = 'petish-cart-badge__number';
                number.setAttribute('aria-hidden', 'true');
                const label = document.createElement('span');
                label.className = 'petish-cart-badge__label';
                badge.append(number, label);
                (link.querySelector('.menu-text') || link).appendChild(badge);
            }
            link.classList.add('petish-cart-link');
            paint(badge);
        });
    }

    async function refresh() {
        const version = ++revision;
        if (request) request.abort();
        request = new AbortController();
        const controller = request, signal = controller.signal;
        const timeout = setTimeout(function () { controller.abort(); }, 10000);
        try {
            const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: signal, headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error('Cart unavailable');
            const result = await response.json(), data = result && result.data;
            if (!result.success || !data || !Number.isSafeInteger(data.count) || data.count < 0 || typeof data.label !== 'string') throw new Error('Invalid cart quantity');
            if (version !== revision) return;
            current = {count: data.count, label: data.label};
            attach();
        } catch (e) {
            if (version !== revision) return;
            current = null;
            attach();
        } finally {
            clearTimeout(timeout);
            if (version === revision) request = null;
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(refresh, 100);
    }

    function changed() {
        schedule();
        // Invalidate another open tab without persisting cart data or counts.
        try { localStorage.setItem(storageKey, String(Date.now())); } catch (e) {}
    }

    function observeBlocks() {
        const data = window.wp && window.wp.data;
        const stores = window.wc && window.wc.wcBlocksData;
        if (subscribed || !data || typeof data.subscribe !== 'function' || !stores || !stores.CART_STORE_KEY) return;
        const store = stores.CART_STORE_KEY;
        let previous, pending = false;
        function inspect() {
            const selectors = data.select(store);
            if (!selectors || typeof selectors.getCartData !== 'function') return;
            const wasPending = pending;
            pending = typeof selectors.hasPendingItemsOperations === 'function' && selectors.hasPendingItemsOperations();
            if (pending) return;
            const state = selectors.getCartData(), count = state && state.itemsCount;
            if (!Number.isSafeInteger(count) || (count === previous && !wasPending)) return;
            const initial = previous === undefined;
            previous = count;
            if (!initial || wasPending) changed();
        }
        subscribed = true;
        inspect();
        data.subscribe(inspect, store);
    }

    function start() {
        attach();
        schedule();
        observeBlocks();
        window.jQuery(document.body).on('added_to_cart.petishCartBadge removed_from_cart.petishCartBadge updated_wc_div.petishCartBadge updated_cart_totals.petishCartBadge', changed);
        window.jQuery(document.body).on('wc_fragments_loaded.petishCartBadge wc_fragments_refreshed.petishCartBadge', function () { attach(); schedule(); });
        document.addEventListener('wc-blocks_added_to_cart', changed);
        document.addEventListener('wc-blocks_removed_from_cart', changed);
        // The7 can clone sticky/mobile headers and replace the entire mini cart.
        new MutationObserver(function (records) {
            if (records.some(record => Array.from(record.addedNodes).some(node => node.nodeType === 1 && (node.matches('a') || node.querySelector('a'))))) attach();
        }).observe(document.body, {childList: true, subtree: true});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
    window.addEventListener('load', observeBlocks, {once: true});
    window.addEventListener('pageshow', schedule);
    window.addEventListener('storage', event => { if (event.key === storageKey) schedule(); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) schedule(); });
}());
