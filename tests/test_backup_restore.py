"""Fixtures for the private restore tool. Docker integration is opt-in."""

import importlib.util
import io
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/test-full-backup-restore.py"
SPEC = importlib.util.spec_from_file_location("restore_smoke", SCRIPT)
RESTORE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RESTORE)


def fixture(extra=(), sql=b"CREATE TABLE fixture (id INT PRIMARY KEY);\nINSERT INTO fixture VALUES (7);\n"):
    data = io.BytesIO()
    with tarfile.open(fileobj=data, mode="w:gz") as archive:
        for name in ("site-files", "site-files/wp-includes", "private-files",
                     "private-files/petish-passport", "database", "backup-metadata"):
            member = tarfile.TarInfo(name)
            member.type = tarfile.DIRTYPE
            archive.addfile(member)
        for name, contents in [("site-files/wp-config.php", b"<?php // never executed\n"),
                               ("site-files/wp-includes/version.php", b"<?php $wp_version='fixture';\n"),
                               ("database/site.sql", sql), ("backup-metadata/README.txt", b"fixture\n"),
                               ("private-files/petish-passport/fixture.jpg", b"private bytes")]:
            member = tarfile.TarInfo(name)
            member.size = len(contents)
            archive.addfile(member, io.BytesIO(contents))
        for member, contents in extra:
            archive.addfile(member, io.BytesIO(contents) if contents is not None else None)
    return data.getvalue()


