#!/usr/bin/env python3
"""Read public production pages and reviewed assets; never log in or submit data."""
import hashlib
from html.parser import HTMLParser
import importlib.util
import json
import os
from pathlib import Path
import re
import ssl
import sys
from urllib.parse import urljoin, urlsplit
from urllib.request import HTTPSHandler, Request, build_opener

ROOT = Path(__file__).resolve().parents[1]
ORIGIN = 'https://petish.biz'
spec = importlib.util.spec_from_file_location('public_asset_http', ROOT / 'scripts/verify-account-navigation-assets.py')
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
http.MAX_BYTES = 4 * 1024 * 1024
CHECKS = {
    'homepage_http_verified', 'shop_http_verified', 'account_http_verified',
    'product_http_verified', 'product_review_section_verified', 'reviews_below_details_verified',
    'guest_review_controls_verified', 'review_filter_controls_verified',
    'review_stylesheet_bytes_verified', 'review_script_bytes_verified', 'passport_script_bytes_verified',
    'hosted_upload_limits_sufficient', 'targeted_cache_purge_dispatched', 'only_public_gets_used',
}
PROBE_CHECKS = {'targeted_cache_purge_dispatched', 'only_probe_get_used'}


class Markup(HTMLParser):
    """Retain fixed structural facts, never customer text or profile data."""
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.elements = 0
        self.products = []
        self.section = 0
        self.details = 0
        self.filters = set()
        self.login = False
        self.composer = False
        self.private_nonce = False
        self.login_fields = set()

    def handle_starttag(self, tag, attrs):
        self.elements += 1
        if self.elements > 50000:
            raise ValueError('Markup bound')
        attrs = dict(attrs)
        classes = set((attrs.get('class') or '').split())
        if attrs.get('id') == 'petish-customer-reviews':
            self.section = self.elements
        if 'woocommerce-tabs' in classes:
            self.details = self.elements
        if 'data-pcr-form' in attrs:
            self.composer = True
        if tag == 'input' and attrs.get('name') == 'pcr_nonce':
            self.private_nonce = True
        if tag == 'input' and attrs.get('name') in {'username', 'password'}:
            self.login_fields.add(attrs['name'])
        if tag == 'select' and attrs.get('name') in {'pcr_rating', 'pcr_media', 'pcr_sort'}:
            self.filters.add(attrs['name'])
        if tag == 'a':
            target = urlsplit(urljoin(ORIGIN, attrs.get('href') or ''))
            if target.scheme != 'https' or target.netloc != 'petish.biz' or target.username or target.password:
                return
            if target.path == '/wp-login.php':
                self.login = True
            if not target.query and not target.fragment and re.fullmatch(r'/product/[A-Za-z0-9_-]+/', target.path):
                if target.path not in self.products and len(self.products) < 3:
                    self.products.append(target.path)


