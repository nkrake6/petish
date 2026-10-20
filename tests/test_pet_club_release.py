"""Finite release reports and evidence validation; fixtures make no network calls."""
import copy
from datetime import datetime, timezone, timedelta
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


def module(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / (name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


safe = module('sanitize-pet-club-result')
evidence = module('validate-pet-club-release')


class PetClubReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='petish-pet-club-proof-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)

    def raw(self, phase):
        value = {'status': 'success', 'category': 'complete',
                 'checks': dict.fromkeys(safe.REQUIRED[phase], True)}
        if phase in {'inspect', 'apply', 'rollback', 'commit'}:
            value['changed_file_count'] = len(evidence.FILES)
        return value

    def screen(self, phase, value):
        path = self.directory / 'raw.json'
        path.write_text(json.dumps(value))
        return safe.screen(phase, path)

    def test_finite_complete_phases_pass(self):
        for phase in ('inspect', 'apply', 'native', 'fixture', 'browser', 'cleanup', 'verify', 'assets', 'health', 'rollback', 'commit'):
            value = self.raw(phase)
            report, passed = self.screen(phase, value)
            self.assertTrue(passed)
            self.assertEqual(report, value)

    def test_private_values_and_unknown_fields_never_reach_public_report(self):
        bad = []
        value = self.raw('verify')
        value['pet_name'] = 'private-sentinel'
        bad.append(value)
        value = self.raw('verify')
        value['checks']['private_photo_reference'] = 'private-sentinel'
        bad.append(value)
        value = self.raw('verify')
        value['checks']['private_owner_access_verified'] = 1
        bad.append(value)
        for value in bad:
            result, passed = self.screen('verify', value)
            self.assertFalse(passed)
            self.assertEqual(result['category'], 'invalid_report')
            self.assertNotIn('private-sentinel', json.dumps(result))

    def test_missing_or_failed_checks_do_not_pass_and_counts_are_bounded(self):
        value = self.raw('verify')
        value['checks']['orders_unchanged'] = False
        self.assertFalse(self.screen('verify', value)[1])
        del value['checks']['orders_unchanged']
        self.assertFalse(self.screen('verify', value)[1])
        value = self.raw('apply')
        for count in (len(evidence.FILES) + 1, -1, True, '14'):
            value['changed_file_count'] = count
            self.assertFalse(self.screen('apply', value)[1])

    def fixture(self):
        revision = 'a' * 40
        request = {
            'format': 'petish-pet-club-v1', 'staging_source_commit': revision,
            'staging_status_branch': 'automation-status/pet-club-12344-1',
            'backup_branch': 'full-site-backup/12345-1',
            'backup_result_branch': 'automation-status/backup-12345-1',
            'recovery_result_branch': 'automation-status/recovery-12346-1',
        }
        stage = {
            'status': 'success', 'category': 'complete', 'mode': 'staging',
            'staging_source_verified': True, 'source_commit': revision,
            'source_hashes': {name: hashlib.sha256((ROOT / (evidence.PREFIX + name)).read_bytes()).hexdigest()
                              for name in evidence.FILES},
            'phases': {phase: self.raw(phase) for phase in ('preflight', 'native', 'fixture', 'browser', 'cleanup')},
        }
        backup = {
            'AUTOMATION_KIND': 'backup', 'AUTOMATION_RESULT': 'success',
            'GITHUB_RUN_ID': '12345', 'GITHUB_RUN_ATTEMPT': '1',
            'generated_utc': datetime.now(timezone.utc).isoformat(),
            'restore_check': {'restore_smoke_test_passed': True, 'database_import_verified': True, 'private_passport_root_restored': True},
        }
        recovered = {'wordpress_application_recovery_passed', 'database_import_verified',
                     'wordpress_bootstrap_verified', 'homepage_http_verified', 'shop_http_verified',
                     'product_http_verified', 'cart_http_verified', 'account_http_verified',
                     'private_photo_references_verified', 'anonymous_private_photo_denied', 'application_outbound_http_blocked',
                     'application_email_blocked', 'application_container_removed', 'private_sandbox_removed'}
        recovery = {
            'AUTOMATION_KIND': 'recovery', 'AUTOMATION_RESULT': 'success',
            'backup_branch': request['backup_branch'], 'recovery_check': dict.fromkeys(recovered, True),
        }
        reports = {
            request['staging_status_branch']: stage,
            request['backup_result_branch']: backup,
            request['recovery_result_branch']: recovery,
        }
        original_report = evidence.evidence.report
        original_git = evidence.evidence.git
        self.addCleanup(setattr, evidence.evidence, 'report', original_report)
        self.addCleanup(setattr, evidence.evidence, 'git', original_git)
        evidence.evidence.report = lambda branch, label: reports[branch]

        def git(*arguments):
            if arguments[0] == 'fetch':
                return b''
            if arguments[0] == 'ls-remote':
                return (revision + '\trefs/heads/' + request['backup_branch'] + '\n').encode()
            if arguments[0] == 'show':
                return (ROOT / arguments[1].split(':', 1)[1]).read_bytes()
            raise AssertionError('Unexpected evidence command')

        evidence.evidence.git = git
        path = self.directory / 'request.json'
        path.write_text(json.dumps(request))
        return path, request, stage, backup, recovery

    def test_matching_native_and_backup_evidence_passes(self):
        path, request, _, _, _ = self.fixture()
        self.assertEqual(evidence.validate(path),
                         {'verified': True, **{key: value for key, value in request.items() if key != 'format'}})

    def test_wrong_source_or_unverified_native_phase_refuses(self):
        path, _, stage, _, _ = self.fixture()
        original = copy.deepcopy(stage)
        stage['source_commit'] = 'b' * 40
        with self.assertRaises(ValueError):
            evidence.validate(path)
        stage.clear()
        stage.update(original)
        stage['phases']['native']['checks']['private_media_owner_scoped'] = False
        with self.assertRaises(ValueError):
            evidence.validate(path)
        stage['phases']['native']['checks']['private_media_owner_scoped'] = True
        stage['source_hashes'][evidence.FILES[0]] = 'b' * 64
        with self.assertRaises(ValueError):
            evidence.validate(path)

    def test_each_native_browser_fixture_and_cleanup_phase_is_required(self):
        path, _, stage, _, _ = self.fixture()
        original = copy.deepcopy(stage)
        for phase in ('preflight', 'native', 'fixture', 'browser', 'cleanup'):
            with self.subTest(phase=phase):
                stage.clear()
                stage.update(copy.deepcopy(original))
                del stage['phases'][phase]
                with self.assertRaises(ValueError):
                    evidence.validate(path)
                stage.update(copy.deepcopy(original))
                stage['phases'][phase]['checks'][next(iter(safe.REQUIRED[phase]))] = False
                with self.assertRaises(ValueError):
                    evidence.validate(path)

    def test_private_fields_in_evidence_or_unknown_checks_are_rejected(self):
        path, _, stage, _, _ = self.fixture()
        stage['phases']['native']['checks']['customer_email'] = 'private-sentinel'
        with self.assertRaises(ValueError):
            evidence.validate(path)
        del stage['phases']['native']['checks']['customer_email']
        stage['phases']['browser']['customer_photo'] = 'private-sentinel'
        with self.assertRaises(ValueError):
            evidence.validate(path)

    def test_finish_retains_remote_recovery_tools_until_cleanup_and_rollback_pass(self):
        source = (ROOT / 'scripts/hostinger-pet-club.sh').read_text()
        start = source.index('finish() {')
        end = source.index('\ntrap finish EXIT', start)
        function = source[start:end]
        for scenario, rollback, fixture, expected in (
                ('failed_rollback', 1, 0, ['rollback', 'publish:failure:rollback']),
                ('failed_fixture', 0, 1, ['fixture_cleanup', 'publish:failure:cleanup']),
                ('successful_rollback', 1, 0, ['rollback', 'remote_cleanup', 'publish:failure:prerequisites']),
                ('successful_fixture', 0, 1, ['fixture_cleanup', 'stage_source', 'remote_cleanup', 'publish:success:complete']),
                ('stale_staging_source', 0, 1, ['fixture_cleanup', 'stage_source', 'remote_cleanup', 'publish:failure:staging_source'])):
            with self.subTest(scenario=scenario):
                work = self.directory / scenario
                work.mkdir()
                log = self.directory / (scenario + '.log')
                script = '\n'.join([
                    'work=$1; log=$2; scenario=$3; rollback_needed=$4; fixture_cleanup_needed=$5',
                    'mode=staging; ssh_ready=1; fixture_cleanup_verified=0; operation_verified=1; failure_category=prerequisites',
                    'remote_phase() { printf "rollback\\n" >> "$log"; [[ "$scenario" != failed_rollback ]]; }',
                    'fixture_phase() { printf "fixture_cleanup\\n" >> "$log"; [[ "$scenario" != failed_fixture ]]; }',
                    'verify_staging_source() { printf "stage_source\\n" >> "$log"; [[ "$scenario" != stale_staging_source ]]; }',
                    'remote_cleanup() { printf "remote_cleanup\\n" >> "$log"; }',
                    'publish_result() { printf "publish:%s:%s\\n" "$result_status" "$failure_category" >> "$log"; }',
                    function, 'true', 'finish',
                ])
                completed = subprocess.run(['bash', '-c', script, 'fixture', str(work), str(log),
                                            scenario, str(rollback), str(fixture)], capture_output=True)
                self.assertEqual(log.read_text().splitlines(), expected)
                self.assertEqual(completed.returncode, 0 if scenario == 'successful_fixture' else 1)
                self.assertFalse(work.exists())

    def test_sanitizer_contract_matches_native_constants(self):
        source = (ROOT / 'scripts/verify-pet-club.php').read_text()
        def names(name):
            match = re.search(r'const ' + name + r' = \[(.*?)\];', source, re.S)
            self.assertIsNotNone(match)
            return set(re.findall(r"'([a-z0-9_]+)'", match.group(1)))
        common = names('PETISH_CLUB_VERIFY_COMMON')
        self.assertEqual(safe.STAGING, common | names('PETISH_CLUB_VERIFY_STAGING'))
        self.assertEqual(safe.VERIFY, common | names('PETISH_CLUB_VERIFY_PRODUCTION'))
        self.assertEqual(safe.FIXTURE, names('PETISH_CLUB_FIXTURE_PREPARE'))
        self.assertEqual(safe.CLEANUP, names('PETISH_CLUB_FIXTURE_CLEANUP'))
        self.assertEqual(safe.FILE_COUNT, len(evidence.FILES))
        browser = (ROOT / 'tools/pet-club/browser.mjs').read_text()
        contract = re.search(r'CHECK_KEYS = Object.freeze\(\[(.*?)\]\)', browser, re.S)
        self.assertIsNotNone(contract)
        self.assertEqual(safe.BROWSER, set(re.findall(r"'([a-z0-9_]+)'", contract.group(1))))

    def test_expired_backup_or_unproven_recovery_refuses(self):
        path, _, _, backup, recovery = self.fixture()
        backup['generated_utc'] = (datetime.now(timezone.utc) - timedelta(hours=7)).isoformat()
        with self.assertRaises(ValueError):
            evidence.validate(path)
        backup['generated_utc'] = datetime.now(timezone.utc).isoformat()
        recovery['recovery_check']['shop_http_verified'] = False
        with self.assertRaises(ValueError):
            evidence.validate(path)

    def test_request_cannot_change_scope_or_include_private_material(self):
        path, request, _, _, _ = self.fixture()
        for key, value in [('private_reference', 'private-sentinel'), ('format', 'different-release'),
                           ('staging_status_branch', '../other-branch')]:
            modified = {**request, key: value}
            path.write_text(json.dumps(modified))
            with self.assertRaises(ValueError):
                evidence.validate(path)


if __name__ == '__main__':
    unittest.main()
