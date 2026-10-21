#!/usr/bin/env python3
"""Consume a previously queued HTML purge with one fixed anonymous PHP GET."""
import importlib.util
from pathlib import Path
import ssl
import sys
from urllib.error import HTTPError, URLError
from urllib.request import HTTPSHandler, Request, build_opener

spec = importlib.util.spec_from_file_location('purge_http', Path(__file__).with_name('verify-account-navigation-assets.py'))
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)

URL = 'https://petish.biz/wp-admin/admin-ajax.php'
CHECKS = {'fixed_anonymous_get_verified', 'native_ajax_response_verified'}


def consume(opener):
    report = {'status': 'failure', 'category': 'http', 'checks': dict.fromkeys(sorted(CHECKS), False)}
    # No action, query, body, cookies or authentication: WordPress returns 0
    # after its normal PHP bootstrap has consumed LiteSpeed's queued header.
    request = Request(URL, method='GET', headers={
        'Accept-Encoding': 'identity', 'Cache-Control': 'no-cache',
        'User-Agent': 'Petish-HTML-cache-verifier/1.0'})
    report['checks']['fixed_anonymous_get_verified'] = True
    try:
        try:
            response = opener.open(request, timeout=15)
        except HTTPError as error:
            # An actionless admin-ajax request normally returns HTTP 400.
            response = error
        with response:
            if response.status != 400 or response.geturl() != URL:
                return report
            if response.headers.get('Content-Encoding', '').strip().lower() not in {'', 'identity'}:
                return report
            body = response.read(65)
            if not 0 < len(body) <= 64 or body.strip() != b'0':
                return report
        report['checks']['native_ajax_response_verified'] = True
        report.update(status='success', category='complete')
    except (HTTPError, URLError, TimeoutError, OSError, ValueError):
        pass
    return report


def main(arguments):
    if len(arguments) != 1:
        return 1
    opener = build_opener(http.NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
    result = consume(opener)
    try:
        http.write_report(Path(arguments[0]), result)
    except (OSError, ValueError):
        return 1
    return 0 if result['status'] == 'success' else 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
