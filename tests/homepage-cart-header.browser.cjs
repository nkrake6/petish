/* Offline proof: native empty/skeleton Cart headers never stand in for real cart tests. */
const assert = require('node:assert/strict');
const { chromium } = require('../tools/staging-rehearsal/node_modules/playwright');

const element = '<div class="wc-block-components-skeleton__element" aria-hidden="true"></div>';
const row = `<tr class="wc-block-cart-items__row" aria-label="Loading products in cart…"><td class="wc-block-cart-item__image">${element}</td><td class="wc-block-cart-item__product"><div class="wc-block-cart-item__wrap">${element}${element}</div></td><td class="wc-block-cart-item__total">${element}</td></tr>`;
const skeleton = rows => `<div class="wp-block-woocommerce-cart"><div class="wp-block-woocommerce-filled-cart-block"><table class="wc-block-cart-items"><tbody>${rows}</tbody></table><button disabled>Checkout</button></div></div>`;
const empty = '<div class="cart-empty">Synthetic empty cart</div>';

(async () => {
  const { inspectCartHeaderFixture, allowedHomepageRequest } = await import('../tools/homepage/browser.mjs');
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.route('**/*', route => route.abort('blockedbyclient'));
    const page = await context.newPage();
    const cases = [
      ['native empty', empty, 'native_empty_state'],
      ['native inert loading', skeleton(row + row), 'native_skeleton_state'],
      ['single inert row', skeleton(row), 'native_skeleton_state'],
      ['real row key', skeleton(row.replace('<tr ', '<tr data-cart-item-key="SYNTHETIC" ')), null],
      ['unknown row', skeleton('<tr class="wc-block-cart-items__row"><td>Unknown</td></tr>'), null],
      ['product link', skeleton(row.replace('</td>', '<a href="#">Product</a></td>')), null],
      ['product text', skeleton(row.replace('</td>', 'Product</td>')), null],
      ['wrong loading label', skeleton(row.replace('Loading products in cart…', 'Other')), null],
      ['missing hidden skeleton', skeleton(row.replace('aria-hidden="true"', 'aria-hidden="false"')), null],
      ['active checkout', skeleton(row).replace('<button disabled>', '<button>'), null],
      ['excess rows', skeleton(row.repeat(5)), null],
      ['empty plus real row', empty + skeleton('<tr class="cart_item"><td>Real</td></tr>'), null],
      ['empty plus loading row', empty + skeleton(row), null],
    ];
    for (const [name, body, accepted] of cases) {
      await page.setContent('<!doctype html><style>.wc-block-components-skeleton__element{height:12px;width:50px}</style>' + body);
      const result = await inspectCartHeaderFixture(page);
      assert.equal(result.native_empty_state || result.native_skeleton_state, accepted !== null, name);
      if (accepted) assert.equal(result[accepted], true, name);
    }
    for (const [path, method, type] of [
      ['/cart/', 'POST', 'document'], ['/cart/', 'GET', 'fetch'], ['/cart/?remove_item=1', 'GET', 'document'],
      ['/checkout/', 'GET', 'document'], ['/wp-json/wc/store/v1/cart', 'GET', 'fetch'],
      ['/wp-json/wc/store/v1/batch', 'POST', 'fetch'], ['/?add-to-cart=1', 'GET', 'document'],
    ]) assert.equal(allowedHomepageRequest('https://staging.petish.biz' + path, method, false, '', type), false, path);
    console.log(JSON.stringify({ status: 'success', offline_cart_states: cases.length, blocked_mutation_or_api_cases: 7 }));
  } finally { await browser.close(); }
})().catch(() => { console.error('Offline Cart header classification failed.'); process.exitCode = 1; });
