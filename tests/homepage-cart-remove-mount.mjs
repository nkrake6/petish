/** Reproduce header hydration preceding a main Cart control, with no site access. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { waitForCartRemoveControl } from '../tools/homepage/cart-badge-browser.mjs';
const require=createRequire(import.meta.url),{chromium}=require('../tools/staging-rehearsal/node_modules/playwright');
const executable=fs.existsSync(chromium.executablePath())?undefined:'/usr/bin/chromium';
const browser=await chromium.launch({headless:true,...(executable?{executablePath:executable}:{}),args:['--no-sandbox']});
try{
 const context=await browser.newContext({serviceWorkers:'block'});
 await context.route('**/*',route=>route.abort('blockedbyclient'));
 const page=await context.newPage();
 for(const blocks of [true,false]){
  await page.setContent('<span class="petish-cart-badge" data-count="3">3</span><aside class="wc-block-mini-cart"><button class="wc-block-cart-item__remove-link">Mini Cart remove</button></aside>'+(blocks?'<div class="wc-block-cart"></div>':'<form class="woocommerce-cart-form"></form>'));
  let settled=false;const pending=waitForCartRemoveControl(page,blocks).then(value=>{settled=true;return value;});
  await page.waitForTimeout(30);assert(!settled,'The ready header or Mini Cart must not finish the main Cart wait.');
  await page.evaluate(blocks=>{
   const control=document.createElement(blocks?'button':'a');control.className=blocks?'wc-block-cart-item__remove-link':'remove';control.textContent='Remove owned item';
   control.addEventListener('click',event=>{event.preventDefault();document.body.dataset.removed='true';});
   document.querySelector(blocks?'.wc-block-cart':'.woocommerce-cart-form').appendChild(control);
  },blocks);
  const remove=await pending;assert.equal(await remove.count(),1);assert(await remove.isVisible());assert(await remove.isEnabled());
  await remove.click();assert.equal(await page.locator('body').getAttribute('data-removed'),'true');
 }
 await context.close();
}finally{await browser.close();}
console.log('Cart delayed native removal control checks passed.');
