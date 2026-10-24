/** Fixed live guest-cart inspection. No checkout, orders, login or payment actions. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
const require = createRequire(new URL('./staging-rehearsal/package.json', import.meta.url));
const ORIGIN = 'https://petish.biz';
const PRODUCT = '/product/rainbow-habitat-hideout/';
const RELATED_PRODUCT = '/product/corner-gap-cleaning-brush/';
export const PUBLIC_SCHEMA = {"checks":["browser_started","fresh_guest_context","initial_cart_empty","public_variation_selected","guest_cart_created","cart_quantity_verified","cart_page_loaded","passive_sdk_inspected","network_barrier_enforced","created_guest_cart_cleared"],"observations":["home_http_ok","product_http_ok","cart_http_ok","home_account_in_navigation","home_account_avatar_present","home_native_cart_present","home_cart_badge_present","product_afterpay_present","product_paypal_present","cart_block_present","cart_sidebar_present","cart_express_in_sidebar","cart_paypal_in_express","cart_afterpay_in_express","cart_afterpay_in_sidebar","cart_wcpay_google_in_express","cart_paypal_google_in_express","cart_paylater_in_express","cart_paypal_google_v5_present","cart_paypal_google_v6_present","cart_paypal_v6_present","cart_paypal_message_in_sidebar","cart_paypal_message_in_express","cart_paypal_message_shadow_host_present","cart_paypal_message_shadow_host_visible","cart_paypal_message_direct_sidebar","cart_paypal_message_bare_sidebar_wrapper","cart_paypal_message_block_wrapper","cart_stripe_message_in_sidebar","cart_google_visible","cart_paypal_visible","cart_afterpay_visible","afterpay_frame_present","afterpay_frame_inspected","afterpay_logo_present","afterpay_see_plans_present","afterpay_see_plans_below","checkout_requests_blocked","other_requests_blocked","provider_get_blocked","provider_post_blocked","store_api_blocked","payment_action_blocked","provider_bootstrap_blocked","stripe_session_initialization_allowed","account_fragment_read_allowed","cart_count_fragment_read_allowed","home_account_after_currency","afterpay_brand_badge_present","afterpay_message_below_brand_badge","paypal_sdk_token_initialization_allowed","stripe_session_get_allowed","stripe_telemetry_blocked","paypal_graphql_query_blocked","paypal_graphql_mutation_blocked","paypal_logger_blocked","unknown_provider_request_blocked","afterpay_financing_text_measured","afterpay_learn_more_present","afterpay_info_control_present","afterpay_native_single_method","stripe_session_request_blocked","product_native_font_cache_present","product_native_font_cache_matches","product_payment_crop_saved","cart_payment_crop_saved","product_afterpay_brand_badge_present","product_afterpay_message_right_of_brand_badge","product_afterpay_message_below_brand_badge","product_afterpay_compact_badge","cart_afterpay_brand_badge_present","cart_afterpay_message_right_of_brand_badge","cart_afterpay_message_below_brand_badge","cart_afterpay_compact_badge","afterpay_message_right_of_brand_badge","product_afterpay_native_frame_inspected","product_afterpay_financing_text_measured","cart_bnpl_in_order_summary","cart_bnpl_in_totals_wrapper","cart_bnpl_direct_summary_child","cart_express_in_payment_options","cart_express_dom_after_summary","cart_bnpl_summary_bottom_divider_present","cart_bnpl_touches_summary_bottom_divider","cart_bnpl_totals_top_divider_present","cart_bnpl_touches_totals_top_divider","cart_visible_google_above_bnpl","cart_visible_paypal_below_bnpl","cart_bnpl_direct_order_meta_child","cart_meta_only_bnpl_child","cart_slot_only_meta_child","cart_summary_direct_sidebar","cart_bnpl_exact_native_slot_path","cart_slot_direct_summary_child","cart_meta_direct_slot_child","cart_slot_wrapper_class_present","cart_meta_parent_slot_wrapper_class_present","related_section_present","related_native_template_present","related_images_loaded","related_hot_badges_in_image_anchor","related_hot_badges_positioned_by_image_anchor","related_hot_badges_within_image","related_price_widths_equal","related_prices_aligned_per_row","related_prices_fill_card","related_prices_fill_content","related_title_one_line_seen","related_title_multiple_lines_seen","related_variable_simple_buttons_present","related_buttons_same_background","related_variable_simple_same_background","related_products_crop_saved","related_original_product_inspected","related_alternate_product_inspected","related_result_from_alternate_product","review_filter_form_present","review_filter_visible","review_filter_three_selects","review_filter_native_get_preserved","review_filter_apply_aligned"],"metrics":["home_account_count","home_native_cart_count","home_badge_count","cart_row_count","cart_express_count","cart_paypal_count","cart_wcpay_google_count","cart_paypal_google_count","cart_paylater_count","cart_afterpay_count","cart_paypal_message_count","cart_paypal_message_shadow_host_count","cart_paypal_message_shadow_host_width_px","cart_paypal_message_shadow_host_height_px","cart_afterpay_frame_count","afterpay_logo_count","afterpay_logo_width_px","afterpay_logo_height_px","afterpay_font_px","afterpay_link_font_px","afterpay_message_width_px","afterpay_message_height_px","blocked_request_count","blocked_provider_get_count","blocked_provider_post_count","blocked_store_api_count","blocked_payment_action_count","stripe_session_initialization_count","account_fragment_read_count","cart_count_fragment_read_count","home_account_left_px","home_market_right_px","afterpay_brand_badge_width_px","afterpay_brand_badge_height_px","paypal_sdk_token_initialization_count","stripe_session_get_count","blocked_stripe_telemetry_count","blocked_paypal_graphql_query_count","blocked_paypal_graphql_mutation_count","blocked_paypal_graphql_other_count","blocked_paypal_logger_count","blocked_google_provider_count","blocked_unknown_provider_count","blocked_paypal_sdk_token_count","afterpay_text_font_min_px","afterpay_text_font_max_px","blocked_stripe_session_get_count","blocked_stripe_session_post_count","product_native_quantity_font_px","product_cached_base_font_px","product_cached_text_font_px","product_crop_width_px","product_crop_height_px","cart_crop_width_px","cart_crop_height_px","product_afterpay_brand_badge_width_px","product_afterpay_brand_badge_height_px","product_afterpay_message_width_px","product_afterpay_message_height_px","product_afterpay_brand_to_message_gap_px","product_afterpay_vertical_overlap_px","product_afterpay_message_left_px","product_afterpay_brand_right_px","cart_afterpay_brand_badge_width_px","cart_afterpay_brand_badge_height_px","cart_afterpay_message_width_px","cart_afterpay_message_height_px","cart_afterpay_brand_to_message_gap_px","cart_afterpay_vertical_overlap_px","cart_afterpay_message_left_px","cart_afterpay_brand_right_px","product_afterpay_font_px","product_afterpay_link_font_px","product_afterpay_text_font_min_px","product_afterpay_text_font_max_px","cart_bnpl_summary_bottom_divider_gap_px","cart_bnpl_totals_top_divider_gap_px","cart_bnpl_margin_top_px","cart_bnpl_margin_bottom_px","cart_bnpl_border_top_px","cart_bnpl_border_bottom_px","cart_google_bnpl_vertical_gap_px","cart_bnpl_paypal_vertical_gap_px","cart_bnpl_slot_top_inner_gap_px","cart_bnpl_slot_bottom_inner_gap_px","cart_slot_child_count","cart_meta_child_count","related_card_count","related_image_count","related_hot_badge_count","related_hot_in_anchor_count","related_hot_in_image_count","related_price_min_width_px","related_price_max_width_px","related_price_width_spread_px","related_price_row_top_spread_px","related_title_min_line_count","related_title_max_line_count","related_variable_button_count","related_simple_button_count","related_variable_button_red","related_variable_button_green","related_variable_button_blue","related_simple_button_red","related_simple_button_green","related_simple_button_blue","related_crop_width_px","related_crop_height_px","review_filter_select_count","review_filter_visible_select_count","review_filter_control_bottom_delta_px","review_filter_select_margin_bottom_max_px","review_filter_rating_top_px","review_filter_rating_bottom_px","review_filter_rating_height_px","review_filter_media_top_px","review_filter_media_bottom_px","review_filter_media_height_px","review_filter_sort_top_px","review_filter_sort_bottom_px","review_filter_sort_height_px","review_filter_button_top_px","review_filter_button_bottom_px","review_filter_button_height_px","review_filter_checkbox_top_px","review_filter_checkbox_bottom_px","review_filter_checkbox_height_px"],"categories":["input","browser","home","product","cart","providers","cleanup","complete"]};
const DOCUMENTS = new Set(['/', PRODUCT, RELATED_PRODUCT, '/cart/']);
const PAYMENT_ACTION = /\/(?:checkout|checkoutnow|orders?|payments?|payment_intents|setup_intents|confirm|capture|authorize|approve|refunds?|customers)(?:\/|$)/i;
const PROVIDER_HOST = /(?:^|\.)(?:paypal\.com|paypalobjects\.com|stripe\.com|afterpay\.com|squarecdn\.com|cash\.app)$/i;
const METRIC_MAX = 4096;
export function emptyReport() {
  return { status: 'failure', category: 'input', checks: Object.fromEntries(PUBLIC_SCHEMA.checks.map(key => [key, false])),
    observations: Object.fromEntries(PUBLIC_SCHEMA.observations.map(key => [key, false])),
    metrics: Object.fromEntries(PUBLIC_SCHEMA.metrics.map(key => [key, 0])) };
}
export function screenReport(value) {
  const fields = ['status', 'category', 'checks', 'observations', 'metrics'];
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).sort().join() !== fields.sort().join()
      || !['success', 'failure'].includes(value.status) || !PUBLIC_SCHEMA.categories.includes(value.category)) throw new Error('input');
  for (const group of ['checks', 'observations', 'metrics']) {
    if (!value[group] || typeof value[group] !== 'object' || Array.isArray(value[group])
        || Object.keys(value[group]).sort().join() !== [...PUBLIC_SCHEMA[group]].sort().join()) throw new Error('input');
    for (const item of Object.values(value[group])) {
      if (group === 'metrics' ? !Number.isFinite(item) || item < 0 || item > METRIC_MAX : typeof item !== 'boolean') throw new Error('input');
    }
  }
  if (value.status === 'success' && (value.category !== 'complete' || !Object.values(value.checks).every(Boolean))) throw new Error('input');
  return value;
}
/** Exact audited own-session reads; no extra query or POST fields are accepted. */
export function auditedSessionRead(raw, method, body = '') {
  let url; try { url = new URL(raw); } catch { return null; }
  if (url.protocol !== 'https:' || url.origin !== ORIGIN || url.username || url.password || url.port || url.hash || method !== 'POST') return null;
  if (url.pathname === '/wp-admin/admin-ajax.php' && !url.search && body === 'action=petish_account_dropdown') return 'account';
  if (url.pathname === '/' && url.search === '?wc-ajax=petish_cart_count' && body === '') return 'cart_count';
  return null;
}
/** Audited SDK v6 nonce-only initializer; vendor creates a domain SDK token, never an order. */
export function paypalSdkTokenRead(raw, method, body = '') {
  let url; try { url = new URL(raw); } catch { return false; }
  return url.origin === ORIGIN && !url.username && !url.password && !url.port && !url.hash && method === 'POST'
    && url.pathname === '/' && url.search === '?wc-ajax=ppc-sdk-v6-client-token'
    && typeof body === 'string' && /^\{"nonce":"[a-zA-Z0-9]{6,64}"\}$/.test(body);
}
/** Fixed diagnostic enum only; request paths, operation names, bodies and tokens never leave. */
export function providerRequestKind(raw, method, body = '') {
  let url; try { url = new URL(raw); } catch { return 'other'; }
  if (url.origin === ORIGIN && url.pathname === '/' && url.search === '?wc-ajax=ppc-sdk-v6-client-token') return 'paypal_sdk_token';
  if (url.hostname === 'api.stripe.com' && url.pathname === '/v1/elements/sessions') return method === 'GET' ? 'stripe_session_get' : 'stripe_session_post';
  if ((url.hostname === 'r.stripe.com' && url.pathname === '/b') || (url.hostname === 'm.stripe.com' && url.pathname === '/6') || url.hostname === 'q.stripe.com') return 'stripe_telemetry';
  if (/(?:^|\.)paypal\.com$/i.test(url.hostname) && ['/graphql', '/graphql/', '/smart/api/graphql'].includes(url.pathname)) {
    try {
      const value = JSON.parse(body), entries = Array.isArray(value) ? value : [value];
      if (!entries.length || entries.length > 16) return 'paypal_graphql_other';
      if (entries.some(item => typeof item?.query === 'string' && /^\s*mutation\b/i.test(item.query))) return 'paypal_graphql_mutation';
      if (entries.every(item => typeof item?.query === 'string' && /^\s*(?:query\b|\{)/i.test(item.query))) return 'paypal_graphql_query';
    } catch {}
    return 'paypal_graphql_other';
  }
  if (/(?:^|\.)paypal\.com$/i.test(url.hostname) && ['/xoplatform/logger/api/logger', '/smart/api/logger'].includes(url.pathname)) return 'paypal_logger';
  if (url.hostname === 'pay.google.com') return 'google_provider';
  return PROVIDER_HOST.test(url.hostname) ? 'unknown_provider' : 'other';
}
/** Native Stripe Elements display sessions are read-only widget initialization. */
export function publicStripeSession(raw, method, body) {
  let url; try { url = new URL(raw); } catch { return false; }
  if (url.href !== 'https://api.stripe.com/v1/elements/sessions' || method !== 'POST' || typeof body !== 'string' || body.length < 1 || body.length > 65536) return false;
  const entries = [...new URLSearchParams(body)];
  if (entries.length < 1 || entries.length > 200 || entries.filter(([key]) => key === 'key').length !== 1) return false;
  if (entries.some(([key]) => !/^[a-zA-Z0-9_\[\]-]{1,160}$/.test(key) || /(?:payment_intent|setup_intent|client_secret)/i.test(key))) return false;
  return /^pk_(?:live|test)_[a-zA-Z0-9_]{8,240}$/.test(new URLSearchParams(body).get('key') || '');
}
/** Passive public SDK assets are allowed; all action endpoints and arbitrary writes are denied. */
export function allowedRequest(raw, method = 'GET', type = 'fetch', body = '', permit = null) {
  let url; try { url = new URL(raw); } catch { return false; }
  if (url.protocol !== 'https:' || url.username || url.password || url.port || PAYMENT_ACTION.test(url.pathname)) return false;
  if (publicStripeSession(raw, method, body) || auditedSessionRead(raw, method, body) || paypalSdkTokenRead(raw, method, body)) return true;
  if (url.origin === ORIGIN) {
    if (permit && !permit.used && method === permit.method && url.pathname === permit.path && !url.search && body === permit.body) {
      if (method === 'DELETE') return permit.path === '/wp-json/wc/store/v1/cart/items' && body === '';
      if (method === 'POST' && permit.path === '/wp-json/wc/store/v1/cart/add-item') {
        try { const item = JSON.parse(body); return Object.keys(item).sort().join() === 'id,quantity' && Number.isSafeInteger(item.id) && item.id > 0 && item.quantity === 2; } catch { return false; }
      }
      return false;
    }
    if (!['GET', 'HEAD'].includes(method) || /(?:add-to-cart|remove_item|wc-ajax|_wpnonce|rest_route)/i.test(url.search)) return false;
    if (type === 'document') return DOCUMENTS.has(url.pathname) && !url.search;
    if (url.pathname === '/wp-json/wc/store/v1/cart') return method === 'GET' && !url.search;
    return /^\/(?:wp-content|wp-includes)\/.*\.(?:css|js|mjs|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|json)$/i.test(url.pathname);
  }
  return ['GET', 'HEAD'].includes(method) && (PROVIDER_HOST.test(url.hostname)
    || ['pay.google.com', 'fonts.googleapis.com', 'fonts.gstatic.com'].includes(url.hostname));
}
function reportDestination(filename) {
  if (typeof filename !== 'string' || !path.isAbsolute(filename)) throw new Error('input');
  const resolved = path.resolve(filename), parent = path.dirname(resolved);
  if (fs.realpathSync(parent) !== parent || (fs.statSync(parent).mode & 0o7777) !== 0o700) throw new Error('input');
  try { fs.lstatSync(resolved); throw new Error('input'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
  return resolved;
}
function writeReport(filename, report) {
  const resolved = reportDestination(filename);
  const descriptor = fs.openSync(resolved, fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW, 0o600);
  try { fs.writeFileSync(descriptor, JSON.stringify(screenReport(report), null, 2) + '\n'); fs.fsyncSync(descriptor); }
  finally { fs.closeSync(descriptor); }
}
const bounded = value => Number.isFinite(value) ? Math.max(0, Math.min(METRIC_MAX, Math.round(value * 100) / 100)) : 0;
async function pageFacts(page, location) {
  return page.evaluate(where => {
    const all = selector => [...document.querySelectorAll(selector)];
    const visible = el => { const box = el.getBoundingClientRect(), style = getComputedStyle(el); return box.width > 0 && box.height > 0 && style.display !== 'none' && style.visibility !== 'hidden'; };
    const count = selector => Math.min(99, all(selector).length);
    const inside = (selector, parent) => all(selector).some(el => el.closest(parent));
    const selectors = {
      express: '.wc-block-components-express-payment', paypal: '#express-payment-method-ppcp-gateway-paypal, #ppc-button-ppcp-gateway, #ppc-button-ppcp-gateway-v6',
      wooGoogle: '#express-payment-method-woocommerce_payments_express_checkout_googlePay',
      paypalGoogle: '#express-payment-method-ppcp-googlepay, #ppc-button-ppcp-googlepay, #ppc-button-googlepay-container, #ppc-button-ppcp-googlepay-v6, .ppcp-button-googlepay',
      paylater: '#express-payment-method-ppcp-gateway-paylater',
      afterpay: '.afterpay-paragraph, .afterpay-logo, .payment_method_afterpay_clearpay, [data-afterpay-widget]',
      paypalMessage: '#ppcp-cart-paylater-messages, .ppcp-messages',
    };
    if (where === 'home') {
      const account = all('[data-petish-account], .petish-account-menu').find(visible), market = all('.petish-market-selector').find(visible);
      const accountBox = account?.getBoundingClientRect(), marketBox = market?.getBoundingClientRect();
      return {
      observations: { home_account_in_navigation: inside('.petish-account-menu', '.main-nav, .mobile-main-nav, nav'),
        home_account_avatar_present: count('.petish-account-avatar') > 0,
        home_native_cart_present: count('.shopping-cart, .wc-shopping-cart, .mini-woocommerce-cart, .wc-ico-cart') > 0,
        home_cart_badge_present: count('.petish-cart-badge') > 0,
        home_account_after_currency: !!(accountBox && marketBox && accountBox.left >= marketBox.right - 1 && Math.abs(accountBox.top - marketBox.top) < 48) },
      metrics: { home_account_count: count('.petish-account-menu'), home_native_cart_count: count('.shopping-cart, .wc-shopping-cart, .mini-woocommerce-cart, .wc-ico-cart'), home_badge_count: count('.petish-cart-badge'), home_account_left_px: accountBox?.left || 0, home_market_right_px: marketBox?.right || 0 } };
    }
    if (where === 'product') {
      const quantity = document.querySelector('.product .cart .quantity');
      const sourceFont = quantity ? parseFloat(getComputedStyle(quantity).fontSize) || 0 : 0;
      let base = 0, text = 0, present = false;
      try {
        const raw = localStorage.getItem('wcpay_appearance_bnpl_product_page');
        if (raw && raw.length <= 65536) {
          const value = JSON.parse(raw), appearance = value?.appearance;
          const pixels = item => typeof item === 'string' && /^\d+(?:\.\d+)?px$/.test(item) ? parseFloat(item) : 0;
          base = pixels(appearance?.variables?.fontSizeBase); text = pixels(appearance?.rules?.['.Text']?.fontSize); present = base > 0 || text > 0;
        }
      } catch {}
      return { observations: { product_afterpay_present: count(selectors.afterpay) > 0, product_paypal_present: count('.petish-product-paypal, #ppc-button-ppcp-gateway') > 0,
        product_native_font_cache_present: present, product_native_font_cache_matches: sourceFont > 0 && base > 0 && text > 0 && Math.abs(sourceFont - base) < 0.1 && Math.abs(sourceFont - text) < 0.1 },
        metrics: { product_native_quantity_font_px: sourceFont, product_cached_base_font_px: base, product_cached_text_font_px: text } };
    }
    const express = '.wc-block-components-express-payment', sidebar = '.wc-block-cart__sidebar';
    const host = all('paypal-message').find(visible), hostBox = host?.getBoundingClientRect();
    return { observations: {
      cart_block_present: count('.wc-block-cart, .wp-block-woocommerce-cart') > 0, cart_sidebar_present: count(sidebar) > 0,
      cart_express_in_sidebar: inside(express, sidebar), cart_paypal_in_express: inside(selectors.paypal, express),
      cart_afterpay_in_express: inside(selectors.afterpay, express), cart_afterpay_in_sidebar: inside(selectors.afterpay, sidebar),
      cart_wcpay_google_in_express: inside(selectors.wooGoogle, express), cart_paypal_google_in_express: inside(selectors.paypalGoogle, express),
      cart_paylater_in_express: inside(selectors.paylater, express),
      cart_paypal_google_v5_present: count('#ppc-button-ppcp-googlepay, #ppc-button-googlepay-container') > 0,
      cart_paypal_google_v6_present: count('#ppc-button-ppcp-googlepay-v6') > 0, cart_paypal_v6_present: count('#ppc-button-ppcp-gateway-v6') > 0,
      cart_paypal_message_in_sidebar: inside(selectors.paypalMessage + ', paypal-message', sidebar),
      cart_paypal_message_in_express: inside(selectors.paypalMessage + ', paypal-message', express),
      cart_paypal_message_shadow_host_present: count('paypal-message') > 0, cart_paypal_message_shadow_host_visible: !!host,
      cart_paypal_message_direct_sidebar: all(selectors.paypalMessage + ', paypal-message').some(el => el.parentElement?.matches(sidebar)),
      cart_paypal_message_bare_sidebar_wrapper: all(selectors.paypalMessage + ', paypal-message').some(el => el.parentElement?.tagName === 'DIV' && !el.parentElement.id && !el.parentElement.className && el.parentElement.parentElement?.matches(sidebar)),
      cart_paypal_message_block_wrapper: all(selectors.paypalMessage + ', paypal-message').some(el => el.closest('[data-block-name="woocommerce-paypal-payments/cart-paylater-messages"]')),
      cart_stripe_message_in_sidebar: inside('#payment-method-message, .wc-block-components-bnpl-wrapper', sidebar), cart_google_visible: all(selectors.wooGoogle + ', ' + selectors.paypalGoogle).some(visible),
      cart_paypal_visible: all(selectors.paypal).some(visible), cart_afterpay_visible: all(selectors.afterpay).some(visible),
    }, metrics: { cart_row_count: count('.wc-block-cart-items__row, .cart_item'), cart_express_count: count(express),
      cart_paypal_count: count(selectors.paypal), cart_wcpay_google_count: count(selectors.wooGoogle), cart_paypal_google_count: count(selectors.paypalGoogle),
      cart_paylater_count: count(selectors.paylater), cart_afterpay_count: count(selectors.afterpay), cart_paypal_message_count: count(selectors.paypalMessage), cart_paypal_message_shadow_host_count: count('paypal-message'), cart_paypal_message_shadow_host_width_px: hostBox?.width || 0, cart_paypal_message_shadow_host_height_px: hostBox?.height || 0 } };
  }, location);
}
/** Public review GET controls only; never read option values, review text or account fields. */
export function reviewFilterFacts(){
  const form=document.querySelector('#petish-customer-reviews form.pcr-filters'),selects=form?[...form.querySelectorAll('select')]:[],button=form?.querySelector('button[type="submit"]'),checkbox=form?.querySelector('input[type="checkbox"][name="pcr_verified"]');
  const visible=el=>{if(!el)return false;const r=el.getBoundingClientRect(),style=getComputedStyle(el);return r.width>0&&r.height>0&&style.display!=='none'&&style.visibility!=='hidden';};
  const names=['pcr_rating','pcr_media','pcr_sort'],controls=names.map(name=>form?.querySelector('select[name="'+name+'"]')),shown=controls.filter(visible),bottoms=[...shown,button].filter(visible).map(el=>el.getBoundingClientRect().bottom);
  let native=false;try{const action=new URL(form?.action||'',location.href),actual=[...form.elements].map(el=>el.name).filter(Boolean).sort();native=!!form&&form.method.toLowerCase()==='get'&&action.origin===location.origin&&action.pathname===location.pathname&&!action.search&&action.hash==='#petish-customer-reviews'&&actual.join(',')==='pcr_media,pcr_rating,pcr_sort,pcr_verified';}catch{}
  const delta=bottoms.length?Math.max(...bottoms)-Math.min(...bottoms):0,metrics={review_filter_select_count:selects.length,review_filter_visible_select_count:selects.filter(visible).length,review_filter_control_bottom_delta_px:delta,review_filter_select_margin_bottom_max_px:Math.max(0,...selects.map(el=>parseFloat(getComputedStyle(el).marginBottom)||0))};
  for(const [name,el]of [['rating',controls[0]],['media',controls[1]],['sort',controls[2]],['button',button],['checkbox',checkbox]]){const r=visible(el)?el.getBoundingClientRect():null;metrics['review_filter_'+name+'_top_px']=r?Math.max(0,r.top):0;metrics['review_filter_'+name+'_bottom_px']=r?Math.max(0,r.bottom):0;metrics['review_filter_'+name+'_height_px']=r?.height||0;}
  return {observations:{review_filter_form_present:!!form,review_filter_visible:visible(form),review_filter_three_selects:selects.length===3&&controls.every(Boolean),review_filter_native_get_preserved:native,review_filter_apply_aligned:shown.length===3&&visible(button)&&delta<=1.5},metrics};
}
async function inspectReviewFilters(page,report){
  const tab=page.locator('.woocommerce-tabs #tab-title-reviews > a, .woocommerce-tabs .reviews_tab > a').first();
  if(await tab.count()){try{await tab.click({timeout:5000});}catch{}}
  const form=page.locator('#petish-customer-reviews form.pcr-filters').first();
  if(await form.count())await form.evaluate(el=>scrollTo({top:Math.max(0,el.getBoundingClientRect().top+scrollY-200),behavior:'instant'}));
  merge(report,await page.evaluate(reviewFilterFacts));
}
/** Native public related-card geometry only: no names, amounts, hrefs or supplier metadata. */
export function relatedProductsFacts() {
  const section=document.querySelector('.related.products'),visible=el=>{if(!el)return false;const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden';};
  const cards=section?[...section.querySelectorAll(':scope > ul.related-product > li, :scope > ul.products > li.product')].filter(visible).slice(0,12):[];
  const images=[],badges=[],prices=[],titles=[],buttons=[],rows=[];let anchored=0,positioned=0,inside=0,fill=0,fillContent=0;
  const px=value=>parseFloat(value)||0;
  for(const card of cards){
    const box=card.getBoundingClientRect(),anchor=card.querySelector('a.product-thumbnail, a.woocommerce-LoopProduct-link'),image=anchor?.querySelector(':scope > img'),price=card.querySelector('.product-content > .price, .price'),title=card.querySelector('.product-title, .woocommerce-loop-product__title');
    if(image)images.push(image);
    for(const badge of card.querySelectorAll('.petish-hot-badge')){
      badges.push(badge);if(anchor?.contains(badge))anchored++;
      if(anchor&&(badge.offsetParent===anchor||anchor.contains(badge.offsetParent)))positioned++;
      if(image){const b=badge.getBoundingClientRect(),i=image.getBoundingClientRect();if(b.left>=i.left-1&&b.right<=i.right+1&&b.top>=i.top-1&&b.bottom<=i.bottom+1)inside++;}
    }
    if(price&&visible(price)){
      const p=price.getBoundingClientRect(),style=getComputedStyle(card),inner=box.width-px(style.borderLeftWidth)-px(style.borderRightWidth)-px(style.paddingLeft)-px(style.paddingRight);
      prices.push(p.width);if(Math.abs(p.width-inner)<=2)fill++;const content=card.querySelector('.product-content');if(content){const c=content.getBoundingClientRect(),s=getComputedStyle(content);if(Math.abs(p.width-(c.width-px(s.borderLeftWidth)-px(s.borderRightWidth)-px(s.paddingLeft)-px(s.paddingRight)))<=2)fillContent++;}
      let row=rows.find(item=>Math.abs(item.top-box.top)<=8);if(!row){row={top:box.top,prices:[]};rows.push(row);}row.prices.push(p.top);
    }
    if(title){const range=document.createRange();range.selectNodeContents(title);const tops=[];for(const r of range.getClientRects())if(r.width>0&&r.height>0&&!tops.some(top=>Math.abs(top-r.top)<1))tops.push(r.top);titles.push(Math.min(8,tops.length));}
    const button=card.querySelector('.woo-buttons a, a.add_to_cart_button, a.product_type_variable');
    if(button&&visible(button)){const kind=button.classList.contains('product_type_variable')?'variable':button.classList.contains('product_type_simple')||button.classList.contains('ajax_add_to_cart')?'simple':'other';buttons.push({kind,color:getComputedStyle(button).backgroundColor});}
  }
  const min=values=>values.length?Math.min(...values):0,max=values=>values.length?Math.max(...values):0;
  const spread=max(prices)-min(prices),rowSpread=max(rows.map(row=>max(row.prices)-min(row.prices))),variables=buttons.filter(b=>b.kind==='variable'),simple=buttons.filter(b=>b.kind==='simple');
  const rgb=button=>{const match=button?.color.match(/^rgba?\(\s*(\d+(?:\.\d+)?)[, ]+\s*(\d+(?:\.\d+)?)[, ]+\s*(\d+(?:\.\d+)?)/);return match?match.slice(1,4).map(Number):[0,0,0];},v=rgb(variables[0]),a=rgb(simple[0]);
  return {observations:{related_section_present:!!section,related_native_template_present:cards.length>0&&cards.every(card=>!!card.querySelector('a.product-thumbnail')&&!!card.querySelector('.product-content')),
    related_images_loaded:images.length===cards.length&&images.length>0&&images.every(image=>image.complete&&image.naturalWidth>0),
    related_hot_badges_in_image_anchor:badges.length>0&&anchored===badges.length,related_hot_badges_positioned_by_image_anchor:badges.length>0&&positioned===badges.length,related_hot_badges_within_image:badges.length>0&&inside===badges.length,
    related_price_widths_equal:prices.length===cards.length&&prices.length>0&&spread<=2,related_prices_aligned_per_row:prices.length>0&&rowSpread<=2,related_prices_fill_card:prices.length>0&&fill===prices.length,related_prices_fill_content:prices.length>0&&fillContent===prices.length,
    related_title_one_line_seen:titles.includes(1),related_title_multiple_lines_seen:titles.some(value=>value>=2),related_variable_simple_buttons_present:variables.length>0&&simple.length>0,
    related_buttons_same_background:buttons.length>0&&buttons.every(button=>button.color===buttons[0].color),related_variable_simple_same_background:variables.length>0&&simple.length>0&&variables[0].color===simple[0].color},
    metrics:{related_card_count:cards.length,related_image_count:images.length,related_hot_badge_count:badges.length,related_hot_in_anchor_count:anchored,related_hot_in_image_count:inside,
      related_price_min_width_px:min(prices),related_price_max_width_px:max(prices),related_price_width_spread_px:spread,related_price_row_top_spread_px:rowSpread,related_title_min_line_count:min(titles),related_title_max_line_count:max(titles),
      related_variable_button_count:variables.length,related_simple_button_count:simple.length,related_variable_button_red:v[0],related_variable_button_green:v[1],related_variable_button_blue:v[2],related_simple_button_red:a[0],related_simple_button_green:a[1],related_simple_button_blue:a[2]}};
}
async function inspectRelatedProducts(page,report){
  const section=page.locator('.related.products').first();if(!await section.count())return false;
  await section.evaluate(el=>scrollTo({top:Math.max(0,el.getBoundingClientRect().top+scrollY-200),behavior:'instant'}));
  try{await page.waitForFunction(()=>{const images=[...document.querySelectorAll('.related.products a.product-thumbnail > img')];return images.length>0&&images.every(image=>image.complete&&image.naturalWidth>0);},null,{timeout:5000});}catch{}
  merge(report,await page.evaluate(relatedProductsFacts));return true;
}
/** Native Cart order/summary boundaries only; no text, prices or arbitrary ancestor attributes. */
export function cartNativeFlowFacts() {
  const visible=el=>{if(!el)return false;const r=el.getBoundingClientRect(),style=getComputedStyle(el);return r.width>0&&r.height>0&&style.display!=='none'&&style.visibility!=='hidden';};
  const root=document.querySelector('.wc-block-cart__sidebar');
  const find=selector=>root?[...root.querySelectorAll(selector)].find(visible):null;
  const bnpl=find('.wc-block-components-bnpl-wrapper'),summary=bnpl?.closest('.wp-block-woocommerce-cart-order-summary-block'),totals=bnpl?.parentElement?.closest('.wc-block-components-totals-wrapper'),meta=bnpl?.parentElement?.closest('.wc-block-components-order-meta');
  const express=root?.querySelector('.wc-block-components-express-payment'),options=express?.closest('.wc-block-cart__payment-options');
  const google=find('#express-payment-method-woocommerce_payments_express_checkout_googlePay, #express-payment-method-ppcp-googlepay');
  const paypal=find('#express-payment-method-ppcp-gateway-paypal, #ppc-button-ppcp-gateway, #ppc-button-ppcp-gateway-v6');
  const b=bnpl?.getBoundingClientRect(),g=google?.getBoundingClientRect(),p=paypal?.getBoundingClientRect(),style=bnpl?getComputedStyle(bnpl):null;
  const px=value=>parseFloat(value)||0,summaryStyle=summary?getComputedStyle(summary):null,totalsStyle=totals?getComputedStyle(totals):null;
  const bottomLine=!!(visible(summary)&&summaryStyle&&px(summaryStyle.borderBottomWidth)>0&&summaryStyle.borderBottomStyle!=='none');
  const topLine=!!(visible(totals)&&totalsStyle&&px(totalsStyle.borderTopWidth)>0&&totalsStyle.borderTopStyle!=='none');
  const bottomGap=b&&summary?Math.abs(summary.getBoundingClientRect().bottom-px(summaryStyle.borderBottomWidth)/2-b.bottom):0;
  const topGap=b&&totals?Math.abs(b.top-totals.getBoundingClientRect().top-px(totalsStyle.borderTopWidth)/2):0;
  return {observations:{cart_bnpl_in_order_summary:!!summary,cart_bnpl_in_totals_wrapper:!!totals,
    cart_bnpl_direct_summary_child:!!(bnpl&&summary&&bnpl.parentElement===summary),cart_express_in_payment_options:!!options,
    cart_express_dom_after_summary:!!(express&&summary&&!summary.contains(express)&&(summary.compareDocumentPosition(express)&Node.DOCUMENT_POSITION_FOLLOWING)),
    cart_bnpl_direct_order_meta_child:!!(bnpl&&meta&&bnpl.parentElement===meta),cart_meta_only_bnpl_child:!!(meta&&meta.children.length===1&&meta.firstElementChild===bnpl),
    cart_slot_only_meta_child:!!(totals&&totals.children.length===1&&totals.firstElementChild===meta),
    cart_slot_direct_summary_child:!!(totals&&summary&&totals.parentElement===summary),cart_meta_direct_slot_child:!!(meta&&totals&&meta.parentElement===totals),
    cart_slot_wrapper_class_present:!!totals?.classList.contains('slot-wrapper'),cart_meta_parent_slot_wrapper_class_present:!!meta?.parentElement?.classList.contains('slot-wrapper'),cart_summary_direct_sidebar:!!(summary&&summary.parentElement===root),
    cart_bnpl_exact_native_slot_path:!!(bnpl&&meta&&totals&&summary&&bnpl.parentElement===meta&&meta.parentElement===totals&&totals.parentElement===summary&&summary.parentElement===root),
    cart_bnpl_summary_bottom_divider_present:bottomLine,cart_bnpl_touches_summary_bottom_divider:bottomLine&&bottomGap<=1.5,
    cart_bnpl_totals_top_divider_present:topLine,cart_bnpl_touches_totals_top_divider:topLine&&topGap<=1.5,
    cart_visible_google_above_bnpl:!!(g&&b&&g.bottom<=b.top+1),cart_visible_paypal_below_bnpl:!!(p&&b&&p.top>=b.bottom-1)},
    metrics:{cart_slot_child_count:Math.min(99,totals?.children.length||0),cart_meta_child_count:Math.min(99,meta?.children.length||0),cart_bnpl_summary_bottom_divider_gap_px:bottomGap,cart_bnpl_totals_top_divider_gap_px:topGap,
      cart_bnpl_slot_top_inner_gap_px:b&&totals?Math.max(0,b.top-totals.getBoundingClientRect().top-px(totalsStyle.borderTopWidth)):0,
      cart_bnpl_slot_bottom_inner_gap_px:b&&totals?Math.max(0,totals.getBoundingClientRect().bottom-px(totalsStyle.borderBottomWidth)-b.bottom):0,
      cart_bnpl_margin_top_px:style?Math.max(0,px(style.marginTop)):0,cart_bnpl_margin_bottom_px:style?Math.max(0,px(style.marginBottom)):0,
      cart_bnpl_border_top_px:style?px(style.borderTopWidth):0,cart_bnpl_border_bottom_px:style?px(style.borderBottomWidth):0,
      cart_google_bnpl_vertical_gap_px:g&&b?Math.max(0,b.top-g.bottom):0,cart_bnpl_paypal_vertical_gap_px:p&&b?Math.max(0,p.top-b.bottom):0}};
}
function merge(report, facts) {
  for (const [key, value] of Object.entries(facts.observations)) if (PUBLIC_SCHEMA.observations.includes(key) && typeof value === 'boolean') report.observations[key] = value;
  for (const [key, value] of Object.entries(facts.metrics)) if (PUBLIC_SCHEMA.metrics.includes(key)) report.metrics[key] = bounded(value);
}
/** Read native message typography without returning its sentence, price, URL or markup. */
export function afterpayDOMFacts(input = false) {
  const isProviderFrame = typeof input === 'object' ? input.providerFrame === true : input === true;
  const afterpayOnly = typeof input === 'object' && input.afterpayOnly === true;
  const visible = el => { const r = el.getBoundingClientRect(), st = getComputedStyle(el); return r.width > 0 && r.height > 0 && st.display !== 'none' && st.visibility !== 'hidden'; };
  const logos = [...document.querySelectorAll('.afterpay-logo, [class*="afterpay" i][class*="logo" i], img[alt*="Afterpay" i], svg[aria-label*="Afterpay" i]')].filter(visible);
  const candidates = [...document.querySelectorAll('.afterpay-paragraph, [class*="afterpay-paragraph" i], [class*="afterpay-message" i], [class*="AfterpayClearpayMessage"]')].filter(el => visible(el) && !el.matches('[class*="logo" i], svg, img'));
  const logo = logos[0];
  const outer = candidates.find(el => !candidates.some(parent => parent !== el && parent.contains(el)));
  const scope = isProviderFrame ? document.body : outer || logo?.parentElement;
  const finance = [], fallback = [];
  if (scope) {
    const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT); let text, visited = 0;
    while ((text = walker.nextNode()) && visited++ < 2000) {
      const parent = text.parentElement, value = (text.nodeValue || '').trim();
      if (!value || !parent || !visible(parent) || parent.closest('a, button, svg, script, style, [class*="logo" i]')) continue;
      fallback.push(parent);
      if (/interest[ -]?free|instalments?|installments?|pay\s+in\s+4|over\s+6\s+weeks|split.{0,30}payments|pay\s+later/i.test(value)) finance.push(parent);
    }
  }
  const identified = !!logo || !!outer || (isProviderFrame && afterpayOnly && finance.length > 0);
  const message = identified ? (isProviderFrame ? document.body : outer || logo?.parentElement) : null;
  const textNodes = identified ? (finance.length ? finance : fallback).slice(0, 100) : [];
  const fonts = textNodes.map(el => parseFloat(getComputedStyle(el).fontSize) || 0).filter(value => value > 0);
  const links = message ? [...message.querySelectorAll('a, button, [role="button"]')].filter(visible) : [];
  const plans = links.find(el => /see\s+plans/i.test(el.textContent || ''));
  const learn = links.some(el => /learn\s+more/i.test(el.textContent || ''));
  const info = links.some(el => /(?:more\s+)?info(?:rmation)?|about\s+(?:afterpay|clearpay)/i.test(el.getAttribute('aria-label') || ''));
  const l = logo?.getBoundingClientRect(), m = message?.getBoundingClientRect(), link = plans?.getBoundingClientRect();
  return { observations: { afterpay_logo_present: !!logo, afterpay_see_plans_present: !!plans,
    afterpay_see_plans_below: !!(link && l && link.top >= l.bottom - 1), afterpay_financing_text_measured: identified && finance.length > 0,
    afterpay_learn_more_present: learn, afterpay_info_control_present: info },
    metrics: { afterpay_logo_count: Math.min(99, logos.length), afterpay_logo_width_px: l?.width || 0, afterpay_logo_height_px: l?.height || 0,
      afterpay_font_px: fonts[0] || 0, afterpay_link_font_px: plans ? parseFloat(getComputedStyle(plans).fontSize) || 0 : 0,
      afterpay_message_width_px: m?.width || 0, afterpay_message_height_px: m?.height || 0,
      afterpay_text_font_min_px: fonts.length ? Math.min(...fonts) : 0, afterpay_text_font_max_px: fonts.length ? Math.max(...fonts) : 0 } };
}
/** Measure the decorative pseudo-badge against the actual native frame, without changing either. */
export function brandGeometryFacts(which) {
  const selector = which === 'product' ? '.product .summary .petish-afterpay-brand-heading' :
    '.wc-block-cart .wc-block-components-bnpl-wrapper.petish-afterpay-brand-heading, .cart_totals .petish-afterpay-brand-heading';
  const absent = {present:false,right:false,below:false,compact:false,width:0,height:0,messageWidth:0,messageHeight:0,gap:0,overlap:0,messageLeft:0,brandRight:0};
  if (!['product','cart'].includes(which)) return absent;
  const visible = el => {const r=el.getBoundingClientRect(),style=getComputedStyle(el);return r.width>0&&r.height>8&&style.display!=='none'&&style.visibility!=='hidden';};
  const el = [...document.querySelectorAll(selector)].find(visible);
  if (!el) return absent;
  // Stripe may put a zero-height controller first. Observe its real message frame through native wrappers.
  const frames = [...el.querySelectorAll('iframe')].filter(visible).sort((a,b)=>b.getBoundingClientRect().width-a.getBoundingClientRect().width);
  const frame = frames[0]; if (!frame) return absent;
  const before=getComputedStyle(el,'::before'),style=getComputedStyle(el),box=el.getBoundingClientRect(),iframe=frame.getBoundingClientRect();
  const px=value=>parseFloat(value)||0,width=px(before.width),height=px(before.height);
  const contentLeft=box.left+px(style.borderLeftWidth)+px(style.paddingLeft),contentTop=box.top+px(style.borderTopWidth)+px(style.paddingTop);
  const contentHeight=box.height-px(style.borderTopWidth)-px(style.borderBottomWidth)-px(style.paddingTop)-px(style.paddingBottom);
  const row=style.display.includes('grid')?(px(style.gridTemplateRows.split(' ')[0])||contentHeight):height+px(before.marginTop)+px(before.marginBottom);
  const align=before.alignSelf==='auto'?style.alignItems:before.alignSelf;
  const free=Math.max(0,row-height-px(before.marginTop)-px(before.marginBottom));
  let badgeTop=contentTop+px(before.marginTop)+(align.includes('center')?free/2:align.includes('end')?free:0);
  let badgeLeft=contentLeft+px(before.marginLeft);
  if(before.position==='absolute'){
    const containingWidth=box.width-px(style.borderLeftWidth)-px(style.borderRightWidth),containingHeight=box.height-px(style.borderTopWidth)-px(style.borderBottomWidth);
    const offset=(value,total)=>value.endsWith('%')?px(value)*total/100:px(value);
    let translateX=0,translateY=0;
    try{const matrix=new DOMMatrixReadOnly(before.transform==='none'?undefined:before.transform);translateX=matrix.e;translateY=matrix.f;}catch{}
    badgeLeft=box.left+px(style.borderLeftWidth)+(before.left!=='auto'?offset(before.left,containingWidth):containingWidth-offset(before.right,containingWidth)-width)+px(before.marginLeft)+translateX;
    badgeTop=box.top+px(style.borderTopWidth)+(before.top!=='auto'?offset(before.top,containingHeight):containingHeight-offset(before.bottom,containingHeight)-height)+px(before.marginTop)+translateY;
  }
  const brandRight=badgeLeft+width;
  const overlap=Math.max(0,Math.min(iframe.bottom,badgeTop+height)-Math.max(iframe.top,badgeTop));
  const present=width>0&&height>0&&before.backgroundImage!=='none'&&before.display!=='none'&&style.display!=='none'&&box.width>0;
  return {present,right:present&&iframe.left>=brandRight-1&&overlap>=Math.min(height,iframe.height)/2,
    below:present&&iframe.top>=badgeTop+height-1,compact:present&&Math.abs(width-64)<0.6&&Math.abs(height-40)<0.6,
    width,height,messageWidth:iframe.width,messageHeight:iframe.height,gap:Math.max(0,iframe.left-brandRight),overlap,
    messageLeft:Math.max(0,iframe.left),brandRight:Math.max(0,brandRight)};
}
function recordBrand(report,which,brand) {
  const prefix=which+'_afterpay_';
  for(const [key,value]of Object.entries({brand_badge_present:brand.present,message_right_of_brand_badge:brand.right,message_below_brand_badge:brand.below,compact_badge:brand.compact}))report.observations[prefix+key]=value;
  for(const [key,value]of Object.entries({brand_badge_width_px:brand.width,brand_badge_height_px:brand.height,message_width_px:brand.messageWidth,message_height_px:brand.messageHeight,brand_to_message_gap_px:brand.gap,vertical_overlap_px:brand.overlap,message_left_px:brand.messageLeft,brand_right_px:brand.brandRight}))report.metrics[prefix+key]=bounded(value);
  if(which==='cart'){
    report.observations.afterpay_brand_badge_present=brand.present;report.observations.afterpay_message_below_brand_badge=brand.present&&brand.below;
    report.observations.afterpay_message_right_of_brand_badge=brand.present&&brand.right;
    report.metrics.afterpay_brand_badge_width_px=bounded(brand.width);report.metrics.afterpay_brand_badge_height_px=bounded(brand.height);
  }
}
/** Product-frame typography is kept separate from Cart and from native appearance-cache values. */
async function inspectProductAfterpayFonts(page,report) {
  const afterpayOnly=await page.evaluate(()=>{const value=window.wcpayStripeSiteMessaging;return !!(value&&value.country==='AU'&&value.currencyCode==='AUD'&&Array.isArray(value.paymentMethods)&&value.paymentMethods.length===1&&value.paymentMethods[0]==='afterpay_clearpay');});
  const frames=page.frames().filter(frame=>{try{const url=new URL(frame.url());return frame!==page.mainFrame()&&/(?:^|\.)(?:afterpay|stripe)\.com$/i.test(url.hostname);}catch{return false;}});
  for(const frame of frames){
    try{
      const facts=await frame.evaluate(afterpayDOMFacts,{providerFrame:true,afterpayOnly});
      if(facts.observations.afterpay_logo_present||facts.observations.afterpay_financing_text_measured)report.observations.product_afterpay_native_frame_inspected=true;
      // A tiny brand-only frame has no financing sentence and must not stand in for its text font.
      if(!facts.observations.afterpay_financing_text_measured)continue;
      report.observations.product_afterpay_financing_text_measured=true;
      for(const key of ['afterpay_font_px','afterpay_link_font_px','afterpay_text_font_max_px'])report.metrics['product_'+key]=Math.max(report.metrics['product_'+key],bounded(facts.metrics[key]));
      const minimum=bounded(facts.metrics.afterpay_text_font_min_px);
      if(minimum>0)report.metrics.product_afterpay_text_font_min_px=report.metrics.product_afterpay_text_font_min_px>0?Math.min(report.metrics.product_afterpay_text_font_min_px,minimum):minimum;
    }catch{/* A detached provider frame yields no fabricated font facts. */}
  }
}
async function inspectAfterpay(page, report) {
  const afterpayOnly = await page.evaluate(() => { const value = window.wcpayStripeSiteMessaging; return !!(value && value.country === 'AU' && value.currencyCode === 'AUD' && Array.isArray(value.paymentMethods) && value.paymentMethods.length === 1 && value.paymentMethods[0] === 'afterpay_clearpay'); });
  report.observations.afterpay_native_single_method = afterpayOnly;
  recordBrand(report,'cart',await page.evaluate(brandGeometryFacts,'cart'));
  const frames = page.frames().filter(frame => { try { const url = new URL(frame.url()); return frame !== page.mainFrame() && /(?:^|\.)(?:afterpay|stripe)\.com$/i.test(url.hostname); } catch { return false; } });
  report.metrics.cart_afterpay_frame_count = 0;
  for (const frame of [page.mainFrame(), ...frames]) {
    try {
      const facts = await frame.evaluate(afterpayDOMFacts, {providerFrame: frame !== page.mainFrame(), afterpayOnly});
      if (frame !== page.mainFrame() && (facts.observations.afterpay_logo_present || facts.observations.afterpay_financing_text_measured || /afterpay/i.test(frame.url()))) {
        report.observations.afterpay_frame_present = true; report.observations.afterpay_frame_inspected = true;
        report.metrics.cart_afterpay_frame_count = Math.min(99, report.metrics.cart_afterpay_frame_count + 1);
      }
      for (const [key, value] of Object.entries(facts.observations)) report.observations[key] ||= value;
      for (const [key, value] of Object.entries(facts.metrics)) report.metrics[key] = Math.max(report.metrics[key], bounded(value));
    } catch { /* A detached or blocked provider frame is an observation, never fabricated content. */ }
  }
}
/** Optional public guest payment crops: fixed filenames, no headers, identity or form inputs. */
export async function capturePublicPanel(page, directory, kind) {
  const absent = {saved:false,width:0,height:0};
  if (!directory || !['product','cart','related'].includes(kind)) return absent;
  try {
    if (!path.isAbsolute(directory) || fs.realpathSync(directory) !== directory || (fs.statSync(directory).mode & 0o7777) !== 0o700) return absent;
    const clip = await page.evaluate(which => {
      const selectors = which === 'related' ? ['.related.products'] : which === 'product' ? ['.product .summary #payment-method-message'] :
        ['.wc-block-cart__sidebar .wc-block-components-express-payment','.wc-block-cart__sidebar .wc-block-components-bnpl-wrapper','.wc-block-cart__sidebar > .wp-block-woocommerce-cart-order-summary-block > .wc-block-components-totals-wrapper.slot-wrapper:has(> .wc-block-components-order-meta > .wc-block-components-bnpl-wrapper)','.wc-block-cart__sidebar .ppcp-messages','.wc-block-cart__sidebar paypal-message','.cart_totals #payment-method-message'];
      const visible = el => {const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>8&&r.height>8&&s.display!=='none'&&s.visibility!=='hidden';};
      const elements = [...new Set(selectors.flatMap(selector=>[...document.querySelectorAll(selector)]))].filter(visible);
      if (!elements.length || (which==='product' && !elements.some(el=>[...el.querySelectorAll('iframe')].some(visible)))) return null;
      const boxes=elements.map(el=>el.getBoundingClientRect());
      const box={left:Math.min(...boxes.map(r=>r.left)),top:Math.min(...boxes.map(r=>r.top)),right:Math.max(...boxes.map(r=>r.right)),bottom:Math.max(...boxes.map(r=>r.bottom))};
      const headers=[...document.querySelectorAll(which==='related'?'#masthead,#phantom,#phantom-mobile,.mobile-header-bar':'#masthead,.mobile-header-bar')].filter(visible).map(el=>el.getBoundingClientRect()).filter(r=>r.top>=0&&r.height<400);
      const headerBottom=Math.max(60,...headers.map(r=>r.bottom));
      if(box.left<0||box.right>innerWidth+1||box.top<headerBottom||box.bottom<=box.top)return null;
      // Include the exact native Slot's divider edges and spacing, without widening to Summary/coupon fields.
      if(which==='cart'){box.left=Math.max(0,box.left-2);box.right=Math.min(innerWidth,box.right+2);box.top=Math.max(0,box.top-2);box.bottom+=2;}
      if(box.top<headerBottom)return null;
      const sensitive=[...document.querySelectorAll('input:not([type="hidden"]),textarea,select,.woocommerce-customer-details,.wc-block-components-address-card,.woocommerce-billing-fields,.woocommerce-shipping-fields')].filter(visible);
      if(sensitive.some(el=>{const r=el.getBoundingClientRect();return r.left<box.right&&r.right>box.left&&r.top<box.bottom&&r.bottom>box.top;}))return null;
      const x=Math.floor(box.left+scrollX),y=Math.floor(box.top+scrollY),width=Math.ceil(box.right+scrollX)-x,height=Math.ceil(box.bottom+scrollY)-y;
      return width>0&&height>0&&width<=1600&&height<=1600?{x,y,width,height}:null;
    }, kind);
    if (!clip) return absent;
    const bytes = await page.screenshot({type:'png',clip,animations:'disabled'});
    if (bytes.length < 33 || bytes.length > 4194304 || bytes.subarray(0,8).toString('hex') !== '89504e470d0a1a0a') return absent;
    const width=bytes.readUInt32BE(16),height=bytes.readUInt32BE(20);
    if(width<1||height<1||width>1600||height>1600)return absent;
    const filename=path.join(directory,kind==='related'?'related-products.private.png':kind==='product'?'product-afterpay.private.png':'cart-payments.private.png');
    const descriptor=fs.openSync(filename,fs.constants.O_WRONLY|fs.constants.O_CREAT|fs.constants.O_EXCL|fs.constants.O_NOFOLLOW,0o600);
    try{fs.writeFileSync(descriptor,bytes);fs.fsyncSync(descriptor);}finally{fs.closeSync(descriptor);}
    return {saved:true,width,height};
  } catch {return absent;}
}
async function cartAPI(page, method, pathname, nonce, body = '') {
  return page.evaluate(async args => {
    const response = await fetch(args.pathname, { method: args.method, credentials: 'same-origin', redirect: 'error',
      signal: AbortSignal.timeout(20000), headers: { Accept: 'application/json', ...(args.nonce ? { Nonce: args.nonce } : {}), ...(args.body ? { 'Content-Type': 'application/json' } : {}) }, ...(args.body ? { body: args.body } : {}) });
    const raw = await response.text(); if (raw.length > 1048576) throw new Error('cart');
    const value = JSON.parse(raw);
    return { ok: response.ok, nonce: response.headers.get('Nonce') || args.nonce,
      items: Array.isArray(value.items) ? value.items.map(item => ({ id: item.id, quantity: item.quantity })) : null };
  }, { method, pathname, nonce, body });
}
export async function inspectLive(options = {}) {
  const report = emptyReport(); let browser, context, page, permit = null, nonce = '', selected = 0, ownedCart = false, blocked = 0;
  try {
    report.category = 'browser'; const { chromium } = require('playwright');
    const installed = chromium.executablePath();
    browser = await chromium.launch({ headless: true, ...(fs.existsSync(installed) ? {} : fs.existsSync('/usr/bin/chromium') ? { executablePath: '/usr/bin/chromium' } : {}), args: ['--no-sandbox'] });
    report.checks.browser_started = true;
    context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'en-AU', serviceWorkers: 'block' });
    report.checks.fresh_guest_context = (await context.cookies()).length === 0;
    await context.route('**/*', async route => {
      const request = route.request();
      if (allowedRequest(request.url(), request.method(), request.resourceType(), request.postData() || '', permit)) {
        const requestKind = providerRequestKind(request.url(), request.method(), request.postData() || '');
        if (requestKind === 'stripe_session_get') { report.observations.stripe_session_get_allowed = true; report.metrics.stripe_session_get_count = Math.min(METRIC_MAX, report.metrics.stripe_session_get_count + 1); }
        if (paypalSdkTokenRead(request.url(), request.method(), request.postData() || '')) { report.observations.paypal_sdk_token_initialization_allowed = true; report.metrics.paypal_sdk_token_initialization_count = Math.min(METRIC_MAX, report.metrics.paypal_sdk_token_initialization_count + 1); }
        const ownRead = auditedSessionRead(request.url(), request.method(), request.postData() || '');
        if (ownRead) { const kind = ownRead === 'account' ? 'account_fragment_read' : 'cart_count_fragment_read'; report.observations[kind + '_allowed'] = true; report.metrics[kind + '_count'] = Math.min(METRIC_MAX, report.metrics[kind + '_count'] + 1); }
        if (publicStripeSession(request.url(), request.method(), request.postData() || '')) { report.observations.stripe_session_initialization_allowed = true; report.metrics.stripe_session_initialization_count = Math.min(METRIC_MAX, report.metrics.stripe_session_initialization_count + 1); }
        if (permit && request.method() === permit.method && new URL(request.url()).origin === ORIGIN && new URL(request.url()).pathname === permit.path) permit.used = true;
        return route.continue();
      }
      blocked++; report.observations.other_requests_blocked = true;
      try {
        const url = new URL(request.url()), method = request.method(), kind = providerRequestKind(request.url(), request.method(), request.postData() || '');
        const blockedKind = {stripe_telemetry:'stripe_telemetry',paypal_graphql_query:'paypal_graphql_query',paypal_graphql_mutation:'paypal_graphql_mutation',paypal_graphql_other:'paypal_graphql_other',paypal_logger:'paypal_logger',google_provider:'google_provider',unknown_provider:'unknown_provider',paypal_sdk_token:'paypal_sdk_token',stripe_session_get:'stripe_session_get',stripe_session_post:'stripe_session_post'}[kind];
        if (blockedKind) {
          const key = 'blocked_' + blockedKind + '_count'; report.metrics[key] = Math.min(METRIC_MAX, report.metrics[key] + 1);
          const observation = {stripe_telemetry:'stripe_telemetry_blocked',paypal_graphql_query:'paypal_graphql_query_blocked',paypal_graphql_mutation:'paypal_graphql_mutation_blocked',paypal_logger:'paypal_logger_blocked',unknown_provider:'unknown_provider_request_blocked'}[kind];
          if (observation) report.observations[observation] = true;
          if (kind === 'stripe_session_get' || kind === 'stripe_session_post') report.observations.stripe_session_request_blocked = true;
        }
        if (PAYMENT_ACTION.test(url.pathname)) { report.observations.checkout_requests_blocked = true; report.observations.payment_action_blocked = true; report.metrics.blocked_payment_action_count = Math.min(METRIC_MAX, report.metrics.blocked_payment_action_count + 1); }
        if (PROVIDER_HOST.test(url.hostname) || url.hostname === 'pay.google.com') {
          const key = method === 'GET' || method === 'HEAD' ? 'get' : 'post'; report.observations['provider_' + key + '_blocked'] = true;
          if (key === 'post' && !PAYMENT_ACTION.test(url.pathname) && !['stripe_telemetry','paypal_logger'].includes(kind)) report.observations.provider_bootstrap_blocked = true;
          report.metrics['blocked_provider_' + key + '_count'] = Math.min(METRIC_MAX, report.metrics['blocked_provider_' + key + '_count'] + 1);
        }
        if (url.origin === ORIGIN && url.pathname.startsWith('/wp-json/wc/store/')) { report.observations.store_api_blocked = true; report.metrics.blocked_store_api_count = Math.min(METRIC_MAX, report.metrics.blocked_store_api_count + 1); }
      } catch {}
      return route.abort('blockedbyclient');
    });
    report.checks.network_barrier_enforced = true; page = await context.newPage(); page.setDefaultTimeout(20000);
    const navigate = async (route, category) => { report.category = category; const response = await page.goto(ORIGIN + route, { waitUntil: 'domcontentloaded', timeout: 45000 }); if (!response || response.status() !== 200 || page.url() !== ORIGIN + route) throw new Error(category); return response; };
    await navigate('/', 'home'); report.observations.home_http_ok = true; await page.waitForTimeout(1500); merge(report, await pageFacts(page, 'home'));
    const initial = await cartAPI(page, 'GET', '/wp-json/wc/store/v1/cart', nonce);
    if (!initial.ok || !Array.isArray(initial.items) || initial.items.length !== 0 || typeof initial.nonce !== 'string' || !/^[a-zA-Z0-9]{6,64}$/.test(initial.nonce)) throw new Error('cart');
    nonce = initial.nonce; report.checks.initial_cart_empty = true;
    await navigate(PRODUCT, 'product'); report.observations.product_http_ok = true; await page.waitForTimeout(1500);
    try { await page.waitForFunction(() => {const el=document.querySelector('.product .summary #payment-method-message iframe');return el&&el.getBoundingClientRect().height>8;},null,{timeout:6000}); } catch {}
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    merge(report, await pageFacts(page, 'product'));
    recordBrand(report,'product',await page.evaluate(brandGeometryFacts,'product'));
    await inspectProductAfterpayFonts(page,report);
    const productCrop=await capturePublicPanel(page,options.artifactDirectory,'product'); report.observations.product_payment_crop_saved=productCrop.saved;report.metrics.product_crop_width_px=productCrop.width;report.metrics.product_crop_height_px=productCrop.height;
    await inspectReviewFilters(page,report);
    await inspectRelatedProducts(page,report);report.observations.related_original_product_inspected=report.observations.related_section_present;
    selected = await page.locator('form.variations_form').evaluate(form => {
      const values = JSON.parse(form.getAttribute('data-product_variations') || 'null');
      if (!Array.isArray(values)) return 0;
      const value = values.find(v => Number.isSafeInteger(v.variation_id) && v.variation_id > 0 && v.is_purchasable === true && v.is_in_stock === true && v.variation_is_active !== false && v.variation_is_visible !== false && (!v.max_qty || Number(v.max_qty) >= 2) && (!v.min_qty || Number(v.min_qty) <= 2));
      return value?.variation_id || 0;
    });
    if (!Number.isSafeInteger(selected) || selected < 1) throw new Error('product'); report.checks.public_variation_selected = true;
    if(report.metrics.related_hot_badge_count===0){await navigate(RELATED_PRODUCT,'product');const alternate=await inspectRelatedProducts(page,report);report.observations.related_alternate_product_inspected=true;report.observations.related_result_from_alternate_product=alternate;if(!alternate){await navigate(PRODUCT,'product');await inspectRelatedProducts(page,report);}}
    const relatedCrop=await capturePublicPanel(page,options.artifactDirectory,'related');report.observations.related_products_crop_saved=relatedCrop.saved;report.metrics.related_crop_width_px=relatedCrop.width;report.metrics.related_crop_height_px=relatedCrop.height;
    report.category = 'cart'; const body = JSON.stringify({ id: selected, quantity: 2 }); permit = { method: 'POST', path: '/wp-json/wc/store/v1/cart/add-item', body, used: false }; ownedCart = true;
    let cart; try { cart = await cartAPI(page, 'POST', permit.path, nonce, body); } finally { permit = null; }
    if (!cart.ok || !Array.isArray(cart.items) || cart.items.length !== 1 || cart.items[0].id !== selected || cart.items[0].quantity !== 2) throw new Error('cart');
    nonce = cart.nonce; report.checks.guest_cart_created = true; report.checks.cart_quantity_verified = true;
    await navigate('/cart/', 'cart'); report.observations.cart_http_ok = true;
    await page.locator('.wc-block-cart-items__row, .cart_item').first().waitFor({ state: 'visible', timeout: 30000 });
    report.checks.cart_page_loaded = true; report.category = 'providers';
    await page.waitForTimeout(8000); merge(report, await pageFacts(page, 'cart')); merge(report,await page.evaluate(cartNativeFlowFacts)); await inspectAfterpay(page, report);
    const cartCrop=await capturePublicPanel(page,options.artifactDirectory,'cart');report.observations.cart_payment_crop_saved=cartCrop.saved;report.metrics.cart_crop_width_px=cartCrop.width;report.metrics.cart_crop_height_px=cartCrop.height;
    report.checks.passive_sdk_inspected = true;
  } catch { /* Report only the finite stage and observations; never exception text, page data or SDK URLs. */ }
  finally {
    if (page && report.checks.initial_cart_empty) {
      for (let attempt = 0; attempt < 2 && !report.checks.created_guest_cart_cleared; attempt++) {
        try {
          if (ownedCart) { permit = { method: 'DELETE', path: '/wp-json/wc/store/v1/cart/items', body: '', used: false }; try { const cleared = await cartAPI(page, 'DELETE', permit.path, nonce); nonce = cleared.nonce; } finally { permit = null; } }
          const after = await cartAPI(page, 'GET', '/wp-json/wc/store/v1/cart', nonce);
          report.checks.created_guest_cart_cleared = after.ok && Array.isArray(after.items) && after.items.length === 0;
        } catch { permit = null; }
      }
    }
    report.metrics.blocked_request_count = Math.min(METRIC_MAX, blocked);
    if (ownedCart && !report.checks.created_guest_cart_cleared) report.category = 'cleanup';
    if (context) await context.close().catch(() => {}); if (browser) await browser.close().catch(() => {});
  }
  if (Object.values(report.checks).every(Boolean)) { report.status = 'success'; report.category = 'complete'; }
  return screenReport(report);
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { if (process.argv.length !== 3) throw new Error('input'); reportDestination(process.argv[2]); const report = await inspectLive({artifactDirectory:path.dirname(process.argv[2])}); writeReport(process.argv[2], report); process.exitCode = report.status === 'success' ? 0 : 1; }
  catch { process.stderr.write('The fixed live storefront inspection could not be completed.\n'); process.exitCode = 1; }
}
