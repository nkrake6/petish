/** Anonymous count privacy boundary; no HTTP, auth or cart mutation. */
import assert from 'node:assert/strict';
import { allowedGuestCartRequest,guestCartCountBrowserRequest } from '../tools/homepage/cart-badge-browser.mjs';
const origin='https://staging.petish.biz',count=origin+'/?wc-ajax=petish_cart_count';
const login=origin+'/wp-login.php?redirect_to='+encodeURIComponent(origin+'/my-account/');
const input={product_url:origin+'/product/owned/'};
for(const method of ['GET','POST'])assert(allowedGuestCartRequest(count,method,'','fetch',input));
assert(allowedGuestCartRequest(login,'GET','','document',input));
assert(allowedGuestCartRequest(origin+'/wp-includes/js/jquery/jquery.min.js?ver=1','GET','','script',input));
for(const [url,method,body] of [[login,'POST','log=owned&pwd=secret'],[count,'POST','customer_id=7'],[count+'&customer_id=7','POST',''],
 [origin+'/checkout/','GET',''],[origin+'/wp-json/wc/store/v1/cart/update-item','POST','{}'],[origin+'/product/owned/','GET',''],
 ['https://petish.biz/?wc-ajax=petish_cart_count','POST',''],[count,'DELETE','']])assert(!allowedGuestCartRequest(url,method,body,'fetch',input));
let methodSeen,response,fetches=0;const diagnostics={};
const page={evaluate:async(fn,args)=>{
 const prior=globalThis.fetch;globalThis.fetch=async(target,options)=>{
  fetches++;assert.equal(target,count);methodSeen=options.method;assert(['GET','POST'].includes(methodSeen));
  assert.equal(options.mode,'same-origin');assert.equal(options.credentials,'same-origin');assert.equal(options.cache,'no-store');assert.equal(options.redirect,'error');assert(!('body'in options));
  assert.deepEqual(options.headers,{Accept:'application/json'});assert(options.signal instanceof AbortSignal);return response;
 };try{return await fn(args);}finally{globalThis.fetch=prior;}
}};
const request=guestCartCountBrowserRequest(page,diagnostics),postOptions={data:'',headers:{Origin:origin},maxRedirects:0,timeout:30000};
response=new Response(JSON.stringify({success:true,data:{count:0,label:'0 items in cart'}}),{status:200,headers:{'cache-control':'private, no-store',vary:'Cookie'}});
let result=await request.post(count,postOptions);assert.equal(result.status(),200);assert.equal((await result.json()).data.count,0);assert.equal(methodSeen,'POST');assert(diagnostics.guest_count_http_200);assert.equal(result.headers().vary,'Cookie');
response=new Response('Forbidden',{status:403});result=await request.get(count,{maxRedirects:0,timeout:30000});assert.equal(result.status(),403);assert.equal(methodSeen,'GET');assert(diagnostics.guest_get_http_403);
await assert.rejects(request.post(count,{...postOptions,data:'customer_id=7'}));await assert.rejects(request.get(origin+'/',{maxRedirects:0,timeout:30000}));assert.equal(fetches,2);
response=new Response(new Uint8Array(65537),{status:200});await assert.rejects(request.post(count,postOptions));assert(diagnostics.guest_count_fetch_exception);
assert(Object.values(diagnostics).every(value=>typeof value==='boolean'));
console.log('Cart guest browser privacy checks passed.');
