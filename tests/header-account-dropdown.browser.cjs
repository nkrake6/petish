/* Offline browser proof for the actual header scripts/styles; no hosted requests. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const { chromium } = require('../tools/staging-rehearsal/node_modules/playwright');

const theme = path.join(__dirname, '../wordpress/wp-content/themes/dt-the7-child');
const read = name => fs.readFileSync(path.join(theme, name), 'utf8');
const nativeRoot=[process.env.PETISH_THE7_ROOT,path.resolve(__dirname,'../wordpress/wp-content/themes/dt-the7'),'/tmp/petish-the7-reference/dt-the7'].find(value=>value&&fs.existsSync(path.join(value,'css/main.min.css')));
assert(nativeRoot,'Set PETISH_THE7_ROOT to native The7 source for header verification.');
const nativeCss=fs.readFileSync(path.join(nativeRoot,'css/main.min.css'),'utf8');
const origin = 'https://header-fixture.petish.test';
const php = process.env.PETISH_PHP_EXECUTABLE || (fs.existsSync('/workspace/.petish-dev/bin/php') ? '/workspace/.petish-dev/bin/php' : 'php');
const widgetShell = cp.execFileSync(php,[path.join(__dirname,'account-dropdown.php'),'shell'],{encoding:'utf8'}).replaceAll('https://petish.example',origin);
const signedIn = () => ({ signed_in: true, initial: 'N', avatar: origin + '/wp-content/uploads/header-fixture.png', items: [
  ['dashboard', 'Dashboard', '/my-account/'], ['orders', 'Orders', '/my-account/orders/'],
  ['downloads', 'Downloads', '/my-account/downloads/'], ['edit-address', 'Addresses', '/my-account/edit-address/'],
  ['payment-methods', 'Payment methods', '/my-account/payment-methods/'], ['edit-account', 'Account details', '/my-account/edit-account/'],
  ['my-pets', 'My Pets', '/my-account/my-pets/'], ['for-my-pet', 'For my pet', '/my-account/for-my-pet/'],
  ['my-reviews', 'My reviews', '/my-account/?my-reviews=1'], ['customer-logout', 'Log out', '/my-account/customer-logout/?_wpnonce=synthetic-logout'],
].map(([key, label, url]) => ({ key, label, url: origin + url })) });
const guest = () => ({ signed_in: false, initial: '', avatar: '', items: [] });
const menu = cls => `<ul class="${cls}"><li><a href="/"><span class="menu-text">Home</span></a></li><li><a href="/shop/"><span class="menu-text">Shop</span></a></li><li><a href="/cart/"><span class="menu-text">Cart</span></a></li><li><a href="/my-account/my-pets/"><span class="menu-text">My Pets</span></a></li></ul>`;
// Resolve the native media.less left-logo/right-menu rules and the observed
// site settings (1119/767 switch points; desktop90/mobile60px header heights).
const fixtureCss = `html{font-size:16px}body{margin:0;font:16px/1.5 Arial,sans-serif;background:var(--color-bg);color:var(--color-text)}
.masthead .fixture-bar{display:flex;align-items:center;box-sizing:border-box;width:100%;gap:16px;padding:10px 24px;height:90px;min-height:90px}.branding{width:210px;flex:0 0 210px;font-weight:700}.main-nav{display:flex;align-items:center;flex:1;list-style:none;margin:0;padding:0;gap:12px}.main-nav>li{margin:0;padding:0;flex-shrink:0}.main-nav>li>a{display:flex;align-items:center;box-sizing:border-box;min-height:44px;padding:0 10px;text-decoration:none;color:inherit;line-height:24px}.mini-widgets{display:flex;align-items:center;gap:8px;flex-shrink:0}.native-search{display:flex;align-items:center;justify-content:center;width:16px;min-height:44px;padding:0;border:0;background:transparent;color:inherit}.fixture-mobile{display:none!important}.mobile-main-nav{list-style:none;margin:0;padding:0}.mobile-main-nav>li>a{display:block;min-height:44px;padding:10px 12px;box-sizing:border-box;text-decoration:none;color:inherit}.fixture-mobile-nav{display:none}#outside{margin:40px 24px;padding:10px}.fixture-sticky{margin:20px 24px;display:flex;justify-content:flex-end}.native-cart i{display:inline-flex;width:20px;min-width:20px}.wc-ico-cart{white-space:nowrap}
@media(max-width:1119px){.fixture-desktop{display:none!important}.masthead .fixture-mobile{display:flex!important;height:60px;min-height:60px;padding:0 16px;gap:0}.mobile-header-bar .mobile-mini-widgets{flex:1 1 0%;flex-flow:row wrap;order:1;justify-content:flex-end}.mobile-header-bar .mobile-branding{order:0;justify-content:flex-start;line-height:0}.mobile-branding img{display:block;height:auto;width:210px;max-width:100%}.mobile-branding>a{padding:0}.mobile-navigation{order:2;margin-left:4px}.dt-mobile-menu-icon{display:flex;align-items:center;justify-content:center;width:32px;min-width:32px;min-height:44px;padding:0;border:0}.mobile-mini-widgets>*{display:none}.mobile-mini-widgets .near-logo-first-switch{display:inline-flex}.fixture-mobile-nav{display:block;width:calc(100% - 32px);margin:16px;padding:8px;box-sizing:border-box;background:var(--color-surface)}}
@media(min-width:768px) and (max-width:1119px){.masthead .fixture-mobile{height:90px;min-height:90px}.masthead .mobile-mini-widgets .show-on-second-switch{display:none}}
@media(max-width:767px){.mobile-mini-widgets>*{display:none}.mobile-mini-widgets .near-logo-second-switch{display:inline-flex}.masthead .mobile-mini-widgets .show-on-first-switch{display:none}}`;
const visibility='show-on-desktop near-logo-first-switch near-logo-second-switch';
const widgetChildren=signature=>`<div class="mini-search ${visibility} ${signature}"><button class="native-search" type="button" aria-label="Search">⌕</button></div><div class="petish-theme-switch-wrapper ${visibility} ${signature}" data-petish-theme-switch><button class="petish-theme-switch" type="button" aria-label="Dark mode"><svg width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="7"/></svg></button></div><div class="petish-market-selector ${visibility} ${signature}" data-petish-market><button class="petish-market__trigger" type="button"><svg class="petish-market__flag" width="22" height="22" aria-hidden="true"></svg><span class="petish-market__code">AUD</span><svg width="12" height="12" aria-hidden="true"></svg></button></div><div class="native-cart ${visibility} ${signature}"><div class="wc-shopping-cart"><a class="wc-ico-cart" href="/cart/" aria-label="Cart"><i aria-hidden="true">▣</i><span class="amount">$0.00</span><span class="counter">0</span></a></div></div>${widgetShell.replace('class="','class="'+signature+' ')}`;
const widgets=mobile=>`<div class="mini-widgets ${mobile?'mobile-mini-widgets':''}">${mobile?widgetChildren('show-on-first-switch')+widgetChildren('show-on-second-switch'):widgetChildren('')}</div>`;
const logoImage='<img class="petish-logo" width="1942" height="809" alt="Petish" src="data:image/png;base64,'+fs.readFileSync(path.join(theme,'assets/brand/repaired/lettering-light-v2.png')).toString('base64')+'">';
function documentHtml() {
  const accountConfig = { endpoint: origin + '/wp-admin/admin-ajax.php', action: 'petish_account_dropdown', accountUrl: origin + '/my-account/', openLabel: 'Open account navigation', closeLabel: 'Close account navigation' };
  return `<!doctype html><html data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${nativeCss}\n${read('assets/css/petish.css')}\n${fixtureCss}\n${read('assets/css/market-selector.css')}\n${read('assets/css/cart-badge.css')}\n${read('assets/css/account-dropdown.css')}</style></head><body id="the7-body" class="petish-site"><header class="masthead inline-header first-switch-logo-left first-switch-menu-right second-switch-logo-left second-switch-menu-right"><div class="fixture-bar fixture-desktop header-bar"><div class="branding">${logoImage}</div>${menu('main-nav')}${widgets(false)}</div><div class="fixture-bar fixture-mobile mobile-header-bar"><div class="mobile-branding"><a href="#">${logoImage}</a></div>${widgets(true)}<div class="mobile-navigation"><button class="dt-mobile-menu-icon" type="button" aria-label="Menu">☰</button></div></div></header><aside class="fixture-mobile-nav">${menu('mobile-main-nav')}</aside><button id="outside">Outside account navigation</button><div class="fixture-sticky"></div><script>window.petishAccountDropdown=${JSON.stringify(accountConfig)};window.petishCartBadge={endpoint:'${origin}/?wc-ajax=petish_cart_count',cartUrl:'${origin}/cart/'};window.jQuery=function(){return{on:function(){return this}}};</script><script>${read('assets/js/cart-badge.js')}</script><script>${read('assets/js/account-dropdown.js')}</script></body></html>`;
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  const checks = {}; const mark = (name, value, details) => { assert.equal(value, true, name+(details?' '+JSON.stringify(details):'')); checks[name] = true;if(process.env.PETISH_HEADER_DEBUG==='1')console.log(name); };
  try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, serviceWorkers: 'block' });
    context.setDefaultTimeout(10000);
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
    const root = '.fixture-desktop .mini-widgets > [data-petish-account]';
    const button = page.locator(root + ' > [data-petish-account-toggle]');
    const panel = page.locator(root + ' > [data-petish-account-panel]');
    await page.goto(origin + '/fixture/');
    await page.waitForFunction(selector => { const e = document.querySelector(selector); return e && !e.hidden; }, root + ' > [data-petish-account-toggle]');
    await page.waitForFunction(() => document.querySelector('.fixture-desktop .petish-cart-badge')?.dataset.count === '3');
    mark('account_is_final_header_widget',await page.locator(root).evaluate(el=>el.parentElement.lastElementChild===el));
    mark('account_absent_from_main_navigation',await page.locator('.main-nav > li > a[href="/my-account/"]').count()===0);
    mark('account_after_search_theme_and_currency',await page.locator(root).evaluate(el=>{const siblings=[...el.parentElement.children];return siblings.findIndex(e=>e.matches('.mini-search'))<siblings.findIndex(e=>e.matches('[data-petish-theme-switch]'))&&siblings.findIndex(e=>e.matches('[data-petish-theme-switch]'))<siblings.findIndex(e=>e.matches('[data-petish-market]'))&&siblings.findIndex(e=>e.matches('[data-petish-market]'))<siblings.indexOf(el)}));
    mark('native_direct_link_retained', (await page.locator(root + ' > a').getAttribute('href')) === origin + '/my-account/');
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
    mark('cart_badge_coexists', await page.locator('.fixture-desktop a[href="/cart/"] .petish-cart-badge__number').evaluateAll(nodes=>nodes.length===2&&nodes.every(node=>node.textContent==='3')));
    mark('cart_circle_size_preserved', await page.locator('.fixture-desktop .petish-cart-badge').evaluateAll(nodes=>nodes.length===2&&nodes.every(el => el.getBoundingClientRect().width === 14 && el.getBoundingClientRect().height === 14)));
    mark('header_height_unchanged_while_open', Math.abs((await page.locator('.fixture-desktop').boundingBox()).height - beforeHeight) < 1);
    mark('desktop_no_horizontal_overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await button.focus();
    await page.locator(root).evaluate(node=>{window.__originalAccountWidget=node;node.parentElement.appendChild(node.cloneNode(true));});
    await page.waitForFunction(()=>document.querySelectorAll('.fixture-desktop .mini-widgets > [data-petish-account]').length===1);
    mark('same_group_clone_does_not_duplicate_control',await page.locator(root).evaluate(node=>node===window.__originalAccountWidget&&node.contains(document.activeElement)));
    await page.locator(root).evaluate(node=>{const late=document.createElement('span');late.dataset.lateNativeWidget='';node.parentElement.appendChild(late);});
    await page.waitForFunction(()=>document.querySelector('.fixture-desktop .mini-widgets').lastElementChild.matches('[data-petish-account]'));
    mark('account_remains_last_after_late_native_widget',await page.locator(root).evaluate(node=>node.parentElement.lastElementChild===node));
    await page.locator('[data-late-native-widget]').evaluate(node=>node.remove());
    await page.evaluate(() => { const widgets = document.querySelector('.fixture-desktop .mini-widgets').cloneNode(true); document.querySelector('.fixture-sticky').appendChild(widgets); });
    await page.waitForFunction(() => { const ids = [...document.querySelectorAll('[data-petish-account-panel]')].map(e => e.id); return ids.length === 4 && new Set(ids).size === 4; });
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
    mobile.setDefaultTimeout(10000);
    model = signedIn(); failed = false;
    await mobile.route('**/*', routeHandler);
    const touch = await mobile.newPage(); await touch.goto(origin + '/fixture/');
    const mobileRoot = '.fixture-mobile .mobile-mini-widgets > [data-petish-account].show-on-second-switch';
    await touch.waitForFunction(selector => document.querySelector(selector)?.hidden === false, mobileRoot + ' > [data-petish-account-toggle]');
    await touch.evaluate(()=>{window.__widgetMutations=0;new MutationObserver(records=>window.__widgetMutations+=records.length).observe(document.querySelector('.fixture-mobile .mobile-mini-widgets'),{childList:true,subtree:true});});
    for(const width of [320,360,390,768]){
      await touch.setViewportSize({width,height:844});
      const geometry=await touch.evaluate(()=>{
        const bar=document.querySelector('.fixture-mobile'),rect=node=>{const r=node.getBoundingClientRect();return{left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height}};
        const selectors=['.mini-search','[data-petish-theme-switch]','[data-petish-market]','.native-cart','[data-petish-account]','.mobile-navigation'];
        const visible=node=>node.getBoundingClientRect().width>0&&getComputedStyle(node).display!=='none';
        return {width:innerWidth,mutations:window.__widgetMutations||0,accountVariants:bar.querySelectorAll('[data-petish-account]').length,visibleAccounts:[...bar.querySelectorAll('[data-petish-account]')].filter(visible).length,scroll:document.documentElement.scrollWidth,header:rect(bar),logo:rect(bar.querySelector('.petish-logo')),
          controls:selectors.map(selector=>rect([...bar.querySelectorAll(selector)].find(visible)))};
      });
      if(process.env.PETISH_HEADER_DEBUG==='1')console.log('native_geometry '+JSON.stringify(geometry));
      mark('native_header_'+width+'_height_preserved',geometry.header.height<=(width<=767?60:90)&&geometry.controls.every(box=>box.top>=geometry.header.top-1&&box.bottom<=geometry.header.bottom+1),geometry);
      mark('native_header_'+width+'_controls_fit',geometry.scroll<=width&&geometry.controls.every((box,index)=>box.width>0&&box.left>=-1&&box.right<=width+1&&(index===0||box.left>=geometry.controls[index-1].right-1)),geometry);
      mark('native_header_'+width+'_logo_readable',geometry.logo.width>=(width===320?90:120),geometry);
      mark('native_header_'+width+'_breakpoint_variants_retained',geometry.accountVariants===2&&geometry.visibleAccounts===1,geometry);
      mark('native_header_'+width+'_mutations_bounded',geometry.mutations<100,geometry);
    }
    await touch.setViewportSize({width:390,height:844});
    const mobileHeight = (await touch.locator('.fixture-mobile.mobile-header-bar').boundingBox()).height;
    await touch.locator(mobileRoot + ' > [data-petish-account-toggle]').tap();
    mark('touch_caret_opens', await touch.locator(mobileRoot + ' > [data-petish-account-panel]').isVisible());
    mark('mobile_no_horizontal_overflow', await touch.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    mark('mobile_header_height_preserved', Math.abs((await touch.locator('.fixture-mobile.mobile-header-bar').boundingBox()).height - mobileHeight) < 1);
    await touch.locator('.mobile-branding').tap();
    mark('touch_outside_closes', await touch.locator(mobileRoot + ' > [data-petish-account-panel]').evaluate(el => el.hidden));
    mark('no_external_or_order_requests', forbidden.length === 0);
    mark('all_account_calls_are_fixed_read_posts', accountRequests.every(r => r.method === 'POST' && r.body === 'action=petish_account_dropdown'));
    await mobile.close();
    console.log(JSON.stringify({ status: 'success', offline_only: true, checks }));
  } finally { await browser.close(); }
})().catch(error => { console.error('Offline account-dropdown browser proof failed: ' + error.message); process.exitCode = 1; });
