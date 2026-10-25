/* Petish channel messaging. No broadcasts, direct messages, external embeds or offline send queue. */
(() => {
    'use strict';
    const root = document.querySelector('[data-pet-chat-stream]');
    if (!root) return;
    const configNode = root.querySelector('[data-chat-config]');
    let config;
    try { config = JSON.parse(configNode.textContent); } catch (_) { return; }
    if (!config || typeof config.endpoint !== 'string' || !config.state || !config.nonces) return;
    const qs = selector => root.querySelector(selector);
    const all = selector => [...root.querySelectorAll(selector)];
    const feed = qs('[data-chat-feed]');
    const messagesNode = qs('[data-chat-messages]');
    const form = qs('[data-chat-composer]');
    const draft = qs('[data-chat-draft]');
    const alias = qs('#pet-chat-alias');
    const consent = form && form.querySelector('[name=consent]');
    const sendButton = qs('[data-chat-send]');
    const statusNode = qs('[data-chat-status]');
    const errorNode = qs('[data-chat-error]');
    const newButton = qs('[data-chat-new]');
    const olderButton = qs('[data-chat-older]');
    const fileInput = qs('[data-chat-file]');
    const uploadList = qs('[data-chat-upload-list]');
    const reactions = ['❤️', '👍', '😂', '😮', '😢', '🎉'];
    const emoji = ['😀', '😂', '😊', '🥳', '👏', '👍', '❤️', '🔥', '🐰', '🐹', '🐾', '✨', '🎉'];
    let state = config.state;
    const initialSession = typeof state.session === 'string' ? state.session : '';
    let messages = Array.isArray(state.messages) ? state.messages : [];
    let cursor = state.cursor || {};
    let channelId = typeof state.channel?.id === 'string' ? state.channel.id : 'general';
    let selectedCategory = typeof state.channel?.category_id === 'string' ? state.channel.category_id : 'community';
    let quote = null;
    let selectedFile = null;
    let sendRequestId = '';
    let busy = false;
    let stopped = false;
    let manuallyPaused = false;
    let timer = 0;
    let failureCount = 0;
    let readController = null;
    let readPending = false;
    let refreshRequested = false;
    let pendingState = null;
    let navigationSerial = 0;
    let lastPresence = 0;
    let lastRead = 0;
    let markTimer = 0;
    const active = () => !stopped && !manuallyPaused && !document.hidden && navigator.onLine !== false;
    const signedIn = () => Boolean(state.viewer && state.viewer.signed_in);
    const canPost = () => signedIn() && Boolean(state.viewer.can_post) && !state.channel.read_only && !state.channel.archived;
    const nearBottom = () => feed.scrollHeight - feed.scrollTop - feed.clientHeight <= 100;
    const chars = value => Array.from(value || '').length;
    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = String(text);
        return node;
    };
    const button = (text, label, handler, className = 'pcs-icon-button') => {
        const node = el('button', className, text);
        node.type = 'button';
        if (label) node.setAttribute('aria-label', label);
        if (handler) node.addEventListener('click', handler);
        return node;
    };
    const announce = text => { statusNode.textContent = text || ''; };
    const showError = text => {
        if (errorNode) { errorNode.textContent = text || ''; errorNode.hidden = !text; }
        else announce(text);
    };
    const asString = value => typeof value === 'string' ? value : '';
    const id = value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : 0;
    const key = value => typeof value === 'string' && /^[a-z0-9][a-z0-9-]{0,47}$/.test(value) ? value : '';
    const safeUrl = (value, sameOrigin = false) => {
        if (typeof value !== 'string') return '';
        try {
            const url = new URL(value, location.href);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return '';
            if (sameOrigin && url.origin !== location.origin) return '';
            return url.href;
        } catch (_) { return ''; }
    };
    const validMessage = message => message && id(message.id) && typeof message.body === 'string' && typeof message.alias === 'string' && typeof message.member_id === 'string' && ['published', 'pending', 'draft'].includes(message.status);
    function validState(next) {
        return next && typeof next === 'object' && next.session === initialSession && next.channel && key(next.channel.id) && typeof next.channel.name === 'string' && Array.isArray(next.messages) && next.messages.every(validMessage) && next.viewer && typeof next.viewer.signed_in === 'boolean' && Array.isArray(next.channels) && Array.isArray(next.categories);
    }
    const epoch = value => {
        if (typeof value !== 'string') return 0;
        const result = Date.parse(value.replace(' ', 'T') + (/Z$|[+-]\d\d:\d\d$/.test(value) ? '' : 'Z'));
        return Number.isFinite(result) ? result : 0;
    };
    const timeLabel = value => { const stamp = epoch(value); return stamp ? new Date(stamp).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'}) : ''; };
    const dateLabel = value => { const stamp = epoch(value); return stamp ? new Date(stamp).toLocaleDateString([], {weekday: 'short', day: 'numeric', month: 'short'}) : ''; };
    function formatted(text, depth = 0) {
        const fragment = document.createDocumentFragment();
        if (depth >= 5) { fragment.append(document.createTextNode(text)); return fragment; }
        const pattern = /(\*\*(?=\S)[\s\S]+?\*\*|__(?=\S)[\s\S]+?__|\*(?=\S)[^*\n]+?\*|https?:\/\/[^\s<>]+)/gu;
        let offset = 0;
        for (const match of text.matchAll(pattern)) {
            fragment.append(document.createTextNode(text.slice(offset, match.index)));
            const token = match[0];
            if (/^https?:\/\//i.test(token)) {
                const href = safeUrl(token);
                if (href) {
                    const link = el('a', '', token);
                    link.href = href; link.target = '_blank'; link.rel = 'noopener noreferrer nofollow ugc';
                    fragment.append(link);
                } else fragment.append(document.createTextNode(token));
            } else {
                const count = token.startsWith('**') || token.startsWith('__') ? 2 : 1;
                const tag = token.startsWith('**') ? 'strong' : token.startsWith('__') ? 'u' : 'em';
                const node = el(tag);
                node.append(formatted(token.slice(count, -count), depth + 1));
                fragment.append(node);
            }
            offset = match.index + token.length;
        }
        fragment.append(document.createTextNode(text.slice(offset)));
        return fragment;
    }
    async function request(op, payload = {}, signal) {
        const endpoint = safeUrl(config.endpoint, true);
        if (!endpoint || stopped) throw new Error('Reload Pet Chat to reconnect. Your draft is still here.');
        const data = payload instanceof FormData ? payload : new FormData();
        if (!(payload instanceof FormData)) Object.entries(payload).forEach(([key, value]) => { if (Array.isArray(value)) value.forEach(item => data.append(`${key}[]`, String(item))); else data.set(key, String(value)); });
        data.set('action', config.action); data.set('op', op); data.set('session', initialSession);
        data.set('petish_chat_stream_nonce', asString(config.nonces[op]));
        if (!data.has('channel')) data.set('channel', String(channelId));
        let response;
        try { response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: data, signal, headers: {'X-Requested-With': 'XMLHttpRequest'}}); }
        catch (error) { if (error.name === 'AbortError') throw error; throw new Error('Connection interrupted. Your draft is still here; please try again.'); }
        let body;
        try { body = await response.json(); } catch (_) { throw new Error('Pet Chat could not read the response. Your draft is still here.'); }
        if ([401, 403, 409].includes(response.status) && (!body || !body.success)) {
            const message = body && body.data && asString(body.data.message);
            if (/session|account|reload|sign in/i.test(message)) terminate('Your session changed. Reload Pet Chat to continue. Your draft is still here.');
        }
        if (!response.ok || !body || body.success !== true || !body.data) throw new Error(body && body.data && asString(body.data.message) || 'That action could not be completed. Your draft is still here.');
        if (body.data.session !== initialSession) {
            terminate('Your account session changed. Reload Pet Chat to continue. Your draft is still here.');
            throw new Error('Your account session changed.');
        }
        if (body.data.nonces && typeof body.data.nonces === 'object') config.nonces = body.data.nonces;
        return body.data.result;
    }
    function purgePrivate() {
        messages = messages.filter(message => message.status === 'published');
        pendingState = null; quote = null;
        if (configNode) configNode.textContent = '';
        config.state = null;
        state = {...state, messages, viewer: {signed_in: false, can_post: false, is_staff: false, muted_members: []}, unread: {}, presence: []};
        render(messages, false);
        all('[data-chat-private]').forEach(node => node.remove());
        const staffPanel = qs('[data-chat-staff-panel]'); if (staffPanel) staffPanel.replaceChildren();
        const mutedPanel = qs('[data-chat-muted-panel]'); if (mutedPanel) mutedPanel.replaceChildren();
        updateReply();
    }
    function terminate(message) {
        stopped = true;
        clearTimeout(timer); clearTimeout(markTimer);
        if (readController) readController.abort();
        purgePrivate();
        all('button,input,select').forEach(node => { node.disabled = true; });
        if (draft) draft.readOnly = true;
        announce(message);
    }
    function busyControls(value) {
        busy = value;
        if (draft) draft.readOnly = value || stopped;
        if (sendButton) { sendButton.disabled = value || stopped || !canPost(); sendButton.textContent = value ? 'Sending…' : 'Send'; }
        if (fileInput) fileInput.disabled = value || stopped;
        const upload = qs('[data-chat-upload-button]'); if (upload) upload.disabled = value || stopped;
    }
    function updateCounter() {
        if (!draft) return;
        const count = chars(draft.value);
        const counter = qs('[data-chat-counter]');
        counter.textContent = `${count.toLocaleString()} / 1,000`;
        counter.setAttribute('aria-invalid', count > 1000 ? 'true' : 'false');
        draft.style.height = 'auto'; draft.style.height = `${Math.min(144, Math.max(56, draft.scrollHeight))}px`;
    }
    function updateReply() {
        const banner = qs('[data-chat-reply-banner]');
        if (!banner) return;
        banner.hidden = !quote;
        const label = qs('[data-chat-reply-label]');
        label.textContent = quote ? `Replying to ${quote.alias}: ${Array.from(quote.body || 'Attachment').slice(0, 160).join('')}` : '';
    }
    function selectReply(message) {
        if (!canPost()) return;
        quote = {id: id(message.id), alias: message.alias, body: message.body};
        updateReply(); draft.focus({preventScroll: true});
    }
    function jumpTo(messageId) {
        const node = qs(`#pet-chat-message-${id(messageId)}`);
        if (!node) { announce('That earlier message is outside the loaded history. Use Earlier messages to find it.'); return; }
        node.scrollIntoView({block: 'nearest', behavior: 'auto'});
        node.classList.add('pcs-highlight'); setTimeout(() => node.classList.remove('pcs-highlight'), 1800);
    }
    async function mutate(op, payload) {
        if (busy || stopped) return;
        showError('');
        try { const result = await request(op, payload); await refresh(true); return result; }
        catch (error) { showError(error.message); return null; }
    }
    function messageActions(message) {
        const actions = el('div', 'pcs-message-actions');
        const groups = el('div', 'pcs-reactions');
        const counts = message.reactions && typeof message.reactions === 'object' ? message.reactions : {};
        const own = Array.isArray(message.own_reactions) ? message.own_reactions : [];
        Object.entries(counts).forEach(([emojiValue, count]) => {
            if (!reactions.includes(emojiValue) || !Number.isFinite(Number(count)) || Number(count) < 1) return;
            const chip = button(`${emojiValue} ${Number(count)}`, `${emojiValue}: ${Number(count)} reactions`, () => mutate('react', {id: message.id, emoji: emojiValue}), 'pcs-reaction');
            chip.setAttribute('aria-pressed', own.includes(emojiValue) ? 'true' : 'false'); chip.disabled = !canPost(); groups.append(chip);
        });
        actions.append(groups);
        if (!signedIn()) return actions;
        const tools = el('div', 'pcs-message-tools');
        if (canPost() && message.status === 'published') {
            for (const emojiValue of ['❤️', '👍', '😂']) {
                if (Number(counts[emojiValue]) > 0) continue;
                const react = button(emojiValue, `React ${emojiValue}`, () => mutate('react', {id: message.id, emoji: emojiValue})); tools.append(react);
            }
            tools.append(button('↪ Reply', `Reply to ${message.alias}`, () => selectReply(message)));
        }
        if (message.can_edit) tools.append(button('Edit', 'Edit your message', () => editMessage(message)));
        if (message.can_remove) tools.append(button('Delete', 'Delete message', async () => { if (window.confirm('Remove this message from Pet Chat?')) await mutate('delete', {id: message.id}); }));
        if (!message.own && message.status === 'published') {
            tools.append(button('Report', 'Report message to staff', () => reportMessage(message)));
            tools.append(button('Mute', `Mute ${message.alias} for you`, () => mutate('mute', {member_id: message.member_id, muted: 1})));
        }
        if (state.viewer.is_staff) {
            if (message.status !== 'published') tools.append(button('Approve', 'Approve message', () => mutate('moderate', {id: message.id, operation: 'approve'})));
            if (message.status === 'published') tools.append(button('Hide', 'Hide message', () => mutate('moderate', {id: message.id, operation: 'hide'})));
        }
        actions.append(tools); return actions;
    }
    function mediaNode(media) {
        const url = safeUrl(media.url, true);
        if (!url) return null;
        if (media.kind === 'video') {
            const video = el('video'); video.controls = true; video.preload = 'metadata'; video.src = url; video.setAttribute('aria-label', asString(media.label) || 'Uploaded pet video'); if (id(media.width) && id(media.height)) { video.width = Number(media.width); video.height = Number(media.height); } return video;
        }
        if (!['photo', 'image'].includes(media.kind)) return null;
        const link = el('a'); link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer';
        const image = el('img'); image.src = url; image.alt = asString(media.label) || 'Shared pet photo'; image.loading = 'lazy'; image.decoding = 'async'; if (id(media.width) && id(media.height)) { image.width = Number(media.width); image.height = Number(media.height); } link.append(image); return link;
    }
    function messageNode(message, previous) {
        const grouped = previous && previous.member_id === message.member_id && previous.status === message.status && epoch(message.created_gmt) - epoch(previous.created_gmt) >= 0 && epoch(message.created_gmt) - epoch(previous.created_gmt) <= 900 && dateLabel(message.created_gmt) === dateLabel(previous.created_gmt);
        const article = el('article', `pcs-message${grouped ? ' pcs-grouped' : ''}`);
        article.id = `pet-chat-message-${id(message.id)}`; article.dataset.messageId = id(message.id);
        if (message.status !== 'published') article.dataset.chatPrivate = '1';
        const avatar = el('span', 'pcs-avatar', asString(message.initials) || 'P'); avatar.setAttribute('aria-hidden', 'true');
        const meta = el('div', 'pcs-message-meta'); meta.append(el('strong', 'pcs-alias', message.alias));
        const time = el('time', '', timeLabel(message.created_gmt)); const stamp = epoch(message.created_gmt); if (stamp) { time.dateTime = new Date(stamp).toISOString(); time.title = new Date(stamp).toLocaleString(); } meta.append(time);
        if (message.updated_gmt && message.updated_gmt !== message.created_gmt) meta.append(el('span', 'pcs-edited', 'edited'));
        article.append(avatar, meta);
        if (message.status !== 'published') article.append(el('span', 'pcs-pending', message.status === 'draft' ? 'Upload incomplete · only you and staff' : 'Awaiting moderation · only you and staff'));
        if (message.quote && id(message.quote.id) && typeof message.quote.alias === 'string' && typeof message.quote.body === 'string') {
            const reference = button('', 'Jump to quoted message', () => jumpTo(message.quote.id), 'pcs-quote');
            reference.append(el('span', '', '↪'));
            const text = el('span'); text.append(el('strong', '', message.quote.alias), document.createTextNode(`: ${Array.from(message.quote.body || 'Attachment').slice(0, 160).join('')}`)); reference.append(text); article.append(reference);
        }
        const body = el('div', 'pcs-message-body'); body.append(formatted(message.body)); article.append(body);
        if (Array.isArray(message.media)) {
            const attachments = el('div', 'pcs-media'); message.media.slice(0, 4).forEach(media => { const node = mediaNode(media); if (node) attachments.append(node); }); if (attachments.childNodes.length) article.append(attachments);
        }
        article.append(messageActions(message)); return article;
    }
    function render(nextMessages, scrollBottom = false, prepend = false) {
        const oldHeight = feed.scrollHeight;
        const oldTop = feed.scrollTop;
        const fragment = document.createDocumentFragment();
        let previous = null; let previousDate = '';
        nextMessages.forEach(message => {
            const date = dateLabel(message.created_gmt);
            if (date && date !== previousDate) { fragment.append(el('div', 'pcs-day', date)); previousDate = date; }
            fragment.append(messageNode(message, previous)); previous = message;
        });
        messagesNode.replaceChildren(fragment);
        const empty = qs('[data-chat-empty]'); if (empty) empty.hidden = nextMessages.length > 0;
        if (!nextMessages.length && !empty) {
            const node = el('div', 'pcs-empty'); node.dataset.chatEmpty = '1'; node.append(el('h3', '', 'Make yourself at home.'), el('p', '', 'Share a little moment, ask a question or say hello.')); feed.append(node);
        }
        if (scrollBottom) feed.scrollTop = feed.scrollHeight;
        else feed.scrollTop = prepend ? oldTop + feed.scrollHeight - oldHeight : oldTop;
    }
    function updateMetadata(next) {
        state = next;
        qs('.pcs-main').setAttribute('aria-label', state.channel.name);
        qs('[data-chat-channel-name]').textContent = state.channel.name;
        qs('[data-chat-channel-description]').textContent = asString(state.channel.description);
        if (draft) draft.placeholder = `Message #${state.channel.name}`;
        all('[data-chat-channel]').forEach(node => { if (node.dataset.chatChannel === channelId) node.setAttribute('aria-current', 'page'); else node.removeAttribute('aria-current'); });
        all('[data-chat-unread]').forEach(node => { const value = Number((next.unread || {})[node.dataset.chatUnread]) || 0; node.textContent = value > 99 ? '99+' : String(value); node.hidden = value < 1; });
        const presence = qs('[data-chat-presence]');
        const count = Array.isArray(next.presence) ? next.presence.length : Number(next.presence && next.presence.count) || Number(next.presence) || 0;
        presence.textContent = count > 0 ? `${count} active` : '';
        presence.hidden = count < 1;
        const muted = qs('[data-chat-muted-button]'); if (muted) muted.hidden = !signedIn();
        if (form) { busyControls(busy); if (!canPost()) announce('Posting is paused in this channel or for this account.'); }
    }
    function applyState(next, options = {}) {
        if (!validState(next) || Boolean(next.viewer.signed_in) !== signedIn() || (signedIn() && next.viewer.member_id !== state.viewer.member_id)) {
            terminate('Your session changed or Pet Chat returned an unexpected response. Reload to reconnect; your draft is still here.'); return;
        }
        const wasBottom = nearBottom();
        const newIds = next.messages.filter(message => !messages.some(old => old.id === message.id)).length;
        if (!options.initial && qs('.pcs-inline-edit')) { pendingState = next; newButton.hidden = false; announce('Updates are waiting while you edit.'); return; }
        const removed = new Set(Array.isArray(next.removed_ids) ? next.removed_ids.map(id) : []);
        const refreshed = new Map(Array.isArray(next.refreshed_messages) ? next.refreshed_messages.filter(validMessage).map(message => [message.id, message]) : []);
        messages = messages.filter(message => !removed.has(message.id)).map(message => refreshed.get(message.id) || message);
        let combined = next.messages;
        if (options.prepend) {
            const map = new Map([...next.messages, ...messages].map(message => [message.id, message])); combined = [...map.values()].sort((a, b) => a.id - b.id);
        } else if (!options.replace && messages.length && next.messages.length) {
            const first = Math.min(...next.messages.map(message => message.id));
            combined = [...messages.filter(message => message.id < first), ...next.messages];
        }
        messages = combined.length > 200 ? (options.prepend || !wasBottom ? combined.slice(0, 200) : combined.slice(-200)) : combined;
        cursor = {...cursor, ...next.cursor};
        if (!options.prepend && messages.length) cursor.oldest_id = messages[0].id;
        if (options.prepend) cursor.has_more = Boolean(next.cursor && next.cursor.has_more);
        updateMetadata(next);
        render(messages, options.initial || options.bottom || wasBottom, Boolean(options.prepend));
        olderButton.hidden = !cursor.has_more || messages.length >= 200;
        if (newIds && !wasBottom && !options.initial && !options.prepend) { newButton.hidden = false; announce(`${newIds} new message${newIds === 1 ? '' : 's'}.`); }
        if (wasBottom || options.initial || options.bottom) { newButton.hidden = true; scheduleMarkRead(); }
    }
    async function refresh(force = false, options = {}) {
        if (readPending) { if (force) refreshRequested = true; return; }
        if (stopped || (!force && (!active() || busy))) return;
        readPending = true;
        readController = new AbortController();
        const serial = navigationSerial;
        try {
            const payload = {channel: channelId, visible_ids: messages.map(message => message.id).slice(0, 200)};
            if (options.before) payload.before_id = options.before;
            const next = await request('read', payload, readController.signal);
            if (serial !== navigationSerial || stopped) return;
            applyState(next, {prepend: Boolean(options.before), bottom: Boolean(options.bottom), replace: Boolean(options.replace)});
            failureCount = 0;
            if (!newButton.hidden && !nearBottom()) return;
            if (!manuallyPaused && !busy) announce('');
            if (signedIn() && Date.now() - lastPresence > 60000 && active()) { lastPresence = Date.now(); request('presence').catch(() => {}); }
        } catch (error) {
            if (error.name !== 'AbortError' && !stopped) { failureCount = Math.min(5, failureCount + 1); announce(navigator.onLine === false ? 'You are offline. Your draft is still here.' : 'Updates are temporarily unavailable. Reconnecting shortly…'); }
        } finally { readPending = false; readController = null; if (refreshRequested && !stopped) { refreshRequested = false; clearTimeout(timer); timer = setTimeout(() => refresh(true), 0); } else schedule(); }
    }
    function schedule() {
        clearTimeout(timer);
        if (!active() || busy) return;
        timer = setTimeout(() => refresh(), Math.min(120000, (Number(config.interval) || 10000) * Math.pow(2, failureCount)));
    }
    function scheduleMarkRead() {
        clearTimeout(markTimer);
        if (!signedIn() || !active() || !nearBottom() || !messages.length) return;
        const markChannel = channelId;
        const latest = Math.max(...messages.filter(message => message.status === 'published').map(message => message.id), 0);
        if (!latest || latest <= lastRead) return;
        markTimer = setTimeout(async () => { if (!active() || !nearBottom() || channelId !== markChannel) return; try { await request('mark_read', {id: latest, channel: markChannel}); if (channelId !== markChannel) return; lastRead = latest; const badge = qs(`[data-chat-unread="${channelId}"]`); if (badge) badge.hidden = true; } catch (_) {} }, 500);
    }
    async function selectChannel(nextId) {
        if (!key(nextId) || nextId === channelId || busy || stopped) return;
        if (draft && draft.value.trim() && !window.confirm('Keep your unsent text and switch channels? It will remain in the message box.')) return;
        navigationSerial += 1;
        if (readController) readController.abort();
        clearTimeout(timer); channelId = key(nextId); lastRead = 0; quote = null; updateReply(); pendingState = null; messages = [];
        messagesNode.replaceChildren(); announce('Loading messages…'); newButton.hidden = true;
        const target = state.channels.find(item => item.id === channelId);
        if (target) { selectedCategory = target.category_id; updateCategories(); }
        const url = new URL(location.href); url.searchParams.set('petish_chat', '1'); url.searchParams.set('chat_channel', String(channelId)); url.searchParams.delete('chat_topic'); history.replaceState({}, '', url.href);
        // The aborted poll finishes before the next read; never overlap poll requests.
        const wait = () => { if (stopped) return; if (readPending) { setTimeout(wait, 30); return; } refresh(true, {replace: true, bottom: true}); };
        wait();
    }
    function updateCategories(focus = false) {
        const tabs = all('[data-chat-category]');
        tabs.forEach(node => { const selected = node.dataset.chatCategory === selectedCategory; node.setAttribute('aria-selected', selected ? 'true' : 'false'); node.tabIndex = selected ? 0 : -1; if (selected) { node.setAttribute('aria-current', 'true'); if (focus) node.focus({preventScroll: true}); const row = node.parentElement; row.scrollLeft = Math.max(0, node.offsetLeft - row.offsetLeft - 12); } else node.removeAttribute('aria-current'); });
        all('[data-chat-category-channel]').forEach(node => { node.hidden = node.dataset.chatCategoryChannel !== selectedCategory; });
        const activeChannel = qs(`[data-chat-mobile-channels] [data-chat-channel="${channelId}"]`);
        if (activeChannel && !activeChannel.hidden) activeChannel.parentElement.scrollLeft = Math.max(0, activeChannel.offsetLeft - activeChannel.parentElement.offsetLeft - 12);
    }
    function editMessage(message) {
        if (stopped || busy || qs('.pcs-inline-edit')) return;
        const article = qs(`#pet-chat-message-${id(message.id)}`); if (!article) return;
        const editor = el('form', 'pcs-inline-edit'); const area = el('textarea'); area.maxLength = 1000; area.value = message.body; area.setAttribute('aria-label', 'Edit your message');
        const controls = el('div'); const save = button('Save', '', null, 'pcs-button'); save.type = 'submit';
        const cancel = button('Cancel', '', () => { editor.remove(); if (pendingState) { const next = pendingState; pendingState = null; applyState(next); } }, 'pcs-button pcs-secondary');
        const error = el('p', 'pcs-form-error'); error.setAttribute('role', 'alert'); controls.append(save, cancel); editor.append(area, error, controls); article.append(editor); area.focus({preventScroll: true});
        editor.addEventListener('submit', async event => { event.preventDefault(); if (chars(area.value.trim()) > 1000 || !area.value.trim()) { error.textContent = 'Write a message of 1–1,000 characters.'; return; } save.disabled = true; try { await request('edit', {id: message.id, body: area.value, revision: message.revision}); editor.remove(); pendingState = null; await refresh(true); } catch (err) { error.textContent = err.message; save.disabled = false; } });
    }
    function reportMessage(message) {
        const article = qs(`#pet-chat-message-${id(message.id)}`); if (!article || article.querySelector('.pcs-report-form')) return;
        const report = el('form', 'pcs-report-form'); const label = el('label', '', 'Why are you reporting this?'); const select = el('select'); select.name = 'reason';
        Object.entries({privacy: 'Personal information', abuse: 'Abuse or harassment', unsafe: 'Unsafe advice', spam: 'Spam', other: 'Other concern'}).forEach(([value, text]) => { const option = el('option', '', text); option.value = value; select.append(option); }); label.append(select);
        const submit = button('Send report', '', null, 'pcs-button pcs-secondary'); submit.type = 'submit'; const cancel = button('Cancel', '', () => report.remove()); const note = el('p', 'pcs-help', 'Your report is private to the moderation team.'); report.append(label, submit, cancel, note); article.append(report);
        report.addEventListener('submit', async event => { event.preventDefault(); submit.disabled = true; try { await request('report', {id: message.id, reason: select.value}); report.remove(); announce('Report sent to the moderation team.'); } catch (error) { note.textContent = error.message; submit.disabled = false; } });
        select.focus({preventScroll: true});
    }
    function updateUpload() {
        uploadList.replaceChildren();
        if (!selectedFile) return;
        const chip = el('div', 'pcs-upload-chip'); chip.append(el('span', '', `${selectedFile.name} · ${(selectedFile.size / 1048576).toFixed(1)} MiB`), button('×', 'Remove selected attachment', () => { selectedFile = null; fileInput.value = ''; updateUpload(); })); uploadList.append(chip);
    }
    function requestId() {
        const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); return [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    }
    async function send(event) {
        if (event) event.preventDefault();
        if (busy || stopped || !form || !canPost()) return;
        const body = draft.value.trim();
        if (!body && !selectedFile) { showError('Write a message or choose a photo or video.'); draft.focus(); return; }
        if (chars(body) > 1000) { showError('Keep your message to 1,000 characters.'); return; }
        if (!alias.value.trim() || chars(alias.value.trim()) > 40 || !consent.checked) { form.reportValidity(); return; }
        showError(''); busyControls(true); clearTimeout(timer);
        const file = selectedFile; const originalText = draft.value;
        if (!sendRequestId) sendRequestId = requestId();
        let draftId = 0;
        try {
            let result = await request('send', {body, alias: alias.value.trim(), consent: '1', request_id: sendRequestId, quote_id: quote ? quote.id : 0, attachment_pending: file ? '1' : '0'});
            let message = result && result.message;
            if (!validMessage(message)) throw new Error('Pet Chat returned an unexpected message. Your draft is still here.');
            if (file) {
                draftId = message.id;
                announce(`Uploading ${file.name}…`);
                const data = new FormData(); data.set('id', String(draftId)); data.set('upload', file, file.name);
                result = await request('upload', data); message = result && result.message;
                if (!validMessage(message) || message.status === 'draft') throw new Error('The attachment could not be published. Your draft is still here.');
            }
            if (draft.value === originalText) draft.value = '';
            selectedFile = null; if (fileInput) fileInput.value = ''; quote = null; sendRequestId = ''; updateReply(); updateUpload(); updateCounter();
            announce(message.status === 'pending' ? 'Your first contribution is awaiting moderation. Only you and staff can see it.' : 'Message sent.');
            await refresh(true, {bottom: true});
        } catch (error) {
            showError(error.message);
            // A failed upload remains a private server draft. Retrying uses the same idempotency key.
            if (draftId) announce('The upload was not published. Keep your attachment selected and retry.');
        } finally { busyControls(false); schedule(); }
    }
    if (!validState(state)) { terminate('Pet Chat could not load safely. Reload to reconnect.'); return; }
    render(messages, true); updateMetadata(state); updateCategories();
    const pauseButton = qs('[data-chat-poll-toggle]'); pauseButton.hidden = false;
    pauseButton.addEventListener('click', () => { manuallyPaused = !manuallyPaused; pauseButton.setAttribute('aria-pressed', String(manuallyPaused)); pauseButton.textContent = manuallyPaused ? 'Resume' : 'Pause'; if (manuallyPaused) { clearTimeout(timer); if (readController) readController.abort(); announce('Updates paused.'); } else { announce('Reconnecting…'); refresh(); } });
    newButton.addEventListener('click', () => { if (pendingState) { const next = pendingState; pendingState = null; applyState(next, {bottom: true}); } else refresh(true, {replace: true, bottom: true}); newButton.hidden = true; announce(''); scheduleMarkRead(); });
    olderButton.addEventListener('click', () => { if (!readPending && cursor.oldest_id) refresh(true, {before: cursor.oldest_id}); });
    root.addEventListener('click', event => {
        const link = event.target.closest('[data-chat-channel]');
        if (link && root.contains(link)) { if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return; event.preventDefault(); selectChannel(link.dataset.chatChannel); return; }
        const category = event.target.closest('[data-chat-category]');
        if (category && root.contains(category)) { selectedCategory = category.dataset.chatCategory; updateCategories(); }
    });
    root.addEventListener('keydown', event => {
        const node = event.target.closest('[data-chat-category]');
        if (!node || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault(); const tabs = all('[data-chat-category]'); let index = tabs.indexOf(node);
        if (event.key === 'Home') index = 0; else if (event.key === 'End') index = tabs.length - 1; else index = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        selectedCategory = tabs[index].dataset.chatCategory; updateCategories(true);
    });
    if (form) {
        form.addEventListener('submit', send);
        draft.addEventListener('input', () => { sendRequestId = ''; updateCounter(); });
        draft.addEventListener('keydown', event => { if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) { event.preventDefault(); send(); } });
        qs('[data-chat-reply-cancel]').addEventListener('click', () => { quote = null; updateReply(); draft.focus({preventScroll: true}); });
        const picker = qs('[data-chat-emoji-picker]'); const pickerButton = qs('[data-chat-emoji-button]');
        emoji.forEach(value => picker.append(button(value, `Insert ${value}`, () => { const start = draft.selectionStart; const end = draft.selectionEnd; draft.value = draft.value.slice(0, start) + value + draft.value.slice(end); draft.setSelectionRange(start + value.length, start + value.length); picker.hidden = true; pickerButton.setAttribute('aria-expanded', 'false'); draft.focus({preventScroll: true}); sendRequestId = ''; updateCounter(); })));
        pickerButton.addEventListener('click', () => { picker.hidden = !picker.hidden; pickerButton.setAttribute('aria-expanded', String(!picker.hidden)); if (!picker.hidden) picker.querySelector('button').focus({preventScroll: true}); });
        picker.addEventListener('keydown', event => { if (event.key === 'Escape') { picker.hidden = true; pickerButton.setAttribute('aria-expanded', 'false'); pickerButton.focus({preventScroll: true}); } });
        const upload = config.upload || {}; const uploadButton = qs('[data-chat-upload-button]');
        uploadButton.hidden = !upload.enabled || !upload.upload_ready;
        const help = qs('[data-chat-upload-help]'); help.hidden = uploadButton.hidden;
        if (!uploadButton.hidden) help.textContent = `Photos or MP4 video · up to ${Math.floor(Number(upload.max_bytes) / 1048576)} MiB. Videos may retain location metadata.`;
        uploadButton.addEventListener('click', () => { if (!busy) fileInput.click(); });
        fileInput.addEventListener('change', () => { const file = fileInput.files && fileInput.files[0]; if (!file) return; if (file.size > Number(upload.max_bytes)) { showError('This file is larger than the 250 MiB limit.'); fileInput.value = ''; return; } selectedFile = file; sendRequestId = ''; updateUpload(); showError(''); });
        updateCounter();
    }
    const mutedButton = qs('[data-chat-muted-button]');
    mutedButton.addEventListener('click', () => {
        const panel = qs('[data-chat-muted-panel]'); panel.hidden = !panel.hidden; panel.replaceChildren(); if (panel.hidden) return;
        const muted = Array.isArray(state.viewer.muted_members) ? state.viewer.muted_members : [];
        if (!muted.length) panel.append(el('p', 'pcs-help', 'You have not muted anyone.'));
        muted.forEach(member => { const token = typeof member === 'string' ? member : asString(member.member_id); const label = typeof member === 'object' ? asString(member.alias) : ''; if (!token) return; panel.append(button(`Unmute ${label || 'member'}`, 'Unmute this member for you', async () => { await mutate('mute', {member_id: token, muted: 0}); panel.hidden = true; }, 'pcs-button pcs-secondary')); });
    });
    const staffPanel = qs('[data-chat-staff-panel]');
    async function loadQueue(page = 1) {
        if (!staffPanel || !state.viewer.is_staff || stopped) return;
        staffPanel.replaceChildren(el('p', 'pcs-help', 'Loading moderation queue…'));
        try {
            const queue = await request('queue', {page});
            staffPanel.replaceChildren();
            if (!queue || !Array.isArray(queue.items)) throw new Error('The moderation queue could not be read.');
            if (!queue.items.length) staffPanel.append(el('p', 'pcs-help', 'No contributions need review.'));
            queue.items.filter(validMessage).forEach(message => {
                const card = el('section', 'pcs-staff-item'); card.dataset.chatPrivate = '1';
                card.append(el('strong', '', message.alias), el('p', 'pcs-help', `${message.channel} · ${message.status} · ${message.reports && typeof message.reports === 'object' ? Object.keys(message.reports).length : 0} reports`));
                const body = el('div', 'pcs-message-body'); body.append(formatted(message.body)); card.append(body);
                if (message.reports && typeof message.reports === 'object') { const reasons = [...new Set(Object.values(message.reports).map(report => asString(report && report.reason)).filter(reason => ['privacy', 'abuse', 'unsafe', 'spam', 'other'].includes(reason)))]; if (reasons.length) card.append(el('p', 'pcs-help', `Reported concerns: ${reasons.join(', ')}`)); }
                if (Array.isArray(message.media)) message.media.forEach(media => { const mediaView = mediaNode(media); if (mediaView) card.append(mediaView); });
                const actions = el('div', 'pcs-staff-actions');
                for (const [operation, label] of [['approve', 'Approve'], ['hide', 'Hide'], ['spam', 'Mark spam']]) actions.append(button(label, '', async () => { try { await request('moderate', {id: message.id, operation}); await loadQueue(page); await refresh(true); } catch (error) { announce(error.message); } }, 'pcs-button pcs-secondary'));
                if (!message.own) for (const [operation, label] of [['restrict', 'Pause member posting'], ['unrestrict', 'Restore member posting']]) actions.append(button(label, '', async () => { try { await request('moderate', {member_id: message.member_id, operation}); announce('Member posting permission updated.'); } catch (error) { announce(error.message); } }, 'pcs-button pcs-secondary'));
                card.append(actions); staffPanel.append(card);
            });
            if (page > 1) staffPanel.append(button('Previous page', '', () => loadQueue(page - 1), 'pcs-button pcs-secondary'));
            if (queue.has_more) staffPanel.append(button('Next page', '', () => loadQueue(page + 1), 'pcs-button pcs-secondary'));
        } catch (error) { staffPanel.replaceChildren(el('p', 'pcs-form-error', error.message)); }
    }
    const queueButton = qs('[data-chat-queue]'); if (queueButton) queueButton.addEventListener('click', () => loadQueue());
    function staffField(labelText, name, value = '', type = 'text') {
        const label = el('label', '', labelText); const input = el(type === 'textarea' ? 'textarea' : 'input');
        if (type !== 'textarea') input.type = type;
        input.name = name; input.value = String(value); input.maxLength = type === 'textarea' ? 300 : 80; label.append(input); return {label, input};
    }
    function managementForm(kind, record = null) {
        const form = el('form', 'pcs-staff-form');
        form.append(el('h3', '', `${record ? 'Edit' : 'New'} ${kind}`));
        const name = staffField('Name', 'name', record ? record.name : ''); name.input.required = true; form.append(name.label);
        let description, category, readOnly;
        if (kind === 'channel') {
            description = staffField('Description', 'description', record ? record.description : '', 'textarea'); form.append(description.label);
            const label = el('label', '', 'Category'); category = el('select'); category.name = 'category_id';
            (state.management?.categories || state.categories).forEach(item => { const option = el('option', '', item.name); option.value = item.id; category.append(option); }); category.value = record ? record.category_id : (state.categories[0] && state.categories[0].id || 'community'); label.append(category); form.append(label);
            const readonlyLabel = el('label', 'pcs-consent'); readOnly = el('input'); readOnly.type = 'checkbox'; readOnly.checked = Boolean(record && record.read_only); readonlyLabel.append(readOnly, document.createTextNode('Read-only channel')); form.append(readonlyLabel);
        }
        const error = el('p', 'pcs-form-error'); error.setAttribute('role', 'alert');
        const controls = el('div', 'pcs-staff-actions'); const save = button(record ? 'Save changes' : `Create ${kind}`, '', null, 'pcs-button pcs-secondary'); save.type = 'submit'; controls.append(save);
        if (record) controls.append(button(record.archived ? 'Restore' : 'Archive', '', async () => { try { await request('manage', {operation: `${record.archived ? 'restore' : 'archive'}_${kind}`, id: record.id, revision: record.revision}); location.reload(); } catch (err) { error.textContent = err.message; } }, 'pcs-button pcs-secondary'));
        form.append(error, controls);
        form.addEventListener('submit', async event => {
            event.preventDefault(); save.disabled = true;
            const payload = {operation: `${record ? 'update' : 'create'}_${kind}`, name: name.input.value.trim()};
            if (record) { payload.id = record.id; payload.revision = record.revision; }
            if (kind === 'channel') { payload.description = description.input.value; payload.category_id = category.value; payload.read_only = readOnly.checked ? '1' : '0'; }
            try { await request('manage', payload); location.reload(); } catch (err) { error.textContent = err.message; save.disabled = false; }
        });
        return form;
    }
    const manageButton = qs('[data-chat-manage]');
    if (manageButton) manageButton.addEventListener('click', () => {
        if (!staffPanel || !state.viewer.is_staff || stopped) return;
        if (draft && draft.value.trim()) { announce('Send or save your message draft before editing channels; successful management reloads this page.'); return; }
        staffPanel.replaceChildren(managementForm('category'), managementForm('channel'));
        const management = state.management || {categories: state.categories, channels: state.channels, revision: state.revision};
        management.categories.forEach(item => staffPanel.append(managementForm('category', item)));
        management.channels.forEach(item => staffPanel.append(managementForm('channel', item)));
        for (const [kind, records] of [['categories', management.categories], ['channels', management.channels]]) {
            const section = el('section', 'pcs-staff-item'); section.append(el('h3', '', `Order ${kind}`));
            records.forEach((record, index) => {
                const row = el('div', 'pcs-staff-actions'); row.append(el('span', 'pcs-help', record.name));
                for (const [delta, label] of [[-1, 'Move up'], [1, 'Move down']]) {
                    const control = button(label, `${label}: ${record.name}`, async () => {
                        const ids = records.map(item => item.id); [ids[index], ids[index + delta]] = [ids[index + delta], ids[index]];
                        try { await request('manage', {operation: `reorder_${kind}`, ids, revision: management.revision}); location.reload(); } catch (error) { announce(error.message); }
                    }, 'pcs-button pcs-secondary'); control.disabled = index + delta < 0 || index + delta >= records.length; row.append(control);
                }
                section.append(row);
            }); staffPanel.append(section);
        }
    });
    const divider = qs('[data-chat-divider]'); const shell = qs('[data-chat-shell]');
    let sideWidth = 240;
    try { const saved = Number(localStorage.getItem('petish-chat-channel-width')); if (saved) sideWidth = saved; } catch (_) {}
    const resize = width => { const max = Math.max(180, Math.min(420, shell.clientWidth - 420)); sideWidth = Math.max(180, Math.min(max, width)); root.style.setProperty('--pcs-side', `${sideWidth}px`); divider.setAttribute('aria-valuenow', String(Math.round(sideWidth))); divider.setAttribute('aria-valuemax', String(Math.round(max))); return max; };
    const saveWidth = () => { try { localStorage.setItem('petish-chat-channel-width', String(Math.round(sideWidth))); } catch (_) {} };
    resize(sideWidth);
    divider.addEventListener('pointerdown', event => { if (event.button !== 0) return; event.preventDefault(); divider.setPointerCapture(event.pointerId); const left = shell.getBoundingClientRect().left; const move = e => resize(e.clientX - left); const end = () => { divider.removeEventListener('pointermove', move); divider.removeEventListener('pointerup', end); divider.removeEventListener('pointercancel', end); saveWidth(); }; divider.addEventListener('pointermove', move); divider.addEventListener('pointerup', end); divider.addEventListener('pointercancel', end); });
    divider.addEventListener('keydown', event => { if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return; event.preventDefault(); resize(event.key === 'Home' ? 180 : event.key === 'End' ? 420 : sideWidth + (event.key === 'ArrowRight' ? 12 : -12)); saveWidth(); });
    let resizeTimer; window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => resize(sideWidth), 100); });
    feed.addEventListener('scroll', () => { if (nearBottom()) { newButton.hidden = true; scheduleMarkRead(); } }, {passive: true});
    document.addEventListener('visibilitychange', () => { if (document.hidden) { clearTimeout(timer); if (readController) readController.abort(); } else if (!stopped) refresh(); });
    window.addEventListener('offline', () => { clearTimeout(timer); if (readController) readController.abort(); announce('You are offline. Your draft is still here.'); });
    window.addEventListener('online', () => { if (!stopped) refresh(); });
    window.addEventListener('pagehide', () => { clearTimeout(timer); if (readController) readController.abort(); purgePrivate(); });
    window.addEventListener('pageshow', event => { if (event.persisted) terminate('This page was restored from history. Reload Pet Chat to reconnect. Your draft is still here.'); });
    schedule(); scheduleMarkRead();
})();
