"""Exercise release evidence gates and the private-to-public report boundary."""
from copy import deepcopy
from datetime import datetime, timedelta, timezone
from importlib.machinery import SourceFileLoader
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
screening = SourceFileLoader('petish_email_release_screening', str(
    ROOT / 'scripts/sanitize-email-release-result.py')).load_module()
request_validation = SourceFileLoader('petish_email_release_request', str(
    ROOT / 'scripts/validate-email-release-request.py')).load_module()
PRIVATE = 'PRIVATE_SENTINEL_SECRET_AND_CUSTOMER_DATA_812094'
INVALID = {'status':'failure', 'category':'invalid_report', 'checks':{}}
VERIFY = {
    'production_paths_verified', 'approved_full_animal_logo_verified',
    'production_environment_verified', 'tested_theme_and_woocommerce_verified',
    'all_outbound_http_blocked', 'mail_calls_blocked', 'remote_file_access_disabled',
    'cron_and_async_runner_disabled', 'synthetic_unsaved_order_and_user',
    'seven_native_email_types_available', 'order_email_html_branded',
    'four_order_types_rendered', 'account_email_html_branded',
    'verification_button_readable_and_native_url_preserved',
    'three_account_types_rendered', 'core_welcome_and_reset_filters_verified',
    'order_counts_unchanged', 'user_counts_unchanged', 'authentication_state_unchanged',
    'product_stock_unchanged', 'email_and_account_settings_unchanged',
    'seven_native_emails_rendered_without_dispatch',
    'production_data_snapshot_verified',
}
BASE = {'paths_verified', 'candidate_manifest_verified', 'candidate_syntax_verified',
        'logo_verified', 'production_config_present', 'live_files_compatible'}
PHASES = {
    'inspect':BASE,
    'apply':BASE | {'protected_state_unchanged', 'rollback_snapshot_created',
                  'files_deployed', 'lock_owned'},
    'commit':{'protected_state_unchanged', 'release_committed'},
    'rollback':{'rollback_completed'},
    'verify':VERIFY,
    'preflight':{'production_paths_verified','approved_full_animal_logo_verified',
                 'production_environment_verified','tested_theme_and_woocommerce_verified',
                 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled',
                 'cron_and_async_runner_disabled','production_data_snapshot_verified'},
    'health':{'homepage_http_verified', 'shop_http_verified', 'product_http_verified',
              'cart_http_verified', 'account_http_verified', 'approved_logo_http_verified'},
}
PREVIEW = {
    'email_preview_seven_types_rendered', 'email_preview_native_renderer',
    'email_preview_no_order_records_created', 'email_preview_no_user_records_created',
    'email_preview_no_auth_tokens_generated', 'email_preview_stock_unchanged',
    'email_preview_core_account_branding',
}
RECOVERY = {
    'wordpress_application_recovery_passed', 'database_import_verified',
    'wordpress_bootstrap_verified', 'homepage_http_verified', 'shop_http_verified',
    'product_http_verified', 'cart_http_verified', 'account_http_verified',
}


class EmailReleaseReportTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-email-report-')
        self.directory = Path(self.temporary.name)
        self.path = self.directory / 'private-report.json'

    def tearDown(self):
        self.temporary.cleanup()

    @staticmethod
    def fixture(phase):
        value = {'status':'success', 'category':'complete',
                 'checks':{name:True for name in PHASES[phase]}}
        if phase in {'inspect', 'apply', 'rollback', 'commit'}:
            value['changed_file_count'] = 6
        return value

    def screen(self, phase, raw):
        self.path.write_text(json.dumps(raw))
        result, passed = screening.screen(phase, self.path)
        self.assertNotIn(PRIVATE, json.dumps(result))
        return result, passed

    def test_all_seven_phase_contracts_and_23_native_checks_pass(self):
        self.assertEqual(len(VERIFY), 23)
        self.assertEqual(screening.VERIFY_CHECKS, VERIFY)
        for phase in PHASES:
            with self.subTest(phase=phase):
                raw = self.fixture(phase)
                self.assertEqual(self.screen(phase, raw), (raw, True))

    def test_every_required_phase_check_must_be_present_and_true(self):
        for phase, required in PHASES.items():
            for name in required:
                for missing in (False, True):
                    with self.subTest(phase=phase, check=name, missing=missing):
                        raw = self.fixture(phase)
                        if missing:
                            del raw['checks'][name]
                        else:
                            raw['checks'][name] = False
                        report, passed = self.screen(phase, raw)
                        self.assertFalse(passed)
                        self.assertEqual(report['status'], 'failure')

    def test_unknown_fields_and_private_payloads_never_cross_report_boundary(self):
        for phase in PHASES:
            for mutate in (
                lambda raw:raw.update(raw_output=PRIVATE),
                lambda raw:raw.update(secret=PRIVATE),
                lambda raw:raw['checks'].update(private_message=PRIVATE),
                lambda raw:raw.update(category=PRIVATE),
                lambda raw:raw.update(status=PRIVATE),
                lambda raw:raw.update(checks=PRIVATE),
            ):
                raw = self.fixture(phase)
                mutate(raw)
                with self.subTest(phase=phase, value=raw):
                    self.assertEqual(self.screen(phase, raw), (INVALID, False))

    def test_checks_require_boolean_values_and_counts_are_bounded_integers(self):
        for phase in PHASES:
            for value in (None, 0, 1, 'true', PRIVATE, [], {'secret':PRIVATE}):
                raw = self.fixture(phase)
                raw['checks'][next(iter(PHASES[phase]))] = value
                self.assertEqual(self.screen(phase, raw), (INVALID, False))
        for phase in ('inspect', 'apply', 'rollback', 'commit'):
            for count in (None, True, False, -1, 7, 1.0, '6', PRIVATE):
                raw = self.fixture(phase)
                raw['changed_file_count'] = count
                self.assertEqual(self.screen(phase, raw), (INVALID, False))
            for count in (0, 6):
                raw = self.fixture(phase)
                raw['changed_file_count'] = count
                self.assertTrue(self.screen(phase, raw)[1])

    def test_failure_or_incomplete_category_cannot_be_promoted_to_success(self):
        for status, category in (('failure', 'complete'), ('success', 'deployment'),
                                 ('failure', 'rollback')):
            raw = self.fixture('apply')
            raw.update(status=status, category=category)
            result, passed = self.screen('apply', raw)
            self.assertFalse(passed)
            self.assertEqual(result['status'], 'failure')
            self.assertEqual(result['category'], category)
        self.assertEqual(screening.screen('unsupported', self.path), (INVALID, False))

    def test_report_symlinks_hardlinks_fifos_oversize_and_malformed_json_refuse(self):
        self.path.write_text(json.dumps(self.fixture('verify')))
        link = self.directory / 'symlink.json'
        link.symlink_to(self.path)
        self.assertEqual(screening.screen('verify', link), (INVALID, False))
        hardlink = self.directory / 'hardlink.json'
        os.link(self.path, hardlink)
        self.assertEqual(screening.screen('verify', hardlink), (INVALID, False))
        hardlink.unlink()
        fifo = self.directory / 'fifo.json'
        os.mkfifo(fifo)
        self.assertEqual(screening.screen('verify', fifo), (INVALID, False))
        for data in (b' ' * 16385, b'{broken', b'[]', b'null', b'\xff'):
            self.path.write_bytes(data)
            self.assertEqual(screening.screen('verify', self.path), (INVALID, False))


class EmailReleaseRequestTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-email-request-')
        self.directory = Path(self.temporary.name)
        self.path = self.directory / 'release-request.json'
        self.request = {
            'format':'petish-email-release-v1',
            'staging_source_commit':'86e315f3daf172e1c9faee9a62d768b48f634bca',
            'staging_preview_branch':'email-preview/39383213374-1',
            'backup_branch':'full-site-backup/39990001-1',
            'backup_result_branch':'automation-status/backup-39990001-1',
            'recovery_result_branch':'automation-status/recovery-39990002-1',
        }
        now = datetime.now(timezone.utc)
        self.evidence = {
            'preview':{'status':'success', 'category':'complete',
                       'source_commit':self.request['staging_source_commit'],
                       'checks':{name:True for name in PREVIEW}},
            'backup':{'AUTOMATION_KIND':'backup', 'AUTOMATION_RESULT':'success',
                      'GITHUB_RUN_ID':'39990001', 'GITHUB_RUN_ATTEMPT':'1',
                      'generated_utc':(now - timedelta(minutes=1)).isoformat(),
                      'restore_check':{'restore_smoke_test_passed':True,
                                       'database_import_verified':True}},
            'recovery':{'AUTOMATION_KIND':'recovery', 'AUTOMATION_RESULT':'success',
                        'backup_branch':self.request['backup_branch'],
                        'recovery_check':{name:True for name in RECOVERY}},
        }

    def tearDown(self):
        self.temporary.cleanup()

    def validate(self):
        self.path.write_text(json.dumps(self.request))
        branches = {key:self.request[field] for key, field in (
            ('preview', 'staging_preview_branch'), ('backup', 'backup_result_branch'),
            ('recovery', 'recovery_result_branch'))}

        def report(branch, label):
            self.assertEqual(branch, branches[label])
            return deepcopy(self.evidence[label])

        def git(*arguments):
            self.assertEqual(arguments, ('ls-remote', '--exit-code', '--heads',
                                         'origin', self.request['backup_branch']))
            return ('a' * 40 + '\trefs/heads/' + self.request['backup_branch'] + '\n').encode()

        with patch.object(request_validation, 'report', side_effect=report), \
                patch.object(request_validation, 'git', side_effect=git):
            return request_validation.validate(self.path)

    def test_fresh_matching_backup_recovery_and_native_preview_allow_fixed_request_only(self):
        for record in self.evidence.values():
            record['unused_private_field'] = PRIVATE
        result = self.validate()
        self.assertEqual(result, {'verified':True, **{
            name:value for name, value in self.request.items() if name != 'format'}})
        self.assertEqual(len(result), 6)
        self.assertNotIn(PRIVATE, json.dumps(result))

    def test_invalid_request_schema_and_injected_branches_refuse_before_git(self):
        good = deepcopy(self.request)
        cases = []
        for key in good:
            bad = deepcopy(good)
            del bad[key]
            cases.append(bad)
        cases += [dict(good, private=PRIVATE), dict(good, format=PRIVATE),
                  dict(good, staging_source_commit='a' * 40), [], None]
        for field in ('staging_preview_branch', 'backup_branch', 'backup_result_branch',
                      'recovery_result_branch'):
            for value in (None, [], {}, 1, '../secret', 'main', '--upload-pack=evil',
                          good[field] + '\n' + PRIVATE, good[field] + ';echo secret',
                          good[field] + '/../../private', 'refs/heads/' + good[field]):
                cases.append(dict(good, **{field:value}))
        cases.append(dict(good, backup_result_branch='automation-status/backup-39990009-1'))
        for case in cases:
            with self.subTest(case=case), patch.object(request_validation, 'report') as report, \
                    patch.object(request_validation, 'git') as git:
                self.path.write_text(json.dumps(case))
                with self.assertRaises((ValueError, TypeError)):
                    request_validation.validate(self.path)
                report.assert_not_called()
                git.assert_not_called()

    def test_missing_or_false_native_preview_evidence_and_wrong_source_refuse(self):
        original = deepcopy(self.evidence['preview'])
        for name in PREVIEW:
            for missing in (False, True):
                self.evidence['preview'] = deepcopy(original)
                if missing:
                    del self.evidence['preview']['checks'][name]
                else:
                    self.evidence['preview']['checks'][name] = False
                with self.subTest(check=name, missing=missing), self.assertRaises(ValueError):
                    self.validate()
        for field, value in (('status','failure'), ('category','renderer'),
                             ('source_commit','a' * 40)):
            self.evidence['preview'] = deepcopy(original)
            self.evidence['preview'][field] = value
            with self.assertRaises(ValueError):
                self.validate()

    def test_backup_must_be_fresh_successful_restored_and_match_exact_requested_run(self):
        original = deepcopy(self.evidence['backup'])
        changes = [('AUTOMATION_KIND','recovery'), ('AUTOMATION_RESULT','failure'),
                   ('GITHUB_RUN_ID','39990009'), ('GITHUB_RUN_ATTEMPT','2')]
        now = datetime.now(timezone.utc)
        changes += [('generated_utc',(now - timedelta(hours=7)).isoformat()),
                    ('generated_utc',(now + timedelta(minutes=10)).isoformat()),
                    ('generated_utc',now.replace(tzinfo=None).isoformat()),
                    ('generated_utc','invalid')]
        for field, value in changes:
            self.evidence['backup'] = deepcopy(original)
            self.evidence['backup'][field] = value
            with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                self.validate()
        for name in ('restore_smoke_test_passed', 'database_import_verified'):
            self.evidence['backup'] = deepcopy(original)
            self.evidence['backup']['restore_check'][name] = False
            with self.assertRaises(ValueError):
                self.validate()

    def test_backup_branch_must_exist_with_one_exact_git_object_reference(self):
        self.path.write_text(json.dumps(self.request))
        for output in (b'', b'not-a-hash\trefs/heads/full-site-backup/39990001-1\n',
                       ('a' * 40 + '\trefs/heads/full-site-backup/39990009-1\n').encode(),
                       ('a' * 40 + '\trefs/heads/full-site-backup/39990001-1\n' + PRIVATE).encode()):
            with patch.object(request_validation, 'report', side_effect=lambda branch, label:deepcopy(self.evidence[label])), \
                    patch.object(request_validation, 'git', return_value=output), self.assertRaises(ValueError):
                request_validation.validate(self.path)

    def test_recovery_requires_the_same_backup_and_each_native_application_check(self):
        original = deepcopy(self.evidence['recovery'])
        for field, value in (('AUTOMATION_KIND','backup'), ('AUTOMATION_RESULT','failure'),
                             ('backup_branch','full-site-backup/39990009-1')):
            self.evidence['recovery'] = deepcopy(original)
            self.evidence['recovery'][field] = value
            with self.assertRaises(ValueError):
                self.validate()
        for name in RECOVERY:
            for missing in (False, True):
                self.evidence['recovery'] = deepcopy(original)
                if missing:
                    del self.evidence['recovery']['recovery_check'][name]
                else:
                    self.evidence['recovery']['recovery_check'][name] = False
                with self.subTest(check=name, missing=missing), self.assertRaises(ValueError):
                    self.validate()

    def test_request_symlink_hardlink_fifo_oversize_and_malformed_json_refuse_before_git(self):
        self.path.write_text(json.dumps(self.request))
        link = self.directory / 'symlink.json'
        link.symlink_to(self.path)
        hardlink = self.directory / 'hardlink.json'
        os.link(self.path, hardlink)
        fifo = self.directory / 'fifo.json'
        os.mkfifo(fifo)
        for path in (link, hardlink, fifo):
            with patch.object(request_validation, 'report') as report, \
                    patch.object(request_validation, 'git') as git, self.assertRaises(ValueError):
                request_validation.validate(path)
            report.assert_not_called()
            git.assert_not_called()
        hardlink.unlink()
        for value in (b' ' * 4097, b'{broken', b'\xff'):
            self.path.write_bytes(value)
            with patch.object(request_validation, 'report') as report, \
                    patch.object(request_validation, 'git') as git, self.assertRaises(ValueError):
                request_validation.validate(self.path)
            report.assert_not_called()
            git.assert_not_called()


if __name__ == '__main__':
    unittest.main()
