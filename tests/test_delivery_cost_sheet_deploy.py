"""Exercise the three-file transaction against disposable filesystem fixtures."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/deploy-delivery-cost-sheet-remote.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
PREFIX = 'wordpress/wp-content/plugins/petish-fulfilment/'
FILES = ['includes/class-petish-delivery-cost-sheet.php',
         'includes/class-petish-delivery-cost-sheet-admin.php', 'petish-fulfilment.php']
BASELINE = 'aa66ee7d8a8f3a200114397f9be8b908c6319c41'
# This production transaction approves only the reviewed 0.5.0 three-file release.
# Keep its positive fixture immutable as later staging work changes the checkout.
PINNED_RELEASE = 'ba45b438cb4f4139ed5fc890dd19ace1a8a15d78'


@unittest.skipUnless(Path(PHP).is_file(), 'PHP required for transaction fixtures')
class DeliveryCostSheetDeployTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='petish-cost-sheet-release-')
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.root = self.home / 'domains/petish.biz/public_html'
        self.plugin = self.root / 'wp-content/plugins/petish-fulfilment'
        (self.plugin / 'includes').mkdir(parents=True)
        self.original = subprocess.check_output(
            ['git', 'show', BASELINE + ':' + PREFIX + FILES[2]], cwd=ROOT)
        (self.plugin / FILES[2]).write_bytes(self.original)
        protected = {
            'wp-content/themes/dt-the7-child/front-page.php': '<?php /* Approved homepage. */',
            'wp-content/themes/dt-the7-child/inc/petish-email-branding.php': '<?php /* Approved email. */',
            'wp-content/plugins/petish-passport/petish-passport.php': '<?php /* Account behaviour. */',
            'wp-content/plugins/petish-fulfilment/includes/class-petish-automatic-shipping.php': '<?php /* Existing rates. */',
            'wp-content/mu-plugins/petish-mailer.php': '<?php /* Existing transport. */',
            'wp-config.php': '<?php /* Private configuration. */',
            '.htaccess': '# Existing routing',
        }
        for name, content in protected.items():
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content)
        self.payload = self.home / 'private-tools/payload'
        self.payload.mkdir(parents=True, mode=0o700)
        self.payload.chmod(0o700)
        for index, name in enumerate(FILES):
            target = self.payload / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('<?php /* Candidate %d. */\n' % index)
            target.chmod(0o600)
        self.script = self.home / 'deployer-fixture.php'
        self.prepare_pins()
        self.run_id = '12345-1'

    def prepare_pins(self):
        source = SCRIPT.read_text()
        manifest = {}
        for name in FILES:
            digest = hashlib.sha256((self.payload / name).read_bytes()).hexdigest()
            manifest[name] = digest
            source, count = re.subn(
                re.escape("'" + name + "'=>") + "'[a-f0-9]{64}'",
                "'" + name + "'=>'" + digest + "'", source, count=1)
            self.assertEqual(count, 1)
        (self.payload / 'manifest.json').write_text(json.dumps(manifest))
        (self.payload / 'manifest.json').chmod(0o600)
        self.script.write_text(source)

    def execute(self, mode, run=None, script=None):
        environment = os.environ.copy()
        environment['HOME'] = str(self.home)
        completed = subprocess.run(
            [PHP, str(script or self.script), mode, run or self.run_id, str(self.payload)],
            capture_output=True, env=environment, timeout=20)
        self.assertFalse(completed.stderr)
        result = json.loads(completed.stdout)
        self.assertEqual(set(result), {'status', 'category', 'checks', 'changed_file_count'})
        self.assertTrue(all(type(value) is bool for value in result['checks'].values()))
        self.assertTrue(0 <= result['changed_file_count'] <= 3)
        self.assertEqual(completed.returncode, 0 if result['status'] == 'success' else 1)
        return result

    def snapshot(self):
        return {str(item.relative_to(self.root)): item.read_bytes()
                for item in self.root.rglob('*') if item.is_file()}

    def test_inspection_reads_without_creating_release(self):
        before = self.snapshot()
        report = self.execute('inspect')
        self.assertEqual(report['status'], 'success')
        self.assertEqual(report['changed_file_count'], 3)
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.home / '.petish-delivery-cost-sheet-releases').exists())

    def test_apply_commit_only_three_files_and_keep_original_private(self):
        before = self.snapshot()
        report = self.execute('apply')
        self.assertEqual(report['status'], 'success')
        self.assertTrue(report['checks']['protected_state_unchanged'])
        self.assertTrue(report['checks']['rollback_snapshot_created'])
        after = self.snapshot()
        self.assertEqual(
            {name for name in set(before) | set(after) if before.get(name) != after.get(name)},
            {'wp-content/plugins/petish-fulfilment/' + name for name in FILES})
        for name in FILES:
            self.assertEqual((self.plugin / name).stat().st_mode & 0o777, 0o644)
            self.assertEqual((self.plugin / name).read_bytes(), (self.payload / name).read_bytes())
        release = self.home / '.petish-delivery-cost-sheet-releases' / self.run_id
        self.assertEqual((release / 'backup/2').read_bytes(), self.original)
        self.assertEqual((release / 'backup/2').stat().st_mode & 0o777, 0o600)
        self.assertFalse((release / 'backup/0').exists())
        self.assertTrue(self.execute('commit')['checks']['release_committed'])
        self.assertEqual(self.execute('rollback')['status'], 'failure')

    def test_rollback_restores_bootstrap_removes_new_helpers_and_can_verify_retry(self):
        before = self.snapshot()
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.assertTrue(self.execute('rollback')['checks']['rollback_completed'])
        self.assertEqual(self.snapshot(), before)
        for name in FILES[:2]:
            self.assertFalse((self.plugin / name).exists())
        self.assertTrue(self.execute('rollback')['checks']['rollback_completed'])
        self.assertEqual(self.snapshot(), before)

    def test_concurrent_live_changes_are_never_overwritten(self):
        for name in FILES:
            with self.subTest(name=name):
                target = self.plugin / name
                previous = target.read_bytes() if target.exists() else None
                target.write_bytes(b'<?php /* Concurrent owner edit. */\n')
                before = self.snapshot()
                self.assertEqual(self.execute('apply')['category'], 'drift')
                self.assertEqual(self.snapshot(), before)
                target.unlink() if previous is None else target.write_bytes(previous)

    def test_wrong_php_or_tampered_payload_rejects_before_writes(self):
        before = self.snapshot()
        target = self.payload / FILES[0]
        previous = target.read_bytes()
        target.write_bytes(b'<?php function broken( {')
        self.prepare_pins()
        self.assertEqual(self.execute('apply')['category'], 'payload')
        self.assertEqual(self.snapshot(), before)
        target.write_bytes(previous)
        self.prepare_pins()
        target.write_bytes(previous + b'/* Unexpected mutation. */')
        self.assertEqual(self.execute('apply')['category'], 'payload')
        self.assertEqual(self.snapshot(), before)

    def test_protected_changes_block_commit_and_rollback(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        target = self.plugin / 'includes/class-petish-automatic-shipping.php'
        target.write_text('<?php /* Operator changed rates code concurrently. */')
        before = self.snapshot()
        self.assertEqual(self.execute('commit')['status'], 'failure')
        self.assertEqual(self.execute('rollback')['status'], 'failure')
        self.assertEqual(self.snapshot(), before)

    def test_partial_file_failure_restores_initial_state(self):
        statement = "delivery_cost_sheet_release_atomic($plugin.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);"
        source = self.script.read_text()
        self.assertEqual(source.count(statement), 1)
        faulty = self.home / 'faulty.php'
        faulty.write_text(source.replace(statement, statement + " if($name==='includes/class-petish-delivery-cost-sheet-admin.php') delivery_cost_sheet_release_fail();"))
        before = self.snapshot()
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertTrue(report['checks']['rollback_completed'])
        self.assertEqual(self.snapshot(), before)

    def test_foreign_lock_and_linked_candidate_are_rejected(self):
        target = self.payload / FILES[0]
        source = self.payload / 'outside.php'
        source.write_bytes(target.read_bytes())
        target.unlink()
        target.symlink_to(source)
        before = self.snapshot()
        self.assertEqual(self.execute('apply')['category'], 'payload')
        self.assertEqual(self.snapshot(), before)
        target.unlink()
        target.write_bytes(source.read_bytes())
        target.chmod(0o600)
        source.unlink()
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.assertEqual(self.execute('apply', '12346-1')['category'], 'lock')

    def test_reviewed_050_candidates_match_pins_and_can_roll_back(self):
        manifest = {}
        for name in FILES:
            content = subprocess.check_output(
                ['git', 'show', PINNED_RELEASE + ':' + PREFIX + name], cwd=ROOT)
            (self.payload / name).write_bytes(content)
            manifest[name] = hashlib.sha256(content).hexdigest()
        (self.payload / 'manifest.json').write_text(json.dumps(manifest))
        before = self.snapshot()
        self.assertEqual(self.execute('inspect', script=SCRIPT)['status'], 'success')
        self.assertEqual(self.execute('apply', script=SCRIPT)['status'], 'success')
        self.assertEqual(self.execute('rollback', script=SCRIPT)['status'], 'success')
        self.assertEqual(self.snapshot(), before)

    def test_new_staging_candidate_cannot_use_old_production_scope(self):
        manifest = {}
        for name in FILES:
            content = (ROOT / (PREFIX + name)).read_bytes()
            (self.payload / name).write_bytes(content)
            manifest[name] = hashlib.sha256(content).hexdigest()
        (self.payload / 'manifest.json').write_text(json.dumps(manifest))
        before = self.snapshot()
        for operation in ('inspect', 'apply'):
            with self.subTest(operation=operation):
                report = self.execute(operation, script=SCRIPT)
                self.assertEqual(report['status'], 'failure')
                self.assertEqual(report['category'], 'payload')
                self.assertFalse(report['checks']['candidate_manifest_verified'])
                self.assertEqual(report['changed_file_count'], 0)
                self.assertEqual(self.snapshot(), before)
                self.assertFalse((self.home / '.petish-delivery-cost-sheet-releases').exists())


if __name__ == '__main__':
    unittest.main()
