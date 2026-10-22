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
export const DIAGNOSTIC_KEYS=Object.freeze(['guest_count_redirect_login', 'guest_count_redirect_other_same_origin', 'guest_count_redirect_external', 'guest_get_redirect_login', 'guest_get_redirect_other_same_origin', 'guest_get_redirect_external', 'guest_homepage_redirect_login', 'guest_homepage_redirect_other_same_origin', 'guest_homepage_redirect_external', 'guest_homepage_final_root', 'shared_guard_auth_redirect', 'shared_guard_wp_safe_redirect', 'shared_guard_wp_login_url', 'shared_guard_is_user_logged_in', 'shared_guard_template_redirect_hook', 'shared_guard_init_hook', 'guest_count_opaque_redirect', 'guest_get_opaque_redirect', 'guest_count_network_http2_protocol_error', 'guest_count_network_empty_response', 'guest_count_network_blocked_by_client', 'guest_count_network_failed', 'guest_count_network_connection_reset', 'guest_count_network_connection_closed', 'guest_count_network_connection_aborted', 'guest_count_network_connection_refused', 'guest_count_network_timed_out', 'guest_count_network_name_not_resolved', 'guest_count_network_cert_error', 'guest_count_network_aborted', 'guest_count_network_other', 'guest_get_network_http2_protocol_error', 'guest_get_network_empty_response', 'guest_get_network_blocked_by_client', 'guest_get_network_failed', 'guest_get_network_connection_reset', 'guest_get_network_connection_closed', 'guest_get_network_connection_aborted', 'guest_get_network_connection_refused', 'guest_get_network_timed_out', 'guest_get_network_name_not_resolved', 'guest_get_network_cert_error', 'guest_get_network_aborted', 'guest_get_network_other', 'guest_homepage_http_403', 'guest_homepage_http_404', 'guest_homepage_http_5xx', 'guest_homepage_http_redirect', 'guest_homepage_fixture_header_present', 'guest_homepage_denied_identity', 'guest_homepage_denied_request', 'guest_homepage_denied_expired', 'guest_homepage_denied_uri', 'guest_homepage_denied_body', 'guest_homepage_denied_unavailable', 'guest_homepage_denied_query_fields', 'guest_homepage_denied_query_locale', 'guest_homepage_denied_query_route', 'guest_homepage_denied_context', 'mobile_390_badge_initial_visible', 'mobile_390_header_height_ok', 'mobile_390_menu_needed', 'mobile_390_menu_control_present', 'mobile_390_menu_click_completed', 'mobile_390_badge_final_visible', 'mobile_390_exception', 'mobile_320_badge_initial_visible', 'mobile_320_header_height_ok', 'mobile_320_menu_needed', 'mobile_320_menu_control_present', 'mobile_320_menu_click_completed', 'mobile_320_badge_final_visible', 'mobile_320_exception', 'guest_count_request_seen', 'guest_count_request_allowed', 'guest_count_request_denied', 'guest_count_response_received', 'guest_count_http_redirect', 'guest_count_content_type_json', 'guest_count_content_type_html', 'guest_count_page_same_origin', 'guest_count_csp_violation', 'guest_count_timeout', 'guest_count_oversize', 'guest_count_transport_error', 'guest_count_body_error', 'guest_count_json_invalid', 'guest_get_request_seen', 'guest_get_request_allowed', 'guest_get_request_denied', 'guest_get_response_received', 'guest_get_http_redirect', 'guest_get_page_same_origin', 'guest_get_csp_violation', 'guest_get_timeout', 'guest_get_oversize', 'guest_get_transport_error', 'guest_get_body_error', 'guest_get_json_invalid', 'guest_homepage_http_ok', 'guest_authentication_absent', 'guest_count_http_200', 'guest_count_http_403', 'guest_count_http_5xx', 'guest_count_fetch_exception', 'guest_count_zero', 'guest_count_private', 'guest_get_http_403', 'guest_get_fetch_exception', 'update_fixture_denied_query_fields', 'update_fixture_denied_query_locale', 'update_fixture_denied_query_route', 'update_fixture_denied_quantity_type', 'update_fixture_denied_context', 'update_server_method_is_post', 'update_server_method_is_other', 'asset_css_response_received', 'asset_css_status_200', 'asset_css_status_403', 'asset_css_status_404', 'asset_css_status_5xx', 'asset_css_hash_matches', 'asset_css_fetch_exception', 'asset_js_response_received', 'asset_js_status_200', 'asset_js_status_403', 'asset_js_status_404', 'asset_js_status_5xx', 'asset_js_hash_matches', 'asset_js_fetch_exception', 'cart_blocks', 'cart_classic', 'quantity_control_single', 'quantity_control_visible', 'quantity_before_two', 'increment_control_single', 'increment_control_enabled', 'update_interaction_completed', 'update_request_seen', 'update_request_allowed', 'update_response_received', 'update_response_2xx', 'update_response_3xx', 'update_response_4xx', 'update_response_5xx', 'update_response_400', 'update_response_403', 'update_response_500', 'update_server_count_read', 'update_server_count_two', 'update_server_count_three', 'update_badge_two', 'update_badge_three', 'update_ui_quantity_three', 'blocks_store_available', 'blocks_store_count_two', 'blocks_store_count_three', 'blocks_pending_operation', 'update_response_fixture_header_present', 'update_request_is_batch', 'update_error_invalid_nonce', 'native_cart_key_matches_formula', 'update_fixture_denied_identity', 'update_fixture_denied_key', 'update_fixture_denied_request', 'update_fixture_denied_body', 'update_fixture_denied_expired', 'update_fixture_denied_uri', 'update_fixture_denied_unavailable']);
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
 return {status:success?'success':'failure',category:success?'complete':CATEGORIES.has(value?.category)&&value.category!=='complete'?value.category:'input',diagnostics:Object.fromEntries(DIAGNOSTIC_KEYS.map(key=>[key,value?.diagnostics?.[key]===true])),checks};}
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
   const fields=formBody(body);return keysAre(fields,['cart['+state.key+'][qty]','woocommerce-cart-nonce','_wp_http_referer','update_cart','coupon_code'])
    &&(!fields.has('coupon_code')||fields.get('coupon_code')==='')&&fields.get('cart['+state.key+'][qty]')==='3'&&fields.has('update_cart')&&/^[a-f0-9]{10}$/.test(fields.get('woocommerce-cart-nonce')??'');
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
/** Anonymous browser can warm the login document, then read only its own count. */
export function allowedGuestCartRequest(raw,method,body,type,input) {
 try{
  const url=new URL(raw);
  if(url.href===COUNT)return (method==='GET'||method==='POST')&&!body;
  return method==='GET'&&(url.href===ORIGIN+'/'&&type==='document'||url.origin===ORIGIN&&/^\/wp-(content|includes)\//.test(url.pathname)
   &&allowedRequest(raw,'GET','',type,input,{phase:'privacy',key:'',loginUsed:true}));
 }catch{return false;}
}
/** Network errors leave the runner only as a fixed enum of boolean facts. */
export function observeGuestNetworkFailure(error,prefix,diagnostics) {
 const known={ERR_HTTP2_PROTOCOL_ERROR:'http2_protocol_error',ERR_EMPTY_RESPONSE:'empty_response',ERR_BLOCKED_BY_CLIENT:'blocked_by_client',ERR_FAILED:'failed',ERR_CONNECTION_RESET:'connection_reset',ERR_CONNECTION_CLOSED:'connection_closed',ERR_CONNECTION_ABORTED:'connection_aborted',ERR_CONNECTION_REFUSED:'connection_refused',ERR_TIMED_OUT:'timed_out',ERR_CONNECTION_TIMED_OUT:'timed_out',ERR_NAME_NOT_RESOLVED:'name_not_resolved',ERR_ABORTED:'aborted'};
 const name=typeof error==='string'&&error.startsWith('net::')?error.slice(5):'';
 const fact=known[name]||(name.startsWith('ERR_CERT_')?'cert_error':'other');
 diagnostics[prefix+'network_'+fact]=true;
}
function observeKnownRedirect(headers,prefix,diagnostics) {
 if(typeof headers.location!=='string')return;
 try{const target=new URL(headers.location,ORIGIN);
  const fact=target.origin!==ORIGIN?'external':target.pathname==='/wp-login.php'?'login':'other_same_origin';
  diagnostics[prefix+'redirect_'+fact]=true;
 }catch{}
}
export function observeGuestHomepage(status,headers,run,diagnostics) {
 observeKnownRedirect(headers,'guest_homepage_',diagnostics);
 diagnostics.guest_homepage_http_ok=status>=200&&status<300;
 if(status===403)diagnostics.guest_homepage_http_403=true;
 if(status===404)diagnostics.guest_homepage_http_404=true;
 if(status>=500&&status<600)diagnostics.guest_homepage_http_5xx=true;
 if(status>=300&&status<400)diagnostics.guest_homepage_http_redirect=true;
 if(headers['x-petish-cart-fixture']===run)diagnostics.guest_homepage_fixture_header_present=true;
 const reason=headers['x-petish-cart-fixture-reason'];
 if(['identity','request','expired','uri','body','unavailable','query_fields','query_locale','query_route','context'].includes(reason))diagnostics['guest_homepage_denied_'+reason]=true;
}
/** Record only fixed facts before reading a possibly oversized/failed response body. */
export function observeGuestCountResponse(status,headers,prefix,diagnostics) {
 diagnostics[prefix+'response_received']=true;observeKnownRedirect(headers,prefix,diagnostics);
 if(status===403)diagnostics[prefix+'http_403']=true;
 if(status>=300&&status<400)diagnostics[prefix+'http_redirect']=true;
 if(prefix==='guest_count_'){
  if(status===200)diagnostics.guest_count_http_200=true;
  if(status>=500&&status<600)diagnostics.guest_count_http_5xx=true;
  if(/application\/json/i.test(headers['content-type']??''))diagnostics.guest_count_content_type_json=true;
  if(/text\/html/i.test(headers['content-type']??''))diagnostics.guest_count_content_type_html=true;
 }
}
/** This fixed endpoint uses normal browser transport, without copying member cookies. */
export function guestCartCountBrowserRequest(page,diagnostics) {
 const read=async(method,url,options)=>{
  if(url!==COUNT||options.maxRedirects!==0||options.timeout!==30000||method==='POST'&&(options.data!==''||options.headers?.Origin!==ORIGIN))throw new Error('privacy');
  const prefix=method==='POST'?'guest_count_':'guest_get_';
  try{
   const result=await page.evaluate(async({target,method})=>{
    const facts={page_same_origin:location.origin===new URL(target).origin,csp_violation:false,timeout:false,oversize:false,transport_error:false,body_error:false,opaque_redirect:false,response_received:false};
    const controller=new AbortController(),timer=setTimeout(()=>{facts.timeout=true;controller.abort();},30000);
    const policy=event=>{if(['connect-src','default-src'].includes(event.effectiveDirective)){try{if(new URL(event.blockedURI).origin===new URL(target).origin)facts.csp_violation=true;}catch{}}};
    document.addEventListener('securitypolicyviolation',policy);
    let response=null,body=null;
    try{
     response=await fetch(target,{method,mode:'same-origin',credentials:'same-origin',cache:'no-store',redirect:'manual',headers:{Accept:'application/json'},signal:controller.signal});
     facts.response_received=true;facts.opaque_redirect=response.type==='opaqueredirect';
     const reader=response.body?.getReader(),chunks=[];let size=0;
     if(Number(response.headers.get('content-length')??0)>65536){facts.oversize=true;controller.abort();throw new Error('privacy');}
     if(reader)while(true){const{done,value}=await reader.read();if(done)break;size+=value.byteLength;if(size>65536){facts.oversize=true;controller.abort();await reader.cancel();throw new Error('privacy');}chunks.push(value);}
     const bytes=new Uint8Array(size);let offset=0;for(const chunk of chunks){bytes.set(chunk,offset);offset+=chunk.byteLength;}body=new TextDecoder().decode(bytes);
    }catch{
     if(!facts.timeout&&!facts.oversize){if(response)facts.body_error=true;else facts.transport_error=true;}
     // CSP events are queued separately from the rejected fetch promise.
     await new Promise(resolve=>setTimeout(resolve,0));
    }finally{clearTimeout(timer);document.removeEventListener('securitypolicyviolation',policy);}
    return{status:response?.status??0,headers:response?Object.fromEntries(response.headers):{},body,facts};
   },{target:COUNT,method});
   for(const fact of ['page_same_origin','csp_violation','timeout','oversize','transport_error','body_error','opaque_redirect','response_received'])diagnostics[prefix+fact]=result.facts?.[fact]===true;
   if(result.status)observeGuestCountResponse(result.status,result.headers,prefix,diagnostics);
   if(typeof result.body!=='string')throw new Error('privacy');
   return{status:()=>result.status,headers:()=>result.headers,json:async()=>{try{return JSON.parse(result.body);}catch{diagnostics[prefix+'json_invalid']=true;throw new Error('privacy');}}};
  }catch{diagnostics[prefix+'fetch_exception']=true;throw new Error('privacy');}
 };
 return{post:(url,options)=>read('POST',url,options),get:(url,options)=>read('GET',url,options)};
}
/** Only these fixed public GETs may be retried; cart/login mutations never are. */
export async function fetchCartAsset(request, relative, digest, diagnostics) {
 const kind=relative==='assets/css/cart-badge.css'?'css':relative==='assets/js/cart-badge.js'?'js':null;
 if(!kind||typeof digest!=='string'||!/^[a-f0-9]{64}$/.test(digest))throw new Error('assets');
 const prefix='asset_'+kind+'_';
 for(let attempt=0;attempt<3;attempt++){
  try{
   const response=await request.get(ORIGIN+'/wp-content/themes/dt-the7-child/'+relative+'?ver='+digest,{maxRedirects:0,timeout:30000});
   const status=response.status();diagnostics[prefix+'response_received']=true;
   for(const [fact,passed] of Object.entries({status_200:status===200,status_403:status===403,status_404:status===404,status_5xx:status>=500&&status<600}))if(passed)diagnostics[prefix+fact]=true;
   // Compute each asset's body hash even if another asset/status already failed.
   const bytes=await response.body();const matches=bytes.length<=1048576&&createHash('sha256').update(bytes).digest('hex')===digest;
   if(matches)diagnostics[prefix+'hash_matches']=true;
   if(status===200)return matches;
   if(status!==403&&status!==408&&status!==429&&(status<500||status>=600))return false;
  }catch{diagnostics[prefix+'fetch_exception']=true;}
 }
 return false;
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
export function nonceError(value){
 const known=new Set(['rest_cookie_invalid_nonce','woocommerce_rest_invalid_nonce','woocommerce_rest_missing_nonce']);
 if(!value||typeof value!=='object'||Array.isArray(value))return false;
 if(known.has(value.code))return true;
 return Array.isArray(value.responses)&&value.responses.length<=3&&value.responses.some(item=>known.has(item?.body?.code));
}
function updateRequest(request,state){
 if(state.phase!=='update'||request.method()!=='POST')return false;
 try{const u=new URL(request.url());return u.origin===ORIGIN&&['/cart/','/wp-json/wc/store/v1/cart/update-item','/wp-json/wc/store/v1/batch'].includes(u.pathname);}catch{return false;}
}
async function updateObservation(page,context,report){
 try{const value=await countRequest(context);report.diagnostics.update_server_count_read=true;report.diagnostics.update_server_count_two=value.count===2;report.diagnostics.update_server_count_three=value.count===3;}catch{}
 try{Object.assign(report.diagnostics,await page.evaluate(()=>{
  const all=[...document.querySelectorAll('.petish-cart-badge')];const quantity=document.querySelector('.wc-block-cart-items .wc-block-components-quantity-selector__input,.woocommerce-cart-form input.qty');
  const data=window.wp?.data,store=window.wc?.wcBlocksData?.CART_STORE_KEY;const selectors=data&&store&&typeof data.select==='function'?data.select(store):null;
  const cart=selectors&&typeof selectors.getCartData==='function'?selectors.getCartData():null;
  return {update_badge_two:all.length>0&&all.every(x=>x.dataset.count==='2'),update_badge_three:all.length>0&&all.every(x=>x.dataset.count==='3'),
   update_ui_quantity_three:quantity?.value==='3',blocks_store_available:!!cart,blocks_store_count_two:cart?.itemsCount===2,blocks_store_count_three:cart?.itemsCount===3,
   blocks_pending_operation:typeof selectors?.hasPendingItemsOperations==='function'&&selectors.hasPendingItemsOperations()===true};
 }));}catch{}
}
async function main(args){let browser,context,guest,page;const responseTasks=[];const report={status:'failure',category:'input',checks:{},diagnostics:{}};
 try{
  if(args.length!==2)throw new Error('input');const input=inputFile(args[0]);mark(report,'fixture_input_verified',true,'input');
  for(const fact of ['auth_redirect','wp_safe_redirect','wp_login_url','is_user_logged_in','template_redirect_hook','init_hook'])report.diagnostics['shared_guard_'+fact]=input.guard_auth_facts?.[fact]===true;
  const require=createRequire(import.meta.url);const {chromium}=require('../staging-rehearsal/node_modules/playwright');
  const executable=process.env.PETISH_BROWSER_EXECUTABLE||(fs.existsSync(chromium.executablePath())?undefined:'/usr/bin/chromium');
  browser=await chromium.launch({headless:true,...(executable?{executablePath:executable}:{}),args:['--no-sandbox']});
  mark(report,'browser_started',true,'browser');context=await browser.newContext({viewport:{width:1280,height:900},serviceWorkers:'block',acceptDownloads:false,locale:'en-AU'});
  const state={phase:'initial',key:'',loginUsed:false,allowed:0,mutations:0,denied:0};
  await context.route('**/*',async route=>{const r=route.request(),updating=updateRequest(r,state);if(updating){report.diagnostics.update_request_seen=true;if(new URL(r.url()).pathname==='/wp-json/wc/store/v1/batch')report.diagnostics.update_request_is_batch=true;}
   if(!allowedRequest(r.url(),r.method(),r.postData()??'',r.resourceType(),input,state)){state.denied++;return route.abort('blockedbyclient');}
   if(updating)report.diagnostics.update_request_allowed=true;
   state.allowed++;if(r.method()==='POST'&&state.phase==='login'&&['/my-account/','/wp-login.php'].includes(new URL(r.url()).pathname))state.loginUsed=true;
   if(r.method()==='POST'&&r.url()!==COUNT&&!r.url().includes('get_refreshed_fragments'))state.mutations++;
   return route.continue();});
  if(typeof context.routeWebSocket==='function')await context.routeWebSocket('**/*',socket=>socket.close());
  context.on('response',response=>{if(!updateRequest(response.request(),state))return;
   const status=response.status();report.diagnostics.update_response_received=true;
   const headers=response.headers();if(headers['x-petish-cart-fixture']===input.run)report.diagnostics.update_response_fixture_header_present=true;
   const reason=headers['x-petish-cart-fixture-reason'];if(['identity','key','request','body','expired','uri','unavailable','query_fields','query_locale','query_route','quantity_type','context'].includes(reason))report.diagnostics['update_fixture_denied_'+reason]=true;
   const method=headers['x-petish-cart-fixture-method'];if(method==='post'||method==='other')report.diagnostics['update_server_method_is_'+method]=true;
   if((headers['content-type']??'').toLowerCase().includes('application/json')&&(!headers['content-length']||Number(headers['content-length'])<=65536)){
    responseTasks.push((async()=>{try{const bytes=await response.body();if(bytes.length<=65536&&nonceError(JSON.parse(bytes.toString('utf8'))))report.diagnostics.update_error_invalid_nonce=true;}catch{}})());
   }
   for(const [name,passed] of Object.entries({update_response_2xx:status>=200&&status<300,update_response_3xx:status>=300&&status<400,
    update_response_4xx:status>=400&&status<500,update_response_5xx:status>=500&&status<600,update_response_400:status===400,update_response_403:status===403,update_response_500:status===500}))if(passed)report.diagnostics[name]=true;
  });
  page=await context.newPage();page.setDefaultTimeout(30000);
  state.phase='login';report.category='login';await page.goto(ORIGIN+'/wp-login.php?redirect_to='+encodeURIComponent(ORIGIN+'/my-account/'),{waitUntil:'domcontentloaded'});
  const form=page.locator('form#loginform');await form.locator('[name=log]').fill(input.login);await form.locator('[name=pwd]').fill(input.password);
  await Promise.all([page.waitForURL(u=>u.pathname==='/my-account/',{waitUntil:'domcontentloaded'}),form.locator('[name=wp-submit]').click()]);
  await page.locator('.woocommerce-MyAccount-navigation').waitFor();mark(report,'customer_login_verified',state.loginUsed&&(await context.cookies()).some(c=>c.name.startsWith('wordpress_logged_in_')),'login');
  report.category='assets';const theme=new URL('../../wordpress/wp-content/themes/dt-the7-child/',import.meta.url);let exact=true;
  for(const relative of ['assets/css/cart-badge.css','assets/js/cart-badge.js']){const bytes=fs.readFileSync(new URL(relative,theme));const digest=createHash('sha256').update(bytes).digest('hex');
   const matches=await fetchCartAsset(context.request,relative,digest,report.diagnostics);exact=matches&&exact;}
  mark(report,'cart_assets_exact',exact,'assets');
  state.phase='initial';report.category='initial';await page.goto(input.product_url,{waitUntil:'domcontentloaded'});await badges(page,0);await cartRead(context,input,0);mark(report,'initial_empty_hidden',true,'initial');
  state.phase='add';report.category='add';const productForm=page.locator('form.cart:has(button[name=add-to-cart])');
  await productForm.locator('[name=quantity]').fill('2');await productForm.locator('button[name=add-to-cart]').click();
  await badges(page,2);state.key=await cartRead(context,input,2);report.diagnostics.native_cart_key_matches_formula=state.key===input.cart_key;mark(report,'add_two_quantity_verified',(await countRequest(context)).count===2,'add');
  state.phase='browse';await page.goto(CART,{waitUntil:'domcontentloaded'});await badges(page,2);
  const blockQty=page.locator('.wc-block-cart-items .wc-block-components-quantity-selector__input');const classicQty=page.locator('.woocommerce-cart-form input.qty');
  await page.waitForFunction(()=>document.querySelector('.wc-block-cart-items .wc-block-components-quantity-selector__input,.woocommerce-cart-form input.qty')!==null,null,{timeout:30000});
  const blocks=await blockQty.count()>0;report.category='update';state.phase='update';
  report.diagnostics.cart_blocks=blocks;report.diagnostics.cart_classic=!blocks;
  const quantity=blocks?blockQty:classicQty;report.diagnostics.quantity_control_single=await quantity.count()===1;
  if(!report.diagnostics.quantity_control_single)throw new Error('update');
  report.diagnostics.quantity_control_visible=await quantity.isVisible();report.diagnostics.quantity_before_two=await quantity.inputValue()==='2';
  if(!report.diagnostics.quantity_control_visible||!report.diagnostics.quantity_before_two)throw new Error('update');
  if(blocks){const plus=page.locator('.wc-block-cart-items .wc-block-components-quantity-selector__button--plus');
   report.diagnostics.increment_control_single=await plus.count()===1;if(!report.diagnostics.increment_control_single)throw new Error('update');
   await page.waitForFunction(()=>{const buttons=document.querySelectorAll('.wc-block-cart-items .wc-block-components-quantity-selector__button--plus');return buttons.length===1&&!buttons[0].disabled;},null,{timeout:30000});
   report.diagnostics.increment_control_enabled=await plus.isEnabled();await plus.click();
  }else{await classicQty.fill('3');await classicQty.press('Tab');await page.locator('[name=update_cart]').click();}
  report.diagnostics.update_interaction_completed=true;
  try{await badges(page,3);await cartRead(context,input,3);mark(report,'update_three_quantity_verified',true,'update');}
  finally{await updateObservation(page,context,report);}
  const count=await countRequest(context);mark(report,'count_response_private',count.count===3&&count.private,'privacy');
  let guestPrivacyPassed=false;
  try{
   report.category='privacy';guest=await browser.newContext({serviceWorkers:'block',acceptDownloads:false});
   await guest.route('**/*',async route=>{const request=route.request(),countRequest=request.url()===COUNT,prefix=request.method()==='POST'?'guest_count_':'guest_get_';
    const allowed=allowedGuestCartRequest(request.url(),request.method(),request.postData()??'',request.resourceType(),input);
    if(countRequest){report.diagnostics[prefix+'request_seen']=true;report.diagnostics[prefix+(allowed?'request_allowed':'request_denied')]=true;}
    return allowed?route.continue():route.abort('blockedbyclient');});
   guest.on('response',response=>{if(response.url()===COUNT)observeGuestCountResponse(response.status(),response.headers(),response.request().method()==='POST'?'guest_count_':'guest_get_',report.diagnostics);else if(response.url()===ORIGIN+'/')observeGuestHomepage(response.status(),response.headers(),input.run,report.diagnostics);});
   guest.on('requestfailed',request=>{if(request.url()===COUNT)observeGuestNetworkFailure(request.failure()?.errorText,request.method()==='POST'?'guest_count_':'guest_get_',report.diagnostics);});
   if(typeof guest.routeWebSocket==='function')await guest.routeWebSocket('**/*',socket=>socket.close());
   const guestPage=await guest.newPage();const warm=await guestPage.goto(ORIGIN+'/',{waitUntil:'domcontentloaded',timeout:30000});
   report.diagnostics.guest_homepage_final_root=warm?.url()===ORIGIN+'/';
   if(report.diagnostics.guest_homepage_final_root)observeGuestHomepage(warm.status(),warm.headers(),input.run,report.diagnostics);
   const noAuth=async()=>(await guest.cookies()).every(cookie=>!/^wordpress_(?:logged_in_|sec_|[a-f0-9]{32}$)/.test(cookie.name));
   const anonymous=await noAuth(),guestRequest=guestCartCountBrowserRequest(guestPage,report.diagnostics);report.diagnostics.guest_authentication_absent=anonymous;
   const anon=await countRequest({request:guestRequest});report.diagnostics.guest_count_zero=anon.count===0;report.diagnostics.guest_count_private=anon.private;
   report.diagnostics.guest_authentication_absent=anonymous&&await noAuth();
   mark(report,'anonymous_session_isolated',report.diagnostics.guest_homepage_http_ok&&report.diagnostics.guest_homepage_final_root&&report.diagnostics.guest_authentication_absent&&anon.count===0&&anon.private,'privacy');
   const denied=await guestRequest.get(COUNT,{maxRedirects:0,timeout:30000});mark(report,'count_get_denied',denied.status()===403,'privacy');
   guestPrivacyPassed=true;
  }catch{/* Keep both privacy gates required while collecting independent owned-cart checks. */}
  finally{await guest?.close().catch(()=>{});guest=undefined;}
  state.phase='browse';report.category='geometry';await page.goto(input.product_url,{waitUntil:'domcontentloaded'});await badges(page,3);
  let g=await geometry(page);mark(report,'badge_yellow_circle_14px',g.valid&&g.shown>0,'geometry');mark(report,'badge_accessible_quantity',g.labels,'geometry');mark(report,'desktop_header_height_preserved',g.height,'geometry');
  let mobileHeight=true,mobileVisible=true;
  for(const width of [390,320]){
   const prefix='mobile_'+width+'_';
   try{
    await page.setViewportSize({width,height:844});await page.waitForTimeout(150);
    g=await geometry(page);report.diagnostics[prefix+'badge_initial_visible']=g.shown>0;report.diagnostics[prefix+'header_height_ok']=g.height;mobileHeight=mobileHeight&&g.height;
    // A closed The7 drawer can leave its close icon "visible" offscreen.
    // Inspect the existing header badge first; do not click that unrelated icon.
    if(!g.shown){
     report.diagnostics[prefix+'menu_needed']=true;
     const menu=page.locator('.dt-mobile-menu-icon:visible,.mobile-navigation .menu-toggle:visible').first();
     report.diagnostics[prefix+'menu_control_present']=await menu.count()>0;
     await menu.click();report.diagnostics[prefix+'menu_click_completed']=true;await page.waitForTimeout(250);g=await geometry(page);
    }
    report.diagnostics[prefix+'badge_final_visible']=g.shown>0&&g.valid;mobileVisible=mobileVisible&&g.shown>0&&g.valid;
   }catch{report.diagnostics[prefix+'exception']=true;mobileHeight=false;mobileVisible=false;}
  }
  report.checks.mobile_header_height_preserved=mobileHeight;report.checks.mobile_badge_visible=mobileVisible;
  let geometryPassed=mobileHeight&&mobileVisible;
  try{await page.evaluate(()=>{document.documentElement.setAttribute('data-theme','dark');document.body.classList.add('petish-dark-mode');});g=await geometry(page);report.checks.dark_mode_badge_verified=g.valid&&g.shown>0;}
  catch{report.checks.dark_mode_badge_verified=false;}
  geometryPassed=geometryPassed&&report.checks.dark_mode_badge_verified;
  await page.reload({waitUntil:'domcontentloaded'});await badges(page,3);mark(report,'reload_quantity_preserved',(await countRequest(context)).count===3,'update');
  await page.goto(CART,{waitUntil:'domcontentloaded'});await badges(page,3);state.phase='remove';report.category='remove';
  if(blocks)await page.locator('.wc-block-cart .wc-block-cart-item__remove-link').click();else await page.locator('.woocommerce-cart-form a.remove').click();
  await badges(page,0);await cartRead(context,input,0);mark(report,'remove_empty_verified',(await countRequest(context)).count===0,'remove');
  mark(report,'request_scope_enforced',state.allowed>0&&state.loginUsed&&state.key.length===32&&state.mutations>=2
   &&!allowedRequest(ORIGIN+'/checkout/','GET','','document',input,state)&&!allowedRequest(ORIGIN+'/wp-json/wc/store/v1/checkout','POST','{}','fetch',input,state),'scope');
  if(!guestPrivacyPassed)throw new Error('privacy');
  if(!geometryPassed)throw new Error('geometry');
  report.status='success';report.category='complete';
 }catch(error){report.category=CATEGORIES.has(error?.message)?error.message:report.category;if(report.category==='update'&&page&&context&&!report.diagnostics.update_server_count_read)await updateObservation(page,context,report);}
 finally{await Promise.allSettled(responseTasks);await guest?.close().catch(()=>{});await context?.close().catch(()=>{});await browser?.close().catch(()=>{});}
 const result=publicReport(report);if(args.length===2){const file=path.resolve(args[1]);if(fs.realpathSync(path.dirname(file))!==path.dirname(file))throw new Error('input');fs.writeFileSync(file,JSON.stringify(result,null,2)+'\n',{flag:'wx',mode:0o600});}
 return result.status==='success'?0:1;
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url))process.exitCode=await main(process.argv.slice(2));
