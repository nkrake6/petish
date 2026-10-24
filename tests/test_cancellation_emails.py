"""Run the actual cancellation mail renderer and durable transition code."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or str(ROOT.parent / ".petish-dev/bin/php")


class CancellationEmailTests(unittest.TestCase):
    def test_rendering_and_durable_delivery(self):
        result = subprocess.run([PHP, str(ROOT / "tests/cancellation-emails.php")], cwd=ROOT,
                                capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["status"], "success")
        self.assertGreaterEqual(len(report["checks"]), 45)
        self.assertTrue(all(report["checks"].values()))


if __name__ == "__main__":
    unittest.main()
