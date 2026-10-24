#!/usr/bin/env python3
"""Inspect three fixed public HTML shells; publish only known DOM structure."""
from html.parser import HTMLParser
import importlib.util
import hashlib
import json
import math
import os
from pathlib import Path
import re
import ssl
import stat
import sys
from urllib.request import HTTPSHandler, Request, build_opener

ROOT = Path(__file__).resolve().parents[1]
ROUTES = {'home': '/', 'cart': '/cart/', 'product': '/product/rainbow-habitat-hideout/'}
MAX_BYTES = 2097152
TAGS = set('html body header nav main div ul ol li a button span section aside form figure p iframe table tbody tr td'.split())
IDS = set('masthead phantom phantom-mobile mobile-header mobile-menu page main primary-menu shopping-cart '
          'express-payment-method-woocommerce_payments express-payment-method-woocommerce_payments_express_checkout_googlePay '
          'express-payment-method-ppcp-googlepay express-payment-method-ppcp-gateway-paypal '
          'express-payment-method-ppcp-gateway-paylater wcpay-express-checkout-element '
          'ppc-button-ppcp-gateway ppc-button-ppcp-googlepay ppc-button-googlepay-container '
          'ppc-button-ppcp-googlepay-v6 ppcp-cart-paylater-messages'.split())
CLASSES = set('masthead header-bar mobile-header-bar inline-header top-header side-header header-side-left header-side-right '
              'branding main-nav mobile-main-nav mini-nav mini-sub-nav mini-widgets mobile-mini-widgets '
              'mobile-header mobile-navigation mobile-mini-widgets-in-menu mobile-menu-show-divider '
              'shopping-cart mini-woocommerce-cart wc-shopping-cart wc-ico-cart mini-login login the7-login login-modal '
              'menu-item menu-item-type-post_type menu-item-object-page menu-text sub-nav '
              'petish-account-menu petish-account-avatar petish-account-toggle petish-account-dropdown__panel '
              'petish-theme-switch petish-theme-control petish-market-selector petish-market-selector__toggle '
              'petish-cart-badge petish-cart-payment-layout petish-product-paypal petish-product-payments '
              'wc-block-cart wc-block-cart__sidebar wp-block-woocommerce-cart '
              'wc-block-components-express-payment wc-block-components-express-payment__event-buttons '
              'wc-block-components-express-payment-continue-rule--cart wc-block-cart__submit '
              'wp-block-woocommerce-cart-express-payment-block ppcp-messages ppcp-button-apm '
              'ppcp-button-googlepay gpay-button StripeElement wcpay-payment-request-wrapper '
              'wcpay-stripelink-modal-trigger wcpay-stripelink-modal-trigger-container '
              'product_meta summary entry-summary payment_method_afterpay_clearpay '
              'payment_method_woocommerce_payments afterpay-logo afterpay-paragraph'.split())
# Each public label maps only to fixed IDs/classes; arbitrary attributes never leave parsing.
TARGETS = {
    'masthead': ({'masthead'}, {'masthead'}),
    'desktop_navigation': (set(), {'main-nav'}),
    'mobile_navigation': (set(), {'mobile-main-nav', 'mobile-navigation'}),
    'branding': (set(), {'branding'}),
    'header_widgets': (set(), {'mini-widgets'}),
    'mobile_widgets': (set(), {'mobile-mini-widgets', 'mobile-mini-widgets-in-menu'}),
    'native_cart': ({'shopping-cart'}, {'shopping-cart', 'mini-woocommerce-cart', 'wc-shopping-cart', 'wc-ico-cart'}),
    'native_login': (set(), {'mini-login', 'login', 'the7-login', 'login-modal'}),
    'account_menu': (set(), {'petish-account-menu'}),
    'account_avatar': (set(), {'petish-account-avatar'}),
    'account_panel': (set(), {'petish-account-dropdown__panel'}),
    'theme_switch': (set(), {'petish-theme-switch', 'petish-theme-control'}),
    'market_selector': (set(), {'petish-market-selector'}),
    'cart_block': (set(), {'wc-block-cart', 'wp-block-woocommerce-cart'}),
    'cart_sidebar': (set(), {'wc-block-cart__sidebar'}),
    'express_container': (set(), {'wc-block-components-express-payment'}),
    'wcpay_google': ({'express-payment-method-woocommerce_payments_express_checkout_googlePay'}, set()),
    'wcpay_express': ({'express-payment-method-woocommerce_payments', 'wcpay-express-checkout-element'}, set()),
    'paypal_google': ({'express-payment-method-ppcp-googlepay', 'ppc-button-ppcp-googlepay',
                       'ppc-button-googlepay-container', 'ppc-button-ppcp-googlepay-v6'}, {'ppcp-button-googlepay'}),
    'paypal': ({'express-payment-method-ppcp-gateway-paypal', 'ppc-button-ppcp-gateway'}, set()),
    'paylater': ({'express-payment-method-ppcp-gateway-paylater'}, set()),
    'paypal_message': ({'ppcp-cart-paylater-messages'}, {'ppcp-messages'}),
    'product_paypal_group': (set(), {'petish-product-paypal'}),
    'product_metadata': (set(), {'product_meta'}),
    'afterpay': (set(), {'payment_method_afterpay_clearpay', 'afterpay-logo', 'afterpay-paragraph'}),
}
PUBLIC_CHECKS = {'fixed_public_get_only', 'responses_bounded', 'known_dom_only', 'static_html_only'}
PUBLIC_CATEGORIES = {'input', 'http', 'html', 'complete'}
VOID = set('area base br col embed hr img input link meta param source track wbr'.split())