class ArchiveRestoreTests(unittest.TestCase):
    def extract(self, data):
        with tempfile.TemporaryDirectory() as directory:
            return RESTORE.safe_extract(io.BytesIO(data), Path(directory) / "restored")

    def test_restores_private_files_with_private_permissions(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "restored"
            result = RESTORE.safe_extract(io.BytesIO(fixture()), target)
            self.assertEqual(result["private_files_restored"], 1)
            self.assertEqual((target / "private-files/petish-passport/fixture.jpg").read_bytes(), b"private bytes")
            self.assertEqual((target / "site-files/wp-config.php").stat().st_mode & 0o777, 0o600)

    def test_traversal_and_absolute_paths_are_rejected(self):
        for name in ("site-files/../../outside", "/site-files/absolute", "other-root/file"):
            with self.subTest(case=name):
                member = tarfile.TarInfo(name)
                member.size = 1
                with self.assertRaises(Exception):
                    self.extract(fixture([(member, b"x")]))

    def test_external_symlink_is_never_created(self):
        member = tarfile.TarInfo("site-files/link")
        member.type, member.linkname = tarfile.SYMTYPE, "/etc/passwd"
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "restored"
            result = RESTORE.safe_extract(io.BytesIO(fixture([(member, None)])), target)
            self.assertEqual(result["archive_links_skipped"], 1)
            self.assertFalse((target / "site-files/link").exists())
            self.assertFalse((target / "site-files/link").is_symlink())

    def test_link_ancestor_rejected_in_either_archive_order(self):
        link = tarfile.TarInfo("site-files/linked")
        link.type, link.linkname = tarfile.SYMTYPE, "/tmp"
        child = tarfile.TarInfo("site-files/linked/file")
        child.size = 1
        for entries in ([(link, None), (child, b"x")], [(child, b"x"), (link, None)]):
            with self.assertRaises(Exception):
                self.extract(fixture(entries))

    def test_hardlink_to_missing_target_rejected(self):
        member = tarfile.TarInfo("site-files/link")
        member.type, member.linkname = tarfile.LNKTYPE, "site-files/missing"
        with self.assertRaises(Exception):
            self.extract(fixture([(member, None)]))

    def test_duplicate_and_special_members_rejected(self):
        duplicate = tarfile.TarInfo("site-files/wp-config.php")
        duplicate.size = 1
        special = tarfile.TarInfo("site-files/device")
        special.type = tarfile.FIFOTYPE
        for member, contents in ((duplicate, b"x"), (special, None)):
            with self.assertRaises(Exception):
                self.extract(fixture([(member, contents)]))

    def test_corrupt_gzip_fails(self):
        data = bytearray(fixture())
        data[-8] ^= 255
        with self.assertRaises(Exception):
            self.extract(bytes(data))

    def test_preexisting_filesystem_link_cannot_escape(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            outside = root / "outside"
            outside.mkdir()
            fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
            try:
                (root / "linked").symlink_to(outside, target_is_directory=True)
                with self.assertRaises(OSError):
                    RESTORE.private_parent(fd, ("linked", "nested"))
                self.assertFalse((outside / "nested").exists())
            finally:
                os.close(fd)

    def test_cli_failure_does_not_print_private_diagnostics_or_leave_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            encrypted = root / "private-sentinel-filename.gpg"
            encrypted.write_bytes(b"corrupt private-sentinel-contents")
            read_fd, write_fd = os.pipe()
            os.write(write_fd, b"fixture-only-" + b"b" * 32 + b"\n")
            os.close(write_fd)
            try:
                result = subprocess.run(["python3", str(SCRIPT), "--encrypted", str(encrypted),
                                         "--passphrase-fd", str(read_fd)], pass_fds=(read_fd,),
                                        env={**os.environ, "RUNNER_TEMP": str(root)},
                                        capture_output=True, timeout=30)
            finally:
                os.close(read_fd)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn(b"private-sentinel", result.stdout + result.stderr)
            self.assertFalse(list(root.glob("petish-restore-*")))

    @unittest.skipUnless(os.environ.get("PETISH_TEST_DOCKER") == "1", "explicit Docker integration opt-in")
    def test_failed_sql_import_removes_disposable_database(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            sql = root / "fixture.sql"
            sql.write_bytes(b"CREATE TABLE fixture (id INT);\nINVALID_PRIVATE_SENTINEL_SQL;\n")
            with self.assertRaises(RESTORE.RestoreFailure):
                RESTORE.restore_database(sql, root)
            containers = subprocess.run(RESTORE.DOCKER + ["ps", "--all", "--quiet", "--filter",
                                        "label=org.petish.restore-smoke=true"], capture_output=True,
                                        check=True).stdout.strip()
            self.assertEqual(containers, b"")

    @unittest.skipUnless(os.environ.get("PETISH_TEST_DOCKER") == "1", "explicit Docker integration opt-in")
    def test_encrypted_database_restore_and_cleanup(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "gnupg").mkdir(mode=0o700)
            encrypted = root / "fixture.gpg"
            passphrase = b"fixture-only-" + b"a" * 32
            read_fd, write_fd = os.pipe()
            os.write(write_fd, passphrase + b"\n")
            os.close(write_fd)
            try:
                subprocess.run(["gpg", "--no-options", "--homedir", str(root / "gnupg"),
                                "--batch", "--pinentry-mode", "loopback", "--passphrase-fd", str(read_fd),
                                "--symmetric", "--cipher-algo", "AES256", "--output", str(encrypted)],
                               input=fixture(), pass_fds=(read_fd,), stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, check=True)
            finally:
                os.close(read_fd)
            try:
                result = RESTORE.restore(encrypted, passphrase, root)
                self.assertTrue(result["restore_smoke_test_passed"])
                self.assertEqual(result["restored_database_tables"], 1)
                self.assertEqual(result["database_network"], "none")
                self.assertEqual(result["database_events"], "disabled")
                self.assertTrue(result["private_sandbox_removed"])
                self.assertFalse(result["wordpress_application_bootstrap_tested"])
                self.assertFalse(list(root.glob("petish-restore-*")))
            finally:
                subprocess.run(["gpgconf", "--homedir", str(root / "gnupg"), "--kill", "gpg-agent"],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)


if __name__ == "__main__":
    unittest.main()
