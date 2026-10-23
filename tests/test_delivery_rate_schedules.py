"""Exercise actual quantity-aware shipping without orders, SQL or supplier calls."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or str(ROOT.parent / ".petish-dev/bin/php")


class DeliveryRateScheduleTests(unittest.TestCase):
    @unittest.skipUnless(Path(PHP).is_file(), "PHP is required")
    def test_supplier_evidence_quantity_money_and_cached_rates(self):
        result = subprocess.run(
            [PHP, str(ROOT / "tests/delivery-rate-schedules.php")],
            cwd=ROOT, text=True, capture_output=True, timeout=45,
        )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["status"], "success")
        self.assertGreaterEqual(len(report["checks"]), 85)
        self.assertTrue(all(report["checks"].values()))


if __name__ == "__main__":
    unittest.main()
