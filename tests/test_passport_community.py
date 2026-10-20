"""Behavioral coverage for explicitly shared pet snapshots and member actions."""
from pathlib import Path
import json
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = Path('/workspace/.petish-dev/bin/php')

class PassportCommunityTests(unittest.TestCase):
    def test_sharing_ownership_nonce_replay_revocation_cleanup_and_erasure(self):
        result = subprocess.run([str(PHP), str(ROOT / 'tests/passport-community.php')], cwd=ROOT, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual(report['status'], 'success')
        self.assertGreaterEqual(report['checks'], 52)

if __name__ == '__main__':
    unittest.main()
