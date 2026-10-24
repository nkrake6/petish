"""Own-session account widgets and native menu branch boundaries."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or str(ROOT.parent / ".petish-dev/bin/php")


class AccountDropdownTests(unittest.TestCase):
    def test_private_native_account_fragment(self):
        result = subprocess.run([PHP, str(ROOT / "tests/account-dropdown.php")], cwd=ROOT,
                                text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["status"], "success")
        self.assertGreaterEqual(len(report["checks"]), 40)
        self.assertTrue(all(report["checks"].values()))


if __name__ == "__main__":
    unittest.main()
