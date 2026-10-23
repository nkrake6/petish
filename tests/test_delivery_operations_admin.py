"""Exercise the actual private admin CSV, authorization and retry boundary code."""
import json
from html.parser import HTMLParser
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
        self.assertGreaterEqual(len(report["checks"]), 60)
        self.assertTrue(all(report["checks"].values()))

    def test_private_native_email_response_is_labeled_and_sandboxed(self):
        result = subprocess.run(
            [PHP, str(ROOT / "tests/delivery-operations-admin.php"), "preview-response"],
            cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        self.assertIn("Staff preview — no email sent", result.stdout)
        self.assertIn("partial_dispatch · 2026-10-23 00:00:00 UTC · Status: superseded", result.stdout)
        self.assertIn("Historical tracking details may differ", result.stdout)
        self.assertIn('name="robots" content="noindex,nofollow,noarchive"', result.stdout)
        self.assertIn("Petish &lt;parcel&gt; &amp; &quot;tracking&quot;", result.stdout)

        class Frames(HTMLParser):
            def __init__(self):
                super().__init__()
                self.frames = []

            def handle_starttag(self, tag, attrs):
                if tag == "iframe":
                    self.frames.append(dict(attrs))

        parser = Frames()
        parser.feed(result.stdout)
        self.assertEqual(len(parser.frames), 1)
        frame = parser.frames[0]
        self.assertEqual(frame["sandbox"], "")
        self.assertEqual(frame["referrerpolicy"], "no-referrer")
        self.assertIn('<p title="Carrier">Carrier &amp; Co</p>', frame["srcdoc"])
        self.assertIn("TRACK-731", frame["srcdoc"])
        self.assertNotIn("Staff preview", frame["srcdoc"])
        self.assertNotIn("_wpnonce", frame["srcdoc"])


if __name__ == "__main__":
    unittest.main()
