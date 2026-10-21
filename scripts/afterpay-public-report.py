#!/usr/bin/env python3
"""Read two fixed anonymous public pages; publish markup facts, never raw HTML/config."""
import importlib.util
import json
import re
import ssl
import sys
from html.parser import HTMLParser
from pathlib import Path
from urllib.request import HTTPRedirectHandler, HTTPSHandler, Request, build_opener

PRODUCT = 'https://petish.biz/product/corner-gap-cleaning-brush/?attribute_color=Blue'
CART = 'https://petish.biz/cart/'


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Markup(HTMLParser):
    def __init__(self):
        super().__init__()
        self.message_count = 0
        self.express_present = False
        self.messaging_asset = False
        self.cart_empty = False
        self.script = False
        self.scripts = []
        self.variations = []

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if attrs.get('id') == 'payment-method-message':
            self.message_count += 1
        classes = attrs.get('class', '').split()
        self.express_present |= attrs.get('id') == 'wcpay-express-checkout-element' or 'wcpay-express-checkout-wrapper' in classes
        self.cart_empty |= 'cart-empty' in classes or 'wc-block-cart__empty-cart__title' in classes
        if tag == 'script':
            self.script = True
            self.messaging_asset |= '/woocommerce-payments/dist/product-details' in attrs.get('src', '')
        if tag == 'form' and 'data-product_variations' in attrs:
            try:
                variations = json.loads(attrs['data-product_variations'])
                if isinstance(variations, list) and len(variations) <= 100:
                    self.variations = variations
            except (ValueError, TypeError):
                pass

    def handle_endtag(self, tag):
        if tag == 'script':
            self.script = False

    def handle_data(self, value):
        if self.script:
            self.scripts.append(value)


def country_or_currency(value, length):
    return value.upper() if isinstance(value, str) and re.fullmatch('[A-Za-z]{%d}' % length, value) else 'unknown'


def boolean_or_unknown(value):
    return value if type(value) is bool else None


def minor_amount(value):
    if type(value) in {int, float} and 0 <= value <= 100000000:
        return int(value) if value == int(value) else None
    return None


def facts(html):
    parser = Markup()
    parser.feed(html)
    config = None
    for script in parser.scripts:
        match = re.search(r'\bvar\s+wcpayStripeSiteMessaging\s*=\s*', script)
        if match:
            try:
                candidate, _ = json.JSONDecoder().raw_decode(script[match.end():])
                if isinstance(candidate, dict):
                    config = candidate
            except ValueError:
                pass
    result = {
        'status': 'success',
        'message_container_count': min(parser.message_count, 3),
        'native_express_present': parser.express_present,
        'messaging_asset_present': parser.messaging_asset,
        'messaging_config_present': config is not None,
        'empty_cart_markup': parser.cart_empty,
        'afterpay_in_messaging_methods': None,
        'messaging_country': 'unknown',
        'messaging_currency': 'unknown',
        'should_initialize_messaging': None,
        'should_show_messaging': None,
        'base_product_minor_amount': None,
        'blue_variation_minor_amount': None,
    }
    if config is not None:
        methods = config.get('paymentMethods')
        result.update({
            'afterpay_in_messaging_methods': 'afterpay_clearpay' in methods if isinstance(methods, list) else None,
            'messaging_country': country_or_currency(config.get('country'), 2),
            'messaging_currency': country_or_currency(config.get('currencyCode'), 3),
            'should_initialize_messaging': boolean_or_unknown(config.get('shouldInitializePMME')),
            'should_show_messaging': boolean_or_unknown(config.get('shouldShowPMME')),
        })
        prices = config.get('productVariations')
        if isinstance(prices, dict):
            base = prices.get('base_product')
            if isinstance(base, dict):
                result['base_product_minor_amount'] = minor_amount(base.get('amount'))
            for variation in parser.variations:
                if not isinstance(variation, dict) or not isinstance(variation.get('attributes'), dict):
                    continue
                if variation['attributes'].get('attribute_color') != 'Blue':
                    continue
                item = prices.get(str(variation.get('variation_id')))
                if isinstance(item, dict):
                    result['blue_variation_minor_amount'] = minor_amount(item.get('amount'))
    return result


def fetch(url):
    try:
        opener = build_opener(NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        request = Request(url, headers={'Accept-Encoding': 'identity', 'User-Agent': 'Petish-Afterpay-read-only-review/1.0'})
        with opener.open(request, timeout=25) as response:
            if response.status != 200 or response.geturl() != url:
                raise ValueError()
            body = response.read(1048577)
            if not 0 < len(body) <= 1048576:
                raise ValueError()
        return facts(body.decode('utf-8'))
    except Exception:
        return {'status': 'unavailable'}


def main():
    if len(sys.argv) != 2:
        raise SystemExit('Public Afterpay probe needs its sanitized report path.')
    path = Path(sys.argv[1])
    if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1 or path.stat().st_size > 8192:
        raise SystemExit('Public Afterpay probe report path failed validation.')
    spec = importlib.util.spec_from_file_location('afterpay_report', Path(__file__).with_name('afterpay-report.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    report = module.validate(json.loads(path.read_text()))
    report['public_ui'] = {'blue_product': fetch(PRODUCT), 'anonymous_empty_cart': fetch(CART)}
    path.write_text(json.dumps(report, indent=2, sort_keys=True) + '\n')


if __name__ == '__main__':
    main()
