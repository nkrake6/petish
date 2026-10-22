/** Anonymous count privacy boundary; no HTTP, auth or cart mutation. */
import assert from 'node:assert/strict';
import { allowedGuestCartRequest,guestCartCountBrowserRequest,observeGuestCountResponse,observeGuestNetworkFailure,observeGuestHomepage,guestCartContinueOptions } from '../tools/homepage/cart-badge-browser.mjs';
const origin='https://staging.petish.biz',count=origin+'/?wc-ajax=petish_cart_count';
const login=origin+'/wp-login.php?redirect_to='+encodeURIComponent(origin+'/my-account/');
const input={product_url:origin+'/product/owned/',guest_access_token:'c'.repeat(64)};
for(const method of ['GET','POST'])assert(allowedGuestCartRequest(count,method,'','fetch',input));
assert(!allowedGuestCartRequest(origin+'/','GET','','document',input));assert(allowedGuestCartRequest(login,'GET','','document',input));
for(const method of ['GET','POST']){const options=guestCartContinueOptions(count,method,'',{Accept:'application/json'},input);assert.equal(options.headers['x-petish-cart-guest-fixture'],input.guest_access_token);}
for(const url of [login,origin+'/wp-includes/js/jquery/jquery.min.js?ver=1',origin+'/'])assert(!('x-petish-cart-guest-fixture'in guestCartContinueOptions(url,'GET','',{'X-Petish-Cart-Guest-Fixture':'must-be-stripped'},input).headers));
assert(!('x-petish-cart-guest-fixture'in guestCartContinueOptions(count,'POST','unsafe=1',{},input).headers));
assert(allowedGuestCartRequest(origin+'/wp-includes/js/jquery/jquery.min.js?ver=1','GET','','script',input));
for(const [url,method,body] of [[login,'POST','log=owned&pwd=secret'],[count,'POST','customer_id=7'],[count+'&customer_id=7','POST',''],
 [origin+'/checkout/','GET',''],[origin+'/wp-json/wc/store/v1/cart/update-item','POST','{}'],[origin+'/product/owned/','GET',''],
 ['https://petish.biz/?wc-ajax=petish_cart_count','POST',''],[count,'DELETE','']])assert(!allowedGuestCartRequest(url,method,body,'fetch',input));
let methodSeen,response,fetches=0,fetchFailure=false,cspFailure=false;const diagnostics={};
const page={evaluate:async(fn,args)=>{
 const prior={fetch:globalThis.fetch,location:globalThis.location,document:globalThis.document};let policy;
 globalThis.location={origin};globalThis.document={addEventListener:(type,fn)=>{assert.equal(type,'securitypolicyviolation');policy=fn;},removeEventListener:()=>{}};
 globalThis.fetch=async(target,options)=>{
  fetches++;assert.equal(target,count);methodSeen=options.method;assert(['GET','POST'].includes(methodSeen));
  assert.equal(options.mode,'same-origin');assert.equal(options.credentials,'same-origin');assert.equal(options.cache,'no-store');assert.equal(options.redirect,'manual');assert(!('body'in options));
  assert.deepEqual(options.headers,{Accept:'application/json'});assert(options.signal instanceof AbortSignal);
  if(cspFailure)policy({effectiveDirective:'connect-src',blockedURI:origin});
  if(fetchFailure)throw new TypeError('private transport failure');
  return response;
 };try{return await fn(args);}finally{Object.assign(globalThis,prior);}
}};
const request=guestCartCountBrowserRequest(page,diagnostics),postOptions={data:'',headers:{Origin:origin},maxRedirects:0,timeout:30000};
response=new Response(JSON.stringify({success:true,data:{count:0,label:'0 items in cart'}}),{status:200,headers:{'cache-control':'private, no-store',vary:'Cookie','content-type':'application/json','x-petish-cart-guest-access':'authorized'}});
let result=await request.post(count,postOptions);assert.equal(result.status(),200);assert.equal((await result.json()).data.count,0);assert.equal(methodSeen,'POST');assert(diagnostics.guest_count_http_200);assert.equal(result.headers().vary,'Cookie');assert(diagnostics.guest_count_page_same_origin);assert(diagnostics.guest_count_access_authorized);
response=new Response('Forbidden',{status:403,headers:{'x-petish-cart-guest-access':'authorized'}});result=await request.get(count,{maxRedirects:0,timeout:30000});assert.equal(result.status(),403);assert.equal(methodSeen,'GET');assert(diagnostics.guest_get_http_403);assert(diagnostics.guest_get_access_authorized);
await assert.rejects(request.post(count,{...postOptions,data:'customer_id=7'}));await assert.rejects(request.get(origin+'/',{maxRedirects:0,timeout:30000}));assert.equal(fetches,2);
response=new Response(new Uint8Array(65537),{status:200,headers:{'content-type':'text/html'}});await assert.rejects(request.post(count,postOptions));assert(diagnostics.guest_count_fetch_exception);assert(diagnostics.guest_count_oversize);assert(diagnostics.guest_count_http_200);assert(diagnostics.guest_count_content_type_html);assert(!diagnostics.guest_count_transport_error);
response=new Response('not JSON',{status:200});result=await request.post(count,postOptions);await assert.rejects(result.json());assert(diagnostics.guest_count_json_invalid);
fetchFailure=true;cspFailure=true;await assert.rejects(request.post(count,postOptions));assert(diagnostics.guest_count_csp_violation);assert(diagnostics.guest_count_transport_error);
const redirects={};observeGuestCountResponse(302,{'content-type':'text/html'},'guest_count_',redirects);assert(redirects.guest_count_response_received);assert(redirects.guest_count_http_redirect);assert(redirects.guest_count_content_type_html);
fetchFailure=false;cspFailure=false;response={type:'opaqueredirect',status:0,headers:new Headers(),body:null};result=await request.post(count,postOptions);assert.equal(result.status(),0);assert(diagnostics.guest_count_opaque_redirect);assert(diagnostics.guest_count_response_received);assert(!diagnostics.guest_count_transport_error);
const scoped={};observeGuestCountResponse(403,{'x-petish-cart-guest-access':'unsupported','x-petish-cart-fixture-reason':'guest_auth_structure'},'guest_count_',scoped);assert(scoped.guest_count_access_unsupported);assert(scoped.guest_count_fixture_denied_guest_auth_structure);assert(!JSON.stringify(scoped).includes(input.guest_access_token));
const network={};observeGuestNetworkFailure('net::ERR_HTTP2_PROTOCOL_ERROR','guest_count_',network);assert(network.guest_count_network_http2_protocol_error);
observeGuestNetworkFailure('net::ERR_BLOCKED_BY_CLIENT','guest_count_',network);assert(network.guest_count_network_blocked_by_client);
observeGuestNetworkFailure('private unknown error','guest_count_',network);assert(network.guest_count_network_other);assert(!JSON.stringify(network).includes('private unknown error'));
const warm={};observeGuestHomepage(503,{location:'/wp-login.php?private=sentinel','x-petish-cart-fixture':'run-1','x-petish-cart-fixture-reason':'unavailable'},'run-1',warm);assert(warm.guest_homepage_http_5xx);assert(warm.guest_homepage_fixture_header_present);assert(warm.guest_homepage_denied_unavailable);assert(!warm.guest_homepage_http_ok);assert(warm.guest_homepage_redirect_login);assert(!JSON.stringify(warm).includes('sentinel'));
assert(Object.values(diagnostics).every(value=>typeof value==='boolean'));
assert(!JSON.stringify(diagnostics).includes('private transport failure'));
console.log('Cart guest browser privacy checks passed.');
