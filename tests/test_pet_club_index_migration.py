"""Explicit shared-snapshot index migration and exact rollback under faults."""
from pathlib import Path
import json
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = '/workspace/.petish-dev/bin/php'


class PetClubIndexMigrationTests(unittest.TestCase):
    def test_frozen_scope_durable_transactions_and_owned_rollback(self):
        result = subprocess.run([PHP, str(ROOT / 'tests/pet-club-index-migration.php')],
                                cwd=ROOT, text=True, capture_output=True, timeout=40)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')
        report = json.loads(result.stdout)
        self.assertEqual(report['status'], 'success')
        self.assertGreaterEqual(len(report['checks']), 45)
        self.assertTrue(all(report['checks'].values()))


if __name__ == '__main__':
    unittest.main()
