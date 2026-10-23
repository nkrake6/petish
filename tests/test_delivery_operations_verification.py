"""Proof-publication boundaries; no credentials, hosted WordPress or network required."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('delivery_operations_sanitizer', ROOT / 'scripts/sanitize-delivery-operations-result.py')
SCREEN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SCREEN)


def command(args, cwd, **kwargs):
    return subprocess.run(args, cwd=cwd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs)


def manifest():
    return {name: 'a' * 64 for name in SCREEN.REQUIRED_SOURCE}


def proof(available=False):
    return {'schema_version': 1, 'target': 'staging', 'status': 'success', 'category': 'complete',
            'checks': dict.fromkeys(SCREEN.COMMON | (SCREEN.SCENARIOS if available else set()), True),
            'observations': {'catalogue_scenario_available': available,
                             'hosted_order_mutations_not_exercised': True,
                             'external_supplier_tracking_not_exercised': True},
            'source_hashes': manifest()}


class ReportBoundary(unittest.TestCase):
    def screen(self, value):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'report.json'
            path.write_text(json.dumps(value))
            return SCREEN.screen(path)

    def test_complete_and_explicit_no_catalogue_are_distinct_valid_results(self):
        self.assertTrue(self.screen(proof())[1])
        self.assertTrue(self.screen(proof(True))[1])
        value = proof(True)
        del value['checks'][next(iter(SCREEN.SCENARIOS))]
        self.assertFalse(self.screen(value)[1])

    def test_never_accept_private_fields_strings_counts_or_unknown_checks(self):
        mutations = [
            lambda value: value.update(customer_email='PRIVATE@example.com'),
            lambda value: value['checks'].update(private_freight='PRIVATE'),
            lambda value: value['checks'].update(feature_flags_disabled=1),
            lambda value: value['checks'].update(feature_flags_disabled='true'),
            lambda value: value['observations'].update(order_id=123),
            lambda value: value['source_hashes'].update({'../wp-config.php': 'a' * 64}),
            lambda value: value['source_hashes'].update({'includes/private.php': 'PRIVATE'}),
            lambda value: value.update(target='production'),
            lambda value: value.update(schema_version=True),
        ]
        for mutate in mutations:
            with self.subTest(mutate=mutate):
                value = proof()
                mutate(value)
                screened, passed = self.screen(value)
                self.assertFalse(passed)
                self.assertEqual(screened['category'], 'invalid_report')
                self.assertNotIn('PRIVATE', json.dumps(screened))

    def test_false_missing_and_external_claims_cannot_pass(self):
        for mutate in [lambda value: value['checks'].update(settings_unchanged=False),
                       lambda value: value['checks'].pop('sql_write_attempts_absent'),
                       lambda value: value['observations'].update(external_supplier_tracking_not_exercised=False),
                       lambda value: value.update(status='failure'),
                       lambda value: value.update(source_hashes={})]:
            value = proof()
            mutate(value)
            self.assertFalse(self.screen(value)[1])

    def test_symlinks_hardlinks_oversized_and_duplicate_json_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            source = base / 'source'
            source.write_text(json.dumps(proof()))
            link = base / 'link'
            link.symlink_to(source)
            self.assertFalse(SCREEN.screen(link)[1])
            os.link(source, base / 'hardlink')
            self.assertFalse(SCREEN.screen(source)[1])
            duplicate = base / 'duplicate'
            duplicate.write_text('{"status":"success","status":"failure"}')
            self.assertFalse(SCREEN.screen(duplicate)[1])
            large = base / 'large'
            large.write_text(' ' * 65537)
            self.assertFalse(SCREEN.screen(large)[1])


class GitSourceBoundary(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        command(['git', 'init', '-q'], self.root)
        command(['git', 'config', 'user.name', 'Fixture'], self.root)
        command(['git', 'config', 'user.email', 'fixture@example.com'], self.root)
        for name in SCREEN.REQUIRED_SOURCE:
            path = self.root / SCREEN.PREFIX / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('<?php /* reviewed synthetic source */\n')
        self.revision = self.commit()

    def tearDown(self):
        self.temp.cleanup()

    def commit(self):
        command(['git', 'add', '.'], self.root)
        command(['git', 'commit', '-qm', 'Fixture'], self.root)
        return command(['git', 'rev-parse', 'HEAD'], self.root).stdout.decode().strip()

    def build(self, revision):
        old = Path.cwd()
        try:
            os.chdir(self.root)
            return SCREEN.build_manifest(revision)
        finally:
            os.chdir(old)

    def test_hashes_git_objects_and_covers_every_regular_plugin_source(self):
        expected = self.build(self.revision)
        file = self.root / SCREEN.PREFIX / 'petish-fulfilment.php'
        file.write_text('Unreviewed working-tree change')
        self.assertEqual(self.build(self.revision), expected)
        extra = self.root / SCREEN.PREFIX / 'assets/new.css'
        extra.parent.mkdir()
        extra.write_text('body{}')
        revision = self.commit()
        self.assertIn('assets/new.css', self.build(revision))

    def test_missing_required_source_symlinks_and_unknown_file_types_rejected(self):
        original = self.root / SCREEN.PREFIX / 'petish-fulfilment.php'
        original.unlink()
        with self.assertRaises(ValueError):
            self.build(self.commit())
        original.symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):
            self.build(self.commit())
        original.unlink()
        original.write_text('<?php')
        (self.root / SCREEN.PREFIX / 'private.sql').write_text('PRIVATE DATABASE')
        with self.assertRaises(ValueError):
            self.build(self.commit())


class StagingPipeline(unittest.TestCase):
    def run_pipeline(self, mode='success', branch='verify-delivery-operations'):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            repo = base / 'repo'
            repo.mkdir()
            origin = base / 'origin.git'
            command(['git', 'init', '--bare', '-q', str(origin)], base)
            command(['git', 'init', '-q'], repo)
            command(['git', 'config', 'user.name', 'Fixture'], repo)
            command(['git', 'config', 'user.email', 'fixture@example.com'], repo)
            command(['git', 'remote', 'add', 'origin', str(origin)], repo)
            (repo / 'scripts').mkdir()
            for name in ['hostinger-delivery-operations.sh', 'sanitize-delivery-operations-result.py',
                         'hostinger-ssh-common.sh', 'verify-delivery-operations.php',
                         'verify-pet-club.php', 'email-branding-preview-remote.php']:
                (repo / 'scripts' / name).write_bytes((ROOT / 'scripts' / name).read_bytes())
            for name in SCREEN.REQUIRED_SOURCE:
                path = repo / SCREEN.PREFIX / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('<?php /* synthetic reviewed source */\n')
            command(['git', 'add', '.'], repo)
            command(['git', 'commit', '-qm', 'Synthetic source'], repo)
            revision = command(['git', 'rev-parse', 'HEAD'], repo).stdout.decode().strip()
            raw = proof()
            old = Path.cwd()
            try:
                os.chdir(repo)
                raw['source_hashes'] = SCREEN.build_manifest(revision)
            finally:
                os.chdir(old)
            if mode == 'private_failure':
                raw['private_customer'] = 'PRIVATE-CUSTOMER-SENTINEL'
            report = base / 'remote-report.json'
            report.write_text(json.dumps(raw))
            binary = base / 'bin'
            binary.mkdir()
            (binary / 'sshpass').write_text('#!/bin/sh\nshift\nexec "$@"\n')
            (binary / 'ssh-keygen').write_text('#!/bin/sh\ncat >/dev/null\nprintf "256 SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA fixture\\n"\n')
            (binary / 'ssh').write_text('''#!/usr/bin/env python3
import io,json,os,sys,tarfile
from pathlib import Path
command=sys.argv[-1]
base=Path(os.environ['FIXTURE_BASE'])
with (base/'commands.jsonl').open('a') as stream:stream.write(json.dumps(command)+'\\n')
if 'tar --extract' in command:
 archive=tarfile.open(fileobj=io.BytesIO(sys.stdin.buffer.read()))
 names=[member.name for member in archive.getmembers()]
 assert sorted(names)==sorted(['verify-delivery-operations.php','verify-pet-club.php','email-branding-preview-remote.php','source-manifest.json','tools.sha256'])
 assert all(member.isfile() for member in archive.getmembers())
elif 'php -d display_errors=0' in command:
 assert 'staging-rehearsal-remote' not in command and ' config get DB_NAME ' in command
 print((base/'remote-report.json').read_text())
 print('PRIVATE-CUSTOMER-SENTINEL',file=sys.stderr)
elif 'rm -rf -- "$tools"' in command:
 if os.environ['FIXTURE_MODE']=='cleanup_failure':raise SystemExit(1)
else:raise SystemExit(1)
''')
            for path in binary.iterdir():
                path.chmod(0o700)
            runner = base / 'runner'
            runner.mkdir()
            (runner / 'petish-candidate-host-keys').write_text('fixture-key\n')
            env = os.environ | {'PATH': str(binary) + ':' + os.environ['PATH'], 'RUNNER_TEMP': str(runner),
                                'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1', 'GITHUB_SHA': revision,
                                'GITHUB_REF_NAME': branch, 'SSH_HOST': '82.29.81.104', 'SSH_PORT': '65002',
                                'SSH_USER': 'u839205597', 'SSHPASS': 'PRIVATE-SECRET-SENTINEL',
                                'SSH_EXPECTED_FINGERPRINT': 'SHA256:' + 'A' * 43,
                                'DELIVERY_OPERATIONS_PREREQUISITES': 'success',
                                'DELIVERY_OPERATIONS_PREPARATION_STAGE': 'complete',
                                'FIXTURE_BASE': str(base), 'FIXTURE_MODE': mode}
            completed = subprocess.run(['bash', 'scripts/hostinger-delivery-operations.sh'], cwd=repo, env=env,
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            receipt = subprocess.run(['git', '--git-dir=' + str(origin), 'show',
                                      'refs/heads/automation-status/delivery-operations-123-1:status.json'],
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            public = json.loads(receipt.stdout) if receipt.returncode == 0 else None
            commands = (base / 'commands.jsonl').read_text() if (base / 'commands.jsonl').exists() else ''
            self.assertNotIn('PRIVATE-', completed.stdout.decode() + completed.stderr.decode() + receipt.stdout.decode())
            self.assertFalse(list(runner.glob('petish-delivery-operations.*')))
            return completed.returncode, public, commands

    def test_success_publishes_finite_receipt_after_remote_cleanup(self):
        status, receipt, commands = self.run_pipeline()
        self.assertEqual(status, 0)
        self.assertEqual(receipt['status'], 'success')
        self.assertTrue(receipt['remote_tools_removed'])
        self.assertEqual(receipt['proof']['source_hashes'], receipt['source_hashes'])
        self.assertEqual(len(commands.splitlines()), 3)

    def test_private_or_malformed_output_is_discarded_and_still_cleaned(self):
        status, receipt, commands = self.run_pipeline('private_failure')
        self.assertNotEqual(status, 0)
        self.assertEqual(receipt['status'], 'failure')
        self.assertEqual(receipt['proof']['category'], 'invalid_report')
        self.assertTrue(receipt['remote_tools_removed'])
        self.assertIn('rm -rf', commands)

    def test_cleanup_failure_can_never_publish_success(self):
        status, receipt, _ = self.run_pipeline('cleanup_failure')
        self.assertNotEqual(status, 0)
        self.assertEqual(receipt['category'], 'cleanup')
        self.assertEqual(receipt['status'], 'failure')
        self.assertFalse(receipt['remote_tools_removed'])

    def test_main_branch_has_no_transport_or_publication(self):
        status, receipt, commands = self.run_pipeline(branch='main')
        self.assertNotEqual(status, 0)
        self.assertIsNone(receipt)
        self.assertEqual(commands, '')

    def test_workflow_is_staging_only_and_checks_out_event_commit(self):
        value = yaml.load((ROOT / '.github/workflows/delivery-operations.yml').read_text(), Loader=yaml.BaseLoader)
        self.assertEqual(value['on']['push']['branches'], ['verify-delivery-operations'])
        self.assertEqual(set(value['on']), {'push', 'workflow_dispatch'})
        job = value['jobs']['read-only-staging']
        self.assertIn("github.ref_name == 'verify-delivery-operations'", job['if'])
        self.assertEqual(job['steps'][0]['with']['ref'], '${{ github.sha }}')
        self.assertEqual(value['concurrency']['group'], 'petish-staging-code')
        self.assertNotIn('apply', (ROOT / 'scripts/hostinger-delivery-operations.sh').read_text().replace('deployment/apply mode', ''))


if __name__ == '__main__':
    unittest.main()
