"""Exercise actual staff cancellation handlers and their private browser response."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or str(ROOT.parent / ".petish-dev/bin/php")


class CancellationAdminTests(unittest.TestCase):
    def test_private_staff_workflow_boundaries(self):
        result = subprocess.run([PHP, str(ROOT / "tests/cancellation-admin.php")], cwd=ROOT,
                                text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["status"], "success")
        self.assertGreaterEqual(len(report["checks"]), 45)
        self.assertTrue(all(report["checks"].values()))

    def test_private_preview_response_is_isolated(self):
        result = subprocess.run([PHP, str(ROOT / "tests/cancellation-admin.php"), "preview"],
                                cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        self.assertIn("Staff preview — no email sent", result.stdout)
        self.assertIn('sandbox=""', result.stdout)
        self.assertIn('referrerpolicy="no-referrer"', result.stdout)
        self.assertIn('srcdoc="&lt;!doctype html&gt;', result.stdout)
        self.assertIn("Request &lt;preview&gt;", result.stdout)
        self.assertNotIn("<p>Customer preview", result.stdout)

    def test_settings_redirect_is_fixed_and_does_not_send(self):
        result = subprocess.run([PHP, str(ROOT / "tests/cancellation-admin.php"), "settings_redirect"],
                                cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["url"], "https://admin.example.test/admin.php?page=petish-cancellation-requests&settings_notice=saved")
        self.assertEqual(report["status"], 303)
        self.assertEqual(report["messages"], 0)
        self.assertEqual(report["flags"], {"petish_cancellation_requests_enabled": "yes", "petish_cancellation_emails_enabled": "no"})


if __name__ == "__main__":
    unittest.main()
