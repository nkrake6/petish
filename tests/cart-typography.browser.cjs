/* Offline fixture of Woo's native Cart row structure. No site requests/orders. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../tools/staging-rehearsal/node_modules/playwright');
const root = path.resolve(__dirname, '../wordpress/wp-content/themes/dt-the7-child');
const wooRoot = [process.env.PETISH_WOOCOMMERCE_ROOT,
  path.resolve(__dirname, '../wordpress/wp-content/plugins/woocommerce'),
  '/tmp/petish-fyp-repro/WordPress-7.1.3/wp-content/plugins/woocommerce']
  .find(value => value && fs.existsSync(path.join(value, 'assets/client/blocks/cart.css')));
if (!wooRoot) throw new Error('Set PETISH_WOOCOMMERCE_ROOT to native WooCommerce source for offline Cart CSS verification.');
const native = fs.readFileSync(path.join(wooRoot,'assets/client/blocks/cart.css'),'utf8');
const theme = fs.readFileSync(path.join(root,'assets/css/petish.css'),'utf8');
const draft = fs.readFileSync(path.join(root, 'assets/css/cart-typography.css'), 'utf8');
const row = `<tr role="row" class="wc-block-cart-items__row" data-cart-item-key="offline-fixture">
<td class="wc-block-cart-item__image" aria-hidden="true"><a href="#" tabindex="-1"><svg width="80" height="80" viewBox="0 0 80 80"><rect width="80" height="80" fill="#d0ead9"/><text x="40" y="45" text-anchor="middle">Pet</text></svg></a></td>
<td role="rowheader" class="wc-block-cart-item__product"><div class="wc-block-cart-item__wrap"><a class="wc-block-components-product-name" href="#">Ultra-soft Small Pet Adventure Harness and Extra-long Safety Lead</a>
<div class="wc-block-cart-item__prices"><span class="price wc-block-components-product-price"><del class="wc-block-components-product-price__regular">$99.95</del> <ins class="wc-block-components-product-price__value is-discounted">$79.95</ins></span></div>
<div class="wc-block-components-product-metadata"><div class="wc-block-components-product-metadata__description"><p>Comfortable support for everyday adventures.</p></div><div class="wc-block-components-product-metadata__variation-data"><div class="wc-block-components-product-details"><span><span class="wc-block-components-product-details__name">Color: </span><span class="wc-block-components-product-details__value">Lavender-and-teal-with-extra-reflective-stitching</span></span></div></div></div>
<div class="wc-block-cart-item__quantity"><div class="wc-block-components-quantity-selector"><input class="wc-block-components-quantity-selector__input" type="number" min="1" max="99" value="2" aria-label="Quantity"><button class="wc-block-components-quantity-selector__button wc-block-components-quantity-selector__button--minus" aria-label="Reduce quantity">−</button><button class="wc-block-components-quantity-selector__button wc-block-components-quantity-selector__button--plus" aria-label="Increase quantity">+</button></div><button class="wc-block-cart-item__remove-link" aria-label="Remove item">×</button></div></div></td>
<td class="wc-block-cart-item__total"><div class="wc-block-cart-item__total-price-and-sale-badge-wrapper"><span class="price wc-block-components-product-price"><span class="wc-block-components-product-price__value wc-block-components-formatted-money-amount">$159.90</span></span></div></td></tr>`;
const html = `<html><head><style>${native}\n${theme}\n${draft}\nbody{margin:0;padding:16px;box-sizing:border-box}main{max-width:1140px;margin:auto}svg{max-width:100%}.wc-block-checkout{margin-top:24px}</style></head><body id="the7-body" class="petish-site petish-cart-payment-layout"><main><div class="wp-block-woocommerce-cart"><div class="wc-block-cart"><table class="wc-block-cart-items"><thead><tr class="wc-block-cart-items__header"><th class="wc-block-cart-items__header-image">Product</th><th class="wc-block-cart-items__header-product">Details</th><th class="wc-block-cart-items__header-total">Total</th></tr></thead><tbody>${row}</tbody></table></div></div><div class="wc-block-checkout"><div class="wc-block-components-product-metadata"><div class="wc-block-components-product-metadata__variation-data">Checkout variation</div></div><input class="wc-block-components-quantity-selector__input" value="2"></div></main></body></html>`;
(async () => {
 const browser = await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try {
  const context = await browser.newContext({serviceWorkers:'block'});
  let requests = 0;
  await context.route('**/*', route => { requests++; return route.abort('blockedbyclient'); });
  const page = await context.newPage();
  const results=[];
  for (const width of [320,390,768,1280]) {
   await page.setViewportSize({width,height:1100});
   await page.setContent(html);
   const result=await page.evaluate(()=>{
    const one=s=>document.querySelector(s);
    const style=s=>getComputedStyle(one(s));
    const rect=s=>{const r=one(s).getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height}};
    return {
     width:innerWidth,documentWidth:document.documentElement.scrollWidth,
     fonts:{name:style('.wc-block-cart .wc-block-components-product-name').fontSize,price:style('.wc-block-cart-item__prices').fontSize,detail:style('.wc-block-cart .wc-block-components-product-details__value').fontSize,total:style('.wc-block-cart-item__total').fontSize,quantity:style('.wc-block-cart .wc-block-components-quantity-selector__input').fontSize,checkout:style('.wc-block-checkout .wc-block-components-product-metadata__variation-data').fontSize,checkoutQuantity:style('.wc-block-checkout input').fontSize},
     product:rect('.wc-block-cart-item__product'),total:rect('.wc-block-cart-item__total'),
     control:rect('.wc-block-cart .wc-block-components-quantity-selector__input'),
     plus:rect('.wc-block-cart .wc-block-components-quantity-selector__button--plus'),
    };
   });
   assert.equal(result.documentWidth,width,`no horizontal scroll at ${width}`);
   assert.equal(result.fonts.name,width<=599?'18px':'20px');
   assert.equal(result.fonts.price,'18px');
   assert.equal(result.fonts.detail,'16px');
   assert.equal(result.fonts.total,'20px');
   assert.equal(result.fonts.quantity,'18px');
   assert.equal(result.fonts.checkout,'12.25px');
   assert.equal(result.fonts.checkoutQuantity,'16px');
   assert.equal(result.control.height,44);
   assert.equal(result.plus.height,44);
   assert.equal(result.plus.width,44);
   if(width<=599) assert.ok(result.total.y>=result.product.bottom,`separate total row at ${width}`);
   for(const key of ['product','total','control','plus']) assert.ok(result[key].right<=width,`${key} fits ${width}`);
   await page.evaluate(()=>{const input=document.querySelector('.wc-block-cart .wc-block-components-quantity-selector__input');document.querySelector('.wc-block-components-quantity-selector__button--plus').addEventListener('click',()=>{input.value=String(Number(input.value)+1)})});
   await page.locator('.wc-block-components-quantity-selector__button--plus').click();
   assert.equal(await page.locator('.wc-block-cart .wc-block-components-quantity-selector__input').inputValue(),'3');
   await page.locator('.wc-block-components-quantity-selector__button--plus').evaluate(button=>{button.disabled=true});
   assert.equal(await page.locator('.wc-block-components-quantity-selector__button--plus').isDisabled(),true);
   results.push(result);
  }
  assert.equal(requests,0,'no network requests');
  console.log(JSON.stringify({status:'passed',widths:results.map(r=>r.width),checks:'typography, no overflow, total placement, 44px controls, native-disabled property, click handler, checkout scope',requests}));
 } finally {await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
