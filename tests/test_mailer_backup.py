"""Full export, private SMTP validation and encrypted v2/v3 compatibility fixtures."""

import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shlex
import subprocess
import tarfile
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / "scripts" / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


VERIFY = load("mailer_backup_verifier", "verify-full-backup.py")
PACKAGE = load("mailer_backup_packager", "package-encrypted-backup.py")
RESTORE = load("mailer_backup_restore", "test-full-backup-restore.py")
CONFIG = {"host": "smtp.hostinger.com", "port": 465, "encryption": "smtps",
          "username": "administrator@petish.biz", "password": "private-fixture-password-only"}


class MailerBackupTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.host = self.root / "host"
        self.domain = self.host / "domains/petish.biz"
        self.site = self.domain / "public_html"
        (self.site / "wp-includes").mkdir(parents=True)
        self.mu = self.site / "wp-content/mu-plugins/petish-mailer.php"
        self.mu.parent.mkdir(parents=True)
        (self.site / "wp-config.php").write_text("<?php // private fixture only\n")
        (self.site / "wp-includes/version.php").write_text("<?php $wp_version='fixture';\n")
        passport = self.domain / "private/petish-passport"
        passport.mkdir(parents=True)
        (passport / "private-photo.jpg").write_bytes(b"fixture photo")
        self.mailer = self.domain / "private/petish-mailer"
        self.bin = self.root / "bin"
        self.bin.mkdir()
        wp = self.bin / "wp"
        wp.write_text('''#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "${!#}" >> "$TEST_WP_CALLS"
case "${!#}" in
 DB_HOST) printf 'localhost\n';;
 DB_NAME) printf 'fixture_db\n';;
 DB_USER) printf 'fixture_user\n';;
 DB_PASSWORD) printf 'private-fixture-database-password\n';;
 MYSQL_CLIENT_FLAGS) exit 1;;
 *) exit 44;;
esac
''')
        wp.chmod(0o700)
        dump_source = '''#!/usr/bin/env bash
set -euo pipefail
if [[ " $* " == *' --help '* ]];then printf '%s\n' '--no-tablespaces --column-statistics';exit 0;fi
[[ "$MYSQL_PWD" == private-fixture-database-password ]]
printf '%s\n' 'CREATE TABLE fixture (id INT);' 'INSERT INTO fixture VALUES (7);'
'''
        for name in ("mariadb-dump", "mysqldump"):
            dump = self.bin / name
            dump.write_text(dump_source)
            dump.chmod(0o700)
        source = (ROOT / "scripts/backup-wordpress-remote.sh").read_text()
        source = source.replace('site_path="$HOME/domains/petish.biz/public_html"', "site_path=" + shlex.quote(str(self.site)))
        source = source.replace('domain_path="$HOME/domains/petish.biz"', "domain_path=" + shlex.quote(str(self.domain)))
        source = source.replace('home_real="$(cd -- "$HOME" 2>/dev/null && pwd -P)"',
                                'home_real="$(cd -- ' + shlex.quote(str(self.host)) + ' 2>/dev/null && pwd -P)"')
        self.script = self.root / "remote.sh"
        self.script.write_text(source)
        self.environment = {**os.environ, "PATH": str(self.bin) + os.pathsep + os.environ["PATH"],
                            "TEST_WP_CALLS": str(self.root / "wp-calls")}

    def enable_mailer(self, adapter=True):
        self.mailer.mkdir(mode=0o700)
        config = self.mailer / "smtp.json"
        config.write_text(json.dumps(CONFIG))
        config.chmod(0o600)
        if adapter:
            self.mu.write_text("<?php // fixture adapter\n")

    def export(self):
        (self.root / "wp-calls").unlink(missing_ok=True)
        result = subprocess.run(["bash", str(self.script)], env=self.environment,
                                capture_output=True, timeout=30)
        self.assertFalse(list(self.host.glob(".petish-full-backup.*")))
        self.assertNotIn(CONFIG["password"].encode(), result.stderr)
        self.assertNotIn(b"private-fixture-database-password", result.stderr)
        return result

    def assert_export_rejected(self):
        result = self.export()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, b"")
        self.assertFalse((self.root / "wp-calls").exists())

    def test_legacy_without_adapter_or_private_mailer_remains_valid_v2(self):
        result = self.export()
        self.assertEqual(result.returncode, 0, "Legacy export failed")
        report = VERIFY.verify(io.BytesIO(result.stdout))
        self.assertFalse(report["private_smtp_config_verified"])
        self.assertEqual(report["private_smtp_regular_files"], 0)
        self.assertEqual(report["private_regular_files"], 1)

    def test_adapter_requires_existing_private_root_and_config(self):
        self.mu.write_text("<?php // fixture adapter\n")
        self.assert_export_rejected()
        self.mailer.mkdir(mode=0o700)
        self.assert_export_rejected()

    def test_valid_private_tree_is_complete_and_never_dereferences_links(self):
        self.enable_mailer()
        (self.mailer / "private-note.txt").write_bytes(b"private fixture additional file")
        (self.mailer / "external-reference").symlink_to("/tmp/private-fixture-never-read")
        result = self.export()
        self.assertEqual(result.returncode, 0, "SMTP export failed")
        self.assertEqual(result.stderr, b"")
        report = VERIFY.verify(io.BytesIO(result.stdout))
        self.assertTrue(report["private_smtp_config_verified"])
        self.assertEqual(report["private_smtp_regular_files"], 2)
        self.assertEqual(report["private_regular_files"], 1)
        self.assertNotIn(CONFIG["password"], json.dumps(report))
        with tarfile.open(fileobj=io.BytesIO(result.stdout), mode="r:gz") as archive:
            self.assertEqual(json.loads(archive.extractfile(VERIFY.SMTP_CONFIG).read()), CONFIG)
            self.assertTrue(archive.getmember(VERIFY.SMTP_ROOT + "/external-reference").issym())
            metadata = archive.extractfile("backup-metadata/metadata.txt").read()
            self.assertIn(b"source_private_smtp_root=", metadata)

    def test_orphan_private_tree_is_included_without_adapter(self):
        self.enable_mailer(adapter=False)
        result = self.export()
        self.assertEqual(result.returncode, 0, "Orphan private tree was omitted")
        self.assertTrue(VERIFY.verify(io.BytesIO(result.stdout))["private_smtp_config_verified"])

    def test_extractor_restores_smtp_outside_document_root_with_private_permissions(self):
        self.enable_mailer()
        (self.mailer / "private-note.txt").write_bytes(b"private fixture additional file")
        exported = self.export()
        self.assertEqual(exported.returncode, 0)
        verified = VERIFY.verify(io.BytesIO(exported.stdout))
        destination = self.root / "restored"
        restored = RESTORE.safe_extract(io.BytesIO(exported.stdout), destination)
        mailer = destination / "private-files/petish-mailer"
        config = mailer / "smtp.json"
        self.assertEqual(json.loads(config.read_text()), CONFIG)
        self.assertEqual(mailer.stat().st_mode & 0o7777, 0o700)
        self.assertEqual(config.stat().st_mode & 0o7777, 0o600)
        self.assertEqual(restored["private_smtp_files_restored"], verified["private_smtp_regular_files"])
        self.assertEqual(restored["private_smtp_files_restored"], 2)
        self.assertFalse(config.is_relative_to(destination / "site-files"))
        self.assertFalse((destination / "site-files/private").exists())

    def test_symlinked_root_or_configuration_is_rejected(self):
        self.enable_mailer()
        saved = self.domain / "private/saved-mailer"
        self.mailer.rename(saved)
        self.mailer.symlink_to(saved, target_is_directory=True)
        self.assert_export_rejected()
        self.mailer.unlink()
        saved.rename(self.mailer)
        config = self.mailer / "smtp.json"
        config.rename(self.mailer / "saved-config.json")
        config.symlink_to("saved-config.json")
        self.assert_export_rejected()

    def test_unsafe_permissions_and_hardlinked_config_are_rejected(self):
        self.enable_mailer()
        self.mailer.chmod(0o755)
        self.assert_export_rejected()
        self.mailer.chmod(0o700)
        config = self.mailer / "smtp.json"
        config.chmod(0o640)
        self.assert_export_rejected()
        config.chmod(0o600)
        os.link(config, self.mailer / "same-secret.json")
        self.assert_export_rejected()

    def rewrite_archive(self, data, edit, additional=()):
        output = io.BytesIO()
        with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as original, \
                tarfile.open(fileobj=output, mode="w:gz") as target:
            for member in original:
                contents = original.extractfile(member).read() if member.isfile() else None
                changed = edit(member, contents)
                if changed is None:
                    continue
                member, contents = changed
                if contents is not None:
                    member.size = len(contents)
                target.addfile(member, io.BytesIO(contents) if contents is not None else None)
            for member, contents in additional:
                target.addfile(member, io.BytesIO(contents) if contents is not None else None)
        return output.getvalue()

    def test_verifier_rejects_missing_config_unsafe_links_paths_and_permissions(self):
        self.enable_mailer()
        data = self.export().stdout
        def edit_case(case):
            def edit(member, contents):
                if member.name == VERIFY.SMTP_CONFIG:
                    if case == "missing":
                        return None
                    if case == "symlink":
                        member.type, member.linkname, member.size = tarfile.SYMTYPE, "/outside", 0
                        contents = None
                    if case == "traversal":
                        member.name = VERIFY.SMTP_ROOT + "/../escaped-secret"
                    if case == "file-permissions":
                        member.mode = 0o644
                if member.name == VERIFY.SMTP_ROOT:
                    if case == "missing-root":
                        return None
                    if case == "root-permissions":
                        member.mode = 0o755
                return member, contents
            return edit
        for case in ("missing", "missing-root", "symlink", "traversal", "file-permissions", "root-permissions"):
            with self.subTest(case=case), self.assertRaises(ValueError):
                VERIFY.verify(io.BytesIO(self.rewrite_archive(data, edit_case(case))))

    def test_verifier_rejects_mailer_link_ancestors_and_config_hardlinks(self):
        self.enable_mailer()
        data = self.export().stdout
        link = tarfile.TarInfo(VERIFY.SMTP_ROOT + "/linked")
        link.type, link.linkname = tarfile.SYMTYPE, "/outside"
        child = tarfile.TarInfo(VERIFY.SMTP_ROOT + "/linked/private-note")
        child.size = 1
        hardlink = tarfile.TarInfo(VERIFY.SMTP_ROOT + "/credential-alias")
        hardlink.type, hardlink.linkname = tarfile.LNKTYPE, VERIFY.SMTP_CONFIG
        for additional in ([(link, None), (child, b"x")], [(child, b"x"), (link, None)], [(hardlink, None)]):
            with self.subTest(kind=additional[0][0].type), self.assertRaises(ValueError):
                VERIFY.verify(io.BytesIO(self.rewrite_archive(data, lambda member, contents: (member, contents), additional)))

    def test_schema_failure_prints_no_private_values(self):
        self.enable_mailer()
        for key, value in (("host", ["private-fixture-host"]), ("port", True), ("encryption", "none"),
                           ("username", "private-fixture-user"), ("username", "orders@petish.biz"),
                           ("password", "private-fixture\npassword")):
            with self.subTest(key=key):
                (self.mailer / "smtp.json").write_text(json.dumps({**CONFIG, key: value}))
                data = self.export().stdout
                result = subprocess.run(["python3", str(ROOT / "scripts/verify-full-backup.py")],
                                        input=data, capture_output=True, timeout=30)
                self.assertEqual(result.returncode, 1)
                self.assertNotIn(b"private-fixture", result.stdout + result.stderr)
                self.assertNotIn(b"Traceback", result.stdout + result.stderr)

    def test_real_encrypted_packaging_supports_v2_and_v3_without_secret_metadata(self):
        gnupg = self.root / "gnupg"
        gnupg.mkdir(mode=0o700)
        try:
            for version in (2, 3):
                if version == 3:
                    self.enable_mailer()
                data = self.export().stdout
                report = VERIFY.verify(io.BytesIO(data))
                verification = self.root / f"verification-{version}.json"
                verification.write_text(json.dumps(report))
                encrypted = self.root / f"fixture-{version}.gpg"
                read_fd, write_fd = os.pipe()
                os.write(write_fd, b"fixture-only-encryption-password-123456789\n")
                os.close(write_fd)
                try:
                    subprocess.run(["gpg", "--no-options", "--homedir", str(gnupg), "--batch",
                                    "--pinentry-mode", "loopback", "--passphrase-fd", str(read_fd),
                                    "--symmetric", "--cipher-algo", "AES256", "--output", str(encrypted)],
                                   input=data, pass_fds=(read_fd,), stdout=subprocess.DEVNULL,
                                   stderr=subprocess.DEVNULL, check=True, timeout=30)
                finally:
                    os.close(read_fd)
                destination = self.root / f"published-{version}"
                with contextlib.redirect_stdout(io.StringIO()):
                    PACKAGE.package(encrypted, destination, verification, part_bytes=512)
                manifest_text = (destination / "manifest.json").read_text()
                manifest = json.loads(manifest_text)
                self.assertEqual(manifest["format"], f"petish-full-wordpress-backup-v{version}")
                self.assertNotIn(CONFIG["password"], manifest_text)
                self.assertNotIn("private-note", manifest_text)
                joined = b"".join((destination / part["file"]).read_bytes() for part in manifest["parts"])
                self.assertEqual(joined, encrypted.read_bytes())
                if version == 3:
                    self.assertIn("private/petish-mailer/", (destination / "RESTORE.md").read_text())
        finally:
            subprocess.run(["gpgconf", "--homedir", str(gnupg), "--kill", "gpg-agent"],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False, timeout=15)


if __name__ == "__main__":
    unittest.main()
