"""Run the actual private Passport APIs against owned file/database fault fixtures."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or str(ROOT.parent / '.petish-dev/bin/php')


class PassportStoreTests(unittest.TestCase):
    @unittest.skipUnless(Path(PHP).is_file(), 'PHP is required')
    def test_private_profiles_moments_media_and_failure_recovery(self):
        result = subprocess.run([PHP, str(ROOT / 'tests/passport-store.php')],
                                cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, '')
        report = json.loads(result.stdout)
        self.assertEqual(report['status'], 'success')
        self.assertGreaterEqual(len(report['checks']), 70)
        self.assertTrue(all(report['checks'].values()))


if __name__ == '__main__':
    unittest.main()
