#!/usr/bin/env python3
"""Verify only the two public account-navigation assets against reviewed bytes."""
import hashlib
import json
import os
from pathlib import Path
import ssl
import stat
import sys
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, HTTPSHandler, Request, build_opener
import zlib


MAX_BYTES = 1048576
ROOT = Path(__file__).resolve().parents[1]
ASSETS = {
    'navigation_script_http_verified': 'account-navigation.js',
    'navigation_stylesheet_http_verified': 'passport.css',
}


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, new_url):
        # Both resources have canonical public URLs. A redirect is unnecessary
        # and must never turn this fixed asset check into another request.
        return None


def source_bytes(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= MAX_BYTES:
        raise ValueError()
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
            raise ValueError()
        body = stream.read(MAX_BYTES + 1)
    if not 0 < len(body) <= MAX_BYTES:
        raise ValueError()
    return body


def decoded_body(response):
    body = response.read(MAX_BYTES + 1)
    if not 0 < len(body) <= MAX_BYTES:
        raise ValueError()
    encoding = response.headers.get('Content-Encoding', '').strip().lower()
    if encoding in {'', 'identity'}:
        return body
    if encoding not in {'gzip', 'deflate'}:
        raise ValueError()
    decoder = zlib.decompressobj(16 + zlib.MAX_WBITS if encoding == 'gzip' else zlib.MAX_WBITS)
    decoded = decoder.decompress(body, MAX_BYTES + 1)
    if not 0 < len(decoded) <= MAX_BYTES or decoder.unconsumed_tail or decoder.unused_data or not decoder.eof:
        raise ValueError()
    return decoded


def verify(opener, expected):
    report = {'status': 'failure', 'category': 'input',
              'checks': {name: False for name in ASSETS}}
    categories = []
    for check, filename in ASSETS.items():
        body = expected[filename]
        digest = hashlib.sha256(body).hexdigest()
        url = 'https://petish.biz/wp-content/plugins/petish-passport/assets/' + filename + '?ver=' + digest
        request = Request(url, headers={'Accept-Encoding': 'identity', 'User-Agent': 'Petish-release-verifier/1.0'})
        try:
            with opener.open(request, timeout=15) as response:
                if response.status != 200 or response.geturl() != url:
                    categories.append('http')
                    continue
                fetched = decoded_body(response)
                if hashlib.sha256(fetched).digest() != hashlib.sha256(body).digest():
                    categories.append('assets')
                    continue
            report['checks'][check] = True
        except (HTTPError, URLError, TimeoutError, OSError):
            categories.append('http')
        except (ValueError, zlib.error):
            categories.append('assets')
    if all(report['checks'].values()):
        report.update(status='success', category='complete')
    else:
        report['category'] = 'http' if 'http' in categories else 'assets'
    return report


def write_report(path, report):
    path = path.absolute()
    if path.parent.resolve(strict=True) != path.parent:
        raise ValueError()
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w', encoding='utf-8') as stream:
        stream.write(json.dumps(report, indent=2) + '\n')


def main(arguments):
    if len(arguments) != 1:
        return 1
    report = {'status': 'failure', 'category': 'input',
              'checks': {name: False for name in ASSETS}}
    try:
        directory = ROOT / 'wordpress/wp-content/plugins/petish-passport/assets'
        expected = {filename: source_bytes(directory / filename) for filename in ASSETS.values()}
        opener = build_opener(NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        report = verify(opener, expected)
    except (OSError, ValueError):
        pass
    try:
        write_report(Path(arguments[0]), report)
    except (OSError, ValueError):
        return 1
    return 0 if report['status'] == 'success' else 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
