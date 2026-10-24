/** Read-only payment/layout observations, finite report shape and offline native Cart. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { cartPaymentLayoutFacts, inspectPopulatedCartPaymentLayout, publicReport, CHECK_KEYS, DIAGNOSTIC_KEYS } from '../tools/homepage/cart-badge-browser.mjs';

let count=0;
const check=value=>{assert.equal(value,true);count++;};
const absent=()=>({count:0,visible_count:0,width:0,height:0,top:0,font_size:0});
const node=(font_size=0,top=100,height=44)=>({count:1,visible_count:1,width:280,height,top,font_size});
const proof=()=>({page:'cart',horizontal_overflow:false,assets:Object.fromEntries(
 ['cart_css','cart_typography_css','cart_js','product_css','product_js'].flatMap(key=>[[key,true],[key+'_content_version',true]])),
 cart:{block_present:true,scoped_body_class:true,woo_google_pay:absent(),paypal_google_pay:absent(),paypal:absent(),
 pay_later:absent(),paypal_message:absent(),installment_message:absent(),names:node(20),prices:node(18),
 variations:node(16),line_totals:node(20),quantity:node(18)}});
const controls={layout_fits:true,quantity_control_44px:true,typography_width:768};
const facts=cartPaymentLayoutFacts(proof(),controls);
check(facts.layout_verified&&facts.typography_verified);
check(!facts.diagnostics.cart_payment_woo_google_pay_present&&!facts.diagnostics.cart_payment_paypal_present);
check(Object.values(facts.diagnostics).every(value=>typeof value==='boolean'));
check(Object.keys(facts.diagnostics).length===12&&Object.keys(facts.diagnostics).every(key=>DIAGNOSTIC_KEYS.includes(key)));
for(const width of [320,390,768,1280]){
 const value=proof();value.cart.names.font_size=width<=599?18:20;
 check(cartPaymentLayoutFacts(value,{...controls,typography_width:width}).typography_verified);
}
const populated=proof();
Object.assign(populated.cart,{woo_google_pay:node(0,100),paypal:node(0,160),pay_later:node(0,204),
 paypal_message:node(0,248),installment_message:node(0,300)});
check(cartPaymentLayoutFacts(populated,controls).layout_verified);
for(const mutate of [value=>{value.assets.product_js=false;},value=>{value.assets.cart_css_content_version=false;},
 value=>{value.cart.scoped_body_class=false;},value=>{value.horizontal_overflow=true;},
 value=>{value.cart.paypal.width=401;},value=>{value.cart.pay_later.top=120;},
 value=>{value.cart.installment_message.width=Infinity;}]){
 const value=structuredClone(populated);mutate(value);check(!cartPaymentLayoutFacts(value,controls).layout_verified);
}
for(const mutate of [value=>{value.cart.names.font_size=16;},value=>{value.cart.quantity.font_size=16;},
 value=>{value.cart.prices.visible_count=0;},value=>{value.cart.variations.font_size=12;}]){
 const value=proof();mutate(value);check(!cartPaymentLayoutFacts(value,controls).typography_verified);
}
const noVariation=proof();noVariation.cart.variations=absent();check(cartPaymentLayoutFacts(noVariation,controls).typography_verified);
check(!cartPaymentLayoutFacts(proof(),{...controls,quantity_control_44px:false}).typography_verified);
const privateValue=proof();privateValue.privateUrl='https://provider.invalid/?private-sentinel';privateValue.cart.customer='private-sentinel';
check(!JSON.stringify(cartPaymentLayoutFacts(privateValue,controls)).includes('private-sentinel'));
for(const required of ['cart_payment_layout_verified','cart_typography_verified']){
 const checks=Object.fromEntries(CHECK_KEYS.map(key=>[key,true]));checks[required]=false;
 const report=publicReport({status:'success',category:'complete',checks,
  diagnostics:{...facts.diagnostics,private_payload:privateValue}});
 check(report.status==='failure'&&!JSON.stringify(report).includes('private-sentinel'));
}

if(process.argv.includes('--browser')){
 const require=createRequire(new URL('../tools/staging-rehearsal/package.json',import.meta.url));
 const {chromium}=require('playwright');
 const theme=new URL('../wordpress/wp-content/themes/dt-the7-child/',import.meta.url);
 const woo=[process.env.PETISH_WOOCOMMERCE_ROOT,'/tmp/petish-fyp-repro/WordPress-7.1.3/wp-content/plugins/woocommerce']
  .find(value=>value&&fs.existsSync(path.join(value,'assets/client/blocks/cart.css')));
 if(!woo)throw new Error('Native WooCommerce source required; set PETISH_WOOCOMMERCE_ROOT.');
 const native=fs.readFileSync(path.join(woo,'assets/client/blocks/cart.css'),'utf8');
 const design=fs.readFileSync(new URL('assets/css/petish.css',theme),'utf8');
 const assets=new Map(['css/cart-payment-layout.css','css/cart-typography.css','js/cart-payment-layout.js','css/payment-layout.css','js/payment-layout.js']
  .map(file=>[file,fs.readFileSync(new URL('assets/'+file,theme))]));
 const base='https://staging.petish.biz/wp-content/themes/dt-the7-child/assets/';
 const tags=[...assets].map(([file,bytes])=>{
  const url=base+file+'?ver='+createHash('sha256').update(bytes).digest('hex').slice(0,16);
  return file.endsWith('.css')?`<link rel="stylesheet" href="${url}">`:`<script defer src="${url}"></script>`;
 }).join('');
 const html=`<!doctype html><html data-theme="light"><head><meta http-equiv="Content-Security-Policy" content="script-src 'self'"><style>${native}\n${design}\nbody{margin:0;padding:16px;box-sizing:border-box}main{max-width:1140px;margin:auto}svg{max-width:100%}</style>${tags}</head>
 <body id="the7-body" class="petish-site petish-cart-payment-layout"><main><div class="wp-block-woocommerce-cart"><div class="wc-block-cart">
 <table class="wc-block-cart-items"><tbody><tr class="wc-block-cart-items__row"><td class="wc-block-cart-item__image"><svg width="80" height="80"><rect width="80" height="80" fill="green"/></svg></td>
 <td class="wc-block-cart-item__product"><div class="wc-block-cart-item__wrap"><a class="wc-block-components-product-name" href="#">Private fixture product name private-sentinel</a>
 <div class="wc-block-cart-item__prices"><span class="price wc-block-components-product-price">$79.95</span></div>
 <div class="wc-block-components-product-metadata"><div class="wc-block-components-product-metadata__variation-data">Private variation</div></div>
 <div class="wc-block-cart-item__quantity"><div class="wc-block-components-quantity-selector"><input class="wc-block-components-quantity-selector__input" value="2" type="number">
 <button class="wc-block-components-quantity-selector__button wc-block-components-quantity-selector__button--minus">−</button><button class="wc-block-components-quantity-selector__button wc-block-components-quantity-selector__button--plus">+</button></div></div></div></td>
 <td class="wc-block-cart-item__total"><div class="wc-block-cart-item__total-price-and-sale-badge-wrapper">$159.90</div></td></tr></tbody></table></div></div></main></body></html>`;
 const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try{
  const page=await browser.newPage({viewport:{width:1280,height:900}});
  let requests=0;
  await page.route('**/*',route=>{
   const request=route.request(),url=new URL(request.url());requests++;
   assert.equal(request.method(),'GET');
   if(url.href==='https://staging.petish.biz/cart/')return route.fulfill({status:200,contentType:'text/html',body:html});
   const file=url.href.startsWith(base)?url.pathname.split('/assets/')[1]:null;
   if(!file||!assets.has(file))return route.abort('blockedbyclient');
   return route.fulfill({status:200,contentType:file.endsWith('.css')?'text/css':'application/javascript',body:assets.get(file)});
  });
  await page.goto('https://staging.petish.biz/cart/');
  const scriptsBefore=await page.locator('script').count();
  const actual=await inspectPopulatedCartPaymentLayout(page);
  check(actual.layout_verified&&actual.typography_verified);
  check(!JSON.stringify(actual).includes('private-sentinel'));
  check(page.viewportSize().width===1280&&page.viewportSize().height===900);
  check(await page.locator('script').count()===scriptsBefore);
  check(await page.evaluate(()=>typeof window.petishPaymentLayoutPublicProof==='undefined'));
  check(requests===6);
 }finally{await browser.close();}
}
process.stdout.write(`Cart native payment/layout proof checks passed (${count}).\n`);
