"""Actual PHP ledger transitions with interruption and transport doubles."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or "/workspace/.petish-dev/bin/php"


class ShipmentOutboxTests(unittest.TestCase):
    def test_durable_ledger_outbox_and_permissions(self):
        result = subprocess.run([PHP, str(ROOT / "tests/shipment-outbox.php")], cwd=ROOT,
                                capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["status"], "success")
        self.assertGreaterEqual(len(report["checks"]), 35)
        self.assertTrue(all(report["checks"].values()))


if __name__ == "__main__":
    unittest.main()
