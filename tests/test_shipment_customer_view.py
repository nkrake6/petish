"""Authorization, privacy and output boundaries for the native owner shipment view."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or str(ROOT.parent / '.petish-dev/bin/php')


class ShipmentCustomerViewTests(unittest.TestCase):
    def test_native_owner_render_boundaries(self):
        result = subprocess.run([PHP, str(ROOT / 'tests/shipment-customer-view.php')], cwd=ROOT,
                                text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, '')
        report = json.loads(result.stdout)
        self.assertEqual(report['status'], 'success')
        self.assertGreaterEqual(len(report['checks']), 50)
        self.assertTrue(all(report['checks'].values()))


if __name__ == '__main__':
    unittest.main()
