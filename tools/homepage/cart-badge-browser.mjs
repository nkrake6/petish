/** Bounded real-cart proof using only a journal-owned staging account/product. */
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { validateInput as accountInput } from '../account-navigation/browser.mjs';
const ORIGIN='https://staging.petish.biz';
const CART=ORIGIN+'/cart/';
const COUNT=ORIGIN+'/?wc-ajax=petish_cart_count';
const STORE=ORIGIN+'/wp-json/wc/store/v1/cart';
export const CHECK_KEYS=Object.freeze(['browser_started','fixture_input_verified','customer_login_verified',
 'cart_assets_exact','initial_empty_hidden','add_two_quantity_verified','update_three_quantity_verified',
 'remove_empty_verified','count_response_private','anonymous_session_isolated','count_get_denied',
 'badge_yellow_circle_14px','badge_accessible_quantity','desktop_header_height_preserved',
 'mobile_header_height_preserved','mobile_badge_visible','dark_mode_badge_verified','reload_quantity_preserved',
 'request_scope_enforced']);
const CATEGORIES=new Set(['input','browser','login','assets','initial','add','update','remove','privacy','geometry','scope','complete']);
function mark(report,key,value,category){report.checks[key]=value===true;if(!value)throw new Error(category);}
export function validateInput(value){
 accountInput(value);
 if(value.format!=='petish-cart-badge-browser-v1'||!/^\d+-\d+$/.test(value.run)||!Number.isSafeInteger(value.product_id)||value.product_id<1)throw new Error('input');
 const product=new URL(value.product_url);
 if(product.origin!==ORIGIN||product.search||product.hash||!/^\/product\/[a-z0-9-]+\/$/.test(product.pathname))throw new Error('input');
 return value;
}
export function publicReport(value){const checks=Object.fromEntries(CHECK_KEYS.map(key=>[key,value?.checks?.[key]===true]));
 const success=value?.status==='success'&&value?.category==='complete'&&Object.values(checks).every(Boolean);
 return {status:success?'success':'failure',category:success?'complete':CATEGORIES.has(value?.category)&&value.category!=='complete'?value.category:'input',checks};}
