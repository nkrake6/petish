"""Exercise the reviewed homepage/header transaction against disposable filesystems."""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/deploy-homepage-remote.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
PREFIX = 'wordpress/wp-content/themes/dt-the7-child/'
import importlib.util
spec = importlib.util.spec_from_file_location('homepage_evidence', ROOT / 'scripts/validate-homepage-release.py')
evidence = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evidence)
FILES = list(evidence.FILES)
BASELINE = '481ea28d27d84c34976e86c9f691b527e45619b1'
HISTORICAL_BASELINE = '19b08bbe998839a98dc845c9afac4ecd651c4e26'
HISTORICAL_NEW_FILES = {'includes/account-dropdown.php','assets/css/account-dropdown.css','assets/js/account-dropdown.js',
             'includes/hero-images.php','assets/js/hero-images-admin.js',
             'includes/payment-layout.php','assets/css/payment-layout.css','assets/js/payment-layout.js',
             'includes/cart-payment-layout.php','assets/css/cart-payment-layout.css',
             'assets/css/cart-typography.css','assets/js/cart-payment-layout.js'}
NEW_FILES = set()
EXISTING = [name for name in FILES if name not in NEW_FILES]
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+bfr0AAAAASUVORK5CYII=')



@unittest.skipUnless(Path(PHP).is_file(), 'PHP required for transaction fixtures')
class HomepageDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='petish-homepage-release-')
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.root = self.home / 'domains/petish.biz/public_html'
        self.theme = self.root / 'wp-content/themes/dt-the7-child'
        for folder in ('includes', 'assets/css', 'assets/js', 'assets/images/ui'):
            (self.theme / folder).mkdir(parents=True, exist_ok=True)
        self.originals = {}
        for name in EXISTING:
            self.originals[name] = subprocess.check_output(['git', 'show', BASELINE + ':' + PREFIX + name], cwd=ROOT)
            (self.theme / name).write_bytes(self.originals[name])
        protected = {
            'wp-content/themes/dt-the7-child/inc/petish-email-branding.php': '<?php /* Approved email. */',
            'wp-content/plugins/petish-fulfilment/petish-fulfilment.php': '<?php /* Existing fulfilment. */',
            'wp-content/plugins/petish-passport/assets/passport.css': '/* Existing account navigation */',
            'wp-content/plugins/petish-passport/assets/account-navigation.js': '/* Existing menu */',
            'wp-content/mu-plugins/petish-mailer.php': '<?php /* Existing transport. */',
            'wp-config.php': '<?php /* Private configuration. */',
            '.htaccess': '# Existing routing',
        }
        for name, content in protected.items():
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content)
        self.private = self.root.parent / 'private/petish-passport/customer-photo.png'
        self.private.parent.mkdir(parents=True)
        self.private.write_bytes(b'existing-private-photo')
        self.payload = self.home / 'private-tools/payload'
        self.payload.mkdir(parents=True, mode=0o700)
        self.payload.chmod(0o700)
        for index, name in enumerate(FILES):
            target = self.payload / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(PNG if name.endswith('.png') else ('<?php /* Candidate %d. */\n' % index).encode())
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
        self.assertTrue(0 <= result['changed_file_count'] <= len(FILES))
        self.assertEqual(completed.returncode, 0 if result['status'] == 'success' else 1)
        return result

    def snapshot(self):
        return {str(item.relative_to(self.root)): item.read_bytes()
                for item in self.root.rglob('*') if item.is_file()}

    def test_inspection_reads_without_creating_release(self):
        before = self.snapshot()
        report = self.execute('inspect')
        self.assertEqual(report['status'], 'success')
        self.assertEqual(report['changed_file_count'], len(FILES))
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.home / '.petish-homepage-releases').exists())

    def test_apply_commit_only_selected_files_and_keep_originals_private(self):
        before = self.snapshot()
        report = self.execute('apply')
        self.assertEqual(report['status'], 'success')
        self.assertTrue(report['checks']['protected_state_unchanged'])
        self.assertTrue(report['checks']['rollback_snapshot_created'])
        after = self.snapshot()
        self.assertEqual(
            {name for name in set(before) | set(after) if before.get(name) != after.get(name)},
            {'wp-content/themes/dt-the7-child/' + name for name in FILES})
        for name in FILES:
            self.assertEqual((self.theme / name).stat().st_mode & 0o777, 0o644)
            self.assertEqual((self.theme / name).read_bytes(), (self.payload / name).read_bytes())
        release = self.home / '.petish-homepage-releases' / self.run_id
        for name, original in self.originals.items():
            backup = release / ('backup/' + str(FILES.index(name)))
            self.assertEqual(backup.read_bytes(), original)
            self.assertEqual(backup.stat().st_mode & 0o777, 0o600)
        self.assertEqual({item.name for item in (release / 'backup').iterdir()},
                         {str(FILES.index(name)) for name in self.originals})
        self.assertTrue(self.execute('commit')['checks']['release_committed'])
        self.assertEqual(self.execute('rollback')['status'], 'failure')

    def test_rollback_restores_deployed_files_and_can_verify_retry(self):
        before = self.snapshot()
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.assertTrue(self.execute('rollback')['checks']['rollback_completed'])
        self.assertEqual(self.snapshot(), before)
        for name in set(FILES) - set(EXISTING):
            self.assertFalse((self.theme / name).exists())
        self.assertTrue(self.execute('rollback')['checks']['rollback_completed'])
        self.assertEqual(self.snapshot(), before)

    def test_all_thirty_existing_files_are_restored_after_rollback(self):
        self.assertEqual(len(FILES), 30)
        self.assertEqual(set(FILES), set(self.originals))
        before = self.snapshot()
        self.assertTrue(all((self.theme / name).is_file() for name in FILES))
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.assertTrue(all((self.theme / name).is_file() for name in FILES))
        self.assertEqual(self.execute('rollback')['status'], 'success')
        self.assertTrue(all((self.theme / name).is_file() for name in FILES))
        self.assertEqual(self.snapshot(), before)

    def test_concurrent_live_changes_are_never_overwritten(self):
        for name in FILES:
            with self.subTest(name=name):
                target = self.theme / name
                previous = target.read_bytes() if target.exists() else None
                target.write_bytes(b'<?php /* Concurrent owner edit. */\n')
                before = self.snapshot()
                self.assertEqual(self.execute('apply')['category'], 'drift')
                self.assertEqual(self.snapshot(), before)
                target.unlink() if previous is None else target.write_bytes(previous)

    def test_wrong_php_or_tampered_payload_rejects_before_writes(self):
        before = self.snapshot()
        target = self.payload / 'includes/for-your-pet.php'
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
        target = self.root / 'wp-content/plugins/petish-passport/assets/passport.css'
        target.write_text('<?php /* Operator changed rates code concurrently. */')
        before = self.snapshot()
        self.assertEqual(self.execute('commit')['status'], 'failure')
        self.assertEqual(self.execute('rollback')['status'], 'failure')
        self.assertEqual(self.snapshot(), before)

    def test_partial_file_failure_restores_initial_state(self):
        statement = "homepage_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);"
        source = self.script.read_text()
        self.assertEqual(source.count(statement), 1)
        faulty = self.home / 'faulty.php'
        faulty.write_text(source.replace(statement, statement + " if($name==='includes/for-your-pet.php') homepage_release_fail();"))
        before = self.snapshot()
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertTrue(report['checks']['maintenance_guard_created'])
        self.assertTrue(report['checks']['maintenance_guard_removed'])
        self.assertFalse((self.root / '.maintenance').exists())
        self.assertTrue(report['checks']['rollback_completed'])
        self.assertEqual(self.snapshot(), before)

    def test_foreign_lock_and_linked_candidate_are_rejected(self):
        target = self.payload / 'includes/for-your-pet.php'
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

    def test_edit_after_snapshot_is_preserved_before_any_swap(self):
        statement = "$report['category']='deployment';"
        source = self.script.read_text()
        self.assertEqual(source.count(statement), 1)
        faulty = self.home / 'interleaved-edit.php'
        target = self.theme / 'functions.php'
        edit = b'<?php /* Concurrent edit after snapshot. */'
        faulty.write_text(source.replace(statement, statement +
            " file_put_contents($theme.'/functions.php','<?php /* Concurrent edit after snapshot. */');"))
        before = self.snapshot()
        before[str(target.relative_to(self.root))] = edit
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.root / '.maintenance').exists())
        self.assertFalse((self.home / '.petish-homepage-releases/deploy.lock').exists())

    def test_interleaved_edit_during_swap_is_not_overwritten(self):
        statement = "homepage_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);"
        faulty = self.home / 'during-swap-edit.php'
        faulty.write_text(self.script.read_text().replace(statement, statement +
            " if($name==='assets/js/for-your-pet.js') file_put_contents($theme.'/functions.php','<?php /* Concurrent edit during swaps. */');"))
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertEqual((self.theme / 'functions.php').read_text(), '<?php /* Concurrent edit during swaps. */')
        self.assertTrue((self.root / '.maintenance').exists())
        self.assertFalse(report['checks']['maintenance_guard_removed'])
        self.assertTrue((self.home / '.petish-homepage-releases/deploy.lock').exists())

    def test_existing_maintenance_is_never_overwritten_or_removed(self):
        guard = self.root / '.maintenance'
        guard.write_text('<?php /* Owned by another operation. */')
        before = self.snapshot()
        report = self.execute('apply')
        self.assertEqual(report['category'], 'maintenance')
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.home / '.petish-homepage-releases/deploy.lock').exists())

    def test_owned_guard_is_short_lived_and_verified_for_apply_and_rollback(self):
        for operation in ('apply', 'rollback'):
            report = self.execute(operation)
            self.assertEqual(report['status'], 'success')
            self.assertTrue(report['checks']['maintenance_guard_created'])
            self.assertTrue(report['checks']['maintenance_guard_removed'])
            self.assertFalse((self.root / '.maintenance').exists())
        self.assertEqual(self.private.read_bytes(), b'existing-private-photo')

    def test_rollback_refuses_concurrent_candidate_edit_but_removes_owned_guard(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        target = self.theme / FILES[-1]
        target.write_bytes(b'<?php /* Concurrent app edit. */')
        before = self.snapshot()
        report = self.execute('rollback')
        self.assertEqual(report['status'], 'failure')
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.root / '.maintenance').exists())

    def test_foreign_guard_change_is_never_deleted(self):
        statement = "homepage_release_write($release.'/pending',\"verified\\n\");"
        source = self.script.read_text()
        self.assertEqual(source.count(statement), 1)
        faulty = self.home / 'foreign-guard.php'
        faulty.write_text(source.replace(statement, statement +
            " file_put_contents($root.'/.maintenance','foreign-maintenance-sentinel');"))
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertEqual(report['category'], 'maintenance')
        self.assertFalse(report['checks']['maintenance_guard_removed'])
        self.assertEqual((self.root / '.maintenance').read_text(), 'foreign-maintenance-sentinel')
        for name, original in self.originals.items():
            self.assertEqual((self.theme / name).read_bytes(), original)

    def test_private_photo_change_blocks_commit_without_touching_photo(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.private.write_bytes(b'concurrent-private-photo')
        self.assertEqual(self.execute('commit')['status'], 'failure')
        self.assertEqual(self.private.read_bytes(), b'concurrent-private-photo')
        self.assertEqual(self.execute('rollback')['status'], 'success')
        self.assertEqual(self.private.read_bytes(), b'concurrent-private-photo')

    def test_same_run_rollback_recovers_guard_after_killed_partial_apply(self):
        statement = "homepage_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);"
        faulty = self.home / 'interrupted-apply.php'
        faulty.write_text(self.script.read_text().replace(statement, statement +
            " file_put_contents($release.'/interrupt-ready','1'); while(true) usleep(50000);"))
        environment = os.environ.copy()
        environment['HOME'] = str(self.home)
        before = self.snapshot()
        process = subprocess.Popen([PHP, str(faulty), 'apply', self.run_id, str(self.payload)],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=environment)
        ready = self.home / '.petish-homepage-releases' / self.run_id / 'interrupt-ready'
        try:
            deadline = time.monotonic() + 5
            while not ready.exists() and process.poll() is None and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertTrue(ready.exists())
        finally:
            process.kill()
            process.communicate(timeout=5)
        self.assertTrue((self.root / '.maintenance').exists())
        report = self.execute('rollback')
        self.assertEqual(report['status'], 'success')
        self.assertTrue(report['checks']['maintenance_guard_removed'])
        self.assertEqual(self.snapshot(), before)

    def test_repeated_rollback_recovers_guard_left_after_restore_completed(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        release = self.home / '.petish-homepage-releases' / self.run_id
        statement = "if(!file_exists($release.'/rolled-back')) homepage_release_write($release.'/rolled-back',\"verified\\n\");"
        source = self.script.read_text()
        self.assertEqual(source.count(statement), 1)
        faulty = self.home / 'interrupted-rollback.php'
        faulty.write_text(source.replace(statement, statement +
            " file_put_contents($release.'/interrupt-ready','1'); while(true) usleep(50000);"))
        environment = os.environ.copy()
        environment['HOME'] = str(self.home)
        process = subprocess.Popen([PHP, str(faulty), 'rollback', self.run_id, str(self.payload)],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=environment)
        try:
            deadline = time.monotonic() + 5
            while not (release / 'interrupt-ready').exists() and process.poll() is None and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertTrue((release / 'interrupt-ready').exists())
        finally:
            process.kill()
            process.communicate(timeout=5)
        self.assertTrue((self.root / '.maintenance').exists())
        self.assertTrue((release / 'rolled-back').exists())
        report = self.execute('rollback')
        self.assertEqual(report['status'], 'success')
        self.assertTrue(report['checks']['maintenance_guard_removed'])
        self.assertFalse((self.root / '.maintenance').exists())

    def test_customer_photo_change_during_partial_failure_is_preserved_and_code_recovers(self):
        statement = "homepage_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);"
        faulty = self.home / 'photo-during-swap.php'
        faulty.write_text(self.script.read_text().replace(statement, statement +
            " if($name==='front-page.php') { file_put_contents(dirname($root).'/private/petish-passport/customer-photo.png','new-customer-photo'); homepage_release_fail(); }"))
        before = self.snapshot()
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertTrue(report['checks']['rollback_completed'])
        self.assertTrue(report['checks']['maintenance_guard_removed'])
        self.assertEqual(self.snapshot(), before)
        self.assertEqual(self.private.read_bytes(), b'new-customer-photo')

    def test_non_png_image_refuses_even_with_matching_test_candidate_pin(self):
        (self.payload / FILES[0]).write_bytes(b'Not an image.')
        self.prepare_pins()
        before = self.snapshot()
        report = self.execute('apply')
        self.assertEqual(report['category'], 'payload')
        self.assertFalse(report['checks']['candidate_badge_png_verified'])
        self.assertEqual(before, self.snapshot())


    def test_actual_candidates_match_pins_and_can_roll_back(self):
        manifest = {}
        for name in FILES:
            content = (ROOT / (PREFIX + name)).read_bytes()
            (self.payload / name).write_bytes(content)
            manifest[name] = hashlib.sha256(content).hexdigest()
        (self.payload / 'manifest.json').write_text(json.dumps(manifest))
        before = self.snapshot()
        expected_changed = {name for name in FILES
                            if self.originals.get(name) != (self.payload / name).read_bytes()}
        inspection=self.execute('inspect', script=SCRIPT)
        self.assertEqual(inspection['status'], 'success')
        self.assertEqual(inspection['changed_file_count'],len(expected_changed))
        self.assertEqual(expected_changed,{'assets/css/petish.css'})
        self.assertEqual(self.execute('apply', script=SCRIPT)['status'], 'success')
        after=self.snapshot()
        self.assertEqual({name for name in set(before)|set(after) if before.get(name)!=after.get(name)},
                         {PREFIX.removeprefix('wordpress/')+name for name in expected_changed})
        self.assertEqual(self.execute('rollback', script=SCRIPT)['status'], 'success')
        self.assertEqual(self.snapshot(), before)


    def test_previous_eighteen_file_payload_cannot_enter_the_expanded_release(self):
        historical_manifest = {}
        for name in FILES:
            target = self.payload / name
            if name in HISTORICAL_NEW_FILES:
                target.unlink()
                continue
            content = subprocess.check_output(['git', 'show', HISTORICAL_BASELINE + ':' + PREFIX + name], cwd=ROOT)
            target.write_bytes(content)
            historical_manifest[name] = hashlib.sha256(content).hexdigest()
        self.assertEqual(len(historical_manifest),18)
        (self.payload / 'manifest.json').write_text(json.dumps(historical_manifest))
        before = self.snapshot()
        for operation in ('inspect','apply'):
            report = self.execute(operation,script=SCRIPT)
            self.assertEqual(report['status'],'failure')
            self.assertEqual(report['category'],'payload')
            self.assertFalse(report['checks']['candidate_manifest_verified'])
            self.assertEqual(self.snapshot(),before)
            self.assertFalse((self.home / '.petish-homepage-releases').exists())


if __name__ == '__main__':
    unittest.main()
