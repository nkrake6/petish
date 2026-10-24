"""Production evidence failures must stop before a deployment can start."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


GATE = load('scripts/validate-cancellation-production.py', 'cancellation_production_gate')
STAGE = load('tests/test_cancellation_staging.py', 'cancellation_stage_fixture')


def request():
    return {'format': 'petish-cancellation-production-v1',
            'staging_source_commit': GATE.STAGING_SOURCE,
            'staging_status_branch': GATE.STAGING_STATUS,
            'staging_prepare_branch': GATE.STAGING_PREPARE,
            'staging_install_branch': GATE.STAGING_INSTALL,
            'backup_branch': 'full-site-backup/123-1',
            'backup_result_branch': 'automation-status/backup-123-1',
            'recovery_result_branch': 'automation-status/recovery-456-1'}


def receipts():
    plugin = {name: 'a' * 64 for name in GATE.FULFILMENT_FILES}
    theme = dict.fromkeys(GATE.screen.REQUIRED_THEME, 'b' * 64)
    values = {}
    for branch, mode, kind in [(GATE.STAGING_PREPARE, 'prepare', 'observed_staging_copy'),
                               (GATE.STAGING_STATUS, 'verify', 'owned')]:
        proof = STAGE.proof(mode, kind)
        proof['source_hashes'] = plugin.copy()
        values[branch] = {'category': 'complete', 'mode': mode, 'preparation_stage': 'complete',
                          'proof': proof, 'remote_tools_removed': True,
                          'run_id': branch.removeprefix('automation-status/cancellation-requests-'),
                          'schema_version': 1, 'source_commit': GATE.STAGING_TOOLS,
                          'source_hashes': plugin.copy(), 'status': 'success', 'target': 'staging',
                          'theme_source_hashes': theme.copy()}
    values[GATE.STAGING_INSTALL] = {
        'AUTOMATION_KIND': 'staging', 'AUTOMATION_RESULT': 'success',
        'GITHUB_RUN_ID': '40056331426', 'GITHUB_RUN_ATTEMPT': '1',
        'GITHUB_SHA': GATE.STAGING_SOURCE, 'staging_requested_source_commit': GATE.STAGING_SOURCE,
        'staging_preparation_stage': 'complete', 'staging_mode': 'apply',
        'staging_runner_steps': dict.fromkeys(
            ['checkout', 'revision', 'source', 'package', 'screening', 'prerequisites', 'execution', 'summary'], 'success'),
        'task': {'phase': 'staging', 'categories': [], 'exit_code': 0},
    }
    return values, theme


class EvidenceGate(unittest.TestCase):
    def validate(self, value=None, reports=None):
        reports, theme = receipts() if reports is None else reports
        hashes = dict.fromkeys(GATE.FILES, 'a' * 64)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'request.json'
            path.write_text(json.dumps(request() if value is None else value))
            with patch.object(GATE, 'candidate_hashes', return_value=hashes), \
                    patch.object(GATE.evidence, 'report', side_effect=lambda branch, label: reports[branch]), \
                    patch.object(GATE.screen, 'build_theme_manifest', return_value=theme), \
                    patch.object(GATE.review_evidence, 'recoverable_backup') as recover:
                result = GATE.validate(path)
                recover.assert_called_once()
                self.assertEqual(recover.call_args.args[0], request() if value is None else value)
                return result

    def test_complete_exact_evidence_reaches_shared_backup_gate(self):
        result = self.validate()
        self.assertIs(result.pop('verified'), True)
        self.assertEqual(result, {key: value for key, value in request().items() if key != 'format'})

    def test_unreviewed_source_and_mismatched_backup_are_rejected(self):
        for field, value in [('staging_source_commit', 'c' * 40),
                             ('staging_status_branch', 'automation-status/cancellation-requests-999-1'),
                             ('staging_prepare_branch', GATE.STAGING_STATUS),
                             ('staging_install_branch', 'automation-status/staging-999-1'),
                             ('backup_result_branch', 'automation-status/backup-999-1'),
                             ('backup_branch', '../unreviewed'), ('enable_emails', True)]:
            with self.subTest(field=field):
                value_request = request(); value_request[field] = value
                with self.assertRaises(ValueError):
                    self.validate(value_request)

    def test_every_missing_or_false_staging_check_is_rejected(self):
        for branch in (GATE.STAGING_PREPARE, GATE.STAGING_STATUS):
            baseline, theme = receipts()
            for name in baseline[branch]['proof']['checks']:
                for operation in ('missing', 'false'):
                    with self.subTest(branch=branch, check=name, operation=operation):
                        values = copy.deepcopy(baseline)
                        if operation == 'missing': del values[branch]['proof']['checks'][name]
                        else: values[branch]['proof']['checks'][name] = False
                        with self.assertRaises(ValueError):
                            self.validate(reports=(values, theme))

    def test_changed_source_private_fields_and_incomplete_cleanup_are_rejected(self):
        mutations = [lambda value: value.update(remote_tools_removed=False),
                     lambda value: value.update(source_commit='c' * 40),
                     lambda value: value['proof'].update(private_note='must not publish'),
                     lambda value: value['source_hashes'].update({'README.md': 'c' * 64}),
                     lambda value: value['theme_source_hashes'].update({'functions.php': 'c' * 64})]
        for branch in (GATE.STAGING_PREPARE, GATE.STAGING_STATUS):
            for index, mutation in enumerate(mutations):
                with self.subTest(branch=branch, mutation=index):
                    values, theme = receipts(); mutation(values[branch])
                    with self.assertRaises(ValueError):
                        self.validate(reports=(values, theme))

    def test_incomplete_staging_install_is_rejected(self):
        for operation in ('failed_step', 'wrong_source', 'wrong_mode'):
            values, theme = receipts(); value = values[GATE.STAGING_INSTALL]
            if operation == 'failed_step': value['staging_runner_steps']['execution'] = 'failure'
            elif operation == 'wrong_source': value['GITHUB_SHA'] = 'c' * 40
            else: value['staging_mode'] = 'inspect'
            with self.subTest(operation=operation), self.assertRaises(ValueError):
                self.validate(reports=(values, theme))


if __name__ == '__main__':
    unittest.main()
