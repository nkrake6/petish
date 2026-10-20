"""Exercise the three-file Passport navigation release on disposable site trees."""

import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/deploy-account-navigation-remote.php'
SOURCE = ROOT / 'wordpress/wp-content/plugins/petish-passport'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
FILES = ['assets/account-navigation.js', 'assets/passport.css', 'includes/class-petish-passport-ui.php']
BASELINE = '51aa5ce'


@unittest.skipUnless(Path(PHP).is_file(), 'PHP required for navigation transaction fixtures')
class AccountNavigationDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-navigation-release-')
        self.home = Path(self.temporary.name) / 'home'
        self.root = self.home / 'domains/petish.biz/public_html'
        self.plugin = self.root / 'wp-content/plugins/petish-passport'
        self.theme = self.root / 'wp-content/themes/dt-the7-child'
        self.plugin.mkdir(parents=True)
        self.theme.mkdir(parents=True)
        (self.plugin / 'assets').mkdir()
        (self.plugin / 'includes').mkdir()
        (self.plugin / 'petish-passport.php').write_text('<?php /* Existing plugin entrypoint. */\n')
        (self.theme / 'functions.php').write_text('<?php /* Existing verified email includes. */\n')
        (self.theme / 'email-header.php').write_text('<?php /* Retain the animal logo. */\n')
        mu = self.root / 'wp-content/mu-plugins'
        mu.mkdir()
        (mu / 'petish-mailer.php').write_text('<?php /* Existing production transport. */\n')
        (self.root / 'wp-config.php').write_text('<?php /* Private configuration fixture. */\n')
        (self.root / '.htaccess').write_text('# Existing routing\n')
        self.originals = {}
        for name in FILES[1:]:
            result = subprocess.run(['git', 'show', BASELINE + ':wordpress/wp-content/plugins/petish-passport/' + name],
                                    cwd=ROOT, capture_output=True, check=True)
            self.originals[name] = result.stdout
            (self.plugin / name).write_bytes(result.stdout)
        self.payload = self.home / 'private-tools/payload'
        self.payload.mkdir(parents=True, mode=0o700)
        self.payload.chmod(0o700)
        manifest = {}
        for name in FILES:
            target = self.payload / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((SOURCE / name).read_bytes())
            target.chmod(0o600)
            manifest[name] = hashlib.sha256(target.read_bytes()).hexdigest()
        (self.payload / 'manifest.json').write_text(json.dumps(manifest))
        (self.payload / 'manifest.json').chmod(0o600)
        self.run_id = '12345-1'

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, mode, run=None, script=SCRIPT):
        env = os.environ.copy()
        env['HOME'] = str(self.home)
        result = subprocess.run([PHP, str(script), mode, run or self.run_id, str(self.payload)],
                                env=env, capture_output=True, timeout=20)
        self.assertFalse(result.stderr, 'Navigation fixture emitted unexpected diagnostics')
        report = json.loads(result.stdout)
        self.assertEqual(set(report), {'status', 'category', 'checks', 'changed_file_count'})
        self.assertTrue(all(type(value) is bool for value in report['checks'].values()))
        self.assertTrue(0 <= report['changed_file_count'] <= 3)
        self.assertEqual(result.returncode, 0 if report['status'] == 'success' else 1)
        return report

    def snapshot(self):
        return {str(path.relative_to(self.root)): path.read_bytes()
                for path in self.root.rglob('*') if path.is_file()}

    def test_inspect_is_read_only_and_identifies_three_file_release(self):
        before = self.snapshot()
        report = self.execute('inspect')
        self.assertEqual(report['status'], 'success')
        self.assertEqual(report['changed_file_count'], 3)
        self.assertTrue(report['checks']['candidate_manifest_verified'])
        self.assertTrue(report['checks']['candidate_syntax_verified'])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.home / '.petish-account-navigation-releases').exists())

    def test_apply_and_commit_change_only_three_passport_files_and_retain_email_theme(self):
        before = self.snapshot()
        report = self.execute('apply')
        self.assertEqual(report['status'], 'success')
        self.assertEqual(report['changed_file_count'], 3)
        self.assertTrue(report['checks']['protected_state_unchanged'])
        self.assertTrue(report['checks']['rollback_snapshot_created'])
        self.assertTrue(report['checks']['files_deployed'])
        after = self.snapshot()
        differences = {name for name in set(before) | set(after) if before.get(name) != after.get(name)}
        self.assertEqual(differences, {'wp-content/plugins/petish-passport/' + name for name in FILES})
        for name in FILES:
            self.assertEqual((self.plugin / name).read_bytes(), (SOURCE / name).read_bytes())
        committed = self.execute('commit')
        self.assertEqual(committed['status'], 'success')
        self.assertTrue(committed['checks']['release_committed'])
        release = self.home / '.petish-account-navigation-releases' / self.run_id
        self.assertEqual((release / 'backup/1').read_bytes(), self.originals[FILES[1]])
        self.assertEqual((release / 'backup/2').read_bytes(), self.originals[FILES[2]])
        self.assertEqual((release / 'backup/1').stat().st_mode & 0o777, 0o600)
        self.assertFalse((release / 'backup/0').exists())
        self.assertFalse((self.home / '.petish-account-navigation-releases/deploy.lock').exists())
        self.assertEqual(self.execute('rollback')['status'], 'failure')

    def test_rollback_restores_existing_files_removes_only_new_asset_and_is_idempotent(self):
        before = self.snapshot()
        self.assertEqual(self.execute('apply')['status'], 'success')
        rolled = self.execute('rollback')
        self.assertEqual(rolled['status'], 'success')
        self.assertTrue(rolled['checks']['rollback_completed'])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.plugin / FILES[0]).exists())
        self.assertTrue((self.plugin / 'assets').is_dir())
        retried = self.execute('rollback')
        self.assertEqual(retried['status'], 'success')
        self.assertTrue(retried['checks']['rollback_completed'])
        self.assertFalse(retried['checks']['lock_owned'])
        self.assertEqual(before, self.snapshot())

    def test_baseline_css_or_ui_drift_is_not_overwritten(self):
        for name in FILES[1:]:
            with self.subTest(name=name):
                (self.plugin / name).write_bytes(self.originals[name] + b'\n/* New live edit. */\n')
                before = self.snapshot()
                report = self.execute('apply')
                self.assertEqual(report['status'], 'failure')
                self.assertEqual(report['category'], 'drift')
                self.assertEqual(before, self.snapshot())
                (self.plugin / name).write_bytes(self.originals[name])
        self.assertFalse((self.home / '.petish-account-navigation-releases').exists())

    def test_unknown_existing_new_asset_or_payload_tamper_refuses(self):
        (self.plugin / FILES[0]).write_text('// Existing human script.\n')
        before = self.snapshot()
        self.assertEqual(self.execute('apply')['category'], 'drift')
        self.assertEqual(before, self.snapshot())
        (self.plugin / FILES[0]).unlink()
        target = self.payload / FILES[0]
        target.write_bytes(target.read_bytes() + b'// Unexpected payload.\n')
        self.assertEqual(self.execute('apply')['category'], 'payload')

    def test_unrelated_theme_change_blocks_commit_and_preserves_new_human_edit(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        target = self.theme / 'email-header.php'
        target.write_text('<?php /* Concurrent email template change. */\n')
        before = self.snapshot()
        self.assertEqual(self.execute('commit')['status'], 'failure')
        self.assertEqual(self.execute('rollback')['status'], 'failure')
        self.assertEqual(before, self.snapshot())
        self.assertTrue((self.home / '.petish-account-navigation-releases/deploy.lock').exists())

    def test_other_pending_run_cannot_take_over_lock(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        before = self.snapshot()
        self.assertEqual(self.execute('apply', '12346-1')['category'], 'lock')
        self.assertEqual(before, self.snapshot())
        self.assertEqual((self.home / '.petish-account-navigation-releases/deploy.lock/owner').read_text(), self.run_id + '\n')

    def test_partial_asset_write_failure_automatically_rolls_back_before_ui_change(self):
        source = SCRIPT.read_text()
        statement = "navigation_release_atomic($plugin.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);"
        self.assertEqual(source.count(statement), 1)
        faulty = self.home / 'faulty-deploy.php'
        faulty.write_text(source.replace(statement, statement + " if($name==='assets/passport.css') navigation_release_fail();"))
        before = self.snapshot()
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertTrue(report['checks']['rollback_snapshot_created'])
        self.assertTrue(report['checks']['rollback_completed'])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.home / '.petish-account-navigation-releases/deploy.lock').exists())


if __name__ == '__main__':
    unittest.main()
