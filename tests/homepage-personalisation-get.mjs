/** Fixed guest GET retry contract; no network, member cookies or mutations. */
import assert from 'node:assert/strict';
import { fetchGuestPersonalisation, anonymousWordPressContext, guestPersonalisationBrowserRequest } from '../tools/homepage/browser.mjs';
const input={login:'owned-customer',customer_email:'owned@example.invalid'};
const good={status:200,headers:{'cache-control':'no-store, private',vary:'Cookie','x-content-type-options':'nosniff'},payload:{success:true,data:{html:'Sign in and choose your pet'}}};
async function trial(sequence){
 const diagnostics={},calls=[];
 const request={async get(url,options){calls.push({url,options});const next=sequence.shift();
  if(next==='network')throw new Error('private network detail');
  return {status:()=>next.status,ok:()=>next.status>=200&&next.status<300,headers:()=>next.headers??{},json:async()=>{if(next.nonJSON)throw new Error('private response');return next.payload??null;}};
 }};
 const facts=await fetchGuestPersonalisation(request,input,diagnostics);
 for(const call of calls){assert.equal(call.url,'https://staging.petish.biz/wp-admin/admin-ajax.php?action=petish_for_your_pet');assert.deepEqual(call.options,{maxRedirects:0,timeout:45000});}
 assert(Object.values(facts).every(value=>typeof value==='boolean'));
 assert(Object.values(diagnostics).every(value=>typeof value==='boolean'));
 assert(!JSON.stringify({facts,diagnostics}).includes('private'));
 return {facts,diagnostics,calls,passed:Object.values(facts).every(Boolean)};
}
let r=await trial([good]);assert(r.passed);assert.equal(r.calls.length,1);
for(const status of [403,408,429,500,503]){
 r=await trial([{status,nonJSON:true},good]);assert(r.passed);assert.equal(r.calls.length,2);assert(r.diagnostics.personalisation_guest_retry_observed);
 assert(r.diagnostics['personalisation_guest_'+(status>=500?'http_5xx':'http_'+status)]);
}
r=await trial(['network',good]);assert(r.passed);assert(r.diagnostics.personalisation_guest_fetch_exception);assert.equal(r.calls.length,2);
for(const status of [301,400,404]){
 r=await trial([{status,nonJSON:true},good]);assert(!r.passed);assert.equal(r.calls.length,1);
 if(status===301)assert(r.diagnostics.personalisation_guest_http_redirect);
 if(status===404)assert(r.diagnostics.personalisation_guest_http_404);
}
r=await trial([{...good,headers:{}},good]);assert(!r.passed);assert.equal(r.calls.length,1);assert(!r.facts.no_store);
r=await trial([{...good,nonJSON:true},good]);assert(!r.passed);assert.equal(r.calls.length,1);assert(!r.facts.json_success);
r=await trial([{status:403},'network','network',good]);assert(!r.passed);assert.equal(r.calls.length,3);assert(Object.values(r.facts).every(value=>!value));
r=await trial([{status:503},{status:503},{status:503},good]);assert(!r.passed);assert.equal(r.calls.length,3);assert(r.diagnostics.personalisation_guest_http_5xx);
assert(anonymousWordPressContext([]));assert(anonymousWordPressContext([{name:'wordpress_test_cookie'},{name:'anonymous-clearance'}]));
for(const name of ['wordpress_logged_in_hash','wordpress_sec_hash','wordpress_'+'a'.repeat(32)])assert(!anonymousWordPressContext([{name}]));
const observed={},pageCalls=[];
const browserResponse={url:()=> 'https://staging.petish.biz/wp-admin/admin-ajax.php?action=petish_for_your_pet',request:()=>({method:()=> 'GET'}),status:()=>200,ok:()=>true,allHeaders:async()=>good.headers,json:async()=>good.payload};
const page={waitForResponse:async(predicate,options)=>{assert(predicate(browserResponse));assert.equal(options.timeout,45000);pageCalls.push('wait');return browserResponse;},
 goto:async(url,options)=>{assert.equal(url,'https://staging.petish.biz/');assert.deepEqual(options,{waitUntil:'domcontentloaded',timeout:45000});pageCalls.push('navigate');return {ok:()=>true};}};
const nativeFacts=await fetchGuestPersonalisation(guestPersonalisationBrowserRequest(page,observed),input,observed);
assert(Object.values(nativeFacts).every(Boolean));assert(observed.personalisation_guest_homepage_http_ok);assert.deepEqual(pageCalls,['wait','navigate']);
await assert.rejects(guestPersonalisationBrowserRequest(page,observed).get('https://foreign.invalid/',{maxRedirects:0,timeout:45000}));
console.log('Homepage guest GET retry checks passed.');
