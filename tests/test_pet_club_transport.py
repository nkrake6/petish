"""Failure classification and bounded reads; no SSH credentials or host access."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'scripts/hostinger-pet-club.sh').read_text()


class PetClubTransportTests(unittest.TestCase):
    def test_input_read_retries_only_transport_and_never_weakens_source_checks(self):
        function = SOURCE[SOURCE.index('fetch_browser_input() {'):SOURCE.index('\nverify_staging_source() {')]
        cases = [('0', True, 1, 'fixture_input_permissions'),
                 ('255 0', True, 2, 'fixture_input_permissions'),
                 ('255 255 255', False, 3, 'fixture_input_transport'),
                 ('41 0', False, 1, 'fixture_input_path'),
                 ('42 0', False, 1, 'fixture_input_permissions'),
                 ('43 0', False, 1, 'fixture_input_size'),
                 ('44 0', False, 1, 'fixture_input_read'),
                 ('1 0', False, 1, 'fixture_input_fetch')]
        for codes, passed, calls, category in cases:
            with self.subTest(codes=codes), tempfile.TemporaryDirectory() as directory:
                script = '''
set -euo pipefail
umask 077
work=$1; target_run=123-1; codes=($2); calls=0; waits=0; failure_category=input
fake_ssh() { local status=${codes[$calls]}; calls=$((calls+1)); printf '%s' '{"private":true}'; return "$status"; }
sleep() { waits=$((waits+$1)); }
petish_ssh=(fake_ssh)
''' + function + '''
ok=false; if fetch_browser_input; then ok=true; fi
printf '{"ok":%s,"calls":%s,"waits":%s,"category":"%s"}\n' "$ok" "$calls" "$waits" "$failure_category"
'''
                result = subprocess.run(['bash', '-c', script, 'proof', directory, codes], capture_output=True)
                self.assertEqual(result.returncode, 0)
                self.assertFalse(result.stderr)
                self.assertEqual(json.loads(result.stdout), {'ok': passed, 'calls': calls,
                                                             'waits': calls * (calls - 1), 'category': category})
                self.assertNotIn(b'private', result.stdout)
                if passed:
                    self.assertEqual((Path(directory) / 'browser-input.json').stat().st_mode & 0o777, 0o600)

    def test_prepare_failure_is_never_retried_or_accepted_from_success_json(self):
        function = SOURCE[SOURCE.index('fixture_phase() {'):SOURCE.index('\n# Retrying this bounded read')]
        for status, validation, category in [(255, 0, 'fixture_prepare_transport'),
                                             (71, 0, 'fixture_prepare_runtime'),
                                             (1, 0, 'fixture_prepare_setup'),
                                             (0, 1, 'fixture_prepare_validation')]:
            with self.subTest(status=status, validation=validation), tempfile.TemporaryDirectory() as directory:
                script = '''
set -euo pipefail
work=$1; target_run=123-1; fake_status=$2; validation_status=$3; calls=0; failure_category=fixture
fake_ssh() { calls=$((calls+1)); printf '%s' '{"status":"success"}'; return "$fake_status"; }
screen_phase() { return "$validation_status"; }
petish_ssh=(fake_ssh)
''' + function + '''
ok=false; if fixture_phase prepare; then ok=true; fi
printf '{"ok":%s,"calls":%s,"category":"%s"}\n' "$ok" "$calls" "$failure_category"
'''
                result = subprocess.run(['bash', '-c', script, 'proof', directory, str(status), str(validation)], capture_output=True)
                self.assertEqual(result.returncode, 0)
                self.assertFalse(result.stderr)
                self.assertEqual(json.loads(result.stdout), {'ok': False, 'calls': 1, 'category': category})


if __name__ == '__main__':
    unittest.main()
