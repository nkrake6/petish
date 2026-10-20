#!/usr/bin/env python3
"""Read public store pages and the approved logo; never log in or submit data."""
import hashlib
import io
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import re
import sys
from urllib.parse import urljoin, urlsplit
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from PIL import Image

ORIGIN = 'https://petish.biz'
LOGO = '/wp-content/themes/dt-the7-child/assets/brand/repaired/combined-light-v2.png'
LOGO_SHA = '45b8ab50ef5fd0b4bc98fb7994d3a4583d0d013db1b253bf7f870496cd5cf615'
LOGO_PIXELS_SHA = '8c4c667651043e9920ddddf218b09071db0be642b66ced741e9d5080c1ae0912'


class LocalRedirects(HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        target = urlsplit(newurl)
        if target.scheme != 'https' or target.netloc != 'petish.biz' or target.query or target.fragment:
            raise ValueError()
        return super().redirect_request(request, response, code, message, headers, newurl)


def fetch(path):
    request = Request(ORIGIN + path, headers={'User-Agent':'Petish-release-verification/1.0','Cache-Control':'no-cache'})
    with build_opener(LocalRedirects()).open(request, timeout=25) as response:
        if response.status != 200 or urlsplit(response.url).netloc != 'petish.biz':
            raise ValueError()
        data = response.read(4194305)
    if not data or len(data) > 4194304:
        raise ValueError()
    return data


class ProductLinks(HTMLParser):
    def __init__(self):
        super().__init__()
        self.paths = []

    def handle_starttag(self, tag, attrs):
        if tag != 'a':
            return
        href = dict(attrs).get('href', '')
        target = urlsplit(urljoin(ORIGIN, href))
        if target.scheme == 'https' and target.netloc == 'petish.biz' and not target.query and not target.fragment \
                and re.fullmatch(r'/product/[A-Za-z0-9_%.-]+/', target.path):
            self.paths.append(target.path)


def verify(checks):
    for name, path, markers in (
        ('homepage','/',['petish']),('shop','/shop/',['woocommerce','product']),
        ('cart','/cart/',['woocommerce','cart']),('account','/my-account/',['woocommerce','login'])):
        checks[name + '_http_verified'] = False
        html = fetch(path).decode('utf-8').lower()
        checks[name + '_http_verified'] = all(marker in html for marker in markers) \
            and 'there has been a critical error' not in html and 'fatal error:' not in html
        if not checks[name + '_http_verified']:
            raise ValueError()
        if name == 'shop':
            parser = ProductLinks(); parser.feed(html)
            if not parser.paths:
                raise ValueError()
            checks['product_http_verified'] = False
            product = fetch(parser.paths[0]).decode('utf-8').lower()
            checks['product_http_verified'] = 'woocommerce' in product and 'product' in product \
                and 'there has been a critical error' not in product and 'fatal error:' not in product
            if not checks['product_http_verified']:
                raise ValueError()
    checks['approved_logo_http_verified'] = False
    checks['logo_http_fetch_succeeded'] = False
    try:
        logo = fetch(LOGO)
        checks['logo_http_fetch_succeeded'] = True
    except HTTPError as error:
        checks['logo_http_forbidden'] = error.code == 403
        checks['logo_http_not_found'] = error.code == 404
        checks['logo_http_server_error'] = 500 <= error.code <= 599
        raise
    checks['logo_http_is_png'] = logo.startswith(b'\x89PNG\r\n\x1a\n')
    checks['logo_http_is_webp'] = logo.startswith(b'RIFF') and logo[8:12] == b'WEBP'
    checks['logo_http_file_bytes_match'] = hashlib.sha256(logo).hexdigest() == LOGO_SHA
    checks['logo_http_dimensions_match'] = False
    checks['logo_http_pixels_match'] = False
    if checks['logo_http_is_png']:
        with Image.open(io.BytesIO(logo)) as image:
            checks['logo_http_dimensions_match'] = image.size == (1536,1024)
            if checks['logo_http_dimensions_match']:
                checks['logo_http_pixels_match'] = hashlib.sha256(image.convert('RGBA').tobytes()).hexdigest() == LOGO_PIXELS_SHA
    # A CDN may losslessly recompress a PNG without changing its appearance.
    # Match every decoded pixel, including alpha, rather than compression bytes.
    checks['approved_logo_http_verified'] = checks['logo_http_is_png'] and checks['logo_http_dimensions_match'] and checks['logo_http_pixels_match']
    if not checks['approved_logo_http_verified']:
        raise ValueError()
    return {'status':'success','category':'complete','checks':checks}


if __name__ == '__main__':
    os.umask(0o077)
    if len(sys.argv) != 2:
        sys.exit(1)
    checks = {}
    try:
        result = verify(checks)
    except Exception:
        result = {'status':'failure','category':'http','checks':checks}
    Path(sys.argv[1]).write_text(json.dumps(result, indent=2) + '\n')
    sys.exit(0 if result['status'] == 'success' else 1)
