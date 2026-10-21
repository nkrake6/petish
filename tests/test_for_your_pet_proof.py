"""Rollback-only staging proof barriers; no customer database access."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or str(ROOT.parent / '.petish-dev/bin/php')


class ForYourPetProofTests(unittest.TestCase):
    @unittest.skipUnless(Path(PHP).is_file(), 'PHP is required')
    def test_request_local_cache_bootstrap_and_source_boundaries(self):
        for scenario in ('core', 'preinitialized', 'foreign_class', 'foreign_functions', 'missing_api',
                         'object_dropin', 'advanced_dropin', 'object_filter', 'advanced_filter'):
            with self.subTest(scenario=scenario):
                result = subprocess.run([PHP, str(ROOT / 'tests/for-your-pet-cache.php'), scenario],
                                        cwd=ROOT, capture_output=True, text=True, timeout=30)
                self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
                report = json.loads(result.stdout)
                self.assertEqual(report['status'], 'success')
                self.assertTrue(report['checks'])
                self.assertTrue(all(report['checks'].values()))

    @unittest.skipUnless(Path(PHP).is_file(), 'PHP is required')
    def test_transaction_sql_boundaries_and_private_journal(self):
        result = subprocess.run([PHP, str(ROOT / 'tests/for-your-pet-proof.php')],
                                cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        report = json.loads(result.stdout)
        self.assertEqual(report['status'], 'success')
        self.assertGreaterEqual(len(report['checks']), 45)
        self.assertTrue(all(report['checks'].values()))


if __name__ == '__main__':
    unittest.main()
