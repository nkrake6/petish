const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require('../tools/staging-rehearsal/node_modules/playwright');
const root=path.resolve(__dirname,'..');
const html=fs.readFileSync(path.join(__dirname,'fixtures/the7-related-products.html'),'utf8');
const hotMarkup=html.match(/<span class="petish-hot-badge[^]*?screen-reader-text[^]*?<\/span><\/span>/)[0];
const vendor=fs.readFileSync(path.join(__dirname,'fixtures/the7-related-native.css'),'utf8');
const theme=fs.readFileSync(path.join(root,'wordpress/wp-content/themes/dt-the7-child/assets/css/petish.css'),'utf8');
const supplier=fs.readFileSync(path.join(root,'wordpress/wp-content/themes/dt-the7-child/assets/css/supplier-popularity.css'),'utf8');
const themeBefore=theme.replace(/\/\* The7's horizontal related cards[^]*?(?=\/\* Full-width retail price badges)/,'');

(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try{
  const page=await browser.newPage();
  await page.route('**/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#cde7dd"/></svg>'}));
  const base=':root{--the7-h1-color:#14213d;--the7-text-small-font-size:14px;--the7-text-small-line-height:20px;--the7-btn-s-font:500 14px/20px Arial;--the7-btn-s-font-size:14px;--the7-btn-s-padding:10px 12px;--the7-btn-s-min-height:44px;--the7-btn-s-border-width:1px;--the7-btn-s-border-style:solid}body{margin:32px}main{max-width:1080px;margin:auto}.related-product{padding:0;list-style:none}.product-thumbnail>img{display:block;width:100%;height:auto}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px)}.shop-control{width:240px}.shop-control .woo-buttons-on-img>a{display:block;width:100px;height:100px}.shop-control .woo-buttons-on-img>a>img{display:block}';
  async function mount(css,mode){await page.setContent('<!doctype html><html data-theme="'+mode+'"><head><style>'+base+'</style><style>'+vendor+'</style><style>'+css+'</style><style>'+supplier+'</style></head><body id="the7-body" class="petish-site"><main class="content"><div class="shop-control cart-btn-below-img"><div class="woo-buttons-on-img"><a href="#shop-image"><img src="/neutral-product.svg" width="100" height="100" alt="Shop control image">'+hotMarkup+'</a></div><div class="woo-buttons"><a class="product_type_variable" href="#shop">Shop control</a></div></div>'+html+'</main></body></html>');await page.evaluate(()=>document.fonts.ready);}
  for(const width of[1280,900,390,320])for(const mode of['light','dark']){
   await page.setViewportSize({width,height:1400});
   await mount(themeBefore,mode);
   const shopBefore=await page.locator('.shop-control .woo-buttons>a').evaluate(el=>{const s=getComputedStyle(el);return[s.backgroundColor,s.color,el.getBoundingClientRect().width]});
   const shopHotBefore=await page.locator('.shop-control .petish-hot-badge').evaluate(el=>{const s=getComputedStyle(el),r=el.getBoundingClientRect(),label=el.querySelector('.petish-hot-badge__label');return[r.width,r.height,s.backgroundColor,s.borderColor,s.borderWidth,s.borderRadius,s.boxShadow,getComputedStyle(label).position,label.getBoundingClientRect().width]});
   const linksBefore=await page.locator('.related-product a').evaluateAll(elements=>elements.map(el=>el.getAttribute('href')));
   await mount(theme,mode);
   const facts=await page.evaluate(()=>{
    const rect=el=>{const r=el.getBoundingClientRect();return{x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}};
    return [...document.querySelectorAll('.related-product>li')].map(li=>{
     const image=li.querySelector('.product-thumbnail'),badge=li.querySelector('.petish-hot-badge'),title=li.querySelector('.product-title'),price=li.querySelector('.price'),content=li.querySelector('.product-content'),button=li.querySelector('.woo-buttons>a');
     const range=document.createRange();range.selectNodeContents(title);const titleLines=new Set([...range.getClientRects()].filter(r=>r.width>0).map(r=>Math.round(r.y))).size;
     const label=badge?.querySelector('.petish-hot-badge__label'),icon=badge?.querySelector('.petish-hot-badge__icon'),style=badge&&getComputedStyle(badge);
     return{titleLines,row:rect(li).y,image:rect(image),badge:badge?rect(badge):null,icon:icon?rect(icon):null,label:label?{rect:rect(label),position:getComputedStyle(label).position,display:getComputedStyle(label).display,text:label.textContent}:null,badgeStyle:style?{border:style.borderWidth,borderColor:style.borderColor,radius:style.borderRadius,background:style.backgroundColor}:null,price:rect(price),content:rect(content),button:rect(button),buttonBg:getComputedStyle(button).backgroundColor,type:button.classList.contains('product_type_variable')?'variable':'simple',titleHeight:rect(title).height};
    });
   });
   if(width>970){assert(facts.some(card=>card.titleLines===1));assert(facts.some(card=>card.titleLines===2));}
   const rows=new Map();
   assert.equal(facts.filter(card=>card.badge).length,5,'Native supplier eligibility is retained');
   for(const card of facts){
    if(card.badge){
     assert(card.badge.x>=card.image.x-1&&card.badge.right<=card.image.right+1&&card.badge.y>=card.image.y-1&&card.badge.bottom<=card.image.bottom+1,'Hot badge must stay on its image');
     assert.equal(card.badge.width,32);assert.equal(card.badge.height,32);
     assert.equal(card.icon.width,20);assert.equal(card.icon.height,20);
     assert.equal(card.badgeStyle.border,'2px');assert.equal(card.badgeStyle.borderColor,'rgb(180, 35, 24)');assert.equal(card.badgeStyle.background,'rgb(255, 255, 255)');assert(parseFloat(card.badgeStyle.radius)>=16);
     assert.equal(card.label.position,'absolute');assert.notEqual(card.label.display,'none');assert.equal(card.label.text,'Hot');assert(card.label.rect.width<=1&&card.label.rect.height<=1,'Hot text is visually hidden while retained');
    }
    assert(Math.abs(card.price.width-card.content.width)<1,'Price fills its content column');
    assert(card.button.right<=card.content.right+1,'Button fits content');
    assert.equal(card.buttonBg,card.type==='variable'?'rgb(237, 231, 255)':'rgb(15, 76, 92)');
    const group=rows.get(card.row)||[];group.push(card);rows.set(card.row,group);
   }
   for(const cards of rows.values())for(const card of cards){assert(Math.abs(card.price.width-cards[0].price.width)<1,'Same-row prices have equal width');assert(Math.abs(card.price.y-cards[0].price.y)<1,'One/two-line titles align price tops');}
   const cols=width>970?3:width>760?2:1;assert.equal([...rows.values()][0].length,cols);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   assert.deepEqual(await page.locator('.shop-control .woo-buttons>a').evaluate(el=>{const s=getComputedStyle(el);return[s.backgroundColor,s.color,el.getBoundingClientRect().width]}),shopBefore);
   assert.deepEqual(await page.locator('.shop-control .petish-hot-badge').evaluate(el=>{const s=getComputedStyle(el),r=el.getBoundingClientRect(),label=el.querySelector('.petish-hot-badge__label');return[r.width,r.height,s.backgroundColor,s.borderColor,s.borderWidth,s.borderRadius,s.boxShadow,getComputedStyle(label).position,label.getBoundingClientRect().width]}),shopHotBefore);
   assert(shopHotBefore[0]>32&&shopHotBefore[8]>1,'Shop keeps its wider pill and visible label');
   assert.deepEqual(await page.locator('.related-product a').evaluateAll(elements=>elements.map(el=>el.getAttribute('href'))),linksBefore);
   for(const type of['variable','simple']){
    const button=page.locator('.related .woo-buttons>a.product_type_'+type).first();
    await button.hover();
    assert.equal(await button.evaluate(el=>getComputedStyle(el).backgroundColor),type==='variable'?'rgb(225, 214, 250)':'rgb(18, 63, 75)');
    await page.mouse.move(0,0);await button.focus();
    assert.equal(await button.evaluate(el=>getComputedStyle(el).outlineStyle),'solid');
    assert.equal(await button.evaluate(el=>getComputedStyle(el).outlineWidth),'3px');
   }
   console.log(JSON.stringify({width,mode,columns:cols,badgeOnImage:true,flameCircle32px:true,pricesAligned:true,actionsDistinct:true,shopPillUnchanged:true}));
  }
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