function keysAre(params,allowed){return [...params.keys()].every(key=>allowed.includes(key))&&[...params.keys()].length===new Set(params.keys()).size;}
export function formBody(body){
 if(typeof body!=='string'||body.length>16384)return new URLSearchParams();
 if(!body.startsWith('--'))return new URLSearchParams(body);
 const boundary=body.slice(0,body.indexOf('\r\n'));if(!/^--[-A-Za-z0-9]{10,100}$/.test(boundary))throw new Error('scope');
 const values=new URLSearchParams();for(const part of body.split(boundary).slice(1,-1)){
  const match=/^\r\nContent-Disposition: form-data; name="([A-Za-z0-9_\-\[\]]+)"\r\n\r\n([^\r\n]*)\r\n$/.exec(part);
  if(!match)throw new Error('scope');values.append(match[1],match[2]);
 }return values;
}
function cartOperation(route,body,state){
 if(!body||typeof body!=='object'||Array.isArray(body)||!state.key)return false;
 if(route.endsWith('/cart/update-item'))return state.phase==='update'&&Object.keys(body).every(key=>['key','quantity'].includes(key))&&body.key===state.key&&body.quantity===3;
 if(route.endsWith('/cart/remove-item'))return state.phase==='remove'&&Object.keys(body).length===1&&body.key===state.key;
 return false;
}
/** Every mutation is bound to the owned item and the currently requested UI step. */
export function allowedRequest(raw,method,body,type,input,state){
 try{
  const u=new URL(raw);if(u.origin!==ORIGIN||u.username||u.password||u.hash)return false;
  if(method==='POST'&&u.href===COUNT)return !body;
  if((method==='GET'||method==='POST')&&u.pathname==='/'&&u.search==='?wc-ajax=get_refreshed_fragments')return !body;
  const q=u.searchParams;
  if(method==='GET'&&u.pathname==='/wp-json/wc/store/v1/cart'&&keysAre(q,['_locale']))return true;
  if(method==='POST'&&/^\/wp-json\/wc\/store\/v1\/(cart\/(update-item|remove-item)|batch)$/.test(u.pathname)&&keysAre(q,['_locale'])){
   const value=JSON.parse(body);
   if(u.pathname.endsWith('/batch'))return value&&Object.keys(value).every(key=>['requests','validation'].includes(key))
    &&(!('validation'in value)||value.validation==='require-all-validate')&&Array.isArray(value.requests)&&value.requests.length===1
    &&value.requests.every(item=>item.method==='POST'&&typeof item.path==='string'&&Object.keys(item).every(key=>['method','path','body','headers'].includes(key))
      &&(!item.headers||(typeof item.headers==='object'&&!Array.isArray(item.headers)&&Object.entries(item.headers).every(([key,value])=>['nonce','content-type','x-wc-store-api-nonce'].includes(key.toLowerCase())&&typeof value==='string'&&value.length<=256)))
      &&/^\/wc\/store\/v1\/cart\/(update-item|remove-item)$/.test(item.path)&&cartOperation(item.path,item.body,state));
   return cartOperation(u.pathname,value,state);
  }
  if(method==='POST'&&state.phase==='login'&&type==='document'&&['/my-account/','/wp-login.php'].includes(u.pathname)&&!u.search){
   const fields=formBody(body);const woo=fields.get('username')===input.login&&fields.get('password')===input.password&&fields.has('login');
   const wp=fields.get('log')===input.login&&fields.get('pwd')===input.password;
   return !state.loginUsed&&(woo||wp)&&keysAre(fields,['username','password','login','woocommerce-login-nonce','_wp_http_referer','rememberme','log','pwd','wp-submit','redirect_to','testcookie','rememberme'])
    &&(!fields.has('redirect_to')||[ORIGIN+'/my-account/','/my-account/'].includes(fields.get('redirect_to')));
  }
  if(method==='POST'&&state.phase==='add'&&(u.href===input.product_url||u.href===ORIGIN+'/?wc-ajax=add_to_cart')){
   const fields=formBody(body);return keysAre(fields,['add-to-cart','product_id','quantity'])
    &&fields.get('quantity')==='2'&&fields.has(u.href===input.product_url?'add-to-cart':'product_id')
    &&['add-to-cart','product_id'].every(key=>!fields.has(key)||fields.get(key)===String(input.product_id));
  }
  if(method==='POST'&&u.href===CART&&state.phase==='update'&&state.key){
   const fields=formBody(body);return keysAre(fields,['cart['+state.key+'][qty]','woocommerce-cart-nonce','_wp_http_referer','update_cart'])
    &&fields.get('cart['+state.key+'][qty]')==='3'&&fields.has('update_cart')&&/^[a-f0-9]{10}$/.test(fields.get('woocommerce-cart-nonce')??'');
  }
  if(method==='GET'&&u.pathname==='/cart/'&&state.phase==='remove'&&state.key&&keysAre(q,['remove_item','_wpnonce'])&&q.size===2)
   return q.get('remove_item')===state.key&&/^[a-f0-9]{10}$/.test(q.get('_wpnonce')??'');
  if(method!=='GET')return false;
  if(!u.search&&['/','/cart/','/my-account/',new URL(input.product_url).pathname].includes(u.pathname))return true;
  if(u.pathname==='/wp-login.php'&&keysAre(q,['redirect_to'])&&(!q.size||q.get('redirect_to')===ORIGIN+'/my-account/'))return true;
  if(type!=='document'&&/^\/wp-(content|includes)\//.test(u.pathname)&&/\.(?:css|js|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|map)$/i.test(u.pathname))return keysAre(q,['ver']);
  return false;
 }catch{return false;}
}
function inputFile(file){const p=path.resolve(file),s=fs.lstatSync(p);if(!s.isFile()||s.isSymbolicLink()||s.nlink!==1||(s.mode&0o777)!==0o600||s.size<1||s.size>32768||fs.realpathSync(p)!==p)throw new Error('input');return validateInput(JSON.parse(fs.readFileSync(p,'utf8')));}
async function countRequest(context){const r=await context.request.post(COUNT,{data:'',headers:{Origin:ORIGIN},maxRedirects:0,timeout:30000});
 if(r.status()!==200)throw new Error('privacy');const data=await r.json(),h=r.headers();
 if(data?.success!==true||!Number.isSafeInteger(data?.data?.count)||data.data.count<0||typeof data.data.label!=='string')throw new Error('privacy');
 return {count:data.data.count,private:/\bprivate\b/.test(h['cache-control']??'')&&/\bno-store\b/.test(h['cache-control']??'')&&(h.vary??'').toLowerCase().split(',').map(v=>v.trim()).includes('cookie')};}
async function cartRead(context,input,quantity){const r=await context.request.get(STORE,{maxRedirects:0,timeout:30000});if(r.status()!==200)throw new Error('scope');const data=await r.json();
 if(!Array.isArray(data.items)||data.items.length!==(quantity?1:0))throw new Error('scope');
 if(quantity&&(data.items[0].id!==input.product_id||data.items[0].quantity!==quantity||!/^[a-f0-9]{32}$/.test(data.items[0].key)))throw new Error('scope');
 return quantity?data.items[0].key:'';}
async function badges(page,count){await page.waitForFunction(n=>{const nodes=[...document.querySelectorAll('.petish-cart-badge')];return nodes.length>0&&nodes.every(x=>x.dataset.count===String(n)&&x.hidden===(n===0));},count,{timeout:30000});}
async function geometry(page){return page.evaluate(()=>{const visible=e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'&&r.right>0&&r.left<innerWidth;};
 const header=[...document.querySelectorAll('.header-bar,.mobile-header-bar')].filter(visible);const all=[...document.querySelectorAll('.petish-cart-badge')];
 const before=header.map(e=>e.getBoundingClientRect().height);const shown=all.filter(visible);const valid=shown.every(e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);return Math.abs(r.width-14)<.1&&Math.abs(r.height-14)<.1&&s.borderRadius==='50%'&&s.backgroundColor==='rgb(249, 168, 37)'&&s.color==='rgb(11, 20, 38)';});
 const labels=all.every(e=>e.getAttribute('role')==='status'&&e.getAttribute('aria-live')==='polite'&&e.querySelector('.petish-cart-badge__number')?.getAttribute('aria-hidden')==='true'&&e.querySelector('.petish-cart-badge__label')?.textContent==='3 items in cart');
 const original=all.map(e=>e.style.display);all.forEach(e=>e.style.setProperty('display','none','important'));const after=header.map(e=>e.getBoundingClientRect().height);all.forEach((e,i)=>{e.style.display=original[i];});
 return {shown:shown.length,valid,labels,height:before.length>0&&before.every((n,i)=>Math.abs(n-after[i])<.1)};});}
