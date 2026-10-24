/* Offline browser proof for the actual header scripts/styles; no hosted requests. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../tools/staging-rehearsal/node_modules/playwright');

const theme = path.join(__dirname, '../wordpress/wp-content/themes/dt-the7-child');
const read = name => fs.readFileSync(path.join(theme, name), 'utf8');
const origin = 'https://header-fixture.petish.test';
const signedIn = () => ({ signed_in: true, initial: 'N', avatar: origin + '/wp-content/uploads/header-fixture.png', items: [
  ['dashboard', 'Dashboard', '/my-account/'], ['orders', 'Orders', '/my-account/orders/'],
  ['downloads', 'Downloads', '/my-account/downloads/'], ['edit-address', 'Addresses', '/my-account/edit-address/'],
  ['payment-methods', 'Payment methods', '/my-account/payment-methods/'], ['edit-account', 'Account details', '/my-account/edit-account/'],
  ['my-pets', 'My Pets', '/my-account/my-pets/'], ['for-my-pet', 'For my pet', '/my-account/for-my-pet/'],
  ['my-reviews', 'My reviews', '/my-account/?my-reviews=1'], ['customer-logout', 'Log out', '/my-account/customer-logout/?_wpnonce=synthetic-logout'],
].map(([key, label, url]) => ({ key, label, url: origin + url })) });
const guest = () => ({ signed_in: false, initial: '', avatar: '', items: [] });
const menu = cls => `<ul class="${cls}"><li><a href="/"><span class="menu-text">Home</span></a></li><li><a href="/shop/"><span class="menu-text">Shop</span></a></li><li class="petish-account-menu"><a href="/my-account/"><span class="menu-text">My account</span></a></li><li><a href="/cart/"><span class="menu-text">Cart</span></a></li><li><a href="/my-account/my-pets/"><span class="menu-text">My Pets</span></a></li></ul>`;
const fixtureCss = `html{font-size:16px}body{margin:0;font:16px/1.5 Arial,sans-serif;background:var(--color-bg);color:var(--color-text)}
.fixture-bar{display:flex;align-items:center;gap:24px;padding:10px 24px}.branding{width:140px;flex-shrink:0;font-weight:700}.main-nav{display:flex;align-items:center;flex:1;list-style:none;margin:0;padding:0;gap:12px}.main-nav>li{margin:0;padding:0;flex-shrink:0}.main-nav>li>a{display:flex;align-items:center;box-sizing:border-box;min-height:44px;padding:0 10px;text-decoration:none;color:inherit;line-height:24px}.mobile-main-nav{list-style:none;margin:0;padding:0}.mobile-main-nav>li{margin:0;padding:0}.mobile-main-nav>li>a{display:block;min-height:44px;padding:10px 12px;box-sizing:border-box;text-decoration:none;color:inherit}.fixture-mobile{display:none}.fixture-mobile-nav{display:none}#outside{margin:40px 24px;padding:10px}.fixture-sticky{margin-top:20px}.fixture-sticky .main-nav{padding:10px 24px}
@media(max-width:991px){.fixture-desktop{display:none}.fixture-mobile{display:flex}.fixture-mobile-nav{display:block;width:calc(100% - 32px);margin:16px;padding:8px;box-sizing:border-box;background:var(--color-surface)}}`;
function documentHtml() {
  const accountConfig = { endpoint: origin + '/wp-admin/admin-ajax.php', action: 'petish_account_dropdown', accountUrl: origin + '/my-account/', openLabel: 'Open account navigation', closeLabel: 'Close account navigation' };
  return `<!doctype html><html data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${read('assets/css/petish.css')}\n${fixtureCss}\n${read('assets/css/cart-badge.css')}\n${read('assets/css/account-dropdown.css')}</style></head><body id="the7-body" class="petish-site"><header class="masthead inline-header"><div class="fixture-bar fixture-desktop header-bar"><div class="branding">Petish</div>${menu('main-nav')}<div class="mini-widgets">AUD</div></div><div class="fixture-bar fixture-mobile mobile-header-bar"><div class="branding">Petish</div><button type="button">Menu</button></div></header><aside class="fixture-mobile-nav">${menu('mobile-main-nav')}</aside><button id="outside">Outside account navigation</button><div class="fixture-sticky"></div><script>window.petishAccountDropdown=${JSON.stringify(accountConfig)};window.petishCartBadge={endpoint:'${origin}/?wc-ajax=petish_cart_count',cartUrl:'${origin}/cart/'};window.jQuery=function(){return{on:function(){return this}}};</script><script>${read('assets/js/cart-badge.js')}</script><script>${read('assets/js/account-dropdown.js')}</script></body></html>`;
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  const checks = {}; const mark = (name, value) => { assert.equal(value, true, name); checks[name] = true; };
  try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, serviceWorkers: 'block' });
    let model = signedIn(), failed = false;
    const accountRequests = [], forbidden = [];
    await context.addInitScript(() => {
      window.__headerFetches = [];
      const original = window.fetch;
      window.fetch = function (input, options) {
        if (String(input).includes('/admin-ajax.php')) window.__headerFetches.push({ method: options.method, cache: options.cache, credentials: options.credentials, body: String(options.body) });
        return original.apply(this, arguments);
      };
    });
    async function routeHandler(route) {
      const req = route.request(), url = new URL(req.url());
      if (url.origin !== origin) { forbidden.push(req.url()); return route.abort('blockedbyclient'); }
      if (url.pathname === '/fixture/') return route.fulfill({ status: 200, contentType: 'text/html', body: documentHtml() });
      if (url.pathname === '/wp-admin/admin-ajax.php') {
        accountRequests.push({ method: req.method(), body: req.postData() });
        return route.fulfill({ status: failed ? 503 : 200, contentType: 'application/json', body: JSON.stringify({ success: !failed, data: model }) });
      }
      if (url.searchParams.get('wc-ajax') === 'petish_cart_count') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { count: 3, label: '3 items in cart' } }) });
      if (url.pathname === '/wp-content/uploads/header-fixture.png') return route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1ioAAAAASUVORK5CYII=', 'base64') });
      forbidden.push(req.url()); return route.abort('blockedbyclient');
    }
    await context.route('**/*', routeHandler);
    const page = await context.newPage();
    async function restorePage() {
      const responseReady = page.waitForResponse(r => new URL(r.url()).pathname === '/wp-admin/admin-ajax.php');
      await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
      await (await responseReady).finished();
      // Wait for the received JSON and DOM paint, rather than accepting the
      // deliberately cleared pre-response state as a successful guest/error case.
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    }
    const root = '.fixture-desktop .main-nav > .petish-account-menu';
    const button = page.locator(root + ' > [data-petish-account-toggle]');
    const panel = page.locator(root + ' > [data-petish-account-panel]');
    await page.goto(origin + '/fixture/');
    await page.waitForFunction(selector => { const e = document.querySelector(selector); return e && !e.hidden; }, root + ' > [data-petish-account-toggle]');
    await page.waitForFunction(() => document.querySelector('.fixture-desktop .petish-cart-badge')?.dataset.count === '3');
    mark('native_account_branch_last', await page.locator('.fixture-desktop .main-nav > li:last-child').evaluate(el => el.classList.contains('petish-account-menu')));
    mark('native_direct_link_retained', (await page.locator(root + ' > a').getAttribute('href')) === '/my-account/');
    mark('all_native_links_hydrated', await panel.locator('a').count() === 10);
    mark('reviews_query_url_retained', (await panel.locator('a').filter({ hasText: 'My reviews' }).getAttribute('href')) === origin + '/my-account/?my-reviews=1');
    const beforeHeight = (await page.locator('.fixture-desktop').boundingBox()).height;
    mark('navigation_font_enlarged', await page.locator('.fixture-desktop .main-nav > li:first-child .menu-text').evaluate(el => parseFloat(getComputedStyle(el).fontSize) >= 16));
    const privateFetches = await page.evaluate(() => window.__headerFetches);
    mark('private_read_only_post', privateFetches.every(r => r.method === 'POST' && r.cache === 'no-store' && r.credentials === 'same-origin' && r.body === 'action=petish_account_dropdown'));
    await button.focus(); await button.press('ArrowDown');
    mark('keyboard_opens_first_link', await panel.locator('a').first().evaluate(el => el === document.activeElement));
    await page.keyboard.press('Escape');
    mark('escape_closes_and_returns_focus', await button.evaluate(el => el === document.activeElement && el.getAttribute('aria-expanded') === 'false'));
    await button.press('ArrowUp');
    mark('keyboard_opens_last_link', await panel.locator('a').last().evaluate(el => el === document.activeElement));
    await page.locator('#outside').focus();
    mark('focus_outside_closes', await panel.evaluate(el => el.hidden));
    await page.locator(root + ' > a').focus(); await page.keyboard.press('Tab');
    mark('native_tab_reaches_toggle', await button.evaluate(el => el === document.activeElement));
    await page.locator('#outside').focus();
    await page.locator(root).hover();
    await page.waitForFunction(selector => document.querySelector(selector)?.getAttribute('aria-expanded') === 'true', root + ' > [data-petish-account-toggle]');
    mark('desktop_hover_opens', await panel.isVisible());
    await page.mouse.move(20, 300);
    await page.waitForFunction(selector => document.querySelector(selector)?.getAttribute('aria-expanded') === 'false', root + ' > [data-petish-account-toggle]');
    mark('desktop_hover_exit_closes', await panel.evaluate(el => el.hidden));
    await page.locator(root).hover();
    await page.waitForFunction(selector => document.querySelector(selector)?.getAttribute('aria-expanded') === 'true', root + ' > [data-petish-account-toggle]');
    await button.click();
    mark('caret_click_closes_hover_open_panel', await button.getAttribute('aria-expanded') === 'false');
    await button.click();
    mark('caret_click_reopens_panel', await button.getAttribute('aria-expanded') === 'true');
    await page.locator('#outside').click();
    mark('outside_click_closes', await panel.evaluate(el => el.hidden));
    await page.evaluate(selector => {
      const anchor = document.querySelector(selector);
      document.addEventListener('click', function listener(e) { if (anchor.contains(e.target)) { window.__nativeAccountDefault = !e.defaultPrevented; e.preventDefault(); document.removeEventListener('click', listener); } });
    }, root + ' > a');
    await page.locator(root + ' > a').click();
    mark('account_link_default_navigation_unmodified', await page.evaluate(() => window.__nativeAccountDefault === true));
    mark('cart_badge_coexists', await page.locator('.fixture-desktop a[href="/cart/"] .petish-cart-badge__number').innerText() === '3');
    mark('cart_circle_size_preserved', await page.locator('.fixture-desktop .petish-cart-badge').evaluate(el => el.getBoundingClientRect().width === 14 && el.getBoundingClientRect().height === 14));
    mark('header_height_unchanged_while_open', Math.abs((await page.locator('.fixture-desktop').boundingBox()).height - beforeHeight) < 1);
    mark('desktop_no_horizontal_overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.evaluate(() => { const nav = document.querySelector('.fixture-desktop .main-nav').cloneNode(true); document.querySelector('.fixture-sticky').appendChild(nav); });
    await page.waitForFunction(() => { const ids = [...document.querySelectorAll('[data-petish-account-panel]')].map(e => e.id); return ids.length === 3 && new Set(ids).size === 3; });
    mark('sticky_clone_ids_unique', await page.evaluate(() => [...document.querySelectorAll('[data-petish-account-toggle]')].every(el => el.parentElement.querySelector('[data-petish-account-panel]').id === el.getAttribute('aria-controls'))));
    const stickyButton = page.locator('.fixture-sticky [data-petish-account-toggle]');
    await stickyButton.focus(); await stickyButton.press('ArrowDown');
    mark('sticky_clone_keyboard_works', await page.locator('.fixture-sticky [data-petish-account-panel] a').first().evaluate(el => el === document.activeElement));
    await page.keyboard.press('Escape');
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
    mark('bfcache_snapshot_clears_private_dom', await page.evaluate(() => [...document.querySelectorAll('[data-petish-account-panel]')].every(e => e.children.length === 0 && e.hidden) && [...document.querySelectorAll('.petish-account-avatar')].every(e => !e.querySelector('img') && !e.textContent.trim())));
    model = guest();
    await restorePage();
    await page.waitForFunction(selector => document.querySelector(selector)?.hidden === true, root + ' > [data-petish-account-toggle]');
    mark('restored_guest_has_no_private_links', await panel.locator('a').count() === 0);
    mark('guest_account_link_still_usable', await page.locator(root + ' > a').isVisible());
    model = signedIn(); model.avatar = 'https://remote-avatar.invalid/photo.png';
    await restorePage();
    await page.waitForFunction(selector => document.querySelector(selector)?.hidden === true, root + ' > [data-petish-account-toggle]');
    mark('remote_avatar_rejected', await panel.locator('a').count() === 0);
    model = signedIn(); model.items[0].label = '<img src=x onerror="window.__headerAttack=true">';
    await restorePage();
    await page.waitForFunction(selector => document.querySelector(selector)?.hidden === false, root + ' > [data-petish-account-toggle]');
    mark('labels_are_text_not_markup', await panel.locator('img').count() === 0 && await page.evaluate(() => window.__headerAttack !== true));
    failed = true;
    await restorePage();
    await page.waitForFunction(selector => document.querySelector(selector)?.hidden === true, root + ' > [data-petish-account-toggle]');
    mark('failed_private_fragment_leaves_native_link', await panel.locator('a').count() === 0 && await page.locator(root + ' > a').isVisible());
    mark('private_data_not_persisted', await page.evaluate(() => !JSON.stringify({ ...localStorage, ...sessionStorage }).includes('synthetic-logout')));
    await context.close();

    const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, serviceWorkers: 'block' });
    model = signedIn(); failed = false;
    await mobile.route('**/*', routeHandler);
    const touch = await mobile.newPage(); await touch.goto(origin + '/fixture/');
    const mobileRoot = '.mobile-main-nav > .petish-account-menu';
    await touch.waitForFunction(selector => document.querySelector(selector)?.hidden === false, mobileRoot + ' > [data-petish-account-toggle]');
    const mobileHeight = (await touch.locator('.fixture-mobile.mobile-header-bar').boundingBox()).height;
    await touch.locator(mobileRoot + ' > [data-petish-account-toggle]').tap();
    mark('touch_caret_opens', await touch.locator(mobileRoot + ' > [data-petish-account-panel]').isVisible());
    mark('mobile_no_horizontal_overflow', await touch.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    mark('mobile_header_height_preserved', Math.abs((await touch.locator('.fixture-mobile.mobile-header-bar').boundingBox()).height - mobileHeight) < 1);
    await touch.locator('#outside').tap();
    mark('touch_outside_closes', await touch.locator(mobileRoot + ' > [data-petish-account-panel]').evaluate(el => el.hidden));
    mark('no_external_or_order_requests', forbidden.length === 0);
    mark('all_account_calls_are_fixed_read_posts', accountRequests.every(r => r.method === 'POST' && r.body === 'action=petish_account_dropdown'));
    await mobile.close();
    console.log(JSON.stringify({ status: 'success', offline_only: true, checks }));
  } finally { await browser.close(); }
})().catch(error => { console.error('Offline account-dropdown browser proof failed: ' + error.message); process.exitCode = 1; });
