/** Hosted acceptance limited to journal-owned staging users, products and reviews. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
const ROOT=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const require=createRequire(path.join(ROOT,'tools/staging-rehearsal/package.json'));
const {chromium}=require('playwright');
const ORIGIN='https://staging.petish.biz';
export const CHECK_KEYS=Object.freeze([
 'input_owned','browser_started','owner_login','member_login','dashboard_counts','mobile_320','mobile_390','desktop_1440',
 'dashboard_uncacheable','hosted_upload_limits_sufficient','public_product_section','reviews_below_details','public_review_visible','pending_review_hidden',
 'rating_filter','self_vote_controls_absent','helpful_remove','helpful_add','vote_switch','vote_withdraw',
 'vote_form_available','vote_form_fields_valid','vote_post_authorized','vote_response_accepted','vote_redirect_scoped',
 'dashboard_received_reactions','composer_ready','rating_selected','draft_filled','invalid_nonce_post_authorized','invalid_nonce_response_accepted','invalid_nonce_denied','real_photo_video_upload','uploaded_review_pending',
 'submission_response_accepted','pending_count_updated','pending_photo_owner_access','pending_video_owner_access','video_50mb_uploaded','pending_avatar_owner_access',
 'pending_media_foreign_denied','pending_media_guest_denied','pending_media_uncacheable','request_scope_enforced',
]);
export const HTTP_PHASE_KEYS=Object.freeze(['vote','invalid_nonce','submission','owner_photo','owner_video','owner_avatar','foreign_photo','foreign_video','foreign_avatar','guest_photo','guest_video','guest_avatar']);
export const HTTP_STATUS_KEYS=Object.freeze(['ok_200','created_201','empty_204','redirect_301','redirect_302','redirect_303','redirect_307','redirect_308','bad_request_400','unauthorized_401','forbidden_403','missing_404','method_405','conflict_409','too_large_413','invalid_422','throttled_429','server_500','gateway_502','unavailable_503','timeout_504','other_success','other_redirect','other_client_error','other_server_error','other']);
export const HTTP_REDIRECT_KEYS=Object.freeze(['missing','owned_product','account','login','other_staging','external_or_invalid']);
export const HTTP_BODY_KEYS=Object.freeze(['not_read','redirect_not_read','empty','wordpress_zero','wordpress_nonce_failure','wordpress_permission','wordpress_failure','staging_guard_denied','access_denied','browser_challenge','server_failure','other_html','other_text','unavailable','too_large']);
const result={status:'failure',checks:Object.fromEntries(CHECK_KEYS.map(k=>[k,false])),failed_check:'',diagnostics:{}};
function check(name,value){if(!CHECK_KEYS.includes(name))throw Error('input_owned');result.checks[name]=value===true;if(value!==true){result.failed_check=name;throw Error(name);}}
function safeURL(raw){const u=new URL(raw);if(u.origin!==ORIGIN||u.username||u.password)throw Error('input_owned');return u;}
function inputFile(name){const st=fs.lstatSync(name);if(!st.isFile()||st.isSymbolicLink()||st.nlink!==1||(st.mode&0o777)!==0o600||st.size>1048576)throw Error('input_owned');const v=JSON.parse(fs.readFileSync(name,'utf8'));
 if(v.format!=='petish-customer-reviews-browser-v1'||v.url!==ORIGIN)throw Error('input_owned');
 for(const role of ['owner','member']){const u=v[role];if(!u||!Number.isSafeInteger(u.id)||u.id<1||!/^pcr-proof-[a-f0-9]{16}-(owner|member)$/.test(u.login??'')||u.email!==u.login+'@example.invalid'||typeof u.password!=='string'||u.password.length<24||u.password.length>128)throw Error('input_owned');}
 if(v.owner.id===v.member.id)throw Error('input_owned');
 for(const key of ['product_id','pending_product_id','submission_product_id','approved_review_id','pending_review_id'])if(!Number.isSafeInteger(v[key])||v[key]<1)throw Error('input_owned');
 for(const key of ['product_url','pending_product_url','submission_product_url']){const u=safeURL(v[key]);if(!u.pathname.startsWith('/product/')||!u.pathname.includes(v.owner.login.replace(/-owner$/,'')))throw Error('input_owned');}
 const account=safeURL(v.account_url);if(account.pathname!=='/my-account/'||account.searchParams.get('my-reviews')!=='1')throw Error('input_owned');v.ownedReviewIds=new Set([v.approved_review_id,v.pending_review_id]);return v;
}
function noStore(response){return response&&/\bno-store\b/i.test(response.headers()['cache-control']??'');}
async function diagnoseHTTP(response,input,phase){
 if(!HTTP_PHASE_KEYS.includes(phase))throw Error('input_owned');
 const code=response.status();const statuses={200:'ok_200',201:'created_201',204:'empty_204',301:'redirect_301',302:'redirect_302',303:'redirect_303',307:'redirect_307',308:'redirect_308',400:'bad_request_400',401:'unauthorized_401',403:'forbidden_403',404:'missing_404',405:'method_405',409:'conflict_409',413:'too_large_413',422:'invalid_422',429:'throttled_429',500:'server_500',502:'gateway_502',503:'unavailable_503',504:'timeout_504'};
 const status=statuses[code]??(code>=500&&code<600?'other_server_error':code>=400&&code<500?'other_client_error':code>=300&&code<400?'other_redirect':code>=200&&code<300?'other_success':'other');
 const headers=response.headers();let redirect='missing';if(headers.location){try{const u=safeURL(new URL(headers.location,ORIGIN).href);redirect=[input.product_url,input.pending_product_url,input.submission_product_url].some(raw=>safeURL(raw).pathname===u.pathname)?'owned_product':u.pathname==='/my-account/'?'account':u.pathname==='/wp-login.php'?'login':'other_staging';}catch{redirect='external_or_invalid';}}
 let body=code>=300&&code<400?'redirect_not_read':'not_read';
 // Error bodies are classified in memory only. Never inspect media payloads or
 // retain response text, arbitrary headers, URLs, tokens or server paths.
 if(['vote','invalid_nonce','submission'].includes(phase)&&code>=400){
  if(Number(headers['content-length']??0)>65536)body='too_large';
  else try{const text=await response.text();body=text.length>65536?'too_large':text.trim()===''?'empty':text.trim()==='0'?'wordpress_zero':text.trim()==='-1'||/are you sure you want to do this|nonce verification failed/i.test(text)?'wordpress_nonce_failure':/sorry, you are not allowed to access this page/i.test(text)?'wordpress_permission':/private staging|staging access|staging[^<\n]{0,80}(?:denied|not allowed|blocked)/i.test(text)?'staging_guard_denied':/verify you are human|checking your browser|cf-chl|captcha/i.test(text)?'browser_challenge':/there has been a critical error|error establishing a database connection/i.test(text)?'server_failure':/access denied|request forbidden|403 forbidden/i.test(text)?'access_denied':/id=["']error-page["']|wp-die-message/i.test(text)?'wordpress_failure':/text\/html/i.test(headers['content-type']??'')?'other_html':'other_text';}catch{body='unavailable';}
 }
 if(!HTTP_STATUS_KEYS.includes(status)||!HTTP_REDIRECT_KEYS.includes(redirect)||!HTTP_BODY_KEYS.includes(body))throw Error('input_owned');
 result.diagnostics[phase]={status,redirect,body};
}
function onlyQuery(u,rules){return [...u.searchParams.keys()].every(k=>Object.hasOwn(rules,k)&&u.searchParams.getAll(k).length===1&&rules[k](u.searchParams.get(k)));}
function ownedMedia(u,input){return u.pathname==='/wp-admin/admin-post.php'&&u.searchParams.size===3&&onlyQuery(u,{
 action:v=>v==='petish_customer_review_media',review_id:v=>/^[1-9][0-9]{0,15}$/.test(v)&&input.ownedReviewIds.has(Number(v)),item:v=>/^[0-5]$/.test(v),
});}
function allowedGET(raw,input){let u;try{u=safeURL(raw);}catch{return false;}
 const filters={pcr_rating:v=>/^[0-5]$/.test(v),pcr_media:v=>['all','photos','video'].includes(v),pcr_verified:v=>v==='1',pcr_sort:v=>['newest','oldest','highest','lowest'].includes(v),pcr_page:v=>/^[1-9][0-9]{0,3}$/.test(v)};
 if([input.product_url,input.pending_product_url,input.submission_product_url].some(raw=>safeURL(raw).pathname===u.pathname))return onlyQuery(u,filters);
 if(u.pathname==='/my-account/')return onlyQuery(u,{'my-reviews':v=>v==='1',pcr_product:v=>v===String(input.submission_product_id),pcr_status:v=>['pending','error'].includes(v),pcr_page:filters.pcr_page});
 if(u.pathname==='/my-account/edit-account/')return u.search==='';
 if(u.pathname==='/wp-login.php')return onlyQuery(u,{redirect_to:v=>{try{return safeURL(v).pathname==='/my-account/'&&safeURL(v).search==='';}catch{return false;}},reauth:v=>v==='1'});
 if(ownedMedia(u,input))return true;
 // The remaining requests are ordinary same-origin static assets only.
 return /^\/(?:wp-content|wp-includes)\/(?:[A-Za-z0-9_@.-]+\/)*[A-Za-z0-9_@.-]+\.(?:css|js|mjs|woff2?|ttf|otf|eot|png|jpe?g|gif|webp|avif|svg|ico|mp4)$/i.test(u.pathname)&&!/(?:^|\/)\.\.?\//.test(u.pathname)&&onlyQuery(u,{ver:v=>/^[A-Za-z0-9_.-]{0,80}$/.test(v),v:v=>/^[A-Za-z0-9_.-]{0,80}$/.test(v)});
}
const guardedActors=[];
function parseFields(request){const type=request.headers()['content-type']??'';const bytes=request.postDataBuffer();if(!bytes||bytes.length>76*1024*1024)return null;
 if(type.startsWith('application/x-www-form-urlencoded')){const params=new URLSearchParams(bytes.toString());if([...params.keys()].some(k=>params.getAll(k).length!==1))return null;return Object.fromEntries(params);}
 const boundary=type.match(/^multipart\/form-data;\s*boundary=(?:"([A-Za-z0-9_-]{1,100})"|([A-Za-z0-9_-]{1,100}))$/i);if(!boundary)return null;const marker='--'+(boundary[1]||boundary[2]);const parts=bytes.toString('latin1').split(marker);const out={};
 if(parts[0]!==''||!/^--\r?\n?$/.test(parts.at(-1)))return null;
 for(const part of parts.slice(1,-1)){const split=part.indexOf('\r\n\r\n');if(!part.startsWith('\r\n')||split<0||split>4096||!part.endsWith('\r\n'))return null;const header=part.slice(2,split);const field=header.match(/(?:^|\r\n)Content-Disposition: form-data; name="([a-z_\[\]]+)"(?:; filename="[^"\r\n]*")?(?:\r\n|$)/i);if(!field)return null;
  const key=field[1];if(/; filename=/.test(header)){if(!['photos[]','video','avatar'].includes(key))return null;continue;}
  if(key in out)return null;out[key]=part.slice(split+4,-2);
 }return out;
}
async function guardedContext(browser,input,role){const context=await browser.newContext({viewport:{width:390,height:900},locale:'en-AU',reducedMotion:'reduce',acceptDownloads:false,serviceWorkers:'block'});const state={expected:null,used:false,routeInstalled:false,gets:0,posts:0,manualPosts:0,mediaGets:0,blocked:0};
 await context.route('**/*',async route=>{const request=route.request();if(['GET','HEAD'].includes(request.method())&&allowedGET(request.url(),input)){state.gets++;return route.continue();}
  if(request.method()==='POST'&&state.expected&&!state.used){let u;try{u=safeURL(request.url());}catch{}const fields=parseFields(request);const e=state.expected;const allowedKeys=e.login?['log','pwd','wp-submit','redirect_to','testcookie','rememberme']:(e.vote?['action','review_id','choice','pcr_nonce','_wp_http_referer']:['action','product_id','rating','alias','title','body','consent','pcr_nonce','_wp_http_referer']);
   if(u&&u.search===''&&u.pathname===(e.login?'/wp-login.php':'/wp-admin/admin-post.php')&&fields&&Object.keys(fields).every(k=>allowedKeys.includes(k))&&Object.entries(e.values).every(([k,v])=>fields[k]===v)){state.used=true;state.posts++;return route.continue();}
  }state.blocked++;return route.abort('blockedbyclient');});
 state.routeInstalled=true;if(context.routeWebSocket)await context.routeWebSocket('**/*',socket=>socket.close());const actor={context,state,role,input};guardedActors.push(actor);return actor;
}
async function login(browser,input,role){const actor=await guardedContext(browser,input,role);const page=await actor.context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(45000);actor.page=page;
 const response=await page.goto(ORIGIN+'/wp-login.php?redirect_to='+encodeURIComponent(ORIGIN+'/my-account/'),{waitUntil:'domcontentloaded'});if(!response?.ok())throw Error(role+'_login');await page.locator('#user_login').fill(input[role].login);await page.locator('#user_pass').fill(input[role].password);
 actor.state.expected={login:true,values:{log:input[role].login,pwd:input[role].password,redirect_to:ORIGIN+'/my-account/'}};await Promise.all([page.waitForURL(u=>u.origin===ORIGIN&&u.pathname.startsWith('/my-account/'),{waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);actor.state.expected=null;
 await page.goto(ORIGIN+'/my-account/edit-account/',{waitUntil:'domcontentloaded'});check(role+'_login',actor.state.used&&await page.locator('input[name="account_email"]').inputValue()===input[role].email);return actor;
}
async function dashboard(actor){const r=await actor.page.goto(actor.input.account_url,{waitUntil:'domcontentloaded'});await actor.page.locator('#petish-review-studio').waitFor();return r;}
async function metrics(page){return page.locator('.pcr-stats .pcr-stat dd').evaluateAll(nodes=>nodes.map(n=>Number(n.textContent.replace(/[^0-9]/g,''))));}
async function vote(actor,id,choice){
 const card=actor.page.locator('#pcr-review-'+id);const form=card.locator('.pcr-votes form').filter({has:actor.page.locator('input[name="choice"][value="'+choice+'"]')});
 check('vote_form_available',await form.count()===1);
 const fields=await form.locator('input').evaluateAll(nodes=>Object.fromEntries(nodes.filter(n=>n.name).map(n=>[n.name,n.value])));
 check('vote_form_fields_valid',id===actor.input.approved_review_id&&['helpful','not-helpful','none'].includes(choice)&&fields.action==='petish_customer_review_vote'&&fields.review_id===String(id)&&fields.choice===choice&&/^[A-Za-z0-9]{8,20}$/.test(fields.pcr_nonce??'')&&Object.keys(fields).every(k=>['action','review_id','choice','pcr_nonce','_wp_http_referer'].includes(k))&&await form.getAttribute('method')==='post'&&await form.getAttribute('action')===ORIGIN+'/wp-admin/admin-post.php'&&(!fields._wp_http_referer||fields._wp_http_referer===safeURL(actor.input.product_url).pathname));
 actor.state.used=false;actor.state.expected={vote:true,values:fields};let response;
 try{[response]=await Promise.all([actor.page.waitForResponse(r=>r.url()===ORIGIN+'/wp-admin/admin-post.php'&&r.request().method()==='POST'),form.locator('button[type="submit"]').click()]);}
 catch{throw Error('vote_post_authorized');}
 finally{actor.state.expected=null;}
 check('vote_post_authorized',actor.state.used);await diagnoseHTTP(response,actor.input,'vote');check('vote_response_accepted',response.status()===303);
 let destination;try{destination=safeURL(response.headers().location);}catch{}
 check('vote_redirect_scoped',Boolean(destination&&destination.pathname===safeURL(actor.input.product_url).pathname&&destination.search===''&&destination.hash==='#pcr-review-'+id));
 await actor.page.waitForURL(u=>u.href===destination.href,{waitUntil:'domcontentloaded'});
 await actor.page.goto(actor.input.product_url,{waitUntil:'domcontentloaded'});return actor.page.locator('#pcr-review-'+id);
}
function counts(card){return card.locator('.pcr-votes button strong').evaluateAll(nodes=>nodes.map(n=>Number(n.textContent.replace(/[^0-9]/g,''))));}
async function fillDraft(form,draft){
 check('composer_ready',await form.count()===1&&await form.isVisible());
 await form.locator('label:has(input[name="rating"][value="4"])').click();
 check('rating_selected',await form.locator('[name="rating"][value="4"]').isChecked());
 for(const name of ['alias','title','body'])await form.locator('[name="'+name+'"]').fill(draft[name]);
 await form.locator('[name="consent"]').check();
 check('draft_filled',await form.locator('[name="alias"]').inputValue()===draft.alias&&await form.locator('[name="title"]').inputValue()===draft.title&&await form.locator('[name="body"]').inputValue()===draft.body&&await form.locator('[name="consent"]').isChecked());
}
async function mediaGet(actor,url){const u=safeURL(url);if(!ownedMedia(u,actor.input))throw Error('pending_photo_owner_access');actor.state.mediaGets++;return actor.context.request.get(u.href,{maxRedirects:0,timeout:120000});}
let browser;
try{
 if(process.argv.length!==4)throw Error('input_owned');const input=inputFile(process.argv[2]);check('input_owned',true);
 browser=await chromium.launch({headless:true});check('browser_started',true);const owner=await login(browser,input,'owner');const member=await login(browser,input,'member');
 const accountResponse=await dashboard(owner);check('dashboard_uncacheable',noStore(accountResponse));check('hosted_upload_limits_sufficient',accountResponse.headers()['x-petish-review-upload-limits']==='sufficient');check('dashboard_counts',JSON.stringify(await metrics(owner.page))===JSON.stringify([1,1,1,0]));
 for(const width of [320,390,1440]){await owner.page.setViewportSize({width,height:1000});await owner.page.evaluate(()=>document.fonts.ready);const stats=owner.page.locator('.pcr-stats .pcr-stat');const visible=await stats.evaluateAll(nodes=>nodes.length===4&&nodes.every(n=>n.getBoundingClientRect().width>0&&n.getBoundingClientRect().height>0));const overflow=await owner.page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);check(width===1440?'desktop_1440':'mobile_'+width,visible&&!overflow);}
 await owner.page.goto(input.product_url,{waitUntil:'domcontentloaded'});check('public_product_section',await owner.page.locator('#petish-customer-reviews').count()===1);check('public_review_visible',await owner.page.locator('#pcr-review-'+input.approved_review_id).count()===1);
 const placement=await owner.page.evaluate(()=>{const section=document.querySelector('#petish-customer-reviews');const details=document.querySelector('.woocommerce-tabs');return section&&(!details||Boolean(details.compareDocumentPosition(section)&Node.DOCUMENT_POSITION_FOLLOWING));});check('reviews_below_details',Boolean(placement));
 check('self_vote_controls_absent',await owner.page.locator('#pcr-review-'+input.approved_review_id+' .pcr-votes form').count()===0);
 await owner.page.goto(input.pending_product_url,{waitUntil:'domcontentloaded'});check('pending_review_hidden',await owner.page.locator('#pcr-review-'+input.pending_review_id).count()===0);
 await member.page.goto(input.product_url,{waitUntil:'domcontentloaded'});const filtered=new URL(input.product_url);filtered.searchParams.set('pcr_rating','1');await member.page.goto(filtered.href,{waitUntil:'domcontentloaded'});check('rating_filter',await member.page.locator('#pcr-review-'+input.approved_review_id).count()===0);await member.page.goto(input.product_url,{waitUntil:'domcontentloaded'});
 let card=await vote(member,input.approved_review_id,'none');check('helpful_remove',JSON.stringify(await counts(card))==='[0,0]');card=await vote(member,input.approved_review_id,'helpful');check('helpful_add',JSON.stringify(await counts(card))==='[1,0]');card=await vote(member,input.approved_review_id,'not-helpful');check('vote_switch',JSON.stringify(await counts(card))==='[0,1]');
 await dashboard(owner);check('dashboard_received_reactions',JSON.stringify(await metrics(owner.page))==='[1,1,0,1]');card=await vote(member,input.approved_review_id,'none');check('vote_withdraw',JSON.stringify(await counts(card))==='[0,0]');
 const compose=new URL(input.account_url);compose.searchParams.set('pcr_product',String(input.submission_product_id));await owner.page.goto(compose.href,{waitUntil:'domcontentloaded'});const form=owner.page.locator('[data-pcr-form]');await form.waitFor();const nonce=await form.locator('[name="pcr_nonce"]').inputValue();
 const draft={action:'petish_customer_review_submit',product_id:String(input.submission_product_id),rating:'4',alias:'Staging Reviewer',title:'Owned browser review fixture',body:'A synthetic first-hand review used only to check private staging.',consent:'1',pcr_nonce:nonce};
 await fillDraft(form,draft);await form.locator('[name="pcr_nonce"]').evaluate(node=>{node.value='invalid';});owner.state.used=false;owner.state.expected={values:{...draft,pcr_nonce:'invalid'}};let bad;
 try{[bad]=await Promise.all([owner.page.waitForResponse(r=>r.url()===ORIGIN+'/wp-admin/admin-post.php'&&r.request().method()==='POST'),form.locator('button[type="submit"]').click()]);}catch{throw Error('invalid_nonce_post_authorized');}finally{owner.state.expected=null;}
 check('invalid_nonce_post_authorized',owner.state.used);await diagnoseHTTP(bad,input,'invalid_nonce');check('invalid_nonce_response_accepted',bad.status()===303);await owner.page.waitForURL(u=>u.href===input.account_url,{waitUntil:'domcontentloaded'});await dashboard(owner);check('invalid_nonce_denied',JSON.stringify(await metrics(owner.page))==='[1,1,0,0]');await owner.page.goto(compose.href,{waitUntil:'domcontentloaded'});
 await fillDraft(form,draft);await form.locator('details summary').click();
 const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAEklEQVR4nGPoPz/rPzJmIF0AAESmL3GjpJ8GAAAAAElFTkSuQmCC','base64');
 await form.locator('[name="photos[]"]').setInputFiles({name:'owned-review.png',mimeType:'image/png',buffer:png});await form.locator('[name="avatar"]').setInputFiles({name:'owned-avatar.png',mimeType:'image/png',buffer:png});
 const clip=fs.readFileSync(path.join(ROOT,'tools/pet-club/fixtures/synthetic-valid.mp4'));
 const videoBytes=50*1024*1024;const video=Buffer.alloc(videoBytes);clip.copy(video);
 const padding=videoBytes-clip.length;if(padding<8)throw Error('video_50mb_uploaded');video.writeUInt32BE(padding,clip.length);video.write('free',clip.length+4,'ascii');
 await form.locator('[name="video"]').setInputFiles({name:'owned-limit-video.mp4',mimeType:'video/mp4',buffer:video},{timeout:120000});
 draft.pcr_nonce=await form.locator('[name="pcr_nonce"]').inputValue();owner.state.used=false;owner.state.expected={values:draft};
 const [submitted]=await Promise.all([owner.page.waitForResponse(r=>r.url()===ORIGIN+'/wp-admin/admin-post.php'&&r.request().method()==='POST',{timeout:120000}),form.locator('button[type="submit"]').click({timeout:120000})]);owner.state.expected=null;check('real_photo_video_upload',owner.state.used);await diagnoseHTTP(submitted,input,'submission');check('submission_response_accepted',submitted.status()===303);await owner.page.waitForURL(u=>u.origin===ORIGIN&&u.searchParams.get('pcr_status')==='pending',{waitUntil:'domcontentloaded',timeout:120000});
 const uploaded=owner.page.locator('.pcr-my-reviews .pcr-review').filter({has:owner.page.getByRole('heading',{name:draft.title,exact:true})});check('uploaded_review_pending',await uploaded.count()===1&&/Awaiting moderation/.test(await uploaded.textContent()));check('pending_count_updated',JSON.stringify(await metrics(owner.page))==='[1,2,0,0]');
 const uploadedID=(await uploaded.getAttribute('id'))?.match(/^pcr-review-([1-9][0-9]{0,15})$/);if(!uploadedID||input.ownedReviewIds.has(Number(uploadedID[1])))throw Error('uploaded_review_pending');input.ownedReviewIds.add(Number(uploadedID[1]));await owner.page.reload({waitUntil:'domcontentloaded'});
 const photo=await uploaded.locator('.pcr-media img').getAttribute('src');const videoURL=await uploaded.locator('.pcr-media video source').getAttribute('src');const avatar=await uploaded.locator('.pcr-avatar img').getAttribute('src');
 const photoResponse=await mediaGet(owner,photo),videoResponse=await mediaGet(owner,videoURL),avatarResponse=await mediaGet(owner,avatar);
 for(const [phase,response]of[['owner_photo',photoResponse],['owner_video',videoResponse],['owner_avatar',avatarResponse]])await diagnoseHTTP(response,input,phase);
 check('pending_photo_owner_access',photoResponse.status()===200&&/^image\//.test(photoResponse.headers()['content-type']??''));check('pending_video_owner_access',videoResponse.status()===200&&/^video\/mp4/.test(videoResponse.headers()['content-type']??''));check('video_50mb_uploaded',Number(videoResponse.headers()['content-length'])===videoBytes);check('pending_avatar_owner_access',avatarResponse.status()===200&&/^image\//.test(avatarResponse.headers()['content-type']??''));check('pending_media_uncacheable',[photoResponse,videoResponse,avatarResponse].every(noStore));
 const foreign=await Promise.all([photo,videoURL,avatar].map(url=>mediaGet(member,url)));for(const [i,kind]of['photo','video','avatar'].entries())await diagnoseHTTP(foreign[i],input,'foreign_'+kind);check('pending_media_foreign_denied',foreign.every(r=>[403,404].includes(r.status())));
 const guest=await guardedContext(browser,input,'guest');const anonymous=await Promise.all([photo,videoURL,avatar].map(url=>mediaGet(guest,url)));for(const [i,kind]of['photo','video','avatar'].entries())await diagnoseHTTP(anonymous[i],input,'guest_'+kind);check('pending_media_guest_denied',anonymous.every(r=>[403,404].includes(r.status())||([302,303].includes(r.status())&&safeURL(r.headers().location).pathname==='/wp-login.php')));
 const forbidden=['/','/cart/','/checkout/','/wp-json/','/?wc-api=anything','/?rest_route=/anything','/?wc-ajax=anything','/wp-cron.php','/product/unowned/','/my-account/customer-logout/','/wp-admin/admin-post.php?action=petish_customer_review_media&review_id=0&item=0','/wp-content/unowned.php'];
 const policiesReject=forbidden.every(p=>!allowedGET(ORIGIN+p,input))&&!allowedGET(input.product_url+'?add-to-cart='+input.product_id,input)&&!allowedGET('https://petish.biz/my-account/',input);
 check('request_scope_enforced',policiesReject&&guardedActors.length===3&&guardedActors.every(a=>a.state.routeInstalled)&&owner.state.gets>0&&member.state.gets>0&&owner.state.posts===3&&member.state.posts===5&&guest.state.posts===0&&guardedActors.every(a=>a.state.manualPosts===0&&a.state.mediaGets===3));result.status='success';
}catch(error){if(CHECK_KEYS.includes(error.message))result.failed_check=error.message;}
finally{if(browser)await browser.close().catch(()=>{});if(process.argv[3])fs.writeFileSync(process.argv[3],JSON.stringify(result,null,2)+'\n',{mode:0o600});}
process.exitCode=result.status==='success'?0:1;
