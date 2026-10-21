"""Release reports must never publish fixture credentials or accept partial proof."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('review_report', ROOT / 'scripts/sanitize-customer-reviews-result.py')
report = importlib.util.module_from_spec(spec)
spec.loader.exec_module(report)


class CustomerReviewStagingReportTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / 'result.json'
        self.keys = report.literal_list(ROOT / 'scripts/customer-reviews-proof.php', 'PETISH_CUSTOMER_REVIEWS_PROOF_CHECKS')

    def write(self, value):
        self.path.write_text(json.dumps(value))
        return self.path

    def success(self):
        return {'status':'success', 'checks':dict.fromkeys(self.keys, True)}

    def test_complete_native_proof_preserves_only_fixed_booleans(self):
        value = self.success()
        value.update({'fixture_password':'do-not-publish', 'private_path':'/private/example', 'cleanup':{'owner_email':'private@example.invalid'}})
        cleaned = report.sanitize('native', self.write(value))
        self.assertEqual(set(cleaned), {'status','checks'})
        self.assertEqual(cleaned['checks'], value['checks'])
        self.assertNotIn('do-not-publish', json.dumps(cleaned))

    def test_missing_required_check_cannot_pass(self):
        value = self.success()
        value['checks'].pop(next(iter(self.keys)))
        with self.assertRaises(ValueError):
            report.sanitize('native', self.write(value))

    def test_false_or_non_boolean_check_cannot_pass(self):
        for bad in [False, 1, 'true', None]:
            value = self.success()
            value['checks'][next(iter(self.keys))] = bad
            with self.subTest(value=bad), self.assertRaises(ValueError):
                report.sanitize('native', self.write(value))

    def test_unknown_check_cannot_smuggle_private_data(self):
        value = self.success()
        value['checks']['private_customer_email'] = True
        with self.assertRaises(ValueError):
            report.sanitize('native', self.write(value))

    def test_failure_can_report_only_fixed_failed_check(self):
        key = next(iter(self.keys))
        value = {'status':'failure', 'checks':{key:False}, 'failed_check':key, 'error':'secret exception text'}
        cleaned = report.sanitize('native', self.write(value))
        self.assertEqual(cleaned, {'status':'failure','checks':{key:False},'failed_check':key})
        value['failed_check'] = 'unknown-sensitive-value'
        with self.assertRaises(ValueError):
            report.sanitize('native', self.write(value))

    def test_symbolic_or_hardlinked_reports_rejected(self):
        original = self.write(self.success())
        linked = original.with_name('linked.json')
        linked.symlink_to(original)
        with self.assertRaises(ValueError):
            report.sanitize('native', linked)
        linked.unlink()
        linked.hardlink_to(original)
        with self.assertRaises(ValueError):
            report.sanitize('native', original)

    def test_browser_and_cleanup_have_distinct_complete_schemas(self):
        for phase, source, constant, php in [
            ('browser', ROOT/'tools/customer-reviews-browser.mjs', 'CHECK_KEYS', False),
            ('cleanup-fixture', ROOT/'scripts/customer-reviews-proof.php', 'PETISH_CUSTOMER_REVIEWS_CLEANUP_CHECKS', True),
        ]:
            with self.subTest(phase=phase):
                keys = report.literal_list(source, constant, php)
                value = {'status':'success','checks':dict.fromkeys(keys, True)}
                self.assertEqual(report.sanitize(phase,self.write(value)),value)
                value['checks'].pop(next(iter(keys)))
                with self.assertRaises(ValueError):
                    report.sanitize(phase,self.write(value))

    def test_enabling_requires_advertised_upload_capacity(self):
        keys = report.literal_list(ROOT/'scripts/customer-reviews-staging-remote.php', 'PCR_CHECKS')
        value = {'status':'success', 'checks':dict.fromkeys(keys, True)}
        self.assertEqual(report.sanitize('enable',self.write(value)),value)
        value['checks'].pop('upload_limits_sufficient')
        with self.assertRaises(ValueError):
            report.sanitize('enable',self.write(value))


if __name__ == '__main__':
    unittest.main()
