"""Navigation releases require bounded public evidence, matching code and recovery."""
from copy import deepcopy
from datetime import datetime, timedelta, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]


def module(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / (name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


screening = module('sanitize-account-navigation-result')
release = module('validate-account-navigation-release')
PRIVATE = 'PRIVATE_SENTINEL_PASSWORD_AND_CUSTOMER_DATA_901782'
INVALID = {'status':'failure', 'category':'invalid_report', 'checks':{}}
BROWSER = {
    'browser_started', 'customer_login_verified', 'native_navigation_links_present',
    'native_hrefs_unchanged', 'my_pets_390_verified', 'my_pets_320_verified',
    'orders_390_verified', 'orders_320_verified', 'edit_account_390_verified',
    'edit_account_320_verified', 'current_label_verified', 'mobile_compact_closed',
    'toggle_opens', 'escape_closes_focus_returns', 'outside_closes', 'focusout_closes',
    'desktop_visible', 'resize_visible', 'no_js_fallback_visible', 'dark_mode_readable',
    'unsafe_requests_blocked',
}
RECOVERY = {
    'wordpress_application_recovery_passed', 'database_import_verified',
    'wordpress_bootstrap_verified', 'homepage_http_verified', 'shop_http_verified',
    'product_http_verified', 'cart_http_verified', 'account_http_verified',
}


class AccountNavigationReportTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-navigation-report-')
        self.directory = Path(self.temporary.name)
        self.path = self.directory / 'private.json'

    def tearDown(self):
        self.temporary.cleanup()

    def fixture(self, phase):
        raw = {'status':'success', 'category':'complete',
               'checks':{name:True for name in screening.REQUIRED[phase]}}
        if phase in {'inspect','apply','commit','rollback'}:
            raw['changed_file_count'] = 3
        if phase == 'preflight':
            raw['checks']['account_is_live'] = False
            raw['phase'] = 'preflight'
        return raw

    def screen(self, phase, raw):
        self.path.write_text(json.dumps(raw))
        result, passed = screening.screen(phase, self.path)
        self.assertNotIn(PRIVATE, json.dumps(result))
        return result, passed

    def test_every_defined_phase_passes_only_with_its_required_checks(self):
        self.assertEqual(screening.BROWSER, BROWSER)
        for phase, required in screening.REQUIRED.items():
            raw = self.fixture(phase)
            result, passed = self.screen(phase, raw)
            if required:
                self.assertTrue(passed, phase)
                self.assertEqual(result['status'], 'success')
            else:
                # An unfinished native-verifier contract must remain fail closed.
                self.assertFalse(passed, phase)

    def test_missing_or_false_browser_checks_cannot_pass(self):
        for name in BROWSER:
            for missing in (False, True):
                raw = self.fixture('browser')
                if missing:
                    del raw['checks'][name]
                else:
                    raw['checks'][name] = False
                with self.subTest(check=name, missing=missing):
                    result, passed = self.screen('browser', raw)
                    self.assertFalse(passed)
                    self.assertEqual(result['status'], 'failure')

    def test_credentials_unknown_names_and_nonboolean_flags_are_rejected(self):
        for phase in ('inspect','apply','browser','preflight','health'):
            mutations = [lambda raw:raw.update(password=PRIVATE),
                         lambda raw:raw.update(raw_output=PRIVATE),
                         lambda raw:raw['checks'].update(secret=PRIVATE),
                         lambda raw:raw['checks'].update(unknown_check=True),
                         lambda raw:raw.update(status=PRIVATE),
                         lambda raw:raw.update(category=PRIVATE)]
            key = next(iter(screening.REQUIRED[phase]))
            for value in (None, 0, 1, 'true', PRIVATE, [], {'password':PRIVATE}):
                mutations.append(lambda raw, value=value:raw['checks'].update({key:value}))
            for mutate in mutations:
                raw = self.fixture(phase)
                mutate(raw)
                with self.subTest(phase=phase, raw=raw):
                    self.assertEqual(self.screen(phase, raw), (INVALID, False))

    def test_release_changes_are_limited_to_three_files_with_integer_count(self):
        for phase in ('inspect','apply','commit','rollback'):
            for count in (-1, 4, 6, True, False, None, '3', 3.0, PRIVATE):
                raw = self.fixture(phase)
                raw['changed_file_count'] = count
                self.assertEqual(self.screen(phase, raw), (INVALID, False))
            for count in (0, 3):
                raw = self.fixture(phase)
                raw['changed_file_count'] = count
                self.assertTrue(self.screen(phase, raw)[1])

    def test_browser_diagnostics_accept_only_finite_stages_and_boolean_flags(self):
        raw = self.fixture('browser')
        raw.update(failure_stage='complete', diagnostics={name:False for name in screening.BROWSER_DIAGNOSTICS})
        self.assertTrue(self.screen('browser',raw)[1])
        for mutation in ('stage','unknown','value'):
            bad = deepcopy(raw)
            if mutation == 'stage':
                bad['failure_stage'] = PRIVATE
            elif mutation == 'unknown':
                bad['diagnostics']['password'] = PRIVATE
            else:
                bad['diagnostics']['qa_email_matches'] = PRIVATE
            self.assertEqual(self.screen('browser',bad),(INVALID,False))

    def test_preflight_requires_nonlive_account_and_each_native_isolation_barrier(self):
        raw = self.fixture('preflight')
        for value in (True, None, 0, PRIVATE):
            raw['checks']['account_is_live'] = value
            self.assertEqual(self.screen('preflight', raw), (INVALID, False))
        for name in screening.PREFLIGHT:
            raw = self.fixture('preflight')
            raw['checks'][name] = False
            self.assertFalse(self.screen('preflight', raw)[1])

    def test_success_category_and_status_cannot_be_inferred_from_flags(self):
        for status, category in (('failure','complete'), ('success','browser'),
                                 ('failure','navigation')):
            raw = self.fixture('browser')
            raw.update(status=status, category=category)
            result, passed = self.screen('browser', raw)
            self.assertFalse(passed)
            self.assertEqual(result['status'], 'failure')

    def test_unsafe_files_and_malformed_or_oversize_reports_are_rejected(self):
        self.path.write_text(json.dumps(self.fixture('browser')))
        link = self.directory / 'symlink.json'
        link.symlink_to(self.path)
        hard = self.directory / 'hardlink.json'
        os.link(self.path, hard)
        fifo = self.directory / 'fifo.json'
        os.mkfifo(fifo)
        for path in (link, hard, fifo):
            self.assertEqual(screening.screen('browser', path), (INVALID, False))
        hard.unlink()
        for raw in (b' ' * 65537, b'{bad', b'[]', b'null', b'\xff'):
            self.path.write_bytes(raw)
            self.assertEqual(screening.screen('browser', self.path), (INVALID, False))


class AccountNavigationReleaseEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-navigation-evidence-')
        self.directory = Path(self.temporary.name)
        self.previous_cwd = Path.cwd()
        os.chdir(self.directory)
        self.path = self.directory / 'request.json'
        self.files = {name:('reviewed synthetic source for ' + name).encode() for name in release.FILES}
        for name, contents in self.files.items():
            path = self.directory / release.PREFIX / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(contents)
        self.request = {
            'format':'petish-account-navigation-v1', 'staging_source_commit':'a' * 40,
            'staging_status_branch':'automation-status/account-navigation-400001-1',
            'backup_branch':'full-site-backup/400002-1',
            'backup_result_branch':'automation-status/backup-400002-1',
            'recovery_result_branch':'automation-status/recovery-400003-1',
        }
        self.records = {
            'account-navigation-stage':{
                'status':'success', 'mode':'staging', 'source_commit':'a' * 40,
                'staging_source_verified':True,
                'source_hashes':{name:hashlib.sha256(contents).hexdigest() for name, contents in self.files.items()},
                'phases':{phase:{'status':'success','category':'complete',
                                'checks':{key:True for key in required}}
                          for phase, required in (('preflight',screening.PREFLIGHT), ('browser',BROWSER))},
            },
            'account-navigation-backup':{
                'AUTOMATION_KIND':'backup', 'AUTOMATION_RESULT':'success',
                'GITHUB_RUN_ID':'400002', 'GITHUB_RUN_ATTEMPT':'1',
                'generated_utc':(datetime.now(timezone.utc) - timedelta(minutes=1)).isoformat(),
                'restore_check':{'restore_smoke_test_passed':True,'database_import_verified':True},
            },
            'account-navigation-recovery':{
                'AUTOMATION_KIND':'recovery', 'AUTOMATION_RESULT':'success',
                'backup_branch':'full-site-backup/400002-1',
                'recovery_check':{name:True for name in RECOVERY},
            },
        }

    def tearDown(self):
        os.chdir(self.previous_cwd)
        self.temporary.cleanup()

    def report(self, branch, label):
        fields = {'account-navigation-stage':'staging_status_branch',
                  'account-navigation-backup':'backup_result_branch',
                  'account-navigation-recovery':'recovery_result_branch'}
        self.assertEqual(branch, self.request[fields[label]])
        return deepcopy(self.records[label])

    def git(self, *arguments):
        if arguments[0] == 'fetch':
            self.assertEqual(arguments, ('fetch','--no-tags','--depth=1','origin',self.request['staging_source_commit']))
            return b''
        if arguments[0] == 'show':
            prefix = self.request['staging_source_commit'] + ':' + release.PREFIX
            self.assertTrue(arguments[1].startswith(prefix))
            return self.files[arguments[1][len(prefix):]]
        self.assertEqual(arguments, ('ls-remote','--exit-code','--heads','origin',self.request['backup_branch']))
        # Actual Git uses a literal tab and newline, not printable escape sequences.
        return ('b' * 40 + '\trefs/heads/' + self.request['backup_branch'] + '\n').encode()

    def validate(self):
        self.path.write_text(json.dumps(self.request))
        with patch.object(release.evidence, 'report', side_effect=self.report), \
                patch.object(release.evidence, 'git', side_effect=self.git):
            return release.validate(self.path)

    def test_matching_three_file_hashes_staging_browser_and_recovery_evidence_pass(self):
        self.assertEqual(len(release.FILES), 3)
        for record in self.records.values():
            record['unused_private_field'] = PRIVATE
        result = self.validate()
        self.assertEqual(result, {'verified':True, **{key:value for key,value in self.request.items() if key != 'format'}})
        self.assertNotIn(PRIVATE, json.dumps(result))

    def test_invalid_schema_or_injected_branch_is_rejected_before_git(self):
        good = deepcopy(self.request)
        cases = [dict(good, secret=PRIVATE), dict(good, format=PRIVATE),
                 dict(good, staging_source_commit='main'),
                 dict(good, staging_source_commit='a' * 40 + '\n' + PRIVATE)]
        for field in good:
            raw = dict(good)
            del raw[field]
            cases.append(raw)
        for field in ('staging_status_branch','backup_branch','backup_result_branch','recovery_result_branch'):
            for value in ('main','../secret','--upload-pack=evil',good[field] + ';echo secret',
                          good[field] + '\n' + PRIVATE, None, 1, [], {}):
                cases.append(dict(good, **{field:value}))
        cases.append(dict(good, backup_result_branch='automation-status/backup-400099-1'))
        for raw in cases:
            self.path.write_text(json.dumps(raw))
            with self.subTest(raw=raw), patch.object(release.evidence,'report') as report, \
                    patch.object(release.evidence,'git') as git:
                with self.assertRaises((ValueError,TypeError)):
                    release.validate(self.path)
                report.assert_not_called()
                git.assert_not_called()

    def test_stage_result_mode_source_and_every_local_file_hash_must_match(self):
        original = deepcopy(self.records['account-navigation-stage'])
        for field, value in (('status','failure'), ('mode','production'), ('source_commit','c' * 40),
                             ('staging_source_verified',False)):
            self.records['account-navigation-stage'] = deepcopy(original)
            self.records['account-navigation-stage'][field] = value
            with self.assertRaises(ValueError):
                self.validate()
        for name in release.FILES:
            self.records['account-navigation-stage'] = deepcopy(original)
            self.records['account-navigation-stage']['source_hashes'][name] = 'c' * 64
            with self.assertRaises(ValueError):
                self.validate()
        self.records['account-navigation-stage'] = deepcopy(original)
        self.records['account-navigation-stage']['source_hashes']['extra.php'] = 'c' * 64
        with self.assertRaises(ValueError):
            self.validate()

    def test_browser_and_preflight_success_need_every_required_check(self):
        original = deepcopy(self.records['account-navigation-stage'])
        for phase, required in (('browser',BROWSER),('preflight',screening.PREFLIGHT)):
            self.records['account-navigation-stage'] = deepcopy(original)
            self.records['account-navigation-stage']['phases'][phase]['status'] = 'failure'
            with self.assertRaises(ValueError):
                self.validate()
            for key in required:
                for missing in (False, True):
                    self.records['account-navigation-stage'] = deepcopy(original)
                    flags = self.records['account-navigation-stage']['phases'][phase]['checks']
                    if missing:
                        del flags[key]
                    else:
                        flags[key] = False
                    with self.subTest(phase=phase, key=key, missing=missing), self.assertRaises(ValueError):
                        self.validate()

    def test_staging_commit_must_contain_the_exact_reviewed_file_bytes(self):
        self.path.write_text(json.dumps(self.request))
        for changed in release.FILES:
            def mismatched(*arguments):
                if arguments[0] == 'show' and arguments[1].endswith(release.PREFIX + changed):
                    return b'other source bytes'
                return self.git(*arguments)
            with self.subTest(file=changed), patch.object(release.evidence,'report',side_effect=self.report), \
                    patch.object(release.evidence,'git',side_effect=mismatched), self.assertRaises(ValueError):
                release.validate(self.path)

    def test_backup_must_be_recent_successful_restored_and_the_requested_run(self):
        original = deepcopy(self.records['account-navigation-backup'])
        changes = [('AUTOMATION_KIND','recovery'),('AUTOMATION_RESULT','failure'),
                   ('GITHUB_RUN_ID','400099'),('GITHUB_RUN_ATTEMPT','2'),
                   ('generated_utc',(datetime.now(timezone.utc) - timedelta(hours=7)).isoformat()),
                   ('generated_utc',(datetime.now(timezone.utc) + timedelta(minutes=10)).isoformat()),
                   ('generated_utc',datetime.now().isoformat())]
        for field, value in changes:
            self.records['account-navigation-backup'] = deepcopy(original)
            self.records['account-navigation-backup'][field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                self.validate()
        for name in ('restore_smoke_test_passed','database_import_verified'):
            self.records['account-navigation-backup'] = deepcopy(original)
            self.records['account-navigation-backup']['restore_check'][name] = False
            with self.assertRaises(ValueError):
                self.validate()

    def test_recovery_must_match_backup_and_pass_all_application_checks(self):
        original = deepcopy(self.records['account-navigation-recovery'])
        for field, value in (('AUTOMATION_KIND','backup'),('AUTOMATION_RESULT','failure'),
                             ('backup_branch','full-site-backup/400099-1')):
            self.records['account-navigation-recovery'] = deepcopy(original)
            self.records['account-navigation-recovery'][field] = value
            with self.assertRaises(ValueError):
                self.validate()
        for key in RECOVERY:
            self.records['account-navigation-recovery'] = deepcopy(original)
            self.records['account-navigation-recovery']['recovery_check'][key] = False
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.validate()

    def test_unsafe_or_oversize_request_files_do_not_reach_git(self):
        self.path.write_text(json.dumps(self.request))
        link = self.directory / 'symlink.json'
        link.symlink_to(self.path)
        hard = self.directory / 'hardlink.json'
        os.link(self.path, hard)
        fifo = self.directory / 'fifo.json'
        os.mkfifo(fifo)
        for path in (link, hard, fifo):
            with patch.object(release.evidence,'report') as report, \
                    patch.object(release.evidence,'git') as git, self.assertRaises(ValueError):
                release.validate(path)
            report.assert_not_called()
            git.assert_not_called()
        hard.unlink()
        for raw in (b' ' * 4097, b'{bad'):
            self.path.write_bytes(raw)
            with patch.object(release.evidence,'report') as report, \
                    patch.object(release.evidence,'git') as git, self.assertRaises(ValueError):
                release.validate(self.path)
            report.assert_not_called()
            git.assert_not_called()


if __name__ == '__main__':
    unittest.main()
