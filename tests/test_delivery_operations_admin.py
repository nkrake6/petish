"""Exercise the actual private admin CSV, authorization and retry boundary code."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or str(ROOT.parent / ".petish-dev/bin/php")


class DeliveryOperationsAdminTests(unittest.TestCase):
    def test_private_csv_and_notification_operations(self):
        result = subprocess.run([PHP, str(ROOT / "tests/delivery-operations-admin.php")], cwd=ROOT,
                                text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["status"], "success")
        self.assertGreaterEqual(len(report["checks"]), 40)
        self.assertTrue(all(report["checks"].values()))


if __name__ == "__main__":
    unittest.main()
