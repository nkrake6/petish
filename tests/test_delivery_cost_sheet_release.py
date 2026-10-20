"""Finite release reports and evidence validation; fixtures make no network calls."""
import copy
from datetime import datetime, timezone, timedelta
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


def module(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / (name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


safe = module('sanitize-delivery-cost-sheet-result')
evidence = module('validate-delivery-cost-sheet-release')


class DeliveryCostSheetReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='petish-cost-sheet-proof-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)

    def raw(self, phase):
        value = {'status': 'success', 'category': 'complete',
                 'checks': dict.fromkeys(safe.REQUIRED[phase], True)}
        if phase in {'inspect', 'apply', 'rollback', 'commit'}:
            value['changed_file_count'] = 3
        return value

    def screen(self, phase, value):
        path = self.directory / 'raw.json'
        path.write_text(json.dumps(value))
        return safe.screen(phase, path)

    def test_finite_complete_phases_pass(self):
        for phase in ('inspect', 'apply', 'verify', 'health', 'rollback', 'commit'):
            value = self.raw(phase)
            report, passed = self.screen(phase, value)
            self.assertTrue(passed)
            self.assertEqual(report, value)

    def test_private_values_and_unknown_fields_never_reach_public_report(self):
        bad = []
        value = self.raw('verify')
        value['supplier_reference'] = 'private-sentinel'
        bad.append(value)
        value = self.raw('verify')
        value['checks']['supplier_unit_cost'] = 'private-sentinel'
        bad.append(value)
        value = self.raw('verify')
        value['checks']['known_calculation_verified'] = 1
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
        for count in (4, -1, True, '3'):
            value['changed_file_count'] = count
            self.assertFalse(self.screen('apply', value)[1])

    def fixture(self):
        revision = 'a' * 40
        request = {
            'format': 'petish-delivery-cost-sheet-v1', 'staging_source_commit': revision,
            'staging_status_branch': 'automation-status/delivery-cost-sheet-12344-1',
            'backup_branch': 'full-site-backup/12345-1',
            'backup_result_branch': 'automation-status/backup-12345-1',
            'recovery_result_branch': 'automation-status/recovery-12346-1',
        }
        stage = {
            'status': 'success', 'category': 'complete', 'mode': 'staging',
            'staging_source_verified': True, 'source_commit': revision,
            'source_hashes': {name: hashlib.sha256((ROOT / (evidence.PREFIX + name)).read_bytes()).hexdigest()
                              for name in evidence.FILES},
            'phases': {phase: self.raw(phase) for phase in ('preflight', 'verify')},
        }
        backup = {
            'AUTOMATION_KIND': 'backup', 'AUTOMATION_RESULT': 'success',
            'GITHUB_RUN_ID': '12345', 'GITHUB_RUN_ATTEMPT': '1',
            'generated_utc': datetime.now(timezone.utc).isoformat(),
            'restore_check': {'restore_smoke_test_passed': True, 'database_import_verified': True},
        }
        recovered = {'wordpress_application_recovery_passed', 'database_import_verified',
                     'wordpress_bootstrap_verified', 'homepage_http_verified', 'shop_http_verified',
                     'product_http_verified', 'cart_http_verified', 'account_http_verified'}
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
        stage['phases']['verify']['checks']['unknown_cost_not_zero'] = False
        with self.assertRaises(ValueError):
            evidence.validate(path)
        stage['phases']['verify']['checks']['unknown_cost_not_zero'] = True
        stage['source_hashes'][evidence.FILES[0]] = 'b' * 64
        with self.assertRaises(ValueError):
            evidence.validate(path)

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
