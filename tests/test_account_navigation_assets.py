"""Public asset verification has no authenticated or state-changing requests."""
import gzip
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from urllib.error import URLError


SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/verify-account-navigation-assets.py'
SPEC = importlib.util.spec_from_file_location('account_navigation_assets', SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class Response(io.BytesIO):
    status = 200

    def __init__(self, body, url, encoding=''):
        super().__init__(body)
        self.url = url
        self.headers = {'Content-Encoding': encoding}

    def geturl(self):
        return self.url


class AccountNavigationAssetTests(unittest.TestCase):
    def test_fixed_gets_with_sha_cache_bust_verify_plain_and_compressed_bytes(self):
        expected = {'account-navigation.js': b'safe script', 'passport.css': b'safe style'}
        requests = []

        class Opener:
            def open(self, request, timeout):
                requests.append((request, timeout))
                filename = request.full_url.split('/')[-1].split('?')[0]
                if filename.endswith('.css'):
                    return Response(gzip.compress(expected[filename]), request.full_url, 'gzip')
                return Response(expected[filename], request.full_url)

        result = MODULE.verify(Opener(), expected)
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['category'], 'complete')
        self.assertTrue(all(result['checks'].values()))
        self.assertEqual(len(requests), 2)
        for request, timeout in requests:
            filename = request.full_url.split('/')[-1].split('?')[0]
            self.assertEqual(request.full_url, 'https://petish.biz/wp-content/plugins/petish-passport/assets/'
                             + filename + '?ver=' + hashlib.sha256(expected[filename]).hexdigest())
            self.assertEqual(request.get_method(), 'GET')
            self.assertNotIn('Authorization', request.headers)
            self.assertEqual(request.headers['Accept-encoding'], 'identity')
            self.assertEqual(timeout, 15)
        self.assertIsNone(MODULE.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://other.invalid/'))

    def test_http_failures_and_changed_assets_publish_only_finite_booleans(self):
        class Opener:
            def open(self, request, timeout):
                if request.full_url.endswith('.js?ver=' + hashlib.sha256(b'expected').hexdigest()):
                    raise URLError('PRIVATE_NETWORK_DETAIL')
                return Response(b'changed asset', request.full_url)

        result = MODULE.verify(Opener(), {filename: b'expected' for filename in MODULE.ASSETS.values()})
        self.assertEqual(result, {'status': 'failure', 'category': 'http',
                                 'checks': {check: False for check in MODULE.ASSETS}})
        self.assertNotIn('PRIVATE', json.dumps(result))

    def test_compression_and_response_bounds_refuse_large_or_foreign_content(self):
        for body, encoding in [(b'x' * (MODULE.MAX_BYTES + 1), ''),
                               (gzip.compress(b'x' * (MODULE.MAX_BYTES + 1)), 'gzip'),
                               (b'content', 'br'), (b'invalid compressed body', 'gzip')]:
            with self.subTest(encoding=encoding, size=len(body)):
                with self.assertRaises((ValueError, MODULE.zlib.error)):
                    MODULE.decoded_body(Response(body, 'https://petish.biz/', encoding))

    def test_output_is_private_and_cannot_overwrite_or_follow_links(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output = root / 'result.json'
            report = {'status': 'success', 'category': 'complete', 'checks': {}}
            MODULE.write_report(output, report)
            self.assertEqual(output.stat().st_mode & 0o777, 0o600)
            self.assertEqual(json.loads(output.read_text()), report)
            with self.assertRaises(FileExistsError):
                MODULE.write_report(output, report)
            link = root / 'linked.json'
            link.symlink_to(output)
            with self.assertRaises(FileExistsError):
                MODULE.write_report(link, report)


if __name__ == '__main__':
    unittest.main()
