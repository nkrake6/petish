#!/usr/bin/env python3
"""Finite production cancellation receipts and fixed-origin read-only health probes."""
from html.parser import HTMLParser
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import sys
import ssl
from urllib.request import HTTPSHandler, Request, build_opener

ROOT = Path(__file__).resolve().parents[1]
DEPLOY = ROOT / 'scripts/deploy-cancellation-production.php'
DEPLOY_PHASES = {'inspect', 'apply', 'faq', 'verify', 'resume', 'commit', 'rollback', 'rollback-finalize'}
HEALTH_REQUIRED = {
    'homepage_http_verified', 'shop_http_verified', 'product_http_verified',
    'cart_http_verified', 'account_http_verified', 'approved_logo_http_verified',
}
HEALTH_OBSERVATIONS = {
    'logo_http_fetch_succeeded', 'logo_http_is_png', 'logo_http_is_webp',
    'logo_http_forbidden', 'logo_http_not_found', 'logo_http_server_error',
    'logo_http_file_bytes_match', 'logo_http_dimensions_match', 'logo_http_pixels_match',
}
FAQ_HEALTH = {'delivery_page_http_verified', 'cancellation_faq_http_verified', 'cancellation_policy_http_verified',
              'review_stylesheet_http_verified','cancellation_stylesheets_http_verified'}
PURGE_CHECKS = {'targeted_cache_purge_dispatched', 'only_probe_get_used'}
PHASES = DEPLOY_PHASES | {'health', 'rollback-health', 'purge', 'rollback-purge'}
PATH = re.compile(r'plugins/(?:petish-fulfilment|petish-passport)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:php|css|js|md)\Z')


def constant_body(path, name):
    match = re.search(r'const\s+' + re.escape(name) + r'\s*=\s*\[(.*?)\];', path.read_text(), re.S)
    if not match:
        raise ValueError('Missing fixed release schema')
    return match.group(1)


def names(body):
    values = re.findall(r"'([a-z][a-z0-9_]{0,79})'", body)
    if not values or len(values) != len(set(values)) or re.sub(r"'[a-z][a-z0-9_]{0,79}'|[\s,]", '', body):
        raise ValueError('Invalid fixed schema')
    return set(values)


def release_files():
    body = constant_body(DEPLOY, 'PETISH_CANCELLATION_RELEASE_FILES')
    pattern = r"'([A-Za-z0-9_./-]+)'\s*=>\s*'([a-f0-9]{64})'"
    pairs = re.findall(pattern, body)
    files = dict(pairs)
    if len(pairs) != 27 or len(files) != 27 or re.sub(pattern + r'|[\s,]', '', body):
        raise ValueError('Invalid fixed file scope')
    if any(not PATH.fullmatch(path) or any(part in {'.', '..'} for part in path.split('/')) for path in files):
        raise ValueError('Invalid application path')
    if sum(path.startswith('plugins/petish-fulfilment/') for path in files) != 24 \
            or sum(path.startswith('plugins/petish-passport/') for path in files) != 3:
        raise ValueError('Invalid component scope')
    return dict(sorted(files.items()))


def deploy_schema():
    allowed = names(constant_body(DEPLOY, 'PETISH_CANCELLATION_RELEASE_CHECKS'))
    categories = names(constant_body(DEPLOY, 'PETISH_CANCELLATION_RELEASE_CATEGORIES'))
    body = constant_body(DEPLOY, 'PETISH_CANCELLATION_RELEASE_REQUIRED')
    pattern = r"'([a-z][a-z_-]*)'\s*=>\s*\[([^\[\]]*)\]"
    pairs = re.findall(pattern, body)
    required = {phase: names(value) for phase, value in pairs}
    if len(pairs) != len(required) or set(required) != DEPLOY_PHASES or re.sub(pattern + r'|[\s,]', '', body) \
            or any(value - allowed for value in required.values()):
        raise ValueError('Invalid release phase schema')
    return allowed, required, categories, len(release_files())


def unique_object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('Duplicate report field')
        value[key] = item
    return value


def regular(path, maximum=65536):
    before = path.lstat()
    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or not 0 < before.st_size <= maximum:
        raise ValueError('Invalid report file')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        data = stream.read(maximum + 1)
    if (before.st_dev, before.st_ino, before.st_size) != (opened.st_dev, opened.st_ino, len(data)):
        raise ValueError('Report changed during reading')
    return data


def sanitize(phase, source):
    if phase not in PHASES:
        raise ValueError('Unknown release phase')
    value = json.loads(regular(source), object_pairs_hook=unique_object)
    fields = {'status', 'category', 'checks'} | ({'changed_file_count'} if phase in DEPLOY_PHASES else set())
    if not isinstance(value, dict) or set(value) != fields or value['status'] not in {'success', 'failure'} or not isinstance(value['checks'], dict):
        raise ValueError('Invalid report shape')
    if phase in DEPLOY_PHASES:
        allowed, required_by_phase, categories, count = deploy_schema()
        required = required_by_phase[phase]
        if type(value['changed_file_count']) is not int or not 0 <= value['changed_file_count'] <= count:
            raise ValueError('Invalid changed-file count')
    elif phase in {'purge', 'rollback-purge'}:
        allowed = required = PURGE_CHECKS
        categories = {'input', 'cache_probe', 'complete'}
    else:
        allowed = HEALTH_REQUIRED | HEALTH_OBSERVATIONS | (FAQ_HEALTH if phase == 'health' else set())
        required = HEALTH_REQUIRED | (FAQ_HEALTH if phase == 'health' else set())
        categories = {'input', 'http', 'faq', 'complete'}
    checks = value['checks']
    if value['category'] not in categories or set(checks) - allowed or any(type(flag) is not bool for flag in checks.values()):
        raise ValueError('Non-finite release report')
    if value['status'] == 'success' and (value['category'] != 'complete' or any(checks.get(key) is not True for key in required)):
        raise ValueError('Incomplete release proof')
    return value


