"""Native-image selection boundaries of the actual hero settings resolver."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or str(ROOT.parent / ".petish-dev/bin/php")


class HeroImageSettingsTests(unittest.TestCase):
    def test_public_images_permissions_resets_and_fallbacks(self):
        result = subprocess.run([PHP, str(ROOT / "tests/hero-images.php")], cwd=ROOT,
                                capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(report["status"], "success")
        self.assertGreaterEqual(len(report["checks"]), 45)
        self.assertTrue(all(report["checks"].values()))


if __name__ == "__main__":
    unittest.main()
