"""Exact three-file scope and read-only native dashboard verification."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or str(ROOT.parent / ".petish-dev/bin/php")
HELPER = ROOT / "scripts/review-dashboard-spacing-release.php"
FILES = (
    "wp-content/plugins/petish-passport/includes/class-petish-customer-reviews-ui.php",
    "wp-content/plugins/petish-passport/templates/customer-reviews-dashboard.php",
    "wp-content/plugins/petish-passport/assets/customer-reviews.css",
)
CORE = Path(os.environ.get("PETISH_WORDPRESS_SOURCE", "/tmp/petish-fyp-repro/WordPress-7.1.3"))


class ReviewDashboardSpacingReleaseTests(unittest.TestCase):
    def php(self, script, *arguments):
        result = subprocess.run([PHP, "-r", "require $argv[1]; " + script, str(HELPER), *map(str, arguments)],
                                cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def test_exact_scope_candidate_and_reviewed_baseline(self):
        scope = self.php("echo json_encode([petish_review_spacing_files(),petish_review_spacing_baselines()]);")
        self.assertEqual(set(scope[0]), set(FILES))
        self.assertEqual(set(scope[1]), set(FILES))
        for name in FILES:
            self.assertEqual(scope[0][name], hashlib.sha256((ROOT / "wordpress" / name).read_bytes()).hexdigest())
            baseline = subprocess.check_output(["git", "show", "baa3e0e:wordpress/" + name], cwd=ROOT)
            self.assertEqual(scope[1][name], hashlib.sha256(baseline).hexdigest())
        self.assertTrue(self.php("echo json_encode(petish_review_spacing_source($argv[2]));", ROOT / "wordpress"))

    def test_rejects_drift_links_and_incomplete_source(self):
        with tempfile.TemporaryDirectory(prefix="petish-spacing-release-") as work:
            root = Path(work) / "site"
            root.mkdir()
            self.assertFalse(self.php("echo json_encode(petish_review_spacing_source($argv[2]));", root))
            for name in FILES:
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes((ROOT / "wordpress" / name).read_bytes())
            self.assertTrue(self.php("echo json_encode(petish_review_spacing_source($argv[2]));", root))
            selected = root / FILES[2]
            selected.write_bytes(selected.read_bytes() + b"\n/* drift */\n")
            self.assertFalse(self.php("echo json_encode(petish_review_spacing_source($argv[2]));", root))
            selected.unlink()
            selected.symlink_to(ROOT / "wordpress" / FILES[2])
            self.assertFalse(self.php("echo json_encode(petish_review_spacing_source($argv[2]));", root))
            link = Path(work) / "linked-site"
            link.symlink_to(ROOT / "wordpress", target_is_directory=True)
            self.assertFalse(self.php("echo json_encode(petish_review_spacing_source($argv[2]));", link))

    @unittest.skipUnless((CORE / "wp-includes/html-api/class-wp-html-processor.php").is_file(),
                         "Set PETISH_WORDPRESS_SOURCE to clean WordPress core for its native HTML parser")
    def test_actual_template_structure_and_css_cache_version(self):
        result = subprocess.run([PHP, str(ROOT / "tests/review-dashboard-spacing.php"), str(CORE)],
                                cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.assertEqual(set(report), {"review_spacing_source_verified", "review_spacing_native_render_verified",
                                     "review_spacing_asset_version_verified", "presentation_registries_restored",
                                     "request_filters_restored"})
        self.assertTrue(all(report.values()))


if __name__ == "__main__":
    unittest.main()