def module(filename, name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'scripts' / filename)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class PlainText(HTMLParser):
    def __init__(self):
        super().__init__()
        self.parts = []

    def handle_data(self, value):
        self.parts.append(value)


def probe_health(faq=True):
    """Only fixed public storefront GETs; no login, query, supplier or payment call."""
    checks = {}
    category = 'http'
    try:
        health = module('verify-live-email-release.py', 'cancellation_public_health')
        health.verify(checks)
        if faq:
            category = 'faq'
            html = health.fetch('/delivery-shipping/').decode('utf-8')
            lower = html.lower()
            checks['delivery_page_http_verified'] = 'fatal error:' not in lower and 'there has been a critical error' not in lower and 'delivery' in lower
            checks['cancellation_faq_http_verified'] = 'petish-faqs' in html and 'id="change-of-mind"' in html and '[petish_faqs]' not in html
            parser = PlainText(); parser.feed(html)
            text = ' '.join(' '.join(parser.parts).lower().split())
            checks['cancellation_policy_http_verified'] = all(part in text for part in (
                '14 days after delivery', 'unused, complete and in their original packaging',
                'change-of-mind return postage', 'payment-processing costs',
                'confirm the refund amount before processing', 'australian consumer law',
            ))
            if not all(checks[name] is True for name in ('delivery_page_http_verified','cancellation_faq_http_verified','cancellation_policy_http_verified')):
                raise ValueError('Public FAQ check failed')
            import hashlib
            files = release_files()
            expected = files['plugins/petish-passport/assets/customer-reviews.css']
            stylesheet = health.fetch('/wp-content/plugins/petish-passport/assets/customer-reviews.css?ver=' + expected)
            checks['review_stylesheet_http_verified'] = hashlib.sha256(stylesheet).hexdigest() == expected
            css_verified = True
            for name in ('cancellation-faq.css','order-cancellation.css','shipment-tracking.css'):
                path = 'plugins/petish-fulfilment/assets/' + name
                content = health.fetch('/wp-content/' + path + '?ver=' + files[path])
                css_verified = css_verified and hashlib.sha256(content).hexdigest() == files[path]
            checks['cancellation_stylesheets_http_verified'] = css_verified
            if not checks['review_stylesheet_http_verified']:
                raise ValueError('Reviewed stylesheet changed')
            if not css_verified:
                raise ValueError('Cancellation stylesheets changed')
        return {'status': 'success', 'category': 'complete', 'checks': checks}
    except Exception:
        return {'status': 'failure', 'category': category, 'checks': checks}


def probe_purge(run, token_path, opener=None):
    checks = dict.fromkeys(PURGE_CHECKS, False)
    result = {'status': 'failure', 'category': 'input', 'checks': checks}
    try:
        if not re.fullmatch(r'[1-9][0-9]{0,31}-[1-9][0-9]{0,8}', run):
            raise ValueError('Invalid run')
        token_path = Path(token_path).absolute()
        if token_path.parent.resolve(strict=True) != token_path.parent or stat.S_IMODE(token_path.lstat().st_mode) != 0o600:
            raise ValueError('Invalid private token')
        token = regular(token_path, 65)
        if not re.fullmatch(rb'[a-f0-9]{64}\n', token):
            raise ValueError('Invalid token shape')
        http = module('verify-account-navigation-assets.py', 'cancellation_purge_http')
        if opener is None:
            opener = build_opener(http.NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        url = 'https://petish.biz/wp-admin/admin-post.php?action=petish_cancellation_release&run=' + run
        request = Request(url, method='GET', headers={'User-Agent':'Petish-cancellation-release-verifier/1.0',
            'Accept-Encoding':'identity','Cache-Control':'no-cache','X-Petish-Release':token[:-1].decode('ascii')})
        result['category'] = 'cache_probe'
        with opener.open(request, timeout=25) as response:
            if response.status != 200 or response.geturl() != url or len(response.read(1025)) > 1024:
                raise ValueError('Invalid purge response')
            checks['targeted_cache_purge_dispatched'] = response.headers.get('X-Petish-Cancellation-Cache-Purge') == 'dispatched'
        checks['only_probe_get_used'] = True
        if all(checks.values()): result.update(status='success', category='complete')
    except Exception:
        pass
    return result


def write_new(path, value):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as stream:
        stream.write(json.dumps(value, indent=2, sort_keys=True) + '\n')


if __name__ == '__main__':
    os.umask(0o077)
    try:
        if len(sys.argv) == 3 and sys.argv[1] in {'probe-health', 'probe-rollback-health'}:
            result = probe_health(sys.argv[1] == 'probe-health')
            write_new(sys.argv[2], result)
        elif len(sys.argv) == 5 and sys.argv[1] in {'probe-purge', 'probe-rollback-purge'}:
            result = probe_purge(sys.argv[2], Path(sys.argv[3]))
            write_new(sys.argv[4], result)
        elif len(sys.argv) == 4:
            result = sanitize(sys.argv[1], Path(sys.argv[2]))
            write_new(sys.argv[3], result)
        else:
            raise ValueError('Invalid arguments')
    except (ValueError, TypeError, OSError, KeyError):
        raise SystemExit('CANCELLATION_PRODUCTION_REPORT_REJECTED')
    raise SystemExit(0 if result['status'] == 'success' else 1)
