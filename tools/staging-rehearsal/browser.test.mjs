import test from 'node:test';
import assert from 'node:assert/strict';
import { allowedRequest, publicReport, validateInput } from './browser.mjs';

const safe = () => ({
  url: 'https://staging.petish.biz', login: 'private-fixture-login', password: 'private-fixture-password',
  variation_id: 246, parent_id: 242, attribute_pa_color: 'blue', run_id: 'fixture-1',
  shipping_method_id: 'petish_rehearsal:fixture-1',
  safety: { environment_staging: true, woocommerce_test_mode: true, wcpay_dev_mode: true,
    account_is_live: false, guard_runtime_verified: true, suppliers_blocked: true,
    mail_capture_verified: true, webhooks_blocked: true },
});

test('only the exact staging origin and explicit provider hosts are allowed', () => {
  for (const url of ['https://staging.petish.biz/checkout/', 'https://api.stripe.com/v1/payment_intents/test',
    'https://js.stripe.com/v3/', 'https://newassets.hcaptcha.com/example']) assert.equal(allowedRequest(url), true);
  for (const url of ['https://petish.biz/', 'http://staging.petish.biz/', 'https://staging.petish.biz:8443/',
    'https://staging.petish.biz.evil.invalid/', 'https://staging.petish.biz@evil.invalid/',
    'https://api.stripe.com.evil.invalid/', 'https://dsers.com/', 'https://aliexpress.com/',
    'https://www.google-analytics.com/', 'data:text/plain,test', 'not-a-url']) assert.equal(allowedRequest(url), false);
});

test('all test-mode and isolation proofs are prerequisites', () => {
  assert.equal(validateInput(safe()).customer_email, 'rehearsal-fixture-1@petish.invalid');
  for (const key of Object.keys(safe().safety)) {
    const input = safe(); delete input.safety[key]; assert.throws(() => validateInput(input));
    input.safety[key] = key === 'account_is_live' ? true : false; assert.throws(() => validateInput(input));
    input.safety[key] = key === 'account_is_live' ? 0 : 1; assert.throws(() => validateInput(input));
  }
});

test('no other site, variation, currency recipient or shipping method is inferred', () => {
  for (const [key, value] of [['url', 'https://petish.biz'], ['variation_id', 153],
    ['parent_id', 999], ['attribute_pa_color', 'red'], ['shipping_method_id', ''],
    ['run_id', '../private'], ['customer_email', 'customer@example.com'], ['customer_email', 'a@b.invalid\nBcc:x']]) {
    assert.throws(() => validateInput({ ...safe(), [key]: value }));
  }
  assert.equal(validateInput({ ...safe(), customer_email: 'qa@example.invalid' }).customer_email, 'qa@example.invalid');
});

test('public report cannot include credentials, receipt access keys, errors or URLs', () => {
  const result = publicReport({ customer_login_verified: true, paid_receipt_verified: false,
    payment_attempts: 1, failure_stage: 'receipt', order_id: 2000, order_key: 'wc_order_PRIVATE',
    login: 'PRIVATE_USER', password: 'PRIVATE_PASSWORD', receipt_url: 'https://private.invalid/?token=PRIVATE',
    failure_message: 'PRIVATE_ERROR', arbitrary: 'PRIVATE' });
  assert.deepEqual(result, { customer_login_verified: true, paid_receipt_verified: false,
    payment_attempts: 1, failure_stage: 'receipt' });
  assert.equal(JSON.stringify(result).includes('PRIVATE'), false);
  assert.deepEqual(publicReport({ payment_attempts: 3, failure_stage: 'PRIVATE', paid_receipt_verified: 'yes' }), {});
});
