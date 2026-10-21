"""Fixed anonymous queue-consumer HTTP checks; no live requests."""
import importlib.util
import io
import json
from pathlib import Path
import tempfile
from urllib.error import HTTPError, URLError
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('purge_consumer', ROOT/'scripts/consume-litespeed-html-purge.py')
consumer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(consumer)
safe_spec = importlib.util.spec_from_file_location('purge_safe', ROOT/'scripts/sanitize-litespeed-html-purge.py')
safe = importlib.util.module_from_spec(safe_spec)
safe_spec.loader.exec_module(safe)


class PurgeConsumerTests(unittest.TestCase):
    def opener(self, status=400, body=b'0', url=consumer.URL, encoding='', raised=True):
        owner = self
        class Response(io.BytesIO):
            def geturl(self): return url
        class Opener:
            calls = 0
            def open(self, request, timeout):
                self.calls += 1
                owner.assertEqual(request.full_url, consumer.URL)
                owner.assertEqual(request.get_method(), 'GET')
                owner.assertIsNone(request.data)
                owner.assertFalse(any(key.lower() in {'cookie', 'authorization'} for key in request.headers))
                owner.assertEqual(timeout, 15)
                response = Response(body)
                response.status = status
                response.headers = {'Content-Encoding': encoding}
                if raised:
                    raise HTTPError(url, status, 'private sentinel', response.headers, response)
                return response
        return Opener()

    def test_native_actionless_response_and_exact_single_anonymous_request(self):
        for raised in (False, True):
            opener = self.opener(raised=raised)
            result = consumer.consume(opener)
            self.assertEqual(result['status'], 'success')
            self.assertTrue(all(result['checks'].values()))
            self.assertEqual(opener.calls, 1)

    def test_login_redirect_html_wrong_status_compression_and_oversize_refuse(self):
        for parameters in ({'status': 200}, {'status': 302}, {'status': 403},
                           {'url': 'https://petish.biz/wp-login.php'}, {'body': b'<html>private sentinel</html>'},
                           {'body': b'0'*65}, {'body': b''}, {'encoding': 'gzip'}):
            with self.subTest(parameters=parameters):
                opener = self.opener(**parameters)
                result = consumer.consume(opener)
                self.assertEqual(result['status'], 'failure')
                self.assertNotIn('private sentinel', str(result))
                self.assertEqual(opener.calls, 1)

    def test_network_failure_remains_finite(self):
        class Offline:
            def open(self, request, timeout): raise URLError('private sentinel')
        result = consumer.consume(Offline())
        self.assertEqual(result['status'], 'failure')
        self.assertNotIn('private sentinel', str(result))

    def test_purge_reports_publish_only_required_finite_boolean_checks(self):
        with tempfile.TemporaryDirectory(prefix='petish-purge-report-') as temporary:
            path = Path(temporary)/'raw.json'
            for phase, required in safe.REQUIRED.items():
                raw = {'status': 'success', 'category': 'complete', 'checks': dict.fromkeys(required, True)}
                path.write_text(json.dumps(raw))
                self.assertEqual(safe.screen(phase, path), (raw, True))
                mutations = [dict(raw, header='private sentinel'), dict(raw, category='private sentinel'),
                             dict(raw, checks={**raw['checks'], 'private sentinel': True}),
                             dict(raw, checks={**raw['checks'], next(iter(required)): 'private sentinel'}),
                             dict(raw, checks={**raw['checks'], next(iter(required)): False})]
                for invalid in mutations:
                    path.write_text(json.dumps(invalid))
                    result, passed = safe.screen(phase, path)
                    self.assertFalse(passed)
                    self.assertNotIn('private sentinel', str(result))

    def test_consumer_check_names_match_shared_sanitizer(self):
        self.assertEqual(consumer.CHECKS, safe.REQUIRED['consume'])


if __name__ == '__main__':
    unittest.main()
