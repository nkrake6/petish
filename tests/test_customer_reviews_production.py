"""Finite release reports and anonymous public GET boundaries."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


def module(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / (name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


reports = module('sanitize-customer-reviews-production')
public = module('verify-customer-reviews-public')


class ReleaseReportTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / 'report.json'

    def save(self, value):
        self.path.write_text(json.dumps(value))
        return self.path

    def phase(self, phase):
        allowed, required, _, count = reports.deploy_schema()
        return {'status': 'success', 'category': 'complete',
                'checks': {name: name in required[phase] for name in allowed}, 'changed_file_count': count}

    def test_each_deploy_phase_requires_its_own_complete_proof(self):
        for phase in sorted(reports.DEPLOY_PHASES):
            value = self.phase(phase)
            with self.subTest(phase=phase):
                self.assertEqual(reports.sanitize(phase, self.save(value)), value)
                required = reports.deploy_schema()[1][phase]
                value['checks'][next(iter(required))] = False
                with self.assertRaises(ValueError):
                    reports.sanitize(phase, self.save(value))

    def test_unknown_and_non_boolean_checks_are_rejected(self):
        for bad in [1, 'true', None, {'private': 'value'}]:
            value = self.phase('enable')
            value['checks']['flag_enabled'] = bad
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                reports.sanitize('enable', self.save(value))
        value = self.phase('enable')
        value['checks']['private-customer-name'] = True
        with self.assertRaises(ValueError):
            reports.sanitize('enable', self.save(value))

    def test_counts_and_categories_are_finite(self):
        for count in [True, -1, reports.deploy_schema()[3] + 1, '11']:
            value = self.phase('apply')
            value['changed_file_count'] = count
            with self.subTest(count=count), self.assertRaises(ValueError):
                reports.sanitize('apply', self.save(value))
        value = self.phase('apply')
        value['category'] = '/private/server/path'
        with self.assertRaises(ValueError):
            reports.sanitize('apply', self.save(value))

    def test_private_fields_are_discarded_and_duplicates_rejected(self):
        value = self.phase('inspect')
        value['credentials'] = 'must never publish'
        self.assertNotIn('credentials', reports.sanitize('inspect', self.save(value)))
        self.path.write_text('{"status":"success","status":"failure","checks":{}}')
        with self.assertRaises(ValueError):
            reports.sanitize('inspect', self.path)

    def test_linked_and_incomplete_reports_cannot_pass(self):
        value = self.phase('inspect')
        self.save(value)
        link = self.path.with_name('link.json')
        link.symlink_to(self.path)
        with self.assertRaises(ValueError):
            reports.sanitize('inspect', link)
        link.unlink()
        link.hardlink_to(self.path)
        with self.assertRaises(ValueError):
            reports.sanitize('inspect', self.path)
        link.unlink()
        value['checks'].pop(next(iter(value['checks'])))
        with self.assertRaises(ValueError):
            reports.sanitize('inspect', self.save(value))

    def test_native_and_public_proofs_require_every_check(self):
        for phase, checks in [('verify', reports.names(reports.constant_body(reports.VERIFY, 'PETISH_CUSTOMER_REVIEWS_VERIFY_CHECKS'))), ('public', public.CHECKS), ('rollback-purge', public.PROBE_CHECKS)]:
            value = {'status': 'success', 'category': 'complete', 'checks': dict.fromkeys(checks, True)}
            with self.subTest(phase=phase):
                self.assertEqual(reports.sanitize(phase, self.save(value)), value)
                value['checks'][next(iter(checks))] = False
                with self.assertRaises(ValueError):
                    reports.sanitize(phase, self.save(value))


class Response(io.BytesIO):
    status = 200

    def __init__(self, url, content, headers=None):
        super().__init__(content)
        self.url = url
        self.headers = headers or {}

    def geturl(self):
        return self.url


class Opener:
    def __init__(self, pages):
        self.pages = pages
        self.requests = []

    def open(self, request, timeout):
        if request.get_method() != 'GET' or request.has_header('Cookie') or request.has_header('Authorization'):
            raise AssertionError('Production mutation or authentication')
        token = request.get_header('X-petish-review-probe')
        if 'action=petish_customer_review_capacity' in request.full_url:
            if token != 'b' * 64:
                raise AssertionError('Missing private probe authorization')
        elif token is not None:
            raise AssertionError('Probe token escaped its exact route')
        self.requests.append(request.full_url)
        content, headers = self.pages[request.full_url]
        return Response(request.full_url, content, headers)


class PublicReviewTests(unittest.TestCase):
    def setUp(self):
        self.run = '12345-1'
        self.token = 'b' * 64
        self.expected = {'customer-reviews.css': b'body{color:green}', 'customer-reviews.js': b'void 0;', 'passport.js': b'void 1;'}
        self.product = public.ORIGIN + '/product/owned/'
        self.pages = {
            public.ORIGIN + '/': (b'<html>Petish</html>', {}),
            public.ORIGIN + '/shop/': (b'<html class="woocommerce"><a href="/product/owned/">Product</a></html>', {}),
            public.ORIGIN + '/my-account/?my-reviews=1': (b'<html class="woocommerce">Login<input name="username"><input name="password"></html>', {}),
            self.product: (b'<div class="woocommerce-tabs"></div><section id="petish-customer-reviews"><a href="/wp-login.php">Sign in</a><select name="pcr_rating"></select><select name="pcr_media"></select><select name="pcr_sort"></select></section>', {}),
            public.ORIGIN + '/wp-admin/admin-post.php?action=petish_customer_review_capacity&run=' + self.run: (b'Finite capacity probe.', {'X-Petish-Review-Upload-Limits': 'sufficient', 'X-Petish-Review-Cache-Purge': 'dispatched'}),
        }
        for name, content in self.expected.items():
            path = public.ORIGIN + '/wp-content/plugins/petish-passport/assets/' + name + '?ver=' + hashlib.sha256(content).hexdigest()
            self.pages[path] = (content, {})

    def test_complete_public_check_uses_only_exact_anonymous_gets(self):
        opener = Opener(self.pages)
        value = public.verify(opener, self.run, self.expected, self.token)
        self.assertEqual(value['status'], 'success')
        self.assertEqual(set(value['checks']), public.CHECKS)
        self.assertTrue(all(value['checks'].values()))
        self.assertEqual(len(opener.requests), 8)
        self.assertIn('action=petish_customer_review_capacity', opener.requests[0])
        self.assertNotIn('product/owned', json.dumps(value))

    def test_untrusted_shop_links_are_never_requested(self):
        body = b'<html class="woocommerce">Product<a href="https://outside.invalid/product/x/">x</a><a href="/wp-admin/">x</a><a href="/product/x/?add-to-cart=1">x</a><a href="/product/%2e%2e/">x</a></html>'
        self.pages[public.ORIGIN + '/shop/'] = (body, {})
        opener = Opener(self.pages)
        value = public.verify(opener, self.run, self.expected, self.token)
        self.assertEqual(value['status'], 'failure')
        self.assertTrue(all(url in self.pages for url in opener.requests))
        self.assertFalse(any('/product/' in url for url in opener.requests))

    def test_guest_nonce_or_composer_exposure_fails(self):
        for markup in [b'<input name="pcr_nonce" value="private">', b'<form data-pcr-form></form>']:
            with self.subTest(markup=markup):
                original = self.pages[self.product]
                self.pages[self.product] = (original[0] + markup, {})
                value = public.verify(Opener(self.pages), self.run, self.expected, self.token)
                self.assertEqual(value['status'], 'failure')
                self.assertFalse(value['checks']['guest_review_controls_verified'])
                self.pages[self.product] = original

    def test_changed_assets_and_insufficient_web_limits_fail(self):
        asset = next(url for url in self.pages if 'customer-reviews.css?' in url)
        self.pages[asset] = (b'changed source', {})
        value = public.verify(Opener(self.pages), self.run, self.expected, self.token)
        self.assertFalse(value['checks']['review_stylesheet_bytes_verified'])
        self.assertEqual(value['status'], 'failure')
        self.pages[asset] = (self.expected['customer-reviews.css'], {})
        capacity = next(url for url in self.pages if 'action=petish_customer_review_capacity' in url)
        self.pages[capacity] = (b'Finite capacity probe.', {'X-Petish-Review-Upload-Limits': 'insufficient', 'X-Petish-Review-Cache-Purge': 'dispatched'})
        value = public.verify(Opener(self.pages), self.run, self.expected, self.token)
        self.assertFalse(value['checks']['hosted_upload_limits_sufficient'])
        self.assertEqual(value['status'], 'failure')

    def test_rollback_purge_requests_only_probe_and_does_not_require_capacity(self):
        capacity = next(url for url in self.pages if 'action=petish_customer_review_capacity' in url)
        self.pages[capacity] = (b'Finite capacity probe.', {'X-Petish-Review-Upload-Limits': 'insufficient', 'X-Petish-Review-Cache-Purge': 'dispatched'})
        opener = Opener(self.pages)
        value = public.verify(opener, self.run, {}, self.token, probe_only=True)
        self.assertEqual(value['status'], 'success')
        self.assertEqual(set(value['checks']), public.PROBE_CHECKS)
        self.assertEqual(opener.requests, [capacity])

    def test_cache_purge_is_required_before_any_storefront_requests(self):
        capacity = next(url for url in self.pages if 'action=petish_customer_review_capacity' in url)
        self.pages[capacity] = (b'Finite capacity probe.', {'X-Petish-Review-Upload-Limits': 'sufficient'})
        opener = Opener(self.pages)
        value = public.verify(opener, self.run, self.expected, self.token)
        self.assertEqual(value['status'], 'failure')
        self.assertFalse(value['checks']['targeted_cache_purge_dispatched'])
        self.assertEqual(opener.requests, [capacity])

    def test_invalid_run_makes_no_http_requests(self):
        opener = Opener(self.pages)
        value = public.verify(opener, '1&action=delete', self.expected, self.token)
        self.assertEqual(value['status'], 'failure')
        self.assertEqual(opener.requests, [])

    def test_missing_probe_token_makes_no_http_requests(self):
        opener = Opener(self.pages)
        value = public.verify(opener, self.run, self.expected, None)
        self.assertEqual(value['status'], 'failure')
        self.assertEqual(opener.requests, [])

    def test_private_probe_file_permissions_and_links_are_checked(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'probe-token'
            path.write_text(self.token + '\n')
            path.chmod(0o600)
            self.assertEqual(public.private_token(path), self.token)
            path.chmod(0o644)
            with self.assertRaises(ValueError):
                public.private_token(path)
            path.chmod(0o600)
            linked = path.with_name('linked-token')
            linked.symlink_to(path)
            with self.assertRaises(ValueError):
                public.private_token(linked)
            linked.unlink()
            linked.hardlink_to(path)
            with self.assertRaises(ValueError):
                public.private_token(path)


if __name__ == '__main__':
    unittest.main()
