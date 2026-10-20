"""Exercise the actual production file transaction against disposable site trees."""

import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/deploy-email-branding-remote.php'
SOURCE = ROOT / 'wordpress/wp-content/themes/dt-the7-child'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
FILES = [
    'inc/petish-email-branding.php',
    'inc/petish-account-email-branding.php',
    'woocommerce/emails/email-header.php',
    'woocommerce/emails/email-footer.php',
    'woocommerce/emails/email-button.php',
    'functions.php',
]
BASELINE_SHA = '301c05283071be0df26d0f428134ca794c2f480520e2835efa20203b3f2e1fb9'


@unittest.skipUnless(Path(PHP).is_file(), 'PHP required for production transaction fixtures')
class ProductionEmailDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-email-release-')
        self.home = Path(self.temporary.name) / 'home'
        self.root = self.home / 'domains/petish.biz/public_html'
        self.theme = self.root / 'wp-content/themes/dt-the7-child'
        self.theme.mkdir(parents=True)
        mu = self.root / 'wp-content/mu-plugins'
        mu.mkdir()
        (mu / 'petish-mailer.php').write_text('<?php /* Existing transport. */\n')
        (self.root / 'wp-config.php').write_text('<?php /* Private configuration fixture. */\n')
        (self.root / '.htaccess').write_text('# Existing routing\n')
        (self.theme / 'style.css').write_text('/* Existing storefront. */\n')
        self.baseline = (SOURCE / 'functions.php').read_bytes()
        for name in ('petish-email-branding.php', 'petish-account-email-branding.php'):
            self.baseline = self.baseline.replace(
                ("require_once get_stylesheet_directory() . '/inc/" + name + "';\n").encode(), b'')
        self.assertEqual(hashlib.sha256(self.baseline).hexdigest(), BASELINE_SHA)
        (self.theme / 'functions.php').write_bytes(self.baseline)
        logo = 'assets/brand/repaired/combined-light-v2.png'
        (self.theme / logo).parent.mkdir(parents=True)
        shutil.copyfile(SOURCE / logo, self.theme / logo)
        (self.theme / logo).chmod(0o644)
        for relative in ('assets', 'assets/brand', 'assets/brand/repaired'):
            (self.theme / relative).chmod(0o755)
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
        self.assertFalse(result.stderr, 'Deployment fixture emitted unexpected diagnostics')
        report = json.loads(result.stdout)
        self.assertEqual(set(report), {'status', 'category', 'checks', 'changed_file_count'})
        self.assertTrue(all(type(value) is bool for value in report['checks'].values()))
        self.assertEqual(result.returncode, 0 if report['status'] == 'success' else 1)
        return report

    def snapshot(self):
        return {str(path.relative_to(self.root)): path.read_bytes()
                for path in self.root.rglob('*') if path.is_file()}

    def test_inspect_is_read_only_and_finds_exact_six_file_release(self):
        before = self.snapshot()
        report = self.execute('inspect')
        self.assertEqual(report['status'], 'success')
        self.assertEqual(report['changed_file_count'], 6)
        for name in ('paths_verified', 'candidate_manifest_verified', 'candidate_syntax_verified',
                     'logo_verified', 'production_config_present', 'live_files_compatible'):
            self.assertTrue(report['checks'][name])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.home / '.petish-email-releases').exists())

    def test_apply_then_commit_changes_only_allowlisted_files_and_keeps_private_backup(self):
        before = self.snapshot()
        report = self.execute('apply')
        self.assertEqual(report['status'], 'success')
        self.assertEqual(report['changed_file_count'], 6)
        self.assertTrue(report['checks']['protected_state_unchanged'])
        self.assertTrue(report['checks']['rollback_snapshot_created'])
        self.assertTrue(report['checks']['files_deployed'])
        self.assertFalse(report['checks']['release_committed'])
        for name in FILES:
            self.assertEqual((self.theme / name).read_bytes(), (SOURCE / name).read_bytes())
        after = self.snapshot()
        differences = {name for name in set(before) | set(after) if before.get(name) != after.get(name)}
        self.assertEqual(differences, {'wp-content/themes/dt-the7-child/' + name for name in FILES})
        base = self.home / '.petish-email-releases'
        self.assertTrue((base / 'deploy.lock/owner').is_file())
        release = base / self.run_id
        self.assertEqual(release.stat().st_mode & 0o777, 0o700)
        self.assertEqual((release / 'backup/5').read_bytes(), self.baseline)
        self.assertEqual((release / 'backup/5').stat().st_mode & 0o777, 0o600)
        committed = self.execute('commit')
        self.assertEqual(committed['status'], 'success')
        self.assertTrue(committed['checks']['release_committed'])
        self.assertTrue((release / 'committed').is_file())
        self.assertTrue((release / 'backup/5').is_file())
        self.assertFalse((base / 'deploy.lock').exists())

    def test_rollback_restores_original_files_absence_and_directory_shape(self):
        before = self.snapshot()
        self.assertEqual(self.execute('apply')['status'], 'success')
        report = self.execute('rollback')
        self.assertEqual(report['status'], 'success')
        self.assertTrue(report['checks']['rollback_completed'])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.theme / 'inc').exists())
        self.assertFalse((self.theme / 'woocommerce').exists())
        self.assertFalse((self.home / '.petish-email-releases/deploy.lock').exists())

    def test_existing_candidate_files_are_idempotent_and_rollback_preserves_them(self):
        for name in FILES:
            path = self.theme / name
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(SOURCE / name, path)
        before = self.snapshot()
        applied = self.execute('apply')
        self.assertEqual(applied['status'], 'success')
        self.assertEqual(applied['changed_file_count'], 0)
        self.assertEqual(self.execute('rollback')['status'], 'success')
        self.assertEqual(before, self.snapshot())

    def test_live_function_drift_is_not_overwritten(self):
        (self.theme / 'functions.php').write_bytes(self.baseline + b'// New live edit.\n')
        before = self.snapshot()
        result = self.execute('apply')
        self.assertEqual(result['status'], 'failure')
        self.assertEqual(result['category'], 'drift')
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.home / '.petish-email-releases').exists())

    def test_existing_unknown_template_is_not_overwritten(self):
        path = self.theme / FILES[2]
        path.parent.mkdir(parents=True)
        path.write_text('<?php /* Live custom header. */\n')
        before = self.snapshot()
        result = self.execute('apply')
        self.assertEqual(result['category'], 'drift')
        self.assertEqual(before, self.snapshot())

    def test_missing_or_changed_logo_stops_before_writes(self):
        (self.theme / 'assets/brand/repaired/combined-light-v2.png').write_bytes(b'wrong logo')
        before = self.snapshot()
        result = self.execute('apply')
        self.assertEqual(result['category'], 'logo')
        self.assertEqual(before, self.snapshot())

    def test_logo_permission_diagnostics_are_read_only_and_not_release_gates(self):
        logo = self.theme / 'assets/brand/repaired/combined-light-v2.png'
        self.assertTrue(self.execute('inspect')['checks']['logo_world_readable'])
        self.assertTrue(self.execute('inspect')['checks']['logo_path_world_traversable'])
        logo.chmod(0o600)
        logo.parent.chmod(0o700)
        report = self.execute('inspect')
        self.assertEqual(report['status'], 'success')
        self.assertTrue(report['checks']['logo_verified'])
        self.assertFalse(report['checks']['logo_world_readable'])
        self.assertFalse(report['checks']['logo_path_world_traversable'])
        self.assertEqual(logo.stat().st_mode & 0o777, 0o600)
        self.assertEqual(logo.parent.stat().st_mode & 0o777, 0o700)

    def test_candidate_tamper_and_unexpected_payload_files_refuse(self):
        target = self.payload / FILES[0]
        original = target.read_bytes()
        target.write_bytes(original + b'// Unexpected change.\n')
        self.assertEqual(self.execute('apply')['category'], 'payload')
        target.write_bytes(original)
        (self.payload / 'unexpected.php').write_text('<?php exit;')
        self.assertEqual(self.execute('apply')['category'], 'payload')
        self.assertEqual((self.theme / 'functions.php').read_bytes(), self.baseline)

    def test_links_and_hardlinks_are_rejected(self):
        path = self.theme / 'functions.php'
        other = self.home / 'external.php'
        path.rename(other)
        path.symlink_to(other)
        self.assertEqual(self.execute('apply')['status'], 'failure')
        path.unlink()
        os.link(other, path)
        self.assertEqual(self.execute('apply')['status'], 'failure')
        self.assertEqual(other.read_bytes(), self.baseline)

    def test_pending_release_blocks_other_run_and_failed_commit_keeps_lock(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.assertEqual(self.execute('apply', '12346-1')['category'], 'lock')
        (self.theme / 'style.css').write_text('/* New live edit during verification. */\n')
        report = self.execute('commit')
        self.assertEqual(report['status'], 'failure')
        self.assertTrue((self.home / '.petish-email-releases/deploy.lock').exists())
        self.assertFalse((self.home / '.petish-email-releases' / self.run_id / 'committed').exists())
        self.assertEqual((self.theme / 'style.css').read_text(), '/* New live edit during verification. */\n')

    def test_rollback_preserves_unexpected_external_edits(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        target = self.theme / 'inc/petish-email-branding.php'
        target.write_text('<?php /* Concurrent human edit. */\n')
        report = self.execute('rollback')
        self.assertEqual(report['status'], 'failure')
        self.assertEqual(report['category'], 'drift')
        self.assertEqual(target.read_text(), '<?php /* Concurrent human edit. */\n')
        self.assertTrue((self.home / '.petish-email-releases/deploy.lock').exists())

    def test_invalid_mode_and_run_never_create_release(self):
        self.assertEqual(self.execute('unknown')['category'], 'input')
        self.assertEqual(self.execute('apply', '../escape')['category'], 'input')
        self.assertFalse((self.home / '.petish-email-releases').exists())

    def test_partial_write_exception_automatically_restores_original_site(self):
        # Inject a failure after the real transaction has written both helpers
        # and the header. Only the disposable script copy has this fault.
        source = SCRIPT.read_text()
        statement = "email_release_atomic($theme.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);"
        self.assertEqual(source.count(statement), 1)
        faulty = self.home / 'faulty-deploy.php'
        faulty.write_text(source.replace(statement, statement +
            " if($name==='woocommerce/emails/email-header.php') email_release_fail();"))
        before = self.snapshot()
        report = self.execute('apply', script=faulty)
        self.assertEqual(report['status'], 'failure')
        self.assertTrue(report['checks']['rollback_snapshot_created'])
        self.assertTrue(report['checks']['rollback_completed'])
        self.assertEqual(before, self.snapshot())
        self.assertFalse((self.home / '.petish-email-releases/deploy.lock').exists())
        # The SSH caller may not have received the automatic rollback's report.
        # Retrying rollback verifies the stored originals without rewriting them.
        retried = self.execute('rollback')
        self.assertEqual(retried['status'], 'success')
        self.assertTrue(retried['checks']['rollback_completed'])
        self.assertFalse(retried['checks']['lock_owned'])
        self.assertEqual(before, self.snapshot())

    def test_rollback_retry_refuses_other_lock_owner_and_original_file_drift(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.assertEqual(self.execute('rollback')['status'], 'success')
        base = self.home / '.petish-email-releases'
        lock = base / 'deploy.lock'
        lock.mkdir(mode=0o700)
        (lock / 'owner').write_text('54321-1\n')
        (lock / 'owner').chmod(0o600)
        self.assertEqual(self.execute('rollback')['status'], 'failure')
        self.assertEqual((lock / 'owner').read_text(), '54321-1\n')
        (lock / 'owner').unlink()
        lock.rmdir()
        (self.theme / 'functions.php').write_bytes(self.baseline + b'// Concurrent edit.\n')
        self.assertEqual(self.execute('rollback')['status'], 'failure')
        self.assertEqual((self.theme / 'functions.php').read_bytes(), self.baseline + b'// Concurrent edit.\n')

    def test_committed_release_cannot_be_rolled_back_by_pending_transaction_path(self):
        self.assertEqual(self.execute('apply')['status'], 'success')
        self.assertEqual(self.execute('commit')['status'], 'success')
        before = self.snapshot()
        self.assertEqual(self.execute('rollback')['status'], 'failure')
        self.assertEqual(before, self.snapshot())


if __name__ == '__main__':
    unittest.main()
