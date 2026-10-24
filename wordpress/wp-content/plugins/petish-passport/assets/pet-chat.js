/* Read-only foreground updates. Native POST forms remain the writing path. */
(function () {
    'use strict';
    const root = document.querySelector('[data-pet-chat]');
    const config = window.petishChat;
    if (!root || !config || !window.fetch || !window.MutationObserver) return;
    const status = root.querySelector('[data-chat-status]');
    const toggle = root.querySelector('[data-chat-poll-toggle]');
    const update = root.querySelector('[data-chat-update]');
    const feed = root.querySelector('[data-chat-feed]');
    const composer = root.querySelector('[data-chat-composer]');
    const context = root.dataset.chatContext;
    const session = root.dataset.chatSession;
    const signedIn = root.dataset.chatSignedIn === '1';
    let filters, endpoint;
    try { filters = JSON.parse(root.dataset.chatFilters); endpoint = new URL(config.endpoint, location.href); } catch (error) { return; }
    if (!feed || !toggle || !update || !status || !window.crypto || !window.crypto.subtle || !window.TextEncoder || endpoint.origin !== location.origin || config.action !== 'petish_chat_refresh' ||
        !/^[a-f0-9]{64}$/.test(context || '') || !/^[a-f0-9]{64}$/.test(session || '') || !/^[a-f0-9]{64}$/.test(root.dataset.chatRevision || '') ||
        !filters || typeof filters !== 'object' || Array.isArray(filters)) return;
    const interval = Math.max(15000, Math.min(30000, Number(root.dataset.chatInterval) || 30000));
    let revision = root.dataset.chatRevision;
    let timer = null, controller = null, paused = false, stopped = false, failures = 0, pending = null, lastStarted = 0;
    toggle.hidden = false;
    const say = text => { status.textContent = typeof text === 'string' ? text : ''; };
    function clearTimer() { if (timer !== null) clearTimeout(timer); timer = null; }
    function abort() { if (controller) controller.abort(); controller = null; }
    function stop(message) {
        stopped = true; pending = null; clearTimer(); abort(); update.hidden = true; toggle.hidden = true;
        // A lost/unverifiable session may produce404 for an owner-only topic.
        // Scrub old pending content on every terminal failure, including BFCache.
        root.querySelectorAll('[data-chat-private]').forEach(node => node.remove());
        root.querySelectorAll('form[method="post"]').forEach(form => {
            form.setAttribute('aria-disabled', 'true');
            form.querySelectorAll('button[type="submit"],input[type="submit"]').forEach(button => { button.disabled = true; });
            form.addEventListener('submit', event => event.preventDefault());
        });
        say(message);
    }
    function schedule(delay) {
        clearTimer();
        if (paused || stopped || document.hidden) return;
        timer = setTimeout(poll, Math.max(delay, interval - (Date.now() - lastStarted)));
    }
    function allowedFragment(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        if (template.content.children.length !== 1 || !template.content.firstElementChild.matches('div[data-chat-feed-content]')) return null;
        const allowed = new Set(['DIV','ARTICLE','SPAN','P','H2','H3','STRONG','TIME','A','FORM','INPUT','BUTTON','DETAILS','SUMMARY','LABEL','SELECT','OPTION','NAV','BR']);
        for (const element of template.content.querySelectorAll('*')) {
            if (!allowed.has(element.tagName)) return null;
            for (const attribute of element.attributes) {
                if (/^on/i.test(attribute.name) || ['style','srcdoc'].includes(attribute.name)) return null;
                if (['href','action','formaction','src'].includes(attribute.name)) {
                    let url;
                    try { url = new URL(attribute.value, location.href); } catch (error) { return null; }
                    if (url.origin !== location.origin || !['http:','https:'].includes(url.protocol)) return null;
                }
            }
            if (element.tagName === 'FORM' && element.getAttribute('method')?.toLowerCase() !== 'post') return null;
            if (element.tagName === 'INPUT' && element.getAttribute('type') !== 'hidden') return null;
        }
        return template.content.firstElementChild;
    }
    async function checkedResponse(json) {
        if (!json || json.success !== true || !json.data || typeof json.data !== 'object') return null;
        const data = json.data;
        if (data.schema !== 1 || data.context !== context || typeof data.signed_in !== 'boolean' ||
            !/^[a-f0-9]{64}$/.test(data.session || '') || !/^[a-f0-9]{64}$/.test(data.revision || '') ||
            typeof data.html !== 'string' || data.html.length > 250000 || typeof data.login_url !== 'string') return null;
        let login;
        try { login = new URL(data.login_url, location.href); } catch (error) { return null; }
        if (login.origin !== location.origin) return null;
        if (data.signed_in !== signedIn || data.session !== session) return {changedSession: true};
        if (!window.crypto || !crypto.subtle || !window.TextEncoder) return null;
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(data.html));
        const hex = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2,'0')).join('');
        if (hex !== data.revision) return null;
        const fragment = allowedFragment(data.html);
        return fragment ? {data, fragment} : null;
    }
    async function poll() {
        clearTimer();
        if (paused || stopped || document.hidden || controller) return;
        lastStarted = Date.now();
        const active = new AbortController(); controller = active;
        const timeout = setTimeout(() => active.abort(), 10000);
        try {
            const body = new URLSearchParams({action:config.action, context});
            for (const key of ['channel','topic','view','page','reply_page']) body.set(key, String(filters[key]));
            const response = await fetch(endpoint.href, {method:'POST', credentials:'same-origin', cache:'no-store', redirect:'error',
                headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'}, body:body.toString(), signal:active.signal});
            if (response.status === 401 || response.status === 403 || response.status === 404) { stop(config.sessionEnded); return; }
            if (!response.ok) throw new Error('unavailable');
            const checked = await checkedResponse(await response.json());
            if (!checked) { stop(config.stopped, false); return; }
            if (checked.changedSession) { stop(config.sessionEnded, true); return; }
            failures = 0;
            if (checked.data.revision !== revision) {
                pending = checked; update.hidden = false; update.textContent = config.loadUpdates; say(config.newUpdates);
            } else if (!pending) say('');
        } catch (error) {
            if (stopped || paused || document.hidden) return;
            failures++;
            if (failures >= 5) { stop(config.stopped, false); return; }
            say(config.retrying);
        } finally {
            clearTimeout(timeout);
            if (controller === active) controller = null;
            schedule(Math.min(120000, interval * Math.pow(2, failures)));
        }
    }
    update.addEventListener('click', () => {
        if (!pending || stopped) return;
        const previousHeight = feed.getBoundingClientRect().height;
        const previousTop = feed.getBoundingClientRect().top;
        const scroll = window.scrollY;
        const focused = document.activeElement;
        const keepComposerFocus = composer && composer.contains(focused);
        const focusTop = keepComposerFocus ? focused.getBoundingClientRect().top : 0;
        const current = feed.querySelector('[data-chat-feed-content]');
        if (!current) { stop(config.stopped, false); return; }
        current.replaceWith(pending.fragment);
        revision = pending.data.revision; root.dataset.chatRevision = revision; pending = null; update.hidden = true;
        // An explicit refresh can change feed height above the composer. Keep
        // the reader's viewport anchored; never replace their form or draft.
        const delta = feed.getBoundingClientRect().height - previousHeight;
        if (keepComposerFocus) {
            const moved = focused.getBoundingClientRect().top - focusTop;
            if (moved) window.scrollTo(window.scrollX, scroll + moved);
        } else {
            if (previousTop + previousHeight < 0 && delta) window.scrollTo(window.scrollX, scroll + delta);
            feed.focus({preventScroll:true});
        }
        say(config.updated);
    });
    toggle.addEventListener('click', () => {
        paused = !paused; toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
        toggle.textContent = paused ? config.resumeLabel : config.pauseLabel;
        if (paused) { clearTimer(); abort(); say(config.paused); }
        else { say(config.resumed); schedule(interval); }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { clearTimer(); abort(); }
        else schedule(interval);
    });
    root.addEventListener('click', event => {
        const reply = event.target.closest('[data-chat-reply-to]');
        const cancel = event.target.closest('[data-chat-reply-cancel]');
        if ((!reply && !cancel) || !composer || stopped) return;
        const field = composer.querySelector('[name="parent_id"]');
        const banner = composer.querySelector('[data-chat-reply-target]');
        const label = composer.querySelector('[data-chat-reply-label]');
        const textarea = composer.querySelector('textarea[name="body"]');
        if (!field || !banner || !label || !textarea) return;
        if (reply && !/^[1-9][0-9]{0,17}$/.test(reply.dataset.chatReplyTo || '')) return;
        event.preventDefault();
        field.value = reply ? reply.dataset.chatReplyTo : '0';
        banner.hidden = !reply;
        label.textContent = reply ? String(config.replyingTo || 'Replying to %s').replace('%s', reply.dataset.chatReplyAlias || '') : '';
        textarea.focus();
    });
    window.addEventListener('pagehide', () => stop(config.stopped));
    window.addEventListener('pageshow', event => { if (event.persisted) stop(config.stopped); });
    schedule(interval);
})();