def verify(opener, run, expected, token, probe_only=False):
    checks = dict.fromkeys(PROBE_CHECKS if probe_only else CHECKS, False)
    report = {'status': 'failure', 'category': 'input', 'checks': checks}
    requests = []

    def fetch(path):
        # Every caller supplies a fixed route, a parsed canonical product path,
        # or an exact asset/run query. Redirects and cookies are never followed.
        url = ORIGIN + path
        headers = {
            'User-Agent': 'Petish-review-release-verifier/1.0',
            'Accept-Encoding': 'identity', 'Cache-Control': 'no-cache',
        }
        if path == '/wp-admin/admin-post.php?action=petish_customer_review_capacity&run=' + run:
            headers['X-Petish-Review-Probe'] = token
        request = Request(url, method='GET', headers=headers)
        requests.append(path)
        with opener.open(request, timeout=20) as response:
            if response.status != 200 or response.geturl() != url:
                raise ValueError('Public HTTP check')
            return http.decoded_body(response), response.headers

    try:
        if not re.fullmatch(r'[1-9][0-9]{0,31}-[1-9][0-9]{0,8}', run) or not isinstance(token, str) or not re.fullmatch(r'[a-f0-9]{64}', token):
            raise ValueError('Run identifier')
        # LiteSpeed processes the targeted purge in an HTTP response. Complete
        # that run-owned request before checking potentially cached storefronts.
        report['category'] = 'cache_probe'
        _, headers = fetch('/wp-admin/admin-post.php?action=petish_customer_review_capacity&run=' + run)
        checks['targeted_cache_purge_dispatched'] = headers.get('X-Petish-Review-Cache-Purge') == 'dispatched'
        if not checks['targeted_cache_purge_dispatched']:
            return report
        if probe_only:
            checks['only_probe_get_used'] = len(requests) == 1
            report.update(status='success', category='complete')
            return report
        checks['hosted_upload_limits_sufficient'] = headers.get('X-Petish-Review-Upload-Limits') == 'sufficient'
        if not checks['hosted_upload_limits_sufficient']:
            report['category'] = 'upload_capacity'
            return report
        report['category'] = 'public_pages'
        for name, path, markers in [
            ('homepage', '/', ['petish']),
            ('shop', '/shop/', ['woocommerce', 'product']),
            ('account', '/my-account/?my-reviews=1', ['woocommerce', 'login']),
        ]:
            body, _ = fetch(path)
            text = body.decode('utf-8')
            lower = text.lower()
            good = all(marker in lower for marker in markers) and 'there has been a critical error' not in lower and 'fatal error:' not in lower
            checks[name + '_http_verified'] = good
            if not good:
                raise ValueError('Public page markup')
            parser = Markup()
            parser.feed(text)
            if name == 'shop':
                products = parser.products
            if name == 'account' and (parser.composer or parser.private_nonce or parser.login_fields != {'username', 'password'}):
                raise ValueError('Guest account boundary')
        report['category'] = 'product'
        for path in products:
            body, _ = fetch(path)
            text = body.decode('utf-8')
            if 'woocommerce' not in text.lower() or 'there has been a critical error' in text.lower() or 'fatal error:' in text.lower():
                raise ValueError('Product HTTP check')
            checks['product_http_verified'] = True
            product = Markup()
            product.feed(text)
            if not product.section:
                continue
            checks['product_review_section_verified'] = True
            checks['reviews_below_details_verified'] = not product.details or product.section > product.details
            checks['guest_review_controls_verified'] = product.login and not product.composer and not product.private_nonce
            checks['review_filter_controls_verified'] = product.filters == {'pcr_rating', 'pcr_media', 'pcr_sort'}
            break
        report['category'] = 'assets'
        for filename, key in [('customer-reviews.css', 'review_stylesheet_bytes_verified'), ('customer-reviews.js', 'review_script_bytes_verified'), ('passport.js', 'passport_script_bytes_verified')]:
            content = expected[filename]
            digest = hashlib.sha256(content).hexdigest()
            body, _ = fetch('/wp-content/plugins/petish-passport/assets/' + filename + '?ver=' + digest)
            checks[key] = hashlib.sha256(body).hexdigest() == digest
        checks['only_public_gets_used'] = 8 <= len(requests) <= 10
        if all(checks.values()):
            report.update(status='success', category='complete')
        elif not checks['product_review_section_verified']:
            report['category'] = 'product'
        elif not checks['review_stylesheet_bytes_verified'] or not checks['review_script_bytes_verified']:
            report['category'] = 'assets'
    except Exception:
        # No exception text, arbitrary response data or URL enters the report.
        pass
    return report


def private_token(path):
    path = path.absolute()
    info = path.lstat()
    if path.parent.resolve(strict=True) != path.parent or (info.st_mode & 0o7777) != 0o600 or info.st_size != 65:
        raise ValueError('Private probe token')
    content = http.source_bytes(path)
    if not re.fullmatch(rb'[a-f0-9]{64}\n', content):
        raise ValueError('Private probe token')
    return content[:-1].decode('ascii')


def main(arguments):
    if len(arguments) not in {3, 4} or (len(arguments) == 4 and arguments[3] != 'rollback-purge'):
        return 1
    os.umask(0o077)
    probe_only = len(arguments) == 4
    report = {'status': 'failure', 'category': 'input', 'checks': dict.fromkeys(PROBE_CHECKS if probe_only else CHECKS, False)}
    try:
        directory = ROOT / 'wordpress/wp-content/plugins/petish-passport/assets'
        expected = {} if probe_only else {name: http.source_bytes(directory / name) for name in ['customer-reviews.css', 'customer-reviews.js', 'passport.js']}
        opener = build_opener(http.NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        report = verify(opener, arguments[1], expected, private_token(Path(arguments[2])), probe_only)
    except Exception:
        pass
    try:
        http.write_report(Path(arguments[0]), report)
    except (OSError, ValueError):
        return 1
    return 0 if report['status'] == 'success' else 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
