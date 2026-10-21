"""Recommendations respect current review, private identity, curation, and purchase history."""
from pathlib import Path
import json
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or str(ROOT.parent / '.petish-dev/bin/php')


class ForYourPetTests(unittest.TestCase):
    def test_matching_history_isolation_curation_and_escaped_cards(self):
        result = subprocess.run([PHP, str(ROOT / 'tests/for-your-pet.php')],
                                cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')
        report = json.loads(result.stdout)
        self.assertEqual(report['status'], 'success')
        self.assertGreaterEqual(len(report['checks']), 90)
        self.assertTrue(all(report['checks'].values()))


if __name__ == '__main__':
    unittest.main()
