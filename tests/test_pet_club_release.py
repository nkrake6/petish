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
import os
import io
import tarfile
import shutil
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
        for phase in ('inspect', 'apply', 'native', 'fixture', 'browser', 'cleanup', 'diagnose', 'verify', 'assets', 'health', 'rollback', 'commit'):
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

    def test_diagnosis_allows_finite_observations_but_never_option_names_or_hashes(self):
        value = self.raw('diagnose')
        value['checks'].update(dict.fromkeys(safe.DIAGNOSTIC_OBSERVATIONS, False))
        value['checks']['single_added_option_identified'] = True
        value['checks']['added_option_runtime_transient'] = True
        self.assertTrue(self.screen('diagnose', value)[1])
        for field in ('option_name', 'value_state', 'settings_digest'):
            modified = copy.deepcopy(value)
            modified[field] = 'private-sentinel'
            report, passed = self.screen('diagnose', modified)
            self.assertFalse(passed)
            self.assertNotIn('private-sentinel', json.dumps(report))

    def test_only_diagnosis_accepts_bounded_structural_option_names(self):
        valid = ['_litespeed_cache', 'software_feature_enabled', 'woocommerce_admin_report_users_update']
        value = self.raw('diagnose')
        value.update(option_names=valid, option_name_prefixes=['_woocommerce_', 'petish_'])
        value['checks']['option_names_code_literals_verified'] = True
        report, passed = self.screen('diagnose', value)
        self.assertTrue(passed)
        self.assertEqual(report, value)
        unproven = copy.deepcopy(value)
        unproven['checks']['option_names_code_literals_verified'] = False
        self.assertFalse(self.screen('diagnose', unproven)[1])
        del unproven['checks']['option_names_code_literals_verified']
        self.assertFalse(self.screen('diagnose', unproven)[1])
        for invalid in ([valid[0]] * 2, list(reversed(valid)), ['customer_email@example.com'],
                ['setting_12345'], ['../private_key'], ['cache_abcdefabcdef'], ['Upper_case'],
                ['software_' + 'z' * 25], ['simple'], [False], 'software_setting',
                ['software_' + chr(97 + i) for i in range(13)]):
            with self.subTest(invalid=invalid):
                modified = {**value, 'option_names': invalid}
                self.assertFalse(self.screen('diagnose', modified)[1])
        for invalid in (['customer_'], ['petish_', 'petish_'], ['wp_', '_wp_'], [False], 'wp_'):
            self.assertFalse(self.screen('diagnose', {**value, 'option_name_prefixes': invalid})[1])
        self.assertFalse(self.screen('native', {**self.raw('native'), 'option_names': valid})[1])

    def test_structural_option_name_validation_matches_native_filter(self):
        php = shutil.which('php') or '/workspace/.petish-dev/bin/php'
        if not Path(php).is_file():
            self.skipTest('PHP required')
        cases = ['software_feature', '_software_feature', 'nonce_key', 'cache-flag', 'setting_123',
                 'name@example.com', '../private_key', 'cache_abcdefabcdef', 'cache_' + 'z' * 25,
                 '_cache__flag', 'simple', 'Upper_case', 'a_b', 'a_' + 'z_' * 15 + 'z',
                 'a_' + 'z_' * 16 + 'z', False, None]
        code = "define('PETISH_CLUB_VERIFY_LIBRARY',true); require $argv[1]; " \
               "echo json_encode(array_map('petish_club_verify_safe_option_name',json_decode(stream_get_contents(STDIN),true)));"
        result = subprocess.run([php, '-r', code, str(ROOT / 'scripts/verify-pet-club.php')],
                                input=json.dumps(cases).encode(), capture_output=True, check=True)
        self.assertEqual(json.loads(result.stdout), [safe.safe_option_name(value) for value in cases])

    def test_recovery_request_is_bounded_and_has_no_secret_or_path_fields(self):
        wrapper = (ROOT / 'scripts/hostinger-pet-club.sh').read_text()
        script = wrapper.split("<<'PY_RECOVERY'", 1)[1].split('\n', 1)[1].split('\nPY_RECOVERY', 1)[0]
        operations = self.directory / 'operations'
        operations.mkdir()
        request = operations / 'pet-club-cleanup.json'
        valid = {'format': 'petish-pet-club-cleanup-v1', 'run_id': '39513159248-1', 'operation': 'diagnose'}
        for value, allowed in [(valid, True), ({**valid, 'operation': 'cleanup'}, True),
                ({**valid, 'run_id': '../../public_html'}, False), ({**valid, 'operation': 'prepare'}, False),
                ({**valid, 'password': 'private-sentinel'}, False),
                ({**valid, 'encrypted_option_names': True}, True),
                ({**valid, 'encrypted_option_names': 'true'}, False),
                ({**valid, 'operation': 'cleanup', 'encrypted_option_names': True}, False), ({**valid, 'run_id': 42}, False)]:
            request.write_text(json.dumps(value))
            result = subprocess.run(['python3', '-c', script], cwd=self.directory, capture_output=True)
            self.assertEqual(result.returncode == 0, allowed)
            self.assertNotIn(b'private-sentinel', result.stdout)

    def test_recovery_replaces_only_reviewed_helpers_after_old_ownership_checks(self):
        wrapper = (ROOT / 'scripts/hostinger-pet-club.sh').read_text()
        start = wrapper.index('    "${petish_ssh[@]}"', wrapper.index('# Do not mark SSH cleanup-ready'))
        end = wrapper.index('\n    if [[ $recovery_operation == cleanup ]]; then', start)
        command = wrapper[start:end]
        for scenario in ('success', 'success_private', 'wrong_marker', 'linked_journal', 'tampered_payload', 'tampered_private', 'tampered_guard'):
            with self.subTest(scenario=scenario):
                home = self.directory / scenario
                home.mkdir()
                work = home / 'work'
                work.mkdir()
                tools = home / '.petish-pet-club-deploy-39513159248-1'
                tools.mkdir(mode=0o700)
                preserved = {'pet-club-native-state.json': b'private-journal-sentinel',
                             'payload/manifest.json': b'private-payload-sentinel'}
                for name, body in {'.pet-club-owned': b'39513159248-1\n',
                        'verify-pet-club.php': b'old-verifier', 'pet-club-fixture.php': b'old-fixture', **preserved}.items():
                    path = tools / name
                    path.parent.mkdir(exist_ok=True, mode=0o700)
                    path.write_bytes(body)
                    path.chmod(0o600)
                if scenario == 'wrong_marker':
                    (tools / '.pet-club-owned').write_text('different-run')
                if scenario == 'linked_journal':
                    target = tools / 'pet-club-native-state.json'
                    target.unlink()
                    target.symlink_to(home / 'outside-state')
                candidate = {'verify-pet-club.php': b'new-reviewed-verifier',
                             'pet-club-fixture.php': b'new-reviewed-fixture',
                             'pet-club-jetpack-guard.php': b'new-reviewed-guard'}
                private = scenario in {'success_private', 'tampered_private'}
                if private:
                    candidate['pet-club-private-option-names.php'] = b'new-reviewed-extractor'
                with tarfile.open(work / 'recovery.tar', 'w') as archive:
                    for name, body in candidate.items():
                        payload = body + (b'tampered' if (scenario == 'tampered_payload' and name == 'pet-club-fixture.php')
                            or (scenario == 'tampered_private' and name == 'pet-club-private-option-names.php')
                            or (scenario == 'tampered_guard' and name == 'pet-club-jetpack-guard.php') else b'')
                        member = tarfile.TarInfo(name)
                        member.size = len(payload)
                        member.mode = 0o600
                        archive.addfile(member, io.BytesIO(payload))
                preamble = 'work=$1; target_run=39513159248-1; run=99999-1; verify_digest=$2; fixture_digest=$3; encrypted_option_names=$4; private_digest=$5; guard_digest=$6; petish_ssh=(bash -c)\n'
                environment = os.environ.copy()
                environment['HOME'] = str(home)
                result = subprocess.run(['bash', '-euc', preamble + command, 'fixture', str(work),
                    hashlib.sha256(candidate['verify-pet-club.php']).hexdigest(),
                    hashlib.sha256(candidate['pet-club-fixture.php']).hexdigest(), str(int(private)),
                    hashlib.sha256(candidate.get('pet-club-private-option-names.php', b'')).hexdigest(),
                    hashlib.sha256(candidate['pet-club-jetpack-guard.php']).hexdigest()], env=environment, capture_output=True)
                success = scenario in {'success', 'success_private'}
                self.assertEqual(result.returncode == 0, success)
                for name, body in candidate.items():
                    if name in {'pet-club-private-option-names.php', 'pet-club-jetpack-guard.php'} and not success:
                        self.assertFalse((tools / name).exists())
                        continue
                    self.assertEqual((tools / name).read_bytes(), body if success
                                     else b'old-verifier' if name == 'verify-pet-club.php' else b'old-fixture')
                for name, body in preserved.items():
                    path = tools / name
                    if not path.is_symlink():
                        self.assertEqual(path.read_bytes(), body)
                if success:
                    self.assertEqual((tools / 'recovery-99999-1/original-verify-pet-club.php').read_bytes(), b'old-verifier')

    def test_finish_retains_remote_recovery_tools_until_cleanup_and_rollback_pass(self):
        source = (ROOT / 'scripts/hostinger-pet-club.sh').read_text()
        start = source.index('finish() {')
        end = source.index('\ntrap finish EXIT', start)
        function = source[start:end]
        for scenario, rollback, fixture, expected in (
                ('failed_rollback', 1, 0, ['rollback', 'publish:failure:rollback']),
                ('failed_fixture', 0, 1, ['fixture_cleanup', 'fixture_diagnose', 'publish:failure:cleanup']),
                ('successful_rollback', 1, 0, ['rollback', 'remote_cleanup', 'publish:failure:prerequisites']),
                ('successful_fixture', 0, 1, ['fixture_cleanup', 'stage_source', 'remote_cleanup', 'publish:success:complete']),
                ('stale_staging_source', 0, 1, ['fixture_cleanup', 'stage_source', 'remote_cleanup', 'publish:failure:staging_source']),
                ('diagnostic_only', 0, 0, ['publish:success:complete'])):
            with self.subTest(scenario=scenario):
                work = self.directory / scenario
                work.mkdir()
                log = self.directory / (scenario + '.log')
                script = '\n'.join([
                    'work=$1; log=$2; scenario=$3; rollback_needed=$4; fixture_cleanup_needed=$5',
                    'mode=staging; ssh_ready=1; fixture_cleanup_verified=0; operation_verified=1; failure_category=prerequisites',
                    'retain_remote_tools=0; if [[ "$scenario" == diagnostic_only ]]; then retain_remote_tools=1; fi',
                    'remote_phase() { printf "rollback\\n" >> "$log"; [[ "$scenario" != failed_rollback ]]; }',
                    'fixture_phase() { printf "fixture_%s\\n" "$1" >> "$log"; [[ "$scenario" != failed_fixture ]]; }',
                    'verify_staging_source() { printf "stage_source\\n" >> "$log"; [[ "$scenario" != stale_staging_source ]]; }',
                    'remote_cleanup() { printf "remote_cleanup\\n" >> "$log"; }',
                    'publish_result() { printf "publish:%s:%s\\n" "$result_status" "$failure_category" >> "$log"; }',
                    function, 'true', 'finish',
                ])
                completed = subprocess.run(['bash', '-c', script, 'fixture', str(work), str(log),
                                            scenario, str(rollback), str(fixture)], capture_output=True)
                self.assertEqual(log.read_text().splitlines(), expected)
                self.assertEqual(completed.returncode, 0 if scenario in {'successful_fixture', 'diagnostic_only'} else 1)
                self.assertFalse(work.exists())

    def test_sanitizer_contract_matches_native_constants(self):
        source = (ROOT / 'scripts/verify-pet-club.php').read_text()
        def names(name):
            match = re.search(r'const ' + name + r' = \[(.*?)\];', source, re.S)
            self.assertIsNotNone(match)
            return set(re.findall(r"'([A-Za-z0-9_.-]+)'", match.group(1)))
        common = names('PETISH_CLUB_VERIFY_COMMON')
        self.assertEqual(safe.STAGING, common | names('PETISH_CLUB_VERIFY_STAGING'))
        self.assertEqual(safe.VERIFY, common | names('PETISH_CLUB_VERIFY_PRODUCTION'))
        self.assertEqual(safe.FIXTURE, names('PETISH_CLUB_FIXTURE_PREPARE'))
        self.assertEqual(safe.CLEANUP, names('PETISH_CLUB_FIXTURE_CLEANUP'))
        self.assertEqual(safe.DIAGNOSE, names('PETISH_CLUB_FIXTURE_DIAGNOSE'))
        known = {'changed_known_option_' + key.replace('.', '_').replace('-', '_')
                 for key in names('PETISH_CLUB_KNOWN_OPTION_KEYS')}
        self.assertEqual(safe.DIAGNOSTIC_OBSERVATIONS, names('PETISH_CLUB_DIAGNOSTIC_OBSERVATIONS') | known)
        self.assertEqual(safe.OPTION_PREFIXES, names('PETISH_CLUB_OPTION_PREFIXES'))
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