def empty_targets():
    return {name: {'count': 0, 'chains': []} for name in TARGETS}


class Structure(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack = []
        self.targets = empty_targets()
        self.elements = 0

    def handle_starttag(self, tag, attrs):
        self.elements += 1
        if self.elements > 100000 or len(self.stack) > 256:
            raise ValueError('HTML exceeds fixed structural bounds')
        attrs = dict(attrs)
        classes = set((attrs.get('class') or '').split()) & CLASSES
        identifier = attrs.get('id') if attrs.get('id') in IDS else None
        node = {'tag': tag if tag in TAGS else 'div', 'id': identifier, 'classes': sorted(classes)}
        chain = [item[1] for item in self.stack[-7:]] + [node]
        for name, (ids, tokens) in TARGETS.items():
            if identifier in ids or classes & tokens:
                target = self.targets[name]
                target['count'] = min(99, target['count'] + 1)
                if len(target['chains']) < 4:
                    target['chains'].append(chain)
        if tag not in VOID:
            self.stack.append((tag, node))

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag not in VOID:
            self.handle_endtag(tag)

    def handle_endtag(self, tag):
        for position in range(len(self.stack) - 1, -1, -1):
            if self.stack[position][0] == tag:
                del self.stack[position:]
                return


def inspect_html(body):
    if isinstance(body, bytes):
        if not 0 < len(body) <= MAX_BYTES:
            raise ValueError('Invalid HTML size')
        body = body.decode('utf-8', errors='replace')
    if not isinstance(body, str) or not 0 < len(body.encode('utf-8')) <= MAX_BYTES:
        raise ValueError('Invalid HTML size')
    parser = Structure()
    parser.feed(body)
    parser.close()
    return parser.targets


def module(filename, name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


def inspect_public(opener=None):
    http = module('verify-account-navigation-assets.py', 'storefront_public_http')
    opener = opener or build_opener(http.NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
    report = {'status': 'failure', 'category': 'http', 'checks': dict.fromkeys(PUBLIC_CHECKS, True),
              'routes': {name: {'http_ok': False, 'html_ok': False, 'targets': empty_targets()} for name in ROUTES}}
    for name, path in ROUTES.items():
        url = 'https://petish.biz' + path
        request = Request(url, headers={'Accept-Encoding': 'identity', 'User-Agent': 'Petish-readonly-inspector/1.0'})
        try:
            with opener.open(request, timeout=20) as response:
                if response.status != 200 or response.geturl() != url:
                    continue
                report['routes'][name]['http_ok'] = True
                if response.headers.get('Content-Encoding', '').lower() not in ('', 'identity'):
                    continue
                body = response.read(MAX_BYTES + 1)
                if not 0 < len(body) <= MAX_BYTES:
                    continue
                report['routes'][name]['targets'] = inspect_html(body)
                report['routes'][name]['html_ok'] = True
        except Exception:
            # No response text, redirect location, cookies, iframe URL or config is retained.
            continue
    if all(route['http_ok'] and route['html_ok'] for route in report['routes'].values()):
        report.update(status='success', category='complete')
    return report


def regular(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= 262144:
        raise ValueError('Invalid private report')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
            raise ValueError('Private report changed')
        return stream.read(262145)


def unique(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('Duplicate report field')
        value[key] = item
    return value


def screen_public(value):
    if not isinstance(value, dict) or set(value) != {'status', 'category', 'checks', 'routes'} \
            or value['status'] not in {'success', 'failure'} or value['category'] not in PUBLIC_CATEGORIES \
            or not isinstance(value['checks'], dict) or set(value['checks']) != PUBLIC_CHECKS \
            or any(type(item) is not bool for item in value['checks'].values()) \
            or not isinstance(value['routes'], dict) or set(value['routes']) != set(ROUTES):
        raise ValueError('Invalid public structure report')
    for route in value['routes'].values():
        if not isinstance(route, dict) or set(route) != {'http_ok', 'html_ok', 'targets'} \
                or type(route['http_ok']) is not bool or type(route['html_ok']) is not bool \
                or not isinstance(route['targets'], dict) or set(route['targets']) != set(TARGETS):
            raise ValueError('Invalid route report')
        for target in route['targets'].values():
            if not isinstance(target, dict) or set(target) != {'count', 'chains'} \
                    or type(target['count']) is not int or not 0 <= target['count'] <= 99 \
                    or not isinstance(target['chains'], list) or len(target['chains']) > min(4, target['count']):
                raise ValueError('Invalid known-node report')
            for chain in target['chains']:
                if not isinstance(chain, list) or not 1 <= len(chain) <= 8:
                    raise ValueError('Invalid ancestor chain')
                for node in chain:
                    if not isinstance(node, dict) or set(node) != {'tag', 'id', 'classes'} \
                            or not isinstance(node['tag'], str) or node['tag'] not in TAGS \
                            or node['id'] is not None and (not isinstance(node['id'], str) or node['id'] not in IDS) \
                            or not isinstance(node['classes'], list) or len(node['classes']) > len(CLASSES) \
                            or any(not isinstance(item, str) or item not in CLASSES for item in node['classes']) \
                            or len(node['classes']) != len(set(node['classes'])):
                        raise ValueError('Invalid known ancestor')
    passed = value['status'] == 'success' and value['category'] == 'complete' \
        and all(value['checks'].values()) and all(route['http_ok'] and route['html_ok'] for route in value['routes'].values())
    if not passed:
        value['status'] = 'failure'
    return value, passed


def native_schema():
    source = (ROOT / 'scripts/storefront-payment-runtime.php').read_text()
    match = re.search(r'const PETISH_STOREFRONT_PAYMENT_CHECKS\s*=\s*\[(.*?)\];', source, re.S)
    if not match:
        raise ValueError('Missing native diagnostic contract')
    names = re.findall(r"'([a-z][a-z0-9_]{0,79})'", match.group(1))
    if not 5 <= len(names) <= 300 or len(names) != len(set(names)):
        raise ValueError('Invalid native diagnostic contract')
    return set(names)


def screen_native(value):
    required = native_schema()
    if not isinstance(value, dict) or set(value) != {'status', 'category', 'checks'} \
            or value['status'] not in {'success', 'failure'} \
            or value['category'] not in {'input', 'paths', 'bootstrap', 'runtime', 'diagnostics', 'complete'} \
            or not isinstance(value['checks'], dict) or not set(value['checks']) <= required \
            or any(type(item) is not bool for item in value['checks'].values()):
        raise ValueError('Invalid native report')
    guards = {'target_paths_verified', 'target_environment_verified', 'woocommerce_runtime_verified',
              'all_outbound_http_blocked', 'mail_calls_blocked', 'remote_file_access_disabled',
              'cron_and_async_runner_disabled', 'orders_unchanged', 'users_and_authentication_unchanged',
              'product_prices_and_stock_unchanged', 'settings_unchanged', 'existing_passports_unchanged',
              'private_photo_files_unchanged', 'readonly_sql_barrier_attached', 'cart_query_context_verified', 'cart_hook_observed'}
    passed = value['status'] == 'success' and value['category'] == 'complete' and set(value['checks']) == required \
        and all(value['checks'].get(name) is True for name in guards) \
        and value['checks'].get('native_probe_error_observed') is False
    if not passed:
        value['status'] = 'failure'
    return value, passed


def browser_schema():
    source = (ROOT / 'tools/storefront-live-inspect.mjs').read_text()
    match = re.search(r'const PUBLIC_SCHEMA\s*=\s*(\{.*?\});', source, re.S)
    if not match:
        raise ValueError('Missing browser diagnostic contract')
    value = json.loads(match.group(1), object_pairs_hook=unique)
    if not isinstance(value, dict) or set(value) != {'checks', 'observations', 'metrics', 'categories'}:
        raise ValueError('Invalid browser diagnostic contract')
    for group in value.values():
        if not isinstance(group, list) or len(group) > 200 or len(group) != len(set(group)) \
                or any(not isinstance(name, str) or not re.fullmatch(r'[a-z][a-z0-9_]{0,79}', name) for name in group):
            raise ValueError('Invalid browser diagnostic contract')
    return {name: set(group) for name, group in value.items()}


def screen_browser(value):
    schema = browser_schema()
    if not isinstance(value, dict) or set(value) != {'status', 'category', 'checks', 'observations', 'metrics'} \
            or value['status'] not in {'success', 'failure'} or value['category'] not in schema['categories']:
        raise ValueError('Invalid browser diagnostic report')
    for name in ('checks', 'observations', 'metrics'):
        if not isinstance(value[name], dict) or set(value[name]) != schema[name]:
            raise ValueError('Invalid browser diagnostic fields')
        for item in value[name].values():
            if name == 'metrics':
                if type(item) not in (int, float) or not math.isfinite(item) or not 0 <= item <= 4096:
                    raise ValueError('Invalid browser diagnostic measurement')
            elif type(item) is not bool:
                raise ValueError('Invalid browser diagnostic boolean')
    passed = value['status'] == 'success' and value['category'] == 'complete' and all(value['checks'].values())
    if not passed:
        value['status'] = 'failure'
    return value, passed


PAYMENT_CROPS = {
    'product_afterpay': ('product-afterpay.private.png', 'product-afterpay.png'),
    'cart_payments': ('cart-payments.private.png', 'cart-payments.png'),
    'related_products': ('related-products.private.png', 'related-products.png'),
}
CROP_FLAGS = {'product_afterpay': 'product_payment_crop_saved', 'cart_payments': 'cart_payment_crop_saved',
              'related_products': 'related_products_crop_saved'}


def publish_payment_crops(directory, browser):
    """Re-encode fixed guest-storefront crops; discard all original PNG metadata."""
    observations = {name: {'published': False, 'width': 0, 'height': 0} for name in PAYMENT_CROPS}
    guards = {'fresh_guest_context', 'initial_cart_empty', 'network_barrier_enforced', 'created_guest_cart_cleared'}
    if browser is None or any(browser.get('checks', {}).get(name) is not True for name in guards):
        return observations, True
    valid = True
    for name, (private_name, public_name) in PAYMENT_CROPS.items():
        if browser.get('observations', {}).get(CROP_FLAGS[name]) is not True:
            continue
        source = directory / private_name
        created = False
        destination = None
        try:
            info = source.lstat()
        except FileNotFoundError:
            continue
        try:
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o600 \
                    or not 33 <= info.st_size <= 4194304:
                raise ValueError('Invalid payment crop')
            descriptor = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            with os.fdopen(descriptor, 'rb') as stream:
                opened = os.fstat(stream.fileno())
                if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                    raise ValueError('Payment crop changed')
                from PIL import Image
                with Image.open(stream) as image:
                    if image.format != 'PNG' or getattr(image, 'n_frames', 1) != 1 \
                            or not 16 <= image.width <= 1600 or not 16 <= image.height <= 1600:
                        raise ValueError('Invalid payment crop dimensions')
                    image.load()
                    # A fresh image object prevents ICC/EXIF/text/source metadata from
                    # surviving conversion. No original container bytes are published.
                    normalized = Image.new('RGB', image.size, 'white')
                    if image.mode == 'RGBA':
                        normalized.paste(image, mask=image.getchannel('A'))
                    else:
                        normalized.paste(image.convert('RGB'))
                    destination = directory / 'public' / public_name
                    output = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
                    created = True
                    with os.fdopen(output, 'wb') as exported:
                        normalized.save(exported, format='PNG')
                    if destination.stat().st_size > 4194304:
                        destination.unlink()
                        raise ValueError('Payment crop output exceeds bounds')
                    observations[name] = {'published': True, 'width': normalized.width, 'height': normalized.height,
                                          'sha256': hashlib.sha256(destination.read_bytes()).hexdigest()}
        except Exception:
            if created and destination is not None:
                destination.unlink(missing_ok=True)
            valid = False
    return observations, valid


def write(path, value):
    path = path.absolute()
    if path.parent.resolve(strict=True) != path.parent:
        raise ValueError('Invalid report destination')
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as stream:
        stream.write(json.dumps(value, indent=2) + '\n')


def main(arguments):
    try:
        if len(arguments) == 2 and arguments[0] == 'public':
            value, passed = screen_public(inspect_public())
            write(Path(arguments[1]), value)
        elif len(arguments) == 3 and arguments[0] in {'screen-public', 'screen-native', 'screen-browser'}:
            raw = json.loads(regular(Path(arguments[1])), object_pairs_hook=unique)
            value, passed = {'screen-public': screen_public, 'screen-native': screen_native, 'screen-browser': screen_browser}[arguments[0]](raw)
            write(Path(arguments[2]), value)
        else:
            return 1
        return 0 if passed else 1
    except Exception:
        print('The fixed storefront diagnostic could not be verified.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
