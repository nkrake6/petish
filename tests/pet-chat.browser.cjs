/* Offline acceptance of the actual Pet Chat UI/template/assets. No hosted traffic. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const crypto = require('node:crypto');
const { chromium } = require('../tools/staging-rehearsal/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const plugin = path.join(root, 'wordpress/wp-content/plugins/petish-passport');
const theme = path.join(root, 'wordpress/wp-content/themes/dt-the7-child');
const origin = 'https://pet-chat-fixture.petish.test';
const php = process.env.PETISH_PHP_EXECUTABLE || (fs.existsSync('/workspace/.petish-dev/bin/php') ? '/workspace/.petish-dev/bin/php' : 'php');
const vendorRoot = [process.env.PETISH_THE7_ROOT, '/tmp/petish-the7-reference/dt-the7', '/tmp/petish-related-vendor/dt-the7'].find(p => p && fs.existsSync(path.join(p, 'css/main.min.css')));
assert(vendorRoot, 'Set PETISH_THE7_ROOT to the native The7 source.');
const vendor = fs.readFileSync(path.join(vendorRoot, 'css/main.min.css'), 'utf8');
const themeCss = fs.readFileSync(path.join(theme, 'assets/css/petish.css'), 'utf8');
const css = fs.readFileSync(path.join(plugin, 'assets/pet-chat.css'), 'utf8');
const js = fs.readFileSync(path.join(plugin, 'assets/pet-chat.js'), 'utf8');
const renders = new Map();
const screenshotDir = process.env.PETISH_CHAT_SCREENSHOT_DIR;
if (screenshotDir) {
  assert(path.isAbsolute(screenshotDir) && (screenshotDir.startsWith('/tmp/') || screenshotDir.startsWith('/workspace/')), 'Use an owned local screenshot directory.');
  fs.mkdirSync(screenshotDir, { recursive: true, mode: 0o700 });
}
function render(scenario) {
  if (!renders.has(scenario)) renders.set(scenario, JSON.parse(cp.execFileSync(php, [path.join(__dirname, 'fixtures/pet-chat-render.php'), scenario], { encoding: 'utf8' })));
  return renders.get(scenario);
}
function documentHtml(scenario, mode, scripting = true) {
  const content = render(scenario);
  return '<!doctype html><html data-theme="' + mode + '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + vendor + '\n' + themeCss + '\nbody{margin:0}#main{padding:0;background:var(--color-bg)}\n' + css + '</style></head><body id="the7-body" class="petish-site"><div id="page"><div id="main">' + content.html + '</div></div>' + (scripting ? '<script>' + content.config + '</script><script>' + js + '</script>' : '') + '</body></html>';
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  const checks = {};
  function mark(name, passed, extra) { assert.equal(passed, true, name + (extra ? ' ' + JSON.stringify(extra) : '')); checks[name] = true; }
  const contexts = [];
  try {
    async function fixture(scenario = 'thread-member', options = {}) {
      const context = await browser.newContext({ viewport: { width: options.width || 1280, height: 900 }, javaScriptEnabled: options.scripting !== false, serviceWorkers: 'block', reducedMotion: 'reduce' });
      contexts.push(context);
      const page = await context.newPage();
      page.setDefaultTimeout(5000);
      const f = { page, context, requests: [], writes: [], blocked: [], responses: [], behavior: null, pending: null };
      if (options.scripting !== false) {
        await page.clock.install({ time: new Date('2026-10-24T12:00:00Z') });
        await page.clock.pauseAt(new Date('2026-10-24T12:00:01Z'));
        await page.addInitScript(() => {
          window.__chatInjected = false;
          window.__chatFetches = [];
          window.__chatAsync = 0;
          window.__privateAccount = { display_name: 'PRIVATE-ACCOUNT-NAME', email: 'private-account@example.invalid', phone: 'PRIVATE-PHONE', pet: 'PRIVATE-PET-NAME' };
          const fetch = window.fetch;
          window.fetch = function (url, init) {
            window.__chatFetches.push({ url: String(url), method: init?.method, cache: init?.cache, credentials: init?.credentials, body: String(init?.body || '') });
            window.__chatAsync++;
            return fetch.apply(this, arguments).then(response => {
              const json = response.json.bind(response);
              response.json = async function () { window.__chatAsync++; try { return await json(); } finally { window.__chatAsync--; } };
              return response;
            }).finally(() => { window.__chatAsync--; });
          };
          if (window.crypto?.subtle) {
            const digest = crypto.subtle.digest.bind(crypto.subtle);
            crypto.subtle.digest = function () { window.__chatAsync++; return digest(...arguments).finally(() => { window.__chatAsync--; }); };
          }
        });
      }
      await context.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) { f.blocked.push('foreign'); return route.abort('blockedbyclient'); }
        if (url.pathname === '/fixture/') return route.fulfill({ contentType: 'text/html', body: documentHtml(scenario, options.mode || 'light', options.scripting !== false) });
        if (url.pathname === '/wp-admin/admin-post.php') {
          f.writes.push({ method: request.method(), data: Object.fromEntries(new URLSearchParams(request.postData() || '')) });
          return route.fulfill({ contentType: 'text/html', body: '<!doctype html><p>Offline native form received.</p>' });
        }
        if (url.pathname === '/wp-admin/admin-ajax.php') {
          const data = Object.fromEntries(new URLSearchParams(request.postData() || ''));
          f.requests.push({ method: request.method(), data });
          const value = f.behavior ? await f.behavior(data, f.requests.length) : null;
          if (value?.hold) { f.pending = { route }; return; }
          const response = value || { success: true, data: await payload(page, scenario) };
          f.responses.push(response);
          return route.fulfill({ status: response.http || 200, contentType: 'application/json', body: response.raw || JSON.stringify(response) });
        }
        // Fonts and theme-only decorative assets never leave this local origin.
        return route.abort('blockedbyclient');
      });
      await page.goto(origin + '/fixture/');
      await page.evaluate(() => document.fonts.ready);
      return f;
    }
    async function payload(page, scenario, changes = {}) {
      const meta = await page.locator('[data-pet-chat]').evaluate(el => ({ context: el.dataset.chatContext, session: el.dataset.chatSession, signed_in: el.dataset.chatSignedIn === '1' }));
      const html = render(scenario).feed;
      return { schema: 1, ...meta, revision: crypto.createHash('sha256').update(html).digest('hex'), html, login_url: origin + '/wp-login.php', ...changes };
    }
    async function tick(f, milliseconds) {
      await f.page.clock.runFor(milliseconds);
      // Keep virtual timer jumps separate from genuine fetch/JSON/WebCrypto
      // completion. A fixed sleep here can accidentally jump over a response.
      for (let attempt = 0; attempt < 200; attempt++) {
        await new Promise(resolve => setTimeout(resolve, 5));
        if (await f.page.evaluate(() => window.__chatAsync === 0)) break;
        assert(attempt < 199, 'Offline asynchronous work must settle before the next clock jump.');
      }
      await f.page.evaluate(() => Promise.resolve());
    }
    async function close(f) { await f.context.unrouteAll({ behavior: 'ignoreErrors' }); await f.context.close(); contexts.splice(contexts.indexOf(f.context), 1); }

    // Real rendered UI, actual The7/theme CSS and real chat CSS at all breakpoints.
    for (const width of [320, 390, 900, 1280]) for (const mode of ['light', 'dark']) for (const scenario of ['listing-member', 'thread-member', 'listing-guest', 'thread-guest']) {
      const f = await fixture(scenario, { width, mode });
      if (screenshotDir && ((width === 1280 && mode === 'light' && scenario === 'listing-member') || (width === 390 && mode === 'dark' && scenario === 'thread-member'))) {
        const image = path.join(screenshotDir, width === 1280 ? 'pet-chat-desktop.png' : 'pet-chat-mobile.png');
        await f.page.screenshot({ path: image, fullPage: true, animations: 'disabled' });
        fs.chmodSync(image, 0o600);
      }
      const facts = await f.page.evaluate(() => {
        const main = document.querySelector('[data-pet-chat]');
        const visible = el => el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }) && !!(el.getBoundingClientRect().width && el.getBoundingClientRect().height);
        const controls = [...main.querySelectorAll('input:not([type=hidden]),select,textarea')];
        return {
          overflow: document.documentElement.scrollWidth > innerWidth,
          feed: main.querySelectorAll('[data-chat-feed-content]').length === 1,
          composeOutsideFeed: !main.querySelector('[data-chat-feed] [data-chat-composer]'),
          labels: controls.every(el => !!el.labels?.length && [...el.labels].some(label => label.textContent.trim())),
          controlsFit: controls.filter(visible).every(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth + 1; }),
          outsideControls: controls.filter(visible).map(el => { const r = el.getBoundingClientRect(); return { tag: el.tagName, name: el.name, left: r.left, right: r.right }; }).filter(r => r.left < 0 || r.right > innerWidth + 1),
          bodyColor: getComputedStyle(main).color,
          background: getComputedStyle(main.querySelector('.pch-topic,.pch-thread-topic'))?.backgroundColor,
          status: main.querySelector('[data-chat-status]')?.getAttribute('role') === 'status',
          privateContent: /PRIVATE-ACCOUNT-NAME|private-account@example.invalid|PRIVATE-PHONE|PRIVATE-PET-NAME/.test(main.outerHTML),
          nativeForms: [...main.querySelectorAll('form')].every(form => form.method === 'post' && new URL(form.getAttribute('action'), location.href).pathname === '/wp-admin/admin-post.php' && !!form.querySelector('[name=petish_chat_nonce]')),
        };
      });
      const key = width + '_' + mode + '_' + scenario;
      mark(key + '_fits', !facts.overflow && facts.controlsFit, facts);
      mark(key + '_accessible_public_surface', facts.feed && facts.composeOutsideFeed && facts.labels && facts.status && !facts.privateContent && facts.nativeForms);
      mark(key + '_theme_palette', facts.bodyColor === (mode === 'dark' ? 'rgb(247, 248, 252)' : 'rgb(20, 33, 61)') && facts.background === (mode === 'dark' ? 'rgb(22, 36, 59)' : 'rgb(255, 255, 255)'), facts);
      mark(key + '_membership_gate', (await f.page.locator('[data-chat-composer]').count()) === (scenario.endsWith('-guest') ? 0 : 1));
      if (!scenario.endsWith('-guest')) {
        await f.page.locator('#petish-chat-alias').focus();
        await f.page.keyboard.press('Tab');
        mark(key + '_native_tab_order', await f.page.locator(scenario.startsWith('listing') ? '#petish-chat-channel' : '#petish-chat-body').evaluate(el => el === document.activeElement));
      }
      if (width < 700) {
        const channels = f.page.locator('.pch-mobile-channels');
        await channels.locator('summary').focus(); await f.page.keyboard.press('Enter');
        mark(key + '_keyboard_channels', await channels.getAttribute('open') !== null && await channels.locator('nav a').first().isVisible());
      }
      await f.page.locator('[data-chat-poll-toggle]').focus();
      mark(key + '_visible_keyboard_focus', await f.page.locator('[data-chat-poll-toggle]').evaluate(el => document.activeElement === el && getComputedStyle(el).outlineStyle === 'solid' && parseFloat(getComputedStyle(el).outlineWidth) >= 3));
      if (scenario === 'thread-member') {
        const report = f.page.locator('.pch-report').first();
        await report.locator('summary').click();
        mark(key + '_open_report_fits', await report.locator('select').evaluate(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth + 1; }) && !await f.page.evaluate(() => document.documentElement.scrollWidth > innerWidth));
      }
      await close(f);
    }

    // Polling queues read-only updates; neither automatic refresh nor user choice
    // may destroy the separate native composer or an in-progress selection.
    const live = await fixture();
    const p = live.page;
    const body = p.locator('#petish-chat-body');
    await body.fill('A private unfinished draft: quotes " & <literal> 🐇');
    await body.focus();
    await body.evaluate(el => { window.__originalComposer = el; el.setSelectionRange(3, 12); });
    const beforePollTop = await body.evaluate(el => el.getBoundingClientRect().top);
    await p.evaluate(() => { window.__originalReply = document.querySelector('#chat-reply-202'); });
    const draft = await body.inputValue();
    live.behavior = async () => ({ success: true, data: await payload(p, 'thread-updated') });
    await tick(live, 15100);
    mark('thread_polls_after_15_seconds', live.requests.length === 1, live.requests);
    mark('poll_is_readonly_exact_context', live.requests.every(r => r.method === 'POST' && r.data.action === 'petish_chat_refresh' && Object.keys(r.data).sort().join(',') === 'action,channel,context,page,reply_page,topic,view' && r.data.topic === '101' && r.data.channel === 'general'));
    mark('fetch_is_same_origin_uncached', await p.evaluate(() => window.__chatFetches.every(r => r.method === 'POST' && r.cache === 'no-store' && r.credentials === 'same-origin')));
    mark('new_reply_announced_not_inserted', await p.locator('[data-chat-update]').isVisible() && await p.locator('#chat-reply-204').count() === 0 && /new updates/i.test(await p.locator('[data-chat-status]').innerText()));
    mark('automatic_poll_preserves_draft_selection_focus', await body.evaluate(el => el === window.__originalComposer && document.activeElement === el && el.selectionStart === 3 && el.selectionEnd === 12) && await body.inputValue() === draft);
    mark('new_update_does_not_jump_reader', Math.abs(await body.evaluate(el => el.getBoundingClientRect().top) - beforePollTop) < 2);
    await p.locator('[data-chat-update]').evaluate(el => el.click());
    mark('explicit_refresh_updates_feed_only', await p.locator('#chat-reply-204').count() === 1 && await body.inputValue() === draft && await body.evaluate(el => el === window.__originalComposer && document.activeElement === el && el.selectionStart === 3));
    mark('draft_not_persisted', await p.evaluate(draft => !JSON.stringify({ ...localStorage, ...sessionStorage }).includes(draft), draft));
    await p.locator('[data-chat-poll-toggle]').click();
    const pausedCount = live.requests.length;
    await tick(live, 125000);
    mark('explicit_pause_stops_polling', live.requests.length === pausedCount && await p.locator('[data-chat-poll-toggle]').getAttribute('aria-pressed') === 'true');
    await p.locator('[data-chat-poll-toggle]').click();
    await tick(live, 15100);
    mark('resume_restarts_polling', live.requests.length > pausedCount);
    await p.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); document.dispatchEvent(new Event('visibilitychange')); });
    const hiddenCount = live.requests.length;
    await tick(live, 125000);
    mark('platform_hidden_signal_stops_polling', live.requests.length === hiddenCount);
    await p.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => false }); document.dispatchEvent(new Event('visibilitychange')); });
    await tick(live, 15100);
    mark('foreground_resumes_polling', live.requests.length > hiddenCount);
    mark('no_mutation_during_refresh', live.writes.length === 0);
    await close(live);

    const nested = await fixture();
    await nested.page.locator('#petish-chat-body').fill('My reply draft remains intact');
    const replyLink = nested.page.locator('[data-chat-reply-to="202"]');
    mark('nested_reply_has_native_get_fallback', new URL(await replyLink.getAttribute('href'), origin).searchParams.get('chat_reply_to') === '202');
    await replyLink.click();
    mark('nested_reply_preserves_draft_and_selects_parent', await nested.page.locator('[name=parent_id]').inputValue() === '202' && await nested.page.locator('[data-chat-reply-target]').isVisible() && await nested.page.locator('#petish-chat-body').inputValue() === 'My reply draft remains intact' && await nested.page.locator('#petish-chat-body').evaluate(el => el === document.activeElement));
    await nested.page.locator('[data-chat-reply-cancel]').click();
    mark('nested_reply_can_return_to_topic', await nested.page.locator('[name=parent_id]').inputValue() === '0' && !await nested.page.locator('[data-chat-reply-target]').isVisible() && await nested.page.locator('#petish-chat-body').inputValue() === 'My reply draft remains intact');
    await close(nested);

    const listing = await fixture('listing-member');
    await tick(listing, 15000); mark('channel_not_polled_at_thread_interval', listing.requests.length === 0);
    await tick(listing, 15100); mark('channel_polled_at_30_seconds', listing.requests.length === 1);
    await close(listing);

    const backoff = await fixture();
    backoff.behavior = async () => ({ http: 503, success: false });
    await tick(backoff, 15100); mark('failed_refresh_retains_feed', backoff.requests.length === 1 && await backoff.page.locator('#chat-reply-202').count() === 1);
    await tick(backoff, 14900); mark('failure_does_not_storm', backoff.requests.length === 1);
    await tick(backoff, 15500); mark('failure_retries_with_backoff', backoff.requests.length === 2);
    await tick(backoff, 60000); mark('backoff_increases_again', backoff.requests.length === 3);
    await tick(backoff, 120000); mark('backoff_is_bounded_at_two_minutes', backoff.requests.length === 4);
    await close(backoff);

    async function malicious(page, html) { return { success: true, data: await payload(page, 'thread-updated', { html, revision: crypto.createHash('sha256').update(html).digest('hex') }) }; }
    const attacks = {
      malformed_json: () => ({ raw: '{not JSON' }),
      wrong_context: async page => ({ success: true, data: await payload(page, 'thread-updated', { context: '0'.repeat(64) }) }),
      wrong_schema: async page => ({ success: true, data: await payload(page, 'thread-updated', { schema: '1' }) }),
      wrong_revision: async page => ({ success: true, data: await payload(page, 'thread-updated', { revision: 'not-a-hash' }) }),
      foreign_login: async page => ({ success: true, data: await payload(page, 'thread-updated', { login_url: 'https://forbidden.invalid/login' }) }),
      digest_mismatch: async page => ({ success: true, data: await payload(page, 'thread-updated', { revision: 'c'.repeat(64) }) }),
      active_markup: page => malicious(page, '<div data-chat-feed-content><img src="https://forbidden.invalid/x" onerror="window.__chatInjected=true"><script>window.__chatInjected=true</script></div>'),
      inline_handler: page => malicious(page, '<div data-chat-feed-content><a href="/" onclick="window.__chatInjected=true">Unsafe link</a></div>'),
      script_protocol: page => malicious(page, '<div data-chat-feed-content><a href="javascript:window.__chatInjected=true">Unsafe link</a></div>'),
      composer_injection: page => malicious(page, '<div data-chat-feed-content><form data-chat-composer action="https://forbidden.invalid"><textarea>bad</textarea></form></div>'),
    };
    for (const [name, attack] of Object.entries(attacks)) {
      const f = await fixture();
      await f.page.locator('#petish-chat-body').fill('Retain my unsent draft');
      f.behavior = () => attack(f.page);
      await tick(f, 15100);
      mark(name + '_cannot_replace_or_execute', await f.page.locator('#chat-reply-202').count() === 1 && await f.page.locator('#chat-reply-204').count() === 0 && await f.page.locator('#petish-chat-body').inputValue() === 'Retain my unsent draft' && await f.page.evaluate(() => window.__chatInjected === false) && f.blocked.length === 0 && !await f.page.locator('[data-chat-update]').isVisible());
      await close(f);
    }

    // An unpublished topic can disappear from the public projection after an
    // account switch (404 rather than a successful JSON session-change reply).
    for (const terminal of ['missing_private_topic', 'bad_private_json', 'bad_private_digest', 'offline_retry_exhausted', 'bfcache_restore']) {
      const f = await fixture(terminal === 'missing_private_topic' ? 'thread-pending' : 'listing-private');
      const draft = f.page.locator('[data-chat-composer] textarea');
      const hasDraft = await draft.count() === 1;
      if (hasDraft) await draft.fill('Keep my unfinished draft despite terminal privacy cleanup');
      mark(terminal + '_starts_with_private_projection', await f.page.locator('[data-chat-private]').count() > 0);
      if (terminal === 'bfcache_restore') {
        await f.page.evaluate(() => { window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })); window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); });
      } else {
        f.behavior = async () => terminal === 'missing_private_topic' ? { http: 404, success: false }
          : terminal === 'bad_private_json' ? { raw: 'not JSON' }
          : terminal === 'bad_private_digest' ? { success: true, data: await payload(f.page, 'thread-member', { revision: 'c'.repeat(64) }) }
          : { http: 503, success: false };
        if (['offline_retry_exhausted', 'bad_private_json'].includes(terminal)) {
          for (const delay of [30100, 60100, 120100, 120100, 120100]) await tick(f, delay);
        } else await tick(f, terminal === 'missing_private_topic' ? 15100 : 30100);
      }
      mark(terminal + '_scrubs_private_and_stops_all_writes', await f.page.locator('[data-chat-private]').count() === 0 && await f.page.locator('form button[type=submit]').evaluateAll(buttons => buttons.every(button => button.disabled)) && !await f.page.locator('[data-chat-update]').isVisible(), { requests: f.requests.length, status: await f.page.locator('[data-chat-status]').innerText(), privateNodes: await f.page.locator('[data-chat-private]').count() });
      if (hasDraft) mark(terminal + '_retains_unsent_draft', await draft.inputValue() === 'Keep my unfinished draft despite terminal privacy cleanup');
      const count = f.requests.length; await tick(f, 125000);
      mark(terminal + '_requires_explicit_revalidation', f.requests.length === count && f.writes.length === 0);
      await close(f);
    }

    for (const changed of ['logout', 'other_member']) {
      const f = await fixture();
      await f.page.locator('#petish-chat-body').fill('Keep this draft when identity changes');
      f.behavior = async () => ({ success: true, data: await payload(f.page, 'thread-updated', changed === 'logout' ? { signed_in: false } : { session: 'b'.repeat(64) }) });
      await tick(f, 15100);
      const count = f.requests.length;
      await tick(f, 125000);
      mark(changed + '_stops_without_private_refresh', f.requests.length === count && await f.page.locator('#chat-reply-204').count() === 0 && await f.page.locator('#petish-chat-body').inputValue() === 'Keep this draft when identity changes' && !await f.page.locator('[data-chat-update]').isVisible());
      mark(changed + '_expired_form_cannot_send', await f.page.locator('[data-chat-composer] button[type=submit]').isDisabled());
      await close(f);
    }

    for (const scenario of ['listing-empty', 'listing-error', 'listing-escaped', 'thread-pending', 'thread-closed']) {
      const f = await fixture(scenario, { width: 320 });
      mark(scenario + '_safe_state_render', await f.page.locator('[data-pet-chat]').count() === 1 && !await f.page.evaluate(() => document.documentElement.scrollWidth > innerWidth || window.__chatInjected) && f.blocked.length === 0);
      if (scenario === 'listing-error') mark('server_error_retains_escaped_draft', await f.page.locator('[role=alert]').isVisible() && await f.page.locator('#petish-chat-body').inputValue() === 'A retained draft with quotes " and <literal> text.');
      if (scenario.startsWith('thread-')) mark(scenario + '_no_reply_form', await f.page.locator('[data-chat-composer]').count() === 0);
      await close(f);
    }

    // Native HTML forms still work when JavaScript is unavailable. Intercepting
    // the local form target records submitted fields; it never writes anything.
    for (const scenario of ['listing-member', 'thread-member']) {
      const f = await fixture(scenario, { scripting: false, width: 390 });
      await f.page.locator('#petish-chat-alias').fill('Chosen Offline Alias');
      if (scenario === 'listing-member') await f.page.locator('#petish-chat-title').fill('A native form question');
      await f.page.locator('#petish-chat-body').fill('A native form body, not an AJAX submission.');
      await f.page.locator('[name=consent]').check();
      const submit = f.page.locator('[data-chat-composer] button[type=submit]');
      await Promise.all([f.page.waitForURL('**/wp-admin/admin-post.php'), submit.click()]);
      const sent = f.writes[0];
      mark(scenario + '_nojs_native_post', f.writes.length === 1 && f.requests.length === 0 && sent.method === 'POST' && sent.data.action === (scenario === 'listing-member' ? 'petish_chat_create_topic' : 'petish_chat_create_reply') && sent.data.alias === 'Chosen Offline Alias' && sent.data.body === 'A native form body, not an AJAX submission.' && sent.data.consent === '1' && /^fixture-[a-f0-9]{64}$/.test(sent.data.petish_chat_nonce) && /^[a-f0-9-]{36}$/.test(sent.data.request_id) && (scenario !== 'thread-member' || sent.data.topic_id === '101'));
      await close(f);
    }
    console.log(JSON.stringify({ status: 'success', checks: Object.keys(checks).length, matrix: '320/390/900/1280 × light/dark × listing/thread × member/guest', offline: true, native_backend_test: 'tests/pet-chat-native.php' }));
  } finally {
    await Promise.allSettled(contexts.map(async context => { await context.unrouteAll({ behavior: 'ignoreErrors' }); await context.close(); }));
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