async function main(args){let browser,context,guest;const report={status:'failure',category:'input',checks:{}};
 try{
  if(args.length!==2)throw new Error('input');const input=inputFile(args[0]);mark(report,'fixture_input_verified',true,'input');
  const require=createRequire(import.meta.url);const {chromium}=require('../staging-rehearsal/node_modules/playwright');
  const executable=process.env.PETISH_BROWSER_EXECUTABLE||(fs.existsSync(chromium.executablePath())?undefined:'/usr/bin/chromium');
  browser=await chromium.launch({headless:true,...(executable?{executablePath:executable}:{}),args:['--no-sandbox']});
  mark(report,'browser_started',true,'browser');context=await browser.newContext({viewport:{width:1280,height:900},serviceWorkers:'block',acceptDownloads:false,locale:'en-AU'});
  const state={phase:'initial',key:'',loginUsed:false,allowed:0,mutations:0,denied:0};
  await context.route('**/*',async route=>{const r=route.request();if(!allowedRequest(r.url(),r.method(),r.postData()??'',r.resourceType(),input,state)){state.denied++;return route.abort('blockedbyclient');}
   state.allowed++;if(r.method()==='POST'&&state.phase==='login'&&['/my-account/','/wp-login.php'].includes(new URL(r.url()).pathname))state.loginUsed=true;
   if(r.method()==='POST'&&r.url()!==COUNT&&!r.url().includes('get_refreshed_fragments'))state.mutations++;
   return route.continue();});
  if(typeof context.routeWebSocket==='function')await context.routeWebSocket('**/*',socket=>socket.close());
  const page=await context.newPage();page.setDefaultTimeout(30000);
  report.category='assets';const theme=new URL('../../wordpress/wp-content/themes/dt-the7-child/',import.meta.url);let exact=true;
  for(const relative of ['assets/css/cart-badge.css','assets/js/cart-badge.js']){const bytes=fs.readFileSync(new URL(relative,theme));const digest=createHash('sha256').update(bytes).digest('hex');
   const response=await context.request.get(ORIGIN+'/wp-content/themes/dt-the7-child/'+relative+'?ver='+digest,{maxRedirects:0,timeout:30000});exact=exact&&response.status()===200&&createHash('sha256').update(await response.body()).digest('hex')===digest;}
  mark(report,'cart_assets_exact',exact,'assets');
  state.phase='login';report.category='login';await page.goto(ORIGIN+'/wp-login.php?redirect_to='+encodeURIComponent(ORIGIN+'/my-account/'),{waitUntil:'domcontentloaded'});
  const form=page.locator('form#loginform');await form.locator('[name=log]').fill(input.login);await form.locator('[name=pwd]').fill(input.password);
  await Promise.all([page.waitForURL(u=>u.pathname==='/my-account/',{waitUntil:'domcontentloaded'}),form.locator('[name=wp-submit]').click()]);
  await page.locator('.woocommerce-MyAccount-navigation').waitFor();mark(report,'customer_login_verified',state.loginUsed&&(await context.cookies()).some(c=>c.name.startsWith('wordpress_logged_in_')),'login');
  state.phase='initial';report.category='initial';await page.goto(input.product_url,{waitUntil:'domcontentloaded'});await badges(page,0);await cartRead(context,input,0);mark(report,'initial_empty_hidden',true,'initial');
  state.phase='add';report.category='add';const productForm=page.locator('form.cart:has(button[name=add-to-cart])');
  await productForm.locator('[name=quantity]').fill('2');await productForm.locator('button[name=add-to-cart]').click();
  await badges(page,2);state.key=await cartRead(context,input,2);mark(report,'add_two_quantity_verified',(await countRequest(context)).count===2,'add');
  state.phase='browse';await page.goto(CART,{waitUntil:'domcontentloaded'});await badges(page,2);
  const blockQty=page.locator('.wc-block-components-quantity-selector__input');const classicQty=page.locator('.woocommerce-cart-form input.qty');
  await page.waitForFunction(()=>document.querySelector('.wc-block-components-quantity-selector__input,.woocommerce-cart-form input.qty')!==null,null,{timeout:30000});
  const blocks=await blockQty.count()>0;report.category='update';state.phase='update';
  if(blocks){await blockQty.fill('3');await blockQty.press('Tab');}else{await classicQty.fill('3');await page.locator('[name=update_cart]').click();}
  await badges(page,3);await cartRead(context,input,3);mark(report,'update_three_quantity_verified',true,'update');
  const count=await countRequest(context);mark(report,'count_response_private',count.count===3&&count.private,'privacy');
  guest=await browser.newContext({serviceWorkers:'block'});const anon=await countRequest(guest);mark(report,'anonymous_session_isolated',anon.count===0&&anon.private,'privacy');
  const denied=await guest.request.get(COUNT,{maxRedirects:0,timeout:30000});mark(report,'count_get_denied',denied.status()===403,'privacy');
  state.phase='browse';report.category='geometry';await page.goto(input.product_url,{waitUntil:'domcontentloaded'});await badges(page,3);
  let g=await geometry(page);mark(report,'badge_yellow_circle_14px',g.valid&&g.shown>0,'geometry');mark(report,'badge_accessible_quantity',g.labels,'geometry');mark(report,'desktop_header_height_preserved',g.height,'geometry');
  let mobileHeight=true,mobileVisible=true;
  for(const width of [390,320]){
   await page.setViewportSize({width,height:844});await page.waitForTimeout(150);
   const close=page.locator('.dt-close-mobile-menu-icon:visible').first();if(await close.count()){await close.click();await page.waitForTimeout(200);}
   g=await geometry(page);mobileHeight=mobileHeight&&g.height;
   if(!g.shown){const menu=page.locator('.dt-mobile-menu-icon:visible,.mobile-navigation .menu-toggle:visible').first();await menu.click();await page.waitForTimeout(250);g=await geometry(page);}
   mobileVisible=mobileVisible&&g.shown>0&&g.valid;
  }
  mark(report,'mobile_header_height_preserved',mobileHeight,'geometry');mark(report,'mobile_badge_visible',mobileVisible,'geometry');
  await page.evaluate(()=>{document.documentElement.setAttribute('data-theme','dark');document.body.classList.add('petish-dark-mode');});g=await geometry(page);mark(report,'dark_mode_badge_verified',g.valid&&g.shown>0,'geometry');
  await page.reload({waitUntil:'domcontentloaded'});await badges(page,3);mark(report,'reload_quantity_preserved',(await countRequest(context)).count===3,'update');
  await page.goto(CART,{waitUntil:'domcontentloaded'});await badges(page,3);state.phase='remove';report.category='remove';
  if(blocks)await page.locator('.wc-block-cart-item__remove-link').click();else await page.locator('.woocommerce-cart-form a.remove').click();
  await badges(page,0);await cartRead(context,input,0);mark(report,'remove_empty_verified',(await countRequest(context)).count===0,'remove');
  mark(report,'request_scope_enforced',state.allowed>0&&state.loginUsed&&state.key.length===32&&state.mutations>=2
   &&!allowedRequest(ORIGIN+'/checkout/','GET','','document',input,state)&&!allowedRequest(ORIGIN+'/wp-json/wc/store/v1/checkout','POST','{}','fetch',input,state),'scope');
  report.status='success';report.category='complete';
 }catch(error){report.category=CATEGORIES.has(error?.message)?error.message:report.category;}
 finally{await guest?.close().catch(()=>{});await context?.close().catch(()=>{});await browser?.close().catch(()=>{});}
 const result=publicReport(report);if(args.length===2){const file=path.resolve(args[1]);if(fs.realpathSync(path.dirname(file))!==path.dirname(file))throw new Error('input');fs.writeFileSync(file,JSON.stringify(result,null,2)+'\n',{flag:'wx',mode:0o600});}
 return result.status==='success'?0:1;
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url))process.exitCode=await main(process.argv.slice(2));
