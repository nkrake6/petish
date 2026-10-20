"""Encrypted Git input fixtures; disposable socket integration is opt-in."""

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile
import unittest
import uuid


ROOT = Path(__file__).resolve().parents[1]
PREPARE = ROOT / "scripts/prepare-recovery-backup.py"
SPEC = importlib.util.spec_from_file_location("application_recovery_workflow", ROOT / "scripts/verify-wordpress-recovery.py")
RECOVERY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RECOVERY)
BRANCH = "full-site-backup/123456-1"


class EncryptedGitInputTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.encryption_work = tempfile.TemporaryDirectory()
        root = Path(cls.encryption_work.name)
        (root / "gnupg").mkdir(mode=0o700)
        encrypted = root / "fixture.gpg"
        read_fd, write_fd = os.pipe()
        os.write(write_fd, b"fixture-only-encryption-passphrase-123456789\n")
        os.close(write_fd)
        try:
            subprocess.run(["gpg", "--no-options", "--homedir", str(root / "gnupg"),
                            "--batch", "--pinentry-mode", "loopback", "--passphrase-fd", str(read_fd),
                            "--symmetric", "--cipher-algo", "AES256", "--output", str(encrypted)],
                           input=b"fixture-only encrypted backup bytes\n", pass_fds=(read_fd,),
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=30)
        finally:
            os.close(read_fd)
        cls.encrypted = encrypted.read_bytes()

    @classmethod
    def tearDownClass(cls):
        subprocess.run(["gpgconf", "--homedir", str(Path(cls.encryption_work.name) / "gnupg"),
                        "--kill", "gpg-agent"], stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, check=False, timeout=15)
        cls.encryption_work.cleanup()

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.repo = self.root / "repository"
        self.repo.mkdir()
        self.git("init", "--quiet")
        self.git("checkout", "--quiet", "-b", BRANCH)
        self.directory = self.repo / "site-backups/123456-1"
        self.directory.mkdir(parents=True)
        midpoint = len(self.encrypted) // 2
        chunks = (self.encrypted[:midpoint], self.encrypted[midpoint:])
        self.manifest = {"format": "petish-full-wordpress-backup-v2",
                         "encrypted_bytes": len(self.encrypted),
                         "encrypted_sha256": hashlib.sha256(self.encrypted).hexdigest(), "parts": []}
        for index, chunk in enumerate(chunks):
            name = f"site-backup.tar.gz.gpg.part-{index:05d}"
            (self.directory / name).write_bytes(chunk)
            self.manifest["parts"].append({"file": name, "bytes": len(chunk),
                                            "sha256": hashlib.sha256(chunk).hexdigest()})

    def git(self, *arguments):
        return subprocess.run(["git", "-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid",
                               *arguments], cwd=self.repo, stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL, check=True, timeout=30)

    def run_prepare(self, branch=BRANCH):
        (self.directory / "manifest.json").write_text(json.dumps(self.manifest))
        self.git("add", ".")
        self.git("commit", "--quiet", "-m", "encrypted fixture")
        self.git("remote", "add", "origin", str(self.repo))
        output = self.root / "recovery-input"
        result = subprocess.run([sys.executable, str(PREPARE), branch, str(output)],
                                cwd=self.repo, capture_output=True, timeout=30)
        return result, output

    def assert_private_failure(self, result):
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, b"")
        self.assertEqual(result.stderr, b"Encrypted recovery input could not be verified. No backup contents were printed.\n")
        self.assertNotIn(str(self.root).encode(), result.stdout + result.stderr)
        self.assertNotIn(b"private-sentinel", result.stdout + result.stderr)

    def test_reassembles_real_aes256_encrypted_parts(self):
        result, output = self.run_prepare()
        self.assertEqual(result.returncode, 0)
        self.assertEqual((output / "site-backup.tar.gz.gpg").read_bytes(), self.encrypted)
        self.assertEqual(output.stat().st_mode & 0o777, 0o700)

    def test_missing_part_is_rejected_without_private_git_error(self):
        (self.directory / self.manifest["parts"][1]["file"]).unlink()
        result, _ = self.run_prepare()
        self.assert_private_failure(result)

    def test_corrupt_part_checksum_is_rejected(self):
        part = self.directory / self.manifest["parts"][0]["file"]
        data = bytearray(part.read_bytes())
        data[0] ^= 255
        part.write_bytes(data)
        result, _ = self.run_prepare()
        self.assert_private_failure(result)

    def test_part_path_traversal_cannot_access_other_git_files(self):
        self.manifest["parts"][0]["file"] = "../../private-sentinel"
        (self.repo / "private-sentinel").write_text("private-sentinel contents")
        result, _ = self.run_prepare()
        self.assert_private_failure(result)

    def test_unsafe_total_and_part_sizes_fail_closed(self):
        for field, value in (("total", 1610612737), ("total", True), ("part", 48 * 1024 * 1024 + 1)):
            with self.subTest(field=field, value=value):
                original = json.loads(json.dumps(self.manifest))
                if field == "total":
                    self.manifest["encrypted_bytes"] = value
                else:
                    self.manifest["parts"][0]["bytes"] = value
                # Each fixture uses a distinct private output directory.
                (self.directory / "manifest.json").write_text(json.dumps(self.manifest))
                self.git("add", ".")
                self.git("commit", "--quiet", "-m", "unsafe size fixture")
                if not (self.repo / ".git/config").read_text().count('[remote "origin"]'):
                    self.git("remote", "add", "origin", str(self.repo))
                output = self.root / ("unsafe-" + str(value) + field)
                result = subprocess.run([sys.executable, str(PREPARE), BRANCH, str(output)],
                                        cwd=self.repo, capture_output=True, timeout=30)
                self.assert_private_failure(result)
                self.manifest = original

    def test_unsafe_branch_fails_before_creating_directory(self):
        for branch in ("--upload-pack=private-sentinel", "main", "full-site-backup/123-1:refs/heads/main",
                       "full-site-backup/../../private-sentinel"):
            with self.subTest(branch=branch):
                output = self.root / "must-not-exist"
                result = subprocess.run([sys.executable, str(PREPARE), branch, str(output)],
                                        cwd=self.repo, capture_output=True, timeout=30)
                self.assert_private_failure(result)
                self.assertFalse(output.exists())

    def test_manifest_private_exception_data_is_never_printed(self):
        self.manifest["encrypted_sha256"] = {"private-sentinel": "secret fixture"}
        result, _ = self.run_prepare()
        self.assert_private_failure(result)


