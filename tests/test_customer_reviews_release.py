"""Release evidence must fail closed without network access or production writes."""
import copy
from datetime import datetime, timedelta, timezone
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    'customer_reviews_release', ROOT / 'scripts/validate-customer-reviews-release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class CustomerReviewsReleaseTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='petish-review-release-test-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.request_path = self.root / 'request.json'
        self.request = {
            'format': 'petish-customer-reviews-v1',
            'staging_source_commit': release.STAGING_SOURCE,
            'staging_status_branch': release.STAGING_STATUS,
            'backup_branch': 'full-site-backup/12345-1',
            'backup_result_branch': 'automation-status/backup-12345-1',
            'recovery_result_branch': 'automation-status/recovery-12346-1',
        }
        self.stage = {
            'status': 'success', 'scope': 'private-staging-only',
            'source_commit': release.STAGING_SOURCE,
            'run_id': release.STAGING_STATUS.removeprefix('automation-status/customer-reviews-'),
            'generated_utc': datetime.now(timezone.utc).isoformat(), 'category': 'complete',
            'fixture_cleanup_verified': True, 'rollback_verified': False, 'production_changed': False,
            'phases': {phase: {'status': 'success', 'checks': dict.fromkeys(required, True)}
                       for phase, required in release.PHASE_CHECKS.items()},
        }
        self.stage['phases']['browser']['diagnostics'] = {
            phase: {'status': 'redirect_303', 'redirect': 'owned_product', 'body': 'redirect_not_read'}
            for phase in ('vote', 'invalid_nonce', 'submission')
        }
        for role, status in [('owner', 'ok_200'), ('foreign', 'missing_404'), ('guest', 'missing_404')]:
            for kind in ('photo', 'video', 'avatar'):
                self.stage['phases']['browser']['diagnostics'][role + '_' + kind] = {
                    'status': status, 'redirect': 'missing', 'body': 'not_read',
                }
        self.backup = {
            'AUTOMATION_KIND': 'backup', 'AUTOMATION_RESULT': 'success',
            'GITHUB_RUN_ID': '12345', 'GITHUB_RUN_ATTEMPT': '1',
            'generated_utc': (datetime.now(timezone.utc) - timedelta(minutes=15)).isoformat(),
            'restore_check': {'restore_smoke_test_passed': True, 'database_import_verified': True,
                              'private_passport_root_restored': True},
        }
        self.recovery = {
            'AUTOMATION_KIND': 'recovery', 'AUTOMATION_RESULT': 'success',
            'GITHUB_RUN_ID': '12346', 'GITHUB_RUN_ATTEMPT': '1',
            'generated_utc': (datetime.now(timezone.utc) - timedelta(minutes=10)).isoformat(),
            'backup_branch': self.request['backup_branch'],
            'recovery_check': {**dict.fromkeys(release.RECOVERY_CHECKS, True),
                               'private_photo_references_verified': 1},
        }
        self.reports = {
            self.request['staging_status_branch']: self.stage,
            self.request['backup_result_branch']: self.backup,
            self.request['recovery_result_branch']: self.recovery,
        }
        self.blobs = {}
        for name in release.FILES:
            relative = release.PREFIX + name
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            self.blobs[relative] = ('reviewed fixture ' + name + '\n').encode()
            path.write_bytes(self.blobs[relative])
        self.git_calls = []
        self.git_override = None
        self.branch_output = ('a' * 40 + '\trefs/heads/' + self.request['backup_branch'] + '\n').encode()
        self.addCleanup(patch.stopall)
        patch.object(release, 'ROOT', self.root).start()
        patch.object(release.evidence, 'report', side_effect=lambda branch, label: self.reports[branch]).start()
        patch.object(release.evidence, 'git', side_effect=self.git).start()
        self.save_request()

    def save_request(self):
        self.request_path.write_text(json.dumps(self.request))

    def git(self, *arguments):
        self.git_calls.append(arguments)
        if self.git_override:
            replacement = self.git_override(arguments)
            if replacement is not None:
                return replacement
        if arguments[0] == 'fetch':
            return b''
        if arguments[0] == 'show':
            revision, relative = arguments[1].split(':', 1)
            self.assertIn(revision, {'HEAD', release.STAGING_SOURCE})
            return self.blobs[relative]
        if arguments[0] == 'ls-remote':
            return self.branch_output
        self.fail('Unexpected evidence command')

    def validate(self):
        return release.validate(self.request_path)

    def test_complete_proof_and_recent_recoverable_backup_pass(self):
        self.assertEqual(self.validate(), {
            'verified': True, **{key: value for key, value in self.request.items() if key != 'format'},
        })
        self.assertEqual(len(release.FILES), len(set(release.FILES)))
        self.assertEqual(release.FILES[-1], 'petish-passport.php')
        self.assertEqual({phase: len(release.PHASE_CHECKS[phase])
                          for phase in ('native', 'browser', 'cleanup-fixture')},
                         {'native': 47, 'browser': 44, 'cleanup-fixture': 8})
        for revision in ('HEAD', release.STAGING_SOURCE):
            for name in release.FILES:
                self.assertIn(('show', revision + ':' + release.PREFIX + name), self.git_calls)

    def test_request_is_exact_and_cannot_select_an_unreviewed_release(self):
        original = copy.deepcopy(self.request)
        for key, value in [('format', 'different'), ('staging_source_commit', 'b' * 40),
                           ('staging_status_branch', 'automation-status/customer-reviews-12399-1'),
                           ('backup_branch', 'full-site-backup/../private'),
                           ('backup_result_branch', 'automation-status/backup-99999-1'),
                           ('recovery_result_branch', '--upload-pack=private'),
                           ('private_value', 'private-sentinel')]:
            with self.subTest(field=key):
                self.request = {**original, key: value}
                self.save_request()
                with self.assertRaises(ValueError):
                    self.validate()
        self.assertEqual(self.git_calls, [])

    def test_cli_rejection_emits_no_private_request_values_or_success_receipt(self):
        self.request['private_value'] = 'private-sentinel'
        self.save_request()
        output = self.root / 'release-evidence.json'
        result = subprocess.run(
            ['python3', str(ROOT / 'scripts/validate-customer-reviews-release.py'),
             str(self.request_path), str(output)], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, '')
        self.assertNotIn('private-sentinel', result.stderr)
        self.assertNotIn('Traceback', result.stderr)
        self.assertFalse(output.exists())

    def test_missing_request_fields_and_wrong_types_are_rejected(self):
        original = copy.deepcopy(self.request)
        for key in original:
            for mutation in ('missing', None, 1, []):
                with self.subTest(field=key, mutation=mutation):
                    self.request = copy.deepcopy(original)
                    if mutation == 'missing':
                        del self.request[key]
                    else:
                        self.request[key] = mutation
                    self.save_request()
                    with self.assertRaises(ValueError):
                        self.validate()

    def test_stage_requires_exact_source_run_scope_and_success(self):
        original = copy.deepcopy(self.stage)
        mutations = [
            ('status', 'failure'), ('category', 'verify'), ('scope', 'production'),
            ('source_commit', 'b' * 40), ('run_id', '12399-1'),
            ('fixture_cleanup_verified', False), ('fixture_cleanup_verified', 1),
            ('rollback_verified', True), ('rollback_verified', 0),
            ('production_changed', True), ('production_changed', 0),
            ('generated_utc', datetime.now().replace(tzinfo=None).isoformat()),
            ('generated_utc', (datetime.now(timezone.utc) + timedelta(hours=1)).isoformat()),
            ('private_path', 'private-sentinel'),
        ]
        for key, value in mutations:
            with self.subTest(field=key, value=value):
                self.stage.clear()
                self.stage.update(copy.deepcopy(original))
                self.stage[key] = value
                with self.assertRaises(ValueError):
                    self.validate()

    def test_every_staging_check_is_mandatory_and_literal_true(self):
        for phase, required in release.PHASE_CHECKS.items():
            for name in required:
                values = self.stage['phases'][phase]['checks']
                for mutation in ('missing', False, 1):
                    with self.subTest(phase=phase, check=name, mutation=mutation):
                        if mutation == 'missing':
                            del values[name]
                        else:
                            values[name] = mutation
                        with self.assertRaises(ValueError):
                            self.validate()
                        values[name] = True

    def test_missing_failed_or_rollback_phases_cannot_substitute_for_completion(self):
        for phase in release.PHASE_CHECKS:
            original = self.stage['phases'].pop(phase)
            with self.subTest(phase=phase), self.assertRaises(ValueError):
                self.validate()
            self.stage['phases'][phase] = original
            original['status'] = 'failure'
            with self.subTest(phase=phase), self.assertRaises(ValueError):
                self.validate()
            original['status'] = 'success'
        self.stage['phases']['rollback-code'] = {'status': 'success', 'checks': {}}
        with self.assertRaises(ValueError):
            self.validate()

    def test_unknown_checks_and_private_phase_fields_are_rejected(self):
        for phase in release.PHASE_CHECKS:
            value = self.stage['phases'][phase]
            value['checks']['unknown_success'] = True
            with self.subTest(phase=phase), self.assertRaises(ValueError):
                self.validate()
            del value['checks']['unknown_success']
            value['private_value'] = 'private-sentinel'
            with self.subTest(phase=phase), self.assertRaises(ValueError):
                self.validate()
            del value['private_value']

    def test_browser_diagnostics_cannot_contain_raw_responses(self):
        browser = self.stage['phases']['browser']
        original = copy.deepcopy(browser['diagnostics'])
        for mutation in ('unknown_phase', 'unknown_field', 'unknown_value', 'missing_field'):
            with self.subTest(mutation=mutation):
                browser['diagnostics'] = copy.deepcopy(original)
                if mutation == 'unknown_phase':
                    browser['diagnostics']['private-sentinel'] = {}
                elif mutation == 'unknown_field':
                    browser['diagnostics']['vote']['response_text'] = 'private-sentinel'
                elif mutation == 'unknown_value':
                    browser['diagnostics']['vote']['body'] = 'private-sentinel'
                else:
                    del browser['diagnostics']['vote']['status']
                with self.assertRaises(ValueError):
                    self.validate()

    def test_stage_and_head_each_must_match_every_candidate_application_file(self):
        for revision in ('HEAD', release.STAGING_SOURCE):
            for name in release.FILES:
                target = revision + ':' + release.PREFIX + name
                self.git_override = lambda args: b'unreviewed' if args == ('show', target) else None
                with self.subTest(revision=revision, file=name), self.assertRaises(ValueError):
                    self.validate()

    def test_uncommitted_candidate_application_change_is_rejected(self):
        path = self.root / (release.PREFIX + release.FILES[0])
        path.write_bytes(b'uncommitted change')
        with self.assertRaises(ValueError):
            self.validate()

    def test_candidate_symlinks_hardlinks_and_oversized_files_are_rejected(self):
        path = self.root / (release.PREFIX + release.FILES[0])
        original = path.read_bytes()
        target = self.root / 'file-target'
        target.write_bytes(original)
        for kind in ('symlink', 'hardlink', 'oversized'):
            path.unlink()
            if kind == 'symlink':
                path.symlink_to(target)
            elif kind == 'hardlink':
                os.link(target, path)
            else:
                path.write_bytes(b'x' * (2 * 1024 * 1024 + 1))
            with self.subTest(kind=kind), self.assertRaises(ValueError):
                self.validate()

    def test_request_symlinks_hardlinks_and_oversized_files_are_rejected(self):
        source = self.root / 'request-target'
        source.write_bytes(self.request_path.read_bytes())
        for kind in ('symlink', 'hardlink', 'oversized'):
            self.request_path.unlink()
            if kind == 'symlink':
                self.request_path.symlink_to(source)
            elif kind == 'hardlink':
                os.link(source, self.request_path)
            else:
                self.request_path.write_bytes(b' ' * 4097)
            with self.subTest(kind=kind), self.assertRaises(ValueError):
                self.validate()

    def test_backup_must_be_successful_and_match_the_archive_id(self):
        original = copy.deepcopy(self.backup)
        for key, value in [('AUTOMATION_KIND', 'recovery'), ('AUTOMATION_RESULT', 'failure'),
                           ('GITHUB_RUN_ID', '99999'), ('GITHUB_RUN_ATTEMPT', '2'),
                           ('restore_check', None)]:
            self.backup.clear()
            self.backup.update(copy.deepcopy(original))
            self.backup[key] = value
            with self.subTest(field=key), self.assertRaises(ValueError):
                self.validate()
        self.backup.clear()
        self.backup.update(original)
        for key in self.backup['restore_check']:
            self.backup['restore_check'][key] = False
            with self.subTest(check=key), self.assertRaises(ValueError):
                self.validate()
            self.backup['restore_check'][key] = True

    def test_backup_must_be_within_six_hours_with_timezone(self):
        now = datetime.now(timezone.utc)
        for value in [(now - timedelta(hours=6, minutes=1)).isoformat(),
                      (now + timedelta(minutes=6)).isoformat(),
                      now.replace(tzinfo=None).isoformat(), None, 'invalid']:
            self.backup['generated_utc'] = value
            with self.subTest(timestamp=value), self.assertRaises(ValueError):
                self.validate()

    def test_only_the_exact_existing_backup_branch_is_accepted(self):
        for value in [b'', b'not-a-ref', b'a' * 40 + b'\trefs/heads/full-site-backup/99999-1\n',
                      self.branch_output * 2]:
            self.branch_output = value
            with self.subTest(response=value), self.assertRaises(ValueError):
                self.validate()

    def test_recovery_must_match_run_archive_and_each_application_check(self):
        original = copy.deepcopy(self.recovery)
        for key, value in [('AUTOMATION_KIND', 'backup'), ('AUTOMATION_RESULT', 'failure'),
                           ('backup_branch', 'full-site-backup/99999-1'),
                           ('GITHUB_RUN_ID', '99999'), ('GITHUB_RUN_ATTEMPT', '2'),
                           ('recovery_check', None)]:
            self.recovery.clear()
            self.recovery.update(copy.deepcopy(original))
            self.recovery[key] = value
            with self.subTest(field=key), self.assertRaises(ValueError):
                self.validate()
        self.recovery.clear()
        self.recovery.update(original)
        for name in release.RECOVERY_CHECKS:
            for mutation in ('missing', False, 1):
                values = self.recovery['recovery_check']
                if mutation == 'missing':
                    del values[name]
                else:
                    values[name] = mutation
                with self.subTest(check=name, mutation=mutation), self.assertRaises(ValueError):
                    self.validate()
                values[name] = True

    def test_recovery_private_media_reference_count_is_a_bounded_integer(self):
        for value in (0, -1, 20001, True, False, 1.0, '1', None):
            self.recovery['recovery_check']['private_photo_references_verified'] = value
            with self.subTest(count=value), self.assertRaises(ValueError):
                self.validate()

    def test_recovery_timestamp_cannot_precede_its_backup_or_be_in_future(self):
        now = datetime.now(timezone.utc)
        for value in [(now - timedelta(hours=2)).isoformat(),
                      (now + timedelta(minutes=6)).isoformat(),
                      now.replace(tzinfo=None).isoformat(), None]:
            self.recovery['generated_utc'] = value
            with self.subTest(timestamp=value), self.assertRaises(ValueError):
                self.validate()


if __name__ == '__main__':
    unittest.main()
