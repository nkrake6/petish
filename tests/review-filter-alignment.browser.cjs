/* Offline native The7/Passport cascade proof. No storefront requests or account state. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../tools/staging-rehearsal/node_modules/playwright');

const repo = path.resolve(__dirname, '..');
const child = path.join(repo, 'wordpress/wp-content/themes/dt-the7-child');
const passport = path.join(repo, 'wordpress/wp-content/plugins/petish-passport');
const vendorRoot = [process.env.PETISH_THE7_ROOT,
  path.join(repo, 'wordpress/wp-content/themes/dt-the7'),
  '/tmp/petish-the7-reference/dt-the7',
].find(value => value && fs.existsSync(path.join(value, 'css/main.min.css')));
assert.ok(vendorRoot, 'Set PETISH_THE7_ROOT to native The7 source for the control cascade fixture.');
const nativeCss = fs.readFileSync(path.join(vendorRoot, 'css/main.min.css'), 'utf8');
assert.match(nativeCss, /select:not\(\.elementor-field\):not\(\.elementor-field-textual\)[^{]*\{[^}]*margin-bottom:10px/,
  'the actual native The7 select bottom margin is present');
const themeCss = fs.readFileSync(path.join(child, 'assets/css/petish.css'), 'utf8');
const reset = /\.petish-site\s+\.pcr\s+\.pcr-filters\s+select\s*\{\s*margin-bottom\s*:\s*0(?:px)?\s*;?\s*\}/g;
const resets = themeCss.match(reset);
assert.equal(resets?.length, 1, 'one scoped theme compatibility reset');
const baselineCss = themeCss.replace(reset, '');
const passportCss = fs.readFileSync(path.join(passport, 'assets/customer-reviews.css'), 'utf8');
const template = fs.readFileSync(path.join(passport, 'templates/customer-reviews-product.php'), 'utf8');
const formTemplate = template.match(/<form class="pcr-filters"[\s\S]*?<\/form>/)?.[0];
assert.ok(formTemplate, 'use the actual native review filter form, including its PHP options and GET names');
const php = process.env.PETISH_PHP || (fs.existsSync('/tmp/petish-php-bin/php') ? '/tmp/petish-php-bin/php' : 'php');
const fixtureUrl = 'https://fixture.petish.test/product/native-review-filter/';
const form = execFileSync(php, [], { encoding: 'utf8', input: `<?php
function esc_html_e($value, $domain = '') { echo htmlspecialchars($value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function __($value, $domain = '') { return $value; }
function _n($one, $many, $number, $domain = '') { return $number === 1 ? $one : $many; }
function selected($actual, $expected) { if ((string) $actual === (string) $expected) echo ' selected="selected"'; }
function checked($actual, $expected = true) { if ($actual === $expected) echo ' checked="checked"'; }
$filters = array('rating' => 0, 'media' => 'all', 'sort' => 'newest', 'verified' => false);
$filter_url = '${fixtureUrl}';
?>${formTemplate}` });

function html(theme, appearance) {
  // Enqueue order is native The7 -> petish-design -> dependent customer reviews.
  return `<!doctype html><html data-theme="${appearance}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${nativeCss}\n${theme}\n${passportCss}\n
  body{margin:0;padding:24px}.fixture-container{width:100%;max-width:1080px;margin:auto}.pcr-product{margin:0}
  </style></head><body id="the7-body" class="petish-site single-product woocommerce woocommerce-page"><main id="main"><div class="fixture-container"><section id="petish-customer-reviews" class="pcr pcr-product">${form}</section><form class="fixture-unrelated"><label>Other theme select<select><option>Unchanged native control</option></select></label></form></div></main></body></html>`;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  const results = [];
  try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const navigationRequests = [];
    await context.route('**/*', async route => {
      // Form navigation is fulfilled locally; all vendor fonts/images are denied.
      const request = route.request();
      if (request.isNavigationRequest() && request.url().startsWith(fixtureUrl)) {
        navigationRequests.push({ url: request.url(), method: request.method(), body: request.postData() });
        return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><p>Offline GET target</p>' });
      }
      return route.abort();
    });
    const page = await context.newPage();
    const geometry = () => page.locator('.pcr-filters').evaluate(node => ({
      viewport: innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      formWidth: node.getBoundingClientRect().width,
      method: node.method,
      action: node.action,
      controls: [...node.querySelectorAll('select,.pcr-check,.pcr-button')].map(control => {
        const box = control.getBoundingClientRect();
        const style = getComputedStyle(control);
        return { name: control.name || control.className, top: box.top, bottom: box.bottom,
          width: box.width, height: box.height, marginTop: parseFloat(style.marginTop), marginBottom: parseFloat(style.marginBottom) };
      }),
      unrelatedMarginBottom: getComputedStyle(document.querySelector('.fixture-unrelated select')).marginBottom,
    }));

    for (const appearance of ['light', 'dark']) {
      for (const width of [320, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 800 });
        await page.setContent(html(baselineCss, appearance));
        const before = await geometry();
        assert.ok(before.controls.slice(0, 3).every(item => item.marginBottom === 10), 'native theme reproduces the 10px select bottom margin');
        if (width >= 768) {
          for (const select of before.controls.slice(0, 3)) {
            assert.ok(Math.abs(before.controls.at(-1).top - select.top - 10) < 0.1, 'baseline Apply filters sits 10px lower than the native selects');
          }
        }
        await page.setContent(html(themeCss, appearance));
        const after = await geometry();
        assert.ok(after.controls.every(item => Math.abs(item.height - 44) < 0.1), 'all native filter control rows remain 44px');
        assert.ok(after.controls.slice(0, 3).every(item => item.marginBottom === 0 && item.marginTop === 5), 'scoped reset removes only bottom margin; label gap stays 5px');
        assert.equal(after.unrelatedMarginBottom, '10px', 'unrelated native theme select margin stays unchanged');
        assert.ok(after.documentWidth <= width, 'native mobile wrapping has no horizontal overflow');
        assert.equal(after.method, 'get');
        assert.equal(after.action, `${fixtureUrl}#petish-customer-reviews`);
        if (width >= 768) {
          const tops = after.controls.map(item => item.top);
          const bottoms = after.controls.map(item => item.bottom);
          assert.ok(Math.max(...tops) - Math.min(...tops) < 0.1, 'desktop select, checkbox row, and Apply filters tops align');
          assert.ok(Math.max(...bottoms) - Math.min(...bottoms) < 0.1, 'desktop select, checkbox row, and Apply filters bottoms align');
        } else {
          assert.ok(Math.abs(after.controls.at(-1).width - after.formWidth) < 0.1, 'native mobile submit button remains full width');
          assert.ok(after.controls.at(-1).top >= after.controls.at(-2).bottom, 'mobile checkbox row and submit stay separate and usable');
        }
        results.push({ appearance, width, baselineSelectMarginBottom: 10, alignedControlHeight: 44 });
      }
    }

    await page.setViewportSize({ width: 1440, height: 800 });
    await page.setContent(html(themeCss, 'light'));
    const controls = ['select[name="pcr_rating"]', 'select[name="pcr_media"]', 'select[name="pcr_sort"]', 'input[name="pcr_verified"]', 'button[type="submit"]'];
    await page.locator(controls[0]).focus();
    for (const selector of controls) {
      assert.ok(await page.locator(selector).evaluate(node => document.activeElement === node), 'native keyboard order is retained');
      await page.keyboard.press('Tab');
    }
    await page.selectOption('[name="pcr_rating"]', '4');
    await page.selectOption('[name="pcr_media"]', 'photos');
    await page.selectOption('[name="pcr_sort"]', 'highest');
    await page.locator('.pcr-check').click();
    assert.equal(await page.isChecked('[name="pcr_verified"]'), true, 'native label still toggles verified checkbox');
    await Promise.all([page.waitForURL(url => url.origin === 'https://fixture.petish.test'), page.locator('.pcr-filters button').click()]);
    assert.equal(navigationRequests.length, 1, 'one ordinary native form navigation');
    const submitted = navigationRequests[0];
    assert.equal(submitted.method, 'GET');
    assert.equal(submitted.body, null);
    assert.deepEqual(Object.fromEntries(new URL(submitted.url).searchParams), { pcr_rating: '4', pcr_media: 'photos', pcr_sort: 'highest', pcr_verified: '1' });
    assert.equal(new URL(page.url()).hash, '#petish-customer-reviews');
    await context.close();
    console.log(JSON.stringify({ status: 'PASS', checks: results, nativeGetAndKeyboardPreserved: true, externalNetworkAllowed: false }));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
