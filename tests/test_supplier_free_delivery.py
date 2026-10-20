"""Exercise supplier claims against the real Petish checkout validators offline."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or str(ROOT.parent / '.petish-dev/bin/php')


class SupplierFreeDeliveryTests(unittest.TestCase):
    @unittest.skipUnless(Path(PHP).is_file(), 'PHP is required for functional shipping checks')
    def test_supplier_evidence_native_zero_variations_and_ranking(self):
        result = subprocess.run([PHP, str(ROOT / 'tests/supplier-free-delivery.php')],
                                cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, '')
        report = json.loads(result.stdout)
        self.assertEqual(report['status'], 'success')
        self.assertGreaterEqual(len(report['checks']), 40)
        self.assertTrue(all(report['checks'].values()))


if __name__ == '__main__':
    unittest.main()
