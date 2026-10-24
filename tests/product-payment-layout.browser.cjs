const fs = require('fs');
const cp = require('child_process');
const assert = require('assert/strict');
const { createHash } = require('node:crypto');
const {chromium} = require('../tools/staging-rehearsal/node_modules/playwright');
const path = require('node:path');
const root = path.resolve(__dirname, '../wordpress/wp-content/themes/dt-the7-child');
const php = process.env.PETISH_PHP_EXECUTABLE || (fs.existsSync('/workspace/.petish-dev/bin/php') ? '/workspace/.petish-dev/bin/php' : 'php');
const fixture = context => JSON.parse(cp.execFileSync(php,[path.join(__dirname, 'product-payment-layout.php'),context],{encoding:'utf8'}));

(async () => {
    const product = fixture('product');
    assert.equal(product.hook,'petish_product_paypal_controls');
    assert.equal(product.message_priority,40);
    assert.equal((product.html.match(/class="petish-product-paypal"/g)||[]).length,1);
    assert(product.html.indexOf('wcpay-express-checkout-element') < product.html.indexOf('petish-product-paypal'));
    assert(product.html.indexOf('ppc-button-ppcp-gateway') < product.html.indexOf('ppcp-messages'));
    for (const context of ['cart','out-of-stock']) {
        const other = fixture(context);
        assert.equal(other.hook,'woocommerce_single_product_summary');
        assert.equal(other.message_priority,30);
        assert(!other.html.includes('class="petish-product-paypal"'));
    }
    assert(!fixture('provider-unavailable').html.includes('class="petish-product-paypal"'));

    const css = fs.readFileSync(root + '/assets/css/product-purchase.css','utf8') + fs.readFileSync(root + '/assets/css/payment-layout.css','utf8');
    const js = fs.readFileSync(root + '/assets/js/payment-layout.js','utf8');
    const browser = await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
    try {
        const page = await browser.newPage();
        await page.route('**/*',route => route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><title>Native provider fixture</title>'}));
        await page.goto('http://fixture.test/');
        await page.evaluate(() => localStorage.setItem('wcpay_appearance_bnpl_product_page','stale'));
        for (const width of [1440,800,390]) {
            await page.setViewportSize({width,height:1100});
            await page.setContent('<!doctype html><html data-theme="light"><head><style>body{margin:20px;font:16px Arial}.summary{width:min(780px,100%)}iframe{display:block;width:100%;height:44px;border:0}.ppcp-messages iframe{height:34px}.cart{margin-bottom:20px}.product_meta a{font-size:10px}:root{--petish-afterpay-badge-image:url("http://fixture.test/official-provider-badge.svg")}</style><style>' + css + '</style></head><body id="the7-body" class="single-product petish-product-wallet-layout petish-product-payment-groups"><div class="product"><div class="summary">' + product.html + '<div class="product_meta"><span class="posted_in">Categories: <a href="#">Pet supplies</a></span><span class="tagged_as">Tags: <a href="#">Enrichment</a></span></div></div></div></body></html>');
            // Match actual WP_Scripts::localize scalar output: PHP true -> '1'.
            await page.evaluate(() => {window.PayPalCommerceGateway={messages:{style:{text:{size:12}}}};window.wcpayStripeSiteMessaging={shouldInitializePMME:'1',country:'AU',currencyCode:'AUD',paymentMethods:['afterpay_clearpay']};});
            await page.addScriptTag({content:js});
            const initial = await page.evaluate(() => {
                const boxes = ['#wcpay-express-checkout-element','#ppc-button-ppcp-gateway','.petish-product-paypal','.ppcp-messages','.product_meta'].map(sel => {const el=document.querySelector(sel); const r=el.getBoundingClientRect(); return {sel,x:r.x,y:r.y,width:r.width,height:r.height};});
                const target={variables:{colorText:'#14213d',fontSizeBase:'18px'},rules:{'.Text':{color:'#14213d',fontSize:'18px'}}};
                document.dispatchEvent(new CustomEvent('wcpay_elements_appearance',{detail:{elementsLocation:'bnpl_product_page',appearance:target}}));
                const unrelated={variables:{fontSizeBase:'19px'}};
                document.dispatchEvent(new CustomEvent('wcpay_elements_appearance',{detail:{elementsLocation:'blocks_checkout',appearance:unrelated}}));
                window.fixtureReferences=Array.from(document.querySelectorAll('iframe'));
                return {boxes,target,unrelated,cache:localStorage.getItem('wcpay_appearance_bnpl_product_page'),badge:document.querySelector('#payment-method-message').classList.contains('petish-afterpay-brand-heading'),badgeWidth:getComputedStyle(document.querySelector('#payment-method-message'),'::before').width,font:getComputedStyle(document.querySelector('.posted_in')).fontSize,linkFont:getComputedStyle(document.querySelector('.posted_in a')).fontSize,overflow:document.documentElement.scrollWidth>innerWidth};
            });
            assert(Math.abs(initial.boxes[0].width - initial.boxes[1].width) < 1,JSON.stringify(initial.boxes));
            assert(initial.boxes[0].width <= 400);
            assert(initial.boxes[3].y >= initial.boxes[1].y + initial.boxes[1].height);
            assert(initial.boxes[4].y >= initial.boxes[3].y + initial.boxes[3].height + 24);
            assert.equal(initial.font,'16px');
            assert.equal(initial.linkFont,'16px');
            assert.equal(initial.overflow,false);
            assert.equal(initial.target.variables.fontSizeBase,'18px');
            assert.equal(initial.target.rules['.Text'].fontSize,'18px');
            assert.equal(initial.badge,true);
            assert.equal(initial.badgeWidth,'64px');
            assert.equal(initial.target.rules['.Text'].color,'#14213d');
            assert.equal(initial.unrelated.variables.fontSizeBase,'19px');
            assert.equal(initial.cache,null);
            // A provider can update/remove/recreate its own iframe after a
            // variation/quantity update. Layout does not clone wrappers, bind
            // clicks, or interfere with that provider-owned lifecycle.
            const lifecycle = await page.evaluate(() => {
                const original=document.querySelector('#ppc-button-ppcp-gateway');
                const wallet=document.querySelector('#wcpay-express-checkout-element iframe');
                wallet.focus();
                const focused=document.activeElement;
                document.querySelector('.cart input').value='2';
                document.querySelector('.cart input').dispatchEvent(new Event('change',{bubbles:true}));
                original.replaceChildren(Object.assign(document.createElement('iframe'),{title:'Updated native PayPal fixture'}));
                document.body.dispatchEvent(new CustomEvent('ppcp_product_total_updated',{detail:1600}));
                return {sameWrapper:document.querySelector('#ppc-button-ppcp-gateway')===original,sameWallet:document.querySelector('#wcpay-express-checkout-element iframe')===wallet,sameFocus:document.activeElement===focused,groups:document.querySelectorAll('.petish-product-paypal').length,messageAfter:document.querySelector('.ppcp-messages').getBoundingClientRect().y >= original.getBoundingClientRect().bottom};
            });
            assert.deepEqual(lifecycle,{sameWrapper:true,sameWallet:true,sameFocus:true,groups:1,messageAfter:true});
            console.log(JSON.stringify({width,paymentWidth:initial.boxes[0].width,ordering:true,appearance:true,lifecycle:true}));
        }
        await page.evaluate(()=>localStorage.setItem('wcpay_appearance_bnpl_product_page','fresh-native-18px'));
        await page.addScriptTag({content:js});
        assert.equal(await page.evaluate(()=>localStorage.getItem('wcpay_appearance_bnpl_product_page')),'fresh-native-18px');
        // No brand heading may mislabel another/mixed BNPL message, or
        // remain when the native provider hides/empties its message.
        for (const scenario of ['klarna','mixed','currency','country','hidden','zero-height','loading','missing-asset','flag-false','flag-zero','flag-empty','flag-missing','flag-number','flag-string-true']) {
            await page.evaluate(scenario => {
                const native=window.wcpayStripeSiteMessaging;
                native.shouldInitializePMME=scenario==='flag-false'?false:scenario==='flag-zero'?'0':scenario==='flag-empty'?'':scenario==='flag-missing'?undefined:scenario==='flag-number'?1:scenario==='flag-string-true'?'true':'1';
                native.paymentMethods=scenario==='klarna'?['klarna']:scenario==='mixed'?['afterpay_clearpay','klarna']:['afterpay_clearpay'];
                native.country=scenario==='country'?'NZ':'AU';native.currencyCode=scenario==='currency'?'NZD':'AUD';
                const message=document.querySelector('#payment-method-message');
                message.style.display=scenario==='hidden'?'none':'';
                message.querySelector('iframe').style.height=scenario==='zero-height'?'0px':'44px';
                message.querySelector('.pmme-loading')?.remove();
                if(scenario==='loading')message.appendChild(Object.assign(document.createElement('div'),{className:'pmme-loading'}));
                message.style.setProperty('--petish-afterpay-badge-image',scenario==='missing-asset'?'initial':'url("http://fixture.test/official-provider-badge.svg")');
                message.style.setProperty('--fixture-refresh',scenario);
            },scenario);
            await page.waitForFunction(()=>!document.querySelector('#payment-method-message').classList.contains('petish-afterpay-brand-heading'));
        }
        await page.evaluate(()=>{
            window.wcpayStripeSiteMessaging.shouldInitializePMME=true;
            const message=document.querySelector('#payment-method-message');
            message.style.setProperty('--petish-afterpay-badge-image','url("http://fixture.test/official-provider-badge.svg")');
        });
        await page.waitForFunction(()=>document.querySelector('#payment-method-message').classList.contains('petish-afterpay-brand-heading'));
        await page.addScriptTag({content:fs.readFileSync(path.resolve(__dirname,'../tools/homepage/payment-layout-public-proof.js'),'utf8')});
        const safeProof = await page.evaluate(() => {
            window.wcpayStripeSiteMessaging={privateConfiguration:'private-proof-sentinel'};
            window.PayPalCommerceGateway.privateConfiguration='private-proof-sentinel';
            document.querySelector('.summary').appendChild(Object.assign(document.createElement('p'),{textContent:'private-proof-sentinel customer@example.invalid'}));
            return petishPaymentLayoutPublicProof();
        });
        const finite = value => typeof value==='boolean' || (typeof value==='number' && Number.isFinite(value) && value>=0 && value<=8192)
            || (typeof value==='string' && ['product','cart','other','light','dark','unspecified'].includes(value))
            || (value && typeof value==='object' && Object.values(value).every(finite));
        assert(finite(safeProof)); assert.equal(safeProof.page,'product');
        assert(safeProof.product.native_woo_configuration_present && safeProof.product.native_paypal_v5_configuration_present);
        assert(!/private-proof-sentinel|customer@example\.invalid|<iframe|stripe\.com|paypal\.com/.test(JSON.stringify(safeProof)));
        assert(JSON.stringify(safeProof).length<12000);
        await page.evaluate(versions => {
            const base=location.origin+'/wp-content/themes/dt-the7-child/assets/';
            const css=document.createElement('link');css.rel='stylesheet';css.href=base+'css/payment-layout.css?ver='+versions.css;
            const js=document.createElement('script');js.type='application/json';js.src=base+'js/payment-layout.js?ver='+versions.js;
            document.head.append(css,js);
        },{css:createHash('sha256').update(fs.readFileSync(root+'/assets/css/payment-layout.css')).digest('hex').slice(0,16),
            js:createHash('sha256').update(js).digest('hex').slice(0,16)});
        const { inspectProductPaymentLayout } = await import('../tools/homepage/browser.mjs');
        const nativeLayout=await inspectProductPaymentLayout(page);
        assert(nativeLayout.layout && nativeLayout.typography);
        assert(Object.values(nativeLayout.diagnostics).every(value=>typeof value==='boolean'));
        assert(!JSON.stringify(nativeLayout).includes('private-proof-sentinel'));
        await page.evaluate(() => document.documentElement.dataset.theme='dark');
        const bg = await page.locator('#payment-method-message').evaluate(el=>getComputedStyle(el).backgroundColor);
        assert.equal(bg,'rgb(19, 47, 43)');
        const v6 = await page.evaluate(() => {
            window.wc_ppcp_sdk_v6={messages:{style:{fontSize:'14px'}}};
            const appearance={variables:{fontSizeBase:'18px'},rules:{'.Text--redirect':{color:'#0f4c5c',fontSize:'18px'}}};
            document.dispatchEvent(new CustomEvent('wcpay_elements_appearance',{detail:{elementsLocation:'bnpl_product_page',appearance}}));
            return appearance;
        });
        assert.equal(v6.variables.fontSizeBase,'18px');
        assert.equal(v6.rules['.Text--redirect'].fontSize,'18px');
        assert.equal(v6.rules['.Text--redirect'].color,'#0f4c5c');
        await page.evaluate(() => {
            window.petishPaymentMessagePage='cart'; window.wc_ppcp_sdk_v6.messages.style.fontSize='20px';
            window.wcpayStripeSiteMessaging={shouldInitializePMME:'1',country:'AU',currencyCode:'AUD',paymentMethods:['afterpay_clearpay']};
            document.body.classList.add('petish-site','woocommerce-cart'); document.documentElement.dataset.theme='light';
            localStorage.setItem('wcpay_appearance_bnpl_cart_block','stale');
            localStorage.setItem('wcpay_appearance_bnpl_classic_cart','stale');
            localStorage.setItem('wcpay_appearance_bnpl_product_page','keep-product');
            localStorage.setItem('wcpay_appearance_blocks_checkout','keep-checkout');
            const cart=document.createElement('div');cart.className='wc-block-cart';
            cart.innerHTML='<div class="wc-block-components-bnpl-wrapper"><iframe title="Neutral native Cart message fixture"></iframe></div>';
            document.body.appendChild(cart);
        });
        await page.addScriptTag({content:js});
        const cartAppearance=await page.evaluate(() => {
            const appearance={variables:{},rules:{'.Text':{color:'#14213d'}}};
            const unrelated={variables:{fontSizeBase:'19px'}};
            document.dispatchEvent(new CustomEvent('wcpay_elements_appearance',{detail:{elementsLocation:'bnpl_cart_block',appearance}}));
            document.dispatchEvent(new CustomEvent('wcpay_elements_appearance',{detail:{elementsLocation:'blocks_checkout',appearance:unrelated}}));
            const style=getComputedStyle(document.querySelector('.wc-block-components-bnpl-wrapper'));
            return {appearance,unrelated,light:style.backgroundColor,padding:style.paddingTop,badge:document.querySelector('.wc-block-components-bnpl-wrapper').classList.contains('petish-afterpay-brand-heading'),badgeWidth:getComputedStyle(document.querySelector('.wc-block-components-bnpl-wrapper'),'::before').width,
                cartBlock:localStorage.getItem('wcpay_appearance_bnpl_cart_block'),classicCart:localStorage.getItem('wcpay_appearance_bnpl_classic_cart'),
                product:localStorage.getItem('wcpay_appearance_bnpl_product_page'),checkout:localStorage.getItem('wcpay_appearance_blocks_checkout')};
        });
        assert.equal(cartAppearance.appearance.variables.fontSizeBase,undefined);
        assert.equal(cartAppearance.appearance.rules['.Text'].color,'#14213d');
        assert.equal(cartAppearance.unrelated.variables.fontSizeBase,'19px');
        assert.equal(cartAppearance.light,'rgb(239, 250, 245)'); assert.equal(cartAppearance.padding,'12px');
        assert.equal(cartAppearance.badge,true); assert.equal(cartAppearance.badgeWidth,'64px');
        assert.equal(cartAppearance.cartBlock,null); assert.equal(cartAppearance.classicCart,null);
        assert.equal(cartAppearance.product,'keep-product'); assert.equal(cartAppearance.checkout,'keep-checkout');
        await page.evaluate(()=>document.documentElement.dataset.theme='dark');
        assert.equal(await page.locator('.wc-block-components-bnpl-wrapper').evaluate(node=>getComputedStyle(node).backgroundColor),'rgb(19, 47, 43)');
        console.log('Native hook order, context fallback, responsive layout and appearance fixtures passed.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