class SharedSocketIntegrationTests(unittest.TestCase):
    @unittest.skipUnless(os.environ.get("PETISH_TEST_DOCKER") == "1", "explicit Docker integration opt-in")
    def test_pinned_php_host_user_connects_to_readonly_database_socket(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            root.chmod(0o700)
            sql = root / "fixture.sql"
            sql.write_text("CREATE TABLE fixture (id INT PRIMARY KEY);\nINSERT INTO fixture VALUES (7);\n")
            state = ["input"]
            with RECOVERY.database(sql, root, state) as (name, volume, result):
                self.assertTrue(result["database_import_verified"])
                username, password = "fixture_app", secrets.token_hex(32)
                grant = root / "grant.sql"
                grant.write_text("CREATE USER '" + username + "'@'localhost' IDENTIFIED BY '" + password + "';\n"
                                 "GRANT SELECT ON petish_restore.* TO '" + username + "'@'localhost';\n")
                with grant.open("rb") as source:
                    RECOVERY.BASE.run_private(["exec", "--interactive", name, "mariadb",
                        "--defaults-extra-file=/tmp/petish-restore-client.cnf", "--binary-mode=1"],
                        root, source=source)
                probe = root / "socket-probe.php"
                probe.write_text("<?php $db=new mysqli('localhost','" + username + "','" + password +
                    "','petish_restore',3306,'/run/mysqld/mysqld.sock');"
                    "$value=$db->query('SELECT id FROM fixture')->fetch_row();"
                    "if($value[0] !== '7'){exit(2);}echo 'SOCKET_OK';")
                probe.chmod(0o600)
                RECOVERY.BASE.run_private(["pull", RECOVERY.APPLICATION.IMAGE], root, timeout=300)
                app_name = "petish-recovery-socket-fixture-" + uuid.uuid4().hex
                try:
                    output = RECOVERY.BASE.run_private(["run", "--rm", "--name", app_name,
                        "--label", "org.petish.application-recovery=true", "--network", "none",
                        "--user", str(os.getuid()) + ":" + str(os.getgid()), "--cap-drop", "ALL",
                        "--security-opt", "no-new-privileges:true",
                        "--mount", "type=volume,src=" + volume + ",dst=/run/mysqld,readonly",
                        "--mount", "type=bind,src=" + str(probe) + ",dst=/socket-probe.php,readonly",
                        "--entrypoint", "php", RECOVERY.APPLICATION.IMAGE, "/socket-probe.php"],
                        root, capture=True, timeout=60)
                    self.assertEqual(output, b"SOCKET_OK")
                finally:
                    subprocess.run(RECOVERY.BASE.DOCKER + ["rm", "--force", app_name],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30, check=False)
            self.assertEqual(state[0], "database_verify")


if __name__ == "__main__":
    unittest.main()
