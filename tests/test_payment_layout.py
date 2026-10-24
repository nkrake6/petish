"""Native-provider hook ordering and presentation-only page boundaries; no network."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
THEME = ROOT / 'wordpress/wp-content/themes/dt-the7-child'
PHP = os.environ.get('PETISH_PHP_EXECUTABLE') or shutil.which('php') or '/workspace/.petish-dev/bin/php'
WOO_ROOT = next((Path(value) for value in (os.environ.get('PETISH_WOOCOMMERCE_ROOT'),
    str(ROOT/'wordpress/wp-content/plugins/woocommerce'),
    '/tmp/petish-fyp-repro/WordPress-7.1.3/wp-content/plugins/woocommerce')
    if value and (Path(value)/'src/Blocks/Payments/PaymentMethodRegistry.php').is_file()), None)
PAYPAL_ROOT = next((Path(value) for value in (os.environ.get('PETISH_PAYPAL_PAYMENTS_ROOT'),
    str(ROOT/'wordpress/wp-content/plugins/woocommerce-paypal-payments'),
    '/tmp/petish-payment-layout-draft/paypal-source')
    if value and (Path(value)/'modules/ppcp-settings/src/DTO/LocationStylingDTO.php').is_file()), None)


@unittest.skipUnless(Path(PHP).is_file(), 'PHP required for native provider hook fixtures')
class PaymentLayoutTests(unittest.TestCase):
    def product(self, context):
        result = subprocess.run([PHP, str(ROOT/'tests/product-payment-layout.php'), context],
                                capture_output=True, text=True, check=True, timeout=15)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_native_product_controls_keep_order_and_content_hashed_assets(self):
        result = self.product('product')
        self.assertEqual(result['hook'], 'petish_product_paypal_controls')
        self.assertEqual(result['message_hook'], result['hook'])
        self.assertEqual(result['message_priority'], 40)
        html = result['html']
        self.assertEqual(html.count('class="petish-product-paypal"'), 1)
        self.assertLess(html.index('wcpay-express-checkout-element'), html.index('petish-product-paypal'))
        self.assertLess(html.index('ppc-button-ppcp-gateway'), html.index('ppcp-messages'))
        self.assertEqual(len(result['assets']), 2)
        self.assertEqual(result['inline'], [['petish-product-payment-groups','window.petishPaymentMessagePage = "product";','before']])
        for _, arguments in result['assets']:
            relative = arguments[1].removeprefix('https://offline.invalid/theme/')
            self.assertEqual(arguments[3], hashlib.sha256((THEME/relative).read_bytes()).hexdigest()[:16])

    def test_cart_and_unavailable_product_preserve_native_provider_hooks(self):
        for context in ('cart', 'out-of-stock'):
            with self.subTest(context=context):
                result = self.product(context)
                self.assertEqual(result['hook'], 'woocommerce_single_product_summary')
                self.assertEqual(result['message_priority'], 30)
                self.assertNotIn('petish-product-paypal', result['html'])
                self.assertEqual(len(result['assets']), 2 if context=='cart' else 0)
                if context=='cart':
                    self.assertEqual(result['assets'][0][1][2], ['petish-design'])
                    self.assertIs(result['assets'][1][1][4],False)
                    self.assertEqual(result['inline'], [['petish-product-payment-groups','window.petishPaymentMessagePage = "cart";','before']])
        self.assertNotIn('class="petish-product-paypal"', self.product('provider-unavailable')['html'])

    def test_brand_heading_uses_existing_provider_asset_and_missing_asset_stays_empty(self):
        self.assertEqual(self.product('product')['style_inline'], [])
        with tempfile.TemporaryDirectory(prefix='petish-provider-asset-') as work:
            plugin = Path(work)/'woocommerce-payments.php'
            badge = Path(work)/'assets/images/payment-methods/afterpay-badge.svg'
            plugin.write_text('<?php // Provider path fixture')
            badge.parent.mkdir(parents=True)
            badge.write_text('<svg xmlns="http://www.w3.org/2000/svg"/>')
            result = subprocess.run([PHP, str(ROOT/'tests/product-payment-layout.php'), 'product'],
                env={**os.environ, 'PETISH_TEST_WCPAY_PLUGIN_FILE': str(plugin)},
                capture_output=True, text=True, check=True, timeout=15)
            self.assertEqual(result.stderr, '')
            native = json.loads(result.stdout)
            self.assertEqual(native['provider_assets'], [['assets/images/payment-methods/afterpay-badge.svg', str(plugin)]])
            self.assertEqual(len(native['style_inline']), 1)
            self.assertEqual(native['style_inline'][0][0], 'petish-product-payment-groups')
            self.assertIn('--petish-afterpay-badge-image:url(', native['style_inline'][0][1])
            self.assertIn('afterpay-badge.svg', native['style_inline'][0][1])
            self.assertNotIn('data:image', native['style_inline'][0][1])

    def test_cart_block_assets_do_not_run_on_classic_cart_or_other_pages(self):
        result = subprocess.run([PHP, str(ROOT/'tests/cart-payment-layout.php')],
                                capture_output=True, text=True, check=True, timeout=15)
        self.assertEqual(result.stderr, '')
        self.assertTrue(result.stdout.startswith('PASS: Cart Block-only body/assets;'))

    @unittest.skipUnless(WOO_ROOT and PAYPAL_ROOT, 'Native WooCommerce registry and PayPal DTO source required')
    def test_cart_provider_selection_uses_native_registry_without_gateway_writes(self):
        for mode in ('normal', 'rest', 'cli'):
            with self.subTest(mode=mode):
                result = subprocess.run([PHP, str(ROOT/'tests/cart-google-pay-provider.php'), mode],
                    env={**os.environ, 'PETISH_WOOCOMMERCE_ROOT': str(WOO_ROOT), 'PETISH_PAYPAL_PAYMENTS_ROOT': str(PAYPAL_ROOT)},
                    capture_output=True, text=True, check=True, timeout=15)
                self.assertEqual(result.stderr, '')
                self.assertTrue(result.stdout.startswith('PASS: '))
                self.assertIn('no saved settings or gateway changes', result.stdout)


if __name__ == '__main__':
    unittest.main()
