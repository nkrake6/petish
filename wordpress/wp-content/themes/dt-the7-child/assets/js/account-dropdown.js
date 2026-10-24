/* A private own-session fragment enriches the native header account widget. */
(function () {
    'use strict';
    const config = window.petishAccountDropdown;
    if (!config || !window.fetch) return;
    let endpoint, account;
    try {
        endpoint = new URL(config.endpoint, location.href);
        account = new URL(config.accountUrl, location.href);
        if (endpoint.origin !== location.origin || account.origin !== location.origin || config.action !== 'petish_account_dropdown') return;
    } catch (e) { return; }
    const selector = '[data-petish-account]';
    const initialized = new WeakSet();
    const hoverTimers = new WeakMap();
    let counter = 0, current = null, controller = null, revision = 0;

    function icon(type) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false');
        const path = document.createElementNS(svg.namespaceURI, 'path');
        path.setAttribute('d', type === 'arrow' ? 'm6 9 6 6 6-6' : 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2');
        path.setAttribute('fill', 'none'); path.setAttribute('stroke', 'currentColor'); path.setAttribute('stroke-width', '1.7');
        path.setAttribute('stroke-linecap', 'round'); path.setAttribute('stroke-linejoin', 'round'); svg.appendChild(path);
        return svg;
    }
    function controls(item) {
        return {button: item.querySelector(':scope > [data-petish-account-toggle]'), panel: item.querySelector(':scope > [data-petish-account-panel]')};
    }
    function close(item, focus) {
        const parts = controls(item);
        if (!parts.button || !parts.panel) return;
        parts.button.setAttribute('aria-expanded', 'false'); parts.button.setAttribute('aria-label', config.openLabel);
        parts.panel.hidden = true;
        if (focus && !parts.button.hidden) parts.button.focus();
    }
    function closeAll(except, focus) {
        document.querySelectorAll(selector).forEach(item => { if (item !== except) close(item, focus && item.contains(document.activeElement)); });
    }
    function open(item, edge) {
        const parts = controls(item);
        if (!parts.button || parts.button.hidden || !parts.panel || !parts.panel.children.length) return;
        closeAll(item, false); parts.panel.hidden = false;
        parts.button.setAttribute('aria-expanded', 'true'); parts.button.setAttribute('aria-label', config.closeLabel);
        if (edge) {
            const links = parts.panel.querySelectorAll('a');
            const link = edge === 'last' ? links[links.length - 1] : links[0];
            if (link) link.focus();
        }
    }
    function safeUrl(value) {
        if (typeof value !== 'string' || value.length > 4096 || /[\x00-\x20\x7f]/.test(value)) return null;
        try {
            const url = new URL(value, location.href);
            return url.origin === location.origin && !url.username && !url.password && ['http:', 'https:'].includes(url.protocol) ? url : null;
        } catch (e) { return null; }
    }
    function activeKeys(items) {
        const here = new URL(location.href), path = here.pathname.replace(/\/$/, '');
        const matches = items.filter(item => item.url.pathname.replace(/\/$/, '') === path);
        const specific = matches.filter(item => item.url.search && Array.from(item.url.searchParams).every(([key, value]) => here.searchParams.get(key) === value));
        return new Set((specific.length ? specific : matches.filter(item => !item.url.search)).map(item => item.key));
    }
    function paint(item) {
        const anchor = item.querySelector(':scope > a'), parts = controls(item);
        if (!anchor || !parts.button || !parts.panel) return;
        let avatar = anchor.querySelector('.petish-account-avatar');
        if (!avatar) { avatar = document.createElement('span'); avatar.className = 'petish-account-avatar'; avatar.setAttribute('aria-hidden', 'true'); anchor.prepend(avatar); }
        avatar.replaceChildren(icon('person')); parts.panel.replaceChildren(); close(item, false); parts.button.hidden = true;
        if (!current || !current.signed_in) return;
        if (current.avatar) {
            const img = document.createElement('img'); img.src = current.avatar; img.alt = ''; img.width = 30; img.height = 30;
            img.decoding = 'async'; img.referrerPolicy = 'no-referrer';
            img.addEventListener('error', function () { avatar.textContent = current && current.initial || 'P'; }, {once: true});
            avatar.replaceChildren(img);
        } else avatar.textContent = current.initial || 'P';
        const active = activeKeys(current.items);
        current.items.forEach(entry => {
            const li = document.createElement('li'), link = document.createElement('a');
            link.href = entry.url.href; link.textContent = entry.label;
            if (active.has(entry.key)) link.setAttribute('aria-current', 'page');
            li.appendChild(link); parts.panel.appendChild(li);
        });
        parts.button.hidden = !current.items.length;
    }
    function attach() {
        const parents = new Set(Array.from(document.querySelectorAll(selector), item => item.parentElement));
        parents.forEach(parent => {
            // The7 puts first/second-breakpoint copies in the same group. Keep
            // those distinct, and discard only duplicate copies of one variant.
            const variants = new Map();
            Array.from(parent.children).filter(node => node.matches(selector)).forEach(item => {
                const key = ['show-on-first-switch', 'show-on-second-switch', 'hide-on-first-switch', 'hide-on-second-switch']
                    .filter(name => item.classList.contains(name)).join('|');
                const previous = variants.get(key);
                if (!previous) { variants.set(key, item); return; }
                if (item.contains(document.activeElement)) { previous.remove(); variants.set(key, item); }
                else item.remove();
            });
            const children = Array.from(parent.children), first = children.findIndex(node => node.matches(selector));
            // Move the complete block once. Appending each breakpoint copy in
            // turn would keep reordering siblings inside our MutationObserver.
            if (first >= 0 && children.slice(first + 1).some(node => !node.matches(selector))) {
                children.filter(node => node.matches(selector)).forEach(item => parent.appendChild(item));
            }
        });
        document.querySelectorAll(selector).forEach(item => {
            const anchor = item.querySelector(':scope > a');
            if (!anchor || !safeUrl(anchor.href)) return;
            if (initialized.has(item)) return;
            initialized.add(item);
            let {button, panel} = controls(item);
            if (!button) {
                button = document.createElement('button'); button.type = 'button'; button.className = 'petish-account-toggle';
                button.dataset.petishAccountToggle = ''; button.hidden = true; button.appendChild(icon('arrow')); item.appendChild(button);
            }
            if (!panel) { panel = document.createElement('ul'); panel.className = 'petish-account-dropdown__panel'; panel.dataset.petishAccountPanel = ''; panel.hidden = true; item.appendChild(panel); }
            panel.id = 'petish-account-dropdown-' + (++counter);
            button.setAttribute('aria-controls', panel.id); button.setAttribute('aria-expanded', 'false'); button.setAttribute('aria-label', config.openLabel);
            paint(item);
        });
    }
    async function refresh() {
        const version = ++revision;
        if (controller) controller.abort();
        controller = new AbortController(); const request = controller;
        current = null; document.querySelectorAll(selector).forEach(item => paint(item));
        const timer = setTimeout(() => request.abort(), 10000);
        try {
            const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: request.signal,
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}, body: new URLSearchParams({action: config.action})});
            if (!response.ok) throw new Error('Account unavailable');
            const result = await response.json(), data = result && result.data;
            if (!result.success || !data || typeof data.signed_in !== 'boolean' || !Array.isArray(data.items) || data.items.length > 50 || typeof data.initial !== 'string' || data.initial.length > 8) throw new Error('Invalid account fragment');
            const items = data.items.map(entry => {
                const url = entry && safeUrl(entry.url);
                if (!url || typeof entry.key !== 'string' || !/^[a-z0-9][a-z0-9_-]{0,63}$/.test(entry.key) || typeof entry.label !== 'string' || entry.label.length > 512) throw new Error('Invalid account link');
                return {key: entry.key, label: entry.label, url};
            });
            const avatar = data.avatar ? safeUrl(data.avatar) : null;
            if (data.avatar && (!avatar || avatar.protocol !== 'https:')) throw new Error('Invalid account avatar');
            if (version !== revision) return;
            current = {signed_in: data.signed_in, avatar: avatar && avatar.href, initial: data.initial, items};
            document.querySelectorAll(selector).forEach(item => paint(item));
        } catch (e) {
            if (version === revision) { current = null; document.querySelectorAll(selector).forEach(item => paint(item)); }
        } finally { clearTimeout(timer); if (version === revision) controller = null; }
    }
    function start() {
        attach(); refresh();
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-petish-account-toggle]');
            if (button) {
                const item = button.closest('.petish-account-menu');
                if (!item) return;
                event.preventDefault(); event.stopPropagation();
                if (button.getAttribute('aria-expanded') === 'true') close(item, false); else open(item);
            } else if (!event.target.closest('.petish-account-menu')) closeAll(null, false);
        });
        document.addEventListener('keydown', event => {
            const item = event.target.closest('.petish-account-menu');
            if (!item) return;
            if (event.key === 'Escape') { close(item, true); event.preventDefault(); }
            else if (event.target.matches('[data-petish-account-toggle]') && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault(); open(item, event.key === 'ArrowUp' ? 'last' : 'first');
            }
        });
        document.addEventListener('focusin', event => { if (!event.target.closest('.petish-account-menu')) closeAll(null, false); });
        document.addEventListener('pointerover', event => {
            if (!window.matchMedia('(min-width: 1120px) and (hover: hover) and (pointer: fine)').matches) return;
            const item = event.target.closest('.petish-account-menu');
            if (!item || (event.relatedTarget && item.contains(event.relatedTarget))) return;
            clearTimeout(hoverTimers.get(item)); open(item);
        });
        document.addEventListener('pointerout', event => {
            const item = event.target.closest('.petish-account-menu');
            if (!item || (event.relatedTarget && item.contains(event.relatedTarget))) return;
            clearTimeout(hoverTimers.get(item));
            hoverTimers.set(item, setTimeout(() => { if (!item.contains(document.activeElement)) close(item, false); }, 150));
        });
        new MutationObserver(records => {
            if (records.some(record => record.target.matches?.('.mini-widgets,.mobile-mini-widgets,.mobile-mini-widgets-in-menu')
                || Array.from(record.addedNodes).some(node => node.nodeType === 1 && (node.matches(selector) || node.querySelector(selector))))) attach();
        }).observe(document.body, {childList: true, subtree: true});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
    window.addEventListener('pagehide', () => {
        revision++;
        if (controller) controller.abort();
        current = null; document.querySelectorAll(selector).forEach(item => paint(item));
    });
    window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}());
