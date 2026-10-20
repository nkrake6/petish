"""Test the homepage file transaction without executing WordPress or a live site.

Candidate pins in a disposable script copy are substituted with inert fixture
bytes. The production script remains pinned to the separately reviewed release.
Baseline bytes come from the last released homepage commit.
"""

import base64
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
SCRIPT = ROOT / 'scripts/deploy-homepage-remote.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
PREFIX = 'wordpress/wp-content/themes/dt-the7-child/'
BASELINE = '5dceb02'
FILES = ['assets/images/ui/free-delivery-van.png', 'assets/css/home.css',
         'includes/supplier-popularity.php', 'front-page.php']
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+bfr0AAAAASUVORK5CYII=')


@unittest.skipUnless(Path(PHP).is_file(), 'PHP required for homepage transaction fixtures')
class HomepageDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-homepage-release-')
        self.home = Path(self.temporary.name) / 'home'
        self.root = self.home / 'domains/petish.biz/public_html'
        self.theme = self.root / 'wp-content/themes/dt-the7-child'
        self.theme.mkdir(parents=True)
        (self.theme / 'assets/css').mkdir(parents=True)
        (self.theme / 'assets/images/ui').mkdir(parents=True)
        (self.theme / 'includes').mkdir()
        (self.theme / 'inc').mkdir()
        (self.theme / 'inc/petish-email-branding.php').write_text('<?php /* Existing approved email branding. */\n')
        (self.theme / 'functions.php').write_text('<?php /* Existing child-theme includes. */\n')
        (self.theme / 'assets/images/ui/hot-flame.png').write_bytes(PNG)
        for plugin in ('petish-passport', 'petish-fulfilment'):
            path = self.root / 'wp-content/plugins' / plugin
            path.mkdir(parents=True)
            (path / (plugin + '.php')).write_text('<?php /* Existing custom plugin. */\n')
        mu = self.root / 'wp-content/mu-plugins'
        mu.mkdir()
        (mu / 'petish-mailer.php').write_text('<?php /* Existing production transport. */\n')
        (self.root / 'wp-config.php').write_text('<?php /* Private configuration fixture. */\n')
        (self.root / '.htaccess').write_text('# Existing routing\n')
        self.originals = {}
        for name in FILES[1:]:
            result = subprocess.run(['git', 'show', BASELINE + ':' + PREFIX + name],
                                    cwd=ROOT, capture_output=True, check=True)
            self.originals[name] = result.stdout
            (self.theme / name).write_bytes(result.stdout)
        self.payload = self.home / 'private-tools/payload'
        self.payload.mkdir(parents=True, mode=0o700)
        self.payload.chmod(0o700)
        candidates = {FILES[0]:PNG, FILES[1]:b'/* New homepage CSS fixture. */\n',
                      FILES[2]:b'<?php /* New supplier helper fixture. */\n',
                      FILES[3]:b'<?php /* New homepage template fixture. */\n'}
        for name, data in candidates.items():
            target = self.payload / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            target.chmod(0o600)
        self.script = self.home / 'homepage-deployer-fixture.php'
        self.prepare_candidate_pins()
        self.run_id = '12345-1'

    def tearDown(self):
        self.temporary.cleanup()

    def prepare_candidate_pins(self):
        source = SCRIPT.read_text()
        manifest = {}
        for name in FILES:
            value = hashlib.sha256((self.payload / name).read_bytes()).hexdigest()
            manifest[name] = value
            pattern = re.escape("'" + name + "'=>") + "'[a-f0-9]{64}'"
            source, count = re.subn(pattern, "'" + name + "'=>'" + value + "'", source, count=1)
            self.assertEqual(count, 1)
        (self.payload / 'manifest.json').write_text(json.dumps(manifest))
        (self.payload / 'manifest.json').chmod(0o600)
        self.script.write_text(source)

    def execute(self, mode, run=None, script=None):
        env = os.environ.copy()
        env['HOME'] = str(self.home)
        result = subprocess.run([PHP, str(script or self.script), mode, run or self.run_id, str(self.payload)],
                                env=env, capture_output=True, timeout=20)
        self.assertFalse(result.stderr, 'Homepage fixture emitted unexpected diagnostics')
        report = json.loads(result.stdout)
        self.assertEqual(set(report), {'status', 'category', 'checks', 'changed_file_count'})
        self.assertTrue(all(type(value) is bool for value in report['checks'].values()))
        self.assertTrue(0 <= report['changed_file_count'] <= 4)
        self.assertEqual(result.returncode, 0 if report['status'] == 'success' else 1)
        return report

    def snapshot(self):
        return {str(path.relative_to(self.root)): path.read_bytes()
                for path in self.root.rglob('*') if path.is_file()}

    def test_inspect_does_not_change_site_or_create_release(self):
        before = self.snapshot()
        report = self.execute('inspect')
        self.assertEqual(report['status'], 'success')
        self.assertEqual(report['changed_file_count'], 4)
        self.assertTrue(report['checks']['candidate_badge_png_verified'])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.home / '.petish-homepage-releases').exists())

    def test_apply_commit_changes_only_four_files_and_preserves_email_and_plugins(self):
        before = self.snapshot()
        applied = self.execute('apply')
        self.assertEqual(applied['status'], 'success')
        self.assertTrue(applied['checks']['rollback_snapshot_created'])
        self.assertTrue(applied['checks']['protected_state_unchanged'])
        after = self.snapshot()
        differences = {name for name in set(before) | set(after) if before.get(name) != after.get(name)}
        self.assertEqual(differences, {'wp-content/themes/dt-the7-child/' + name for name in FILES})
        for name in FILES:
            self.assertEqual((self.theme / name).read_bytes(), (self.payload / name).read_bytes())
            self.assertEqual((self.theme / name).stat().st_mode & 0o777, 0o644)
        committed = self.execute('commit')
        self.assertEqual(committed['status'], 'success')
        self.assertTrue(committed['checks']['release_committed'])
        release = self.home / '.petish-homepage-releases' / self.run_id
        self.assertEqual(release.stat().st_mode & 0o777, 0o700)
        self.assertEqual((release / 'backup/3').read_bytes(), self.originals['front-page.php'])
        self.assertEqual((release / 'backup/3').stat().st_mode & 0o777, 0o600)
        self.assertFalse((release / 'backup/0').exists())
        self.assertEqual(self.execute('rollback')['status'], 'failure')

    def test_rollback_restores_old_homepage_and_absent_badge_then_verifies_retry(self):
        before = self.snapshot()
        self.assertEqual(self.execute('apply')['status'], 'success')
        rolled = self.execute('rollback')
        self.assertEqual(rolled['status'], 'success')
        self.assertTrue(rolled['checks']['rollback_completed'])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.theme / FILES[0]).exists())
        self.assertTrue((self.theme / 'assets/images/ui').is_dir())
        retried = self.execute('rollback')
        self.assertEqual(retried['status'], 'success')
        self.assertFalse(retried['checks']['lock_owned'])
        self.assertEqual(before, self.snapshot())

    def test_live_source_drift_or_unknown_badge_is_preserved(self):
        for name in FILES:
            with self.subTest(name=name):
                path = self.theme / name
                original = path.read_bytes() if path.exists() else None
                path.write_bytes(b'/* Concurrent live change. */\n')
                before = self.snapshot()
                report = self.execute('apply')
                self.assertEqual(report['category'], 'drift')
                self.assertEqual(before, self.snapshot())
                if original is None:
                    path.unlink()
                else:
                    path.write_bytes(original)

    def test_non_png_image_refuses_even_with_matching_test_candidate_pin(self):
        (self.payload / FILES[0]).write_bytes(b'Not an image.')
        self.prepare_candidate_pins()
        before = self.snapshot()
        report = self.execute('apply')
        self.assertEqual(report['category'], 'payload')
        self.assertFalse(report['checks']['candidate_badge_png_verified'])
        self.assertEqual(before, self.snapshot())

    def test_payload_tamper_or_extra_member_refuses(self):
        target = self.payload / FILES[1]
        original = target.read_bytes()
        target.write_bytes(original + b'/* Tampered. */\n')
        self.assertEqual(self.execute('apply')['category'], 'payload')
        target.write_bytes(original)
        (self.payload / 'unexpected.php').write_text('<?php exit;')
        self.assertEqual(self.execute('apply')['category'], 'payload')

    def test_protected_email_or_plugin_edits_block_commit_and_remain_untouched(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        target = self.theme / 'inc/petish-email-branding.php'
        target.write_text('<?php /* Human email edit during verification. */\n')
        before = self.snapshot()
        self.assertEqual(self.execute('commit')['status'], 'failure')
        self.assertEqual(self.execute('rollback')['status'], 'failure')
        self.assertEqual(before, self.snapshot())
        self.assertTrue((self.home / '.petish-homepage-releases/deploy.lock').exists())

    def test_partial_write_failure_restores_source_and_removes_new_badge(self):
        source = self.script.read_text()
        statement = "homepage_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);"
        self.assertEqual(source.count(statement), 1)
        faulty = self.home / 'faulty-deploy.php'
        faulty.write_text(source.replace(statement, statement + " if($name==='includes/supplier-popularity.php') homepage_release_fail();"))
        before = self.snapshot()
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertTrue(report['checks']['rollback_completed'])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.home / '.petish-homepage-releases/deploy.lock').exists())

    def test_foreign_lock_is_not_replaced(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.assertEqual(self.execute('apply', '12346-1')['category'], 'lock')
        self.assertEqual((self.home / '.petish-homepage-releases/deploy.lock/owner').read_text(), self.run_id + '\n')

    def test_actual_reviewed_candidate_package_matches_production_pins_and_rolls_back(self):
        # Unlike the boundary fixtures, this exercises the real four source
        # files and unmodified production deployer with its actual pinned SHA.
        manifest = {}
        for name in FILES:
            target = self.payload / name
            target.write_bytes((ROOT / PREFIX / name).read_bytes())
            manifest[name] = hashlib.sha256(target.read_bytes()).hexdigest()
        (self.payload / 'manifest.json').write_text(json.dumps(manifest))
        before = self.snapshot()
        inspected = self.execute('inspect', script=SCRIPT)
        self.assertEqual(inspected['status'], 'success')
        self.assertTrue(inspected['checks']['candidate_badge_png_verified'])
        applied = self.execute('apply', script=SCRIPT)
        self.assertEqual(applied['status'], 'success')
        self.assertEqual(applied['changed_file_count'], 4)
        for name in FILES:
            self.assertEqual((self.theme / name).read_bytes(), (ROOT / PREFIX / name).read_bytes())
        self.assertEqual(self.execute('rollback', script=SCRIPT)['status'], 'success')
        self.assertEqual(before, self.snapshot())


if __name__ == '__main__':
    unittest.main()
