"""Safe classification must never reproduce private log content or arguments."""

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/record-task-result.py"
SPEC = importlib.util.spec_from_file_location("task_diagnostics", SCRIPT)
DIAGNOSTICS = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DIAGNOSTICS)
PRIVATE = "PRIVATE_PASSWORD_customer@example.com_/private/photos/alice.jpg"


class TaskDiagnosticsTests(unittest.TestCase):
    def run_fixture(self, text, phase="backup-export", code=1):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            stdout, stderr, output = root / "stdout", root / "stderr", root / "result.json"
            stdout.write_bytes((PRIVATE + "\n").encode() + b"\xff\x00")
            stderr.write_text(text + "\n" + PRIVATE)
            command = [sys.executable, str(SCRIPT), phase, str(code), str(stdout), str(stderr), str(output)]
            result = subprocess.run(command, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            serialized = output.read_text()
            self.assertNotIn(PRIVATE, serialized + result.stdout + result.stderr)
            self.assertEqual(result.stdout, "")
            self.assertEqual(result.stderr, "")
            report = json.loads(serialized)
            self.assertEqual(set(report), {"phase", "exit_code", "categories"})
            self.assertEqual(report["phase"], phase)
            self.assertEqual(report["exit_code"], code)
            self.assertTrue(set(report["categories"]).issubset(DIAGNOSTICS.CATEGORIES))
            return report

    def test_existing_fixed_failure_messages(self):
        fixtures = [
            ("The required private Passport directory is missing; backup stopped.", "private_passport_missing"),
            ("The required private Passport directory is a symbolic link; backup stopped.", "private_passport_link"),
            ("The required private Passport directory is unreadable; backup stopped.", "private_passport_unreadable"),
            ("The required private Passport directory is missing, unreadable, or a symbolic link; backup stopped.", "private_passport_unavailable"),
            ("The private Passport directory resolves outside its expected private boundary; backup stopped.", "private_boundary"),
            ("WP-CLI is unavailable; no backup was created.", "wpcli_missing"),
            ("The full database export failed; review database permissions or client compatibility.", "db_export_failed"),
            ("Archive diagnostic: live_file_changed", "archive_changed"),
            ("Archive diagnostic: acl_or_extended_attributes", "archive_error"),
            ("Full backup integrity/structure verification failed. Private backup contents were not printed or published.", "verifier_failed"),
            ("A long, single-line backup passphrase is required before connecting.", "passphrase_missing"),
            ("SSH host-key verification failed. Refusing to authenticate.", "ssh_hostkey_failed"),
            ("user@host: Permission denied (publickey,password).", "ssh_auth_failed"),
            ("The secondary secret scanner failed. No source was published.", "source_screening_failed"),
            ("STAGING_DEPLOY_VALIDATION_FAILED", "staging_validation_failed"),
        ]
        for text, category in fixtures:
            with self.subTest(category=category):
                self.assertIn(category, self.run_fixture(text)["categories"])

    def test_restore_failure_and_all_phase_values(self):
        message = "Isolated backup restore smoke test failed. Private contents and diagnostics were not printed."
        for phase in DIAGNOSTICS.PHASES:
            self.assertEqual(self.run_fixture(message, phase)["categories"], ["verifier_failed"])

    def test_archive_labels_do_not_copy_unknown_label_or_numeric_status(self):
        report = self.run_fixture("Archive diagnostic: tar_exit=2\nArchive diagnostic: " + PRIVATE)
        self.assertEqual(report["categories"], ["archive_error"])

    def test_multiple_categories_are_deduplicated_and_stable(self):
        report = self.run_fixture("Archive diagnostic: live_file_changed\nArchive diagnostic: live_file_changed\nWP-CLI is unavailable")
        self.assertEqual(report["categories"], ["wpcli_missing", "archive_changed"])

    def test_success_never_reads_or_classifies_missing_private_logs(self):
        result = DIAGNOSTICS.task_result("backup-export", 0, "/nonexistent", "/nonexistent")
        self.assertEqual(result["categories"], [])
        self.assertEqual(self.run_fixture("SSH host-key verification failed", code=0)["categories"], [])

    def test_unknown_messages_and_missing_logs_are_sanitized(self):
        self.assertEqual(self.run_fixture(PRIVATE)["categories"], ["unknown"])
        self.assertEqual(self.run_fixture("STAGING_DEPLOY_UNKNOWN_PRIVATE_FAILURE_FAILED")["categories"], ["unknown"])
        self.assertEqual(self.run_fixture("Archive diagnostic: unknown_private_failure")["categories"], ["unknown"])
        report = DIAGNOSTICS.task_result("staging", 1, "/nonexistent/" + PRIVATE, "/nonexistent")
        self.assertEqual(report["categories"], ["unknown"])
        self.assertNotIn(PRIVATE, json.dumps(report))

    def test_log_symlinks_and_fifo_are_not_followed(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            secret = root / "private"; secret.write_text(PRIVATE)
            link = root / "linked"; link.symlink_to(secret)
            fifo = root / "fifo"; os.mkfifo(fifo)
            for file in (link, fifo):
                self.assertEqual(DIAGNOSTICS.task_result("backup-export", 1, file, secret)["categories"], ["unknown"])

    def test_large_logs_keep_tail_diagnostic_without_exposing_contents(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            log = root / "large"; log.write_bytes((PRIVATE.encode() * 40_000) + b"\nArchive diagnostic: source_file_removed\n")
            empty = root / "empty"; empty.touch()
            report = DIAGNOSTICS.task_result("backup-export", 1, log, empty)
            self.assertEqual(report["categories"], ["archive_changed"])
            self.assertNotIn(PRIVATE, json.dumps(report))

    def test_invalid_cli_arguments_never_echo_the_argument(self):
        for phase, code in ((PRIVATE, "1"), ("backup-export", PRIVATE), ("staging", "256")):
            result = subprocess.run([sys.executable, str(SCRIPT), phase, code, PRIVATE, PRIVATE, PRIVATE], capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn(PRIVATE, result.stdout + result.stderr)
            self.assertEqual(result.stdout, "")

    def test_output_replacement_does_not_follow_symlink(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            private = root / "private"; private.write_text(PRIVATE)
            output = root / "output"; output.symlink_to(private)
            report = DIAGNOSTICS.task_result("source-sync", 0, "missing", "missing")
            DIAGNOSTICS.write_result(report, output)
            self.assertFalse(output.is_symlink())
            self.assertEqual(private.read_text(), PRIVATE)
            self.assertEqual(output.stat().st_mode & 0o777, 0o600)


if __name__ == "__main__":
    unittest.main()
