"""Exercise the private worksheet using the actual PHP parser and calculator."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or str(ROOT.parent / ".petish-dev/bin/php")


class DeliveryCostSheetTests(unittest.TestCase):
    @unittest.skipUnless(Path(PHP).is_file(), "PHP is required")
    def test_fixed_decimal_costs_evidence_csv_and_private_admin_boundaries(self):
        result = subprocess.run(
            [PHP, str(ROOT / "tests/delivery-cost-sheet.php")],
            cwd=ROOT, text=True, capture_output=True, timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["status"], "success")
        self.assertGreaterEqual(len(report["checks"]), 60)
        self.assertTrue(all(report["checks"].values()))


if __name__ == "__main__":
    unittest.main()
