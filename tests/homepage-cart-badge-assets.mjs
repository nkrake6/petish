/** Fixed read-only asset retry contract; no HTTP or WordPress fixture. */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { fetchCartAsset } from '../tools/homepage/cart-badge-browser.mjs';
const bytes=Buffer.from('expected static asset'),digest=createHash('sha256').update(bytes).digest('hex');
async function trial(sequence,relative='assets/css/cart-badge.css'){
 const diagnostics={},calls=[];
 const request={async get(url,options){calls.push({url,options});const next=sequence.shift();
  if(next==='transport')throw new Error('private network error');
  return {status:()=>next.status,body:async()=>{if(next.bodyError)throw new Error('private body error');return next.bytes??bytes;}};
 }};
 const exact=await fetchCartAsset(request,relative,digest,diagnostics);
 for(const call of calls){assert.equal(call.url,'https://staging.petish.biz/wp-content/themes/dt-the7-child/'+relative+'?ver='+digest);assert.deepEqual(call.options,{maxRedirects:0,timeout:30000});}
 assert(Object.values(diagnostics).every(value=>typeof value==='boolean'));
 return {exact,diagnostics,calls};
}
let result=await trial([{status:200}]);assert(result.exact);assert(result.diagnostics.asset_css_hash_matches);assert.equal(result.calls.length,1);
for(const status of [408,429,500,503]){
 result=await trial([{status},{status:200}]);assert(result.exact);assert.equal(result.calls.length,2);
}
for(const status of [301,400,403,404]){
 result=await trial([{status},{status:200}]);assert(!result.exact);assert.equal(result.calls.length,1);
 if(status===403||status===404)assert(result.diagnostics['asset_css_status_'+status]);
 assert(result.diagnostics.asset_css_hash_matches); // Hash observation is independent of HTTP acceptance.
}
result=await trial(['transport',{status:200}],'assets/js/cart-badge.js');assert(result.exact);assert(result.diagnostics.asset_js_fetch_exception);assert.equal(result.calls.length,2);
result=await trial([{status:200,bytes:Buffer.from('wrong hash')},{status:200}]);assert(!result.exact);assert.equal(result.calls.length,1);assert(!result.diagnostics.asset_css_hash_matches);
result=await trial(['transport','transport','transport',{status:200}]);assert(!result.exact);assert.equal(result.calls.length,3);
result=await trial([{status:500},{status:502},{status:503},{status:200}]);assert(!result.exact);assert.equal(result.calls.length,3);assert(result.diagnostics.asset_css_status_5xx);
result=await trial([{status:200,bodyError:true},{status:200}]);assert(result.exact);assert(result.diagnostics.asset_css_fetch_exception);
let calls=0;const request={async get(){calls++;}};
await assert.rejects(fetchCartAsset(request,'../../wp-config.php',digest,{}));
await assert.rejects(fetchCartAsset(request,'assets/css/cart-badge.css','bad digest',{}));assert.equal(calls,0);
console.log('Cart badge fixed asset GET checks passed.');
