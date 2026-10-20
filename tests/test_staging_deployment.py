"""Isolated package/deploy regressions; never connects to a server or boots WP.

Run with python -m unittest discover -s tests -p test_staging_deployment.py.
Remote integration tests require the normal php CLI (including tokenizer/zlib).
"""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("staging_package", ROOT / "scripts/package-staging-source.py")
PACKAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE)
SHA = "a" * 40


def make_source(root, version="new"):
    for name in PACKAGE.REQUIRED:
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<?php // " + version + "\n" if path.suffix == ".php" else "/* Theme Name: Petish */\n" + version)


def hashes(root):
    return {str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in root.rglob("*") if path.is_file() and not path.is_symlink()}


class PackagingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        make_source(self.source)

    def test_packages_exact_scope_with_manifest_and_hashes(self):
        out = self.root / "payload.tar.gz"
        manifest = PACKAGE.package(self.source, out, SHA)
        with tarfile.open(out) as archive:
            self.assertEqual(archive.getnames()[0], PACKAGE.MANIFEST)
            self.assertEqual(set(archive.getnames()[1:]), set(PACKAGE.REQUIRED))
            for entry in manifest["files"]:
                self.assertEqual(hashlib.sha256(archive.extractfile(entry["path"]).read()).hexdigest(), entry["sha256"])

    def test_atime_change_does_not_reject_stable_source(self):
        for name in PACKAGE.REQUIRED:
            path = self.source / name
            os.utime(path, ns=(0, path.stat().st_mtime_ns))
        PACKAGE.package(self.source, self.root / "payload.tar.gz", SHA)

    def test_rejects_links_private_files_and_literal_secrets(self):
        component = self.source / PACKAGE.COMPONENTS[0]
        scenarios = [("linked.php", "link"), (".env", "private"),
                     ("credentials.php", "<?php $client_secret = 'abcdefghijklmnopqrstuv';")]
        for name, content in scenarios:
            with self.subTest(name=name):
                path = component / name
                if content == "link":
                    path.symlink_to(component / "functions.php")
                else:
                    path.write_text(content)
                with self.assertRaises(ValueError):
                    PACKAGE.package(self.source, self.root / "payload.tar.gz", SHA)
                path.unlink()


@unittest.skipUnless(shutil.which("php"), "php CLI required for standalone validation")
class RemoteTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.home = self.base / "account"
        self.home.mkdir()
        self.target = self.home / "domains/staging.petish.biz/public_html"
        self.live = self.home / "domains/petish.biz/public_html"
        make_source(self.target, "old")
        make_source(self.live, "production")
        mu = self.target / "wp-content/mu-plugins"
        mu.mkdir()
        (mu / "petish-staging-guard.php").write_text("<?php define('PETISH_STAGING_GUARD_ACTIVE', true);\n")
        self.private = self.home / ".petish-staging/config.php"
        self.private.parent.mkdir()
        self.private.write_text("<?php\ndefine('WP_ENVIRONMENT_TYPE', 'staging');\ndefine('WCPAY_DEV_MODE', true);\n")
        (self.target / "wp-config.php").write_text("<?php\nrequire '" + str(self.private) + "';\n")
        (self.target / ".htaccess").write_text("deny protected\n")
        self.source = self.base / "source"
        make_source(self.source)
        self.archive = self.home / "payload.tar.gz"
        PACKAGE.package(self.source, self.archive, SHA)
        self.env = dict(os.environ, HOME=str(self.home))

    def deploy(self, mode="check", sha=SHA):
        return subprocess.run(["bash", str(ROOT / "scripts/deploy-staging-remote.sh"), str(self.archive), mode, sha],
                              env=self.env, capture_output=True, text=True)

    def test_check_preserves_site_then_apply_preserves_guards_and_live(self):
        before, live = hashes(self.target), hashes(self.live)
        check = self.deploy()
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
        self.assertEqual(check.stdout, "STAGING_DEPLOY_CHECK_PASSED\n")
        self.assertEqual(before, hashes(self.target))
        applied = self.deploy("apply")
        self.assertEqual(applied.returncode, 0, applied.stdout + applied.stderr)
        self.assertEqual(applied.stdout, "STAGING_DEPLOY_APPLIED\n")
        self.assertEqual(live, hashes(self.live))
        after = hashes(self.target)
        for name in before:
            if not any(name.startswith(component + "/") for component in PACKAGE.COMPONENTS):
                self.assertEqual(before[name], after[name])
        self.assertIn("new", (self.target / PACKAGE.REQUIRED[1]).read_text())
        backups = list((self.home / ".petish-staging-releases").glob("*/backup/0/functions.php"))
        self.assertEqual(len(backups), 1)
        self.assertIn("old", backups[0].read_text())

    def test_wrong_source_and_unsafe_settings_are_rejected(self):
        before = hashes(self.target)
        self.assertNotEqual(self.deploy(sha="b" * 40).returncode, 0)
        configs = ["<?php if(false) define('WP_ENVIRONMENT_TYPE','staging'); define('WCPAY_DEV_MODE',true);",
                   "<?php define('WP_ENVIRONMENT_TYPE','staging'); define('WCPAY_DEV_MODE',false);"]
        for text in configs:
            self.private.write_text(text)
            self.assertNotEqual(self.deploy("apply").returncode, 0)
        self.assertEqual(before, hashes(self.target))

    def test_comment_does_not_prove_config_loader(self):
        (self.target / "wp-config.php").write_text("<?php // require '" + str(self.private) + "';\n")
        self.assertNotEqual(self.deploy().returncode, 0)

    def test_destination_symlink_is_rejected_without_touching_production(self):
        component = PACKAGE.COMPONENTS[1]
        shutil.rmtree(self.target / component)
        (self.target / component).symlink_to(self.live / component, target_is_directory=True)
        before = hashes(self.live)
        self.assertNotEqual(self.deploy("apply").returncode, 0)
        self.assertEqual(before, hashes(self.live))

    def rewrite_archive(self, change):
        with tarfile.open(self.archive) as archive:
            items = [(member, archive.extractfile(member).read()) for member in archive]
        items = change(items)
        self.archive.unlink()
        with tarfile.open(self.archive, "w:gz", format=tarfile.USTAR_FORMAT) as archive:
            for member, data in items:
                archive.addfile(member, io.BytesIO(data) if member.isfile() else None)

    def test_tamper_duplicate_traversal_and_archive_links_are_rejected(self):
        original = self.archive.read_bytes()
        for attack in ("hash", "duplicate", "traversal", "symlink", "hardlink"):
            with self.subTest(attack=attack):
                self.archive.write_bytes(original)
                def change(items):
                    if attack == "hash":
                        member, data = items[1]
                        data = data.replace(b"new", b"bad")
                        member.size = len(data)
                        items[1] = member, data
                    elif attack == "duplicate":
                        items.append(items[1])
                    else:
                        member = tarfile.TarInfo("../escape" if attack == "traversal" else PACKAGE.COMPONENTS[0] + "/linked.php")
                        if attack in ("symlink", "hardlink"):
                            member.type = tarfile.SYMTYPE if attack == "symlink" else tarfile.LNKTYPE
                            member.linkname = "/outside"
                        items.append((member, b""))
                    return items
                self.rewrite_archive(change)
                before = hashes(self.target)
                self.assertNotEqual(self.deploy("apply").returncode, 0)
                self.assertEqual(before, hashes(self.target))

    def test_second_component_swap_failure_rolls_back_every_component(self):
        before = hashes(self.target)
        bin_dir = self.base / "bin"
        bin_dir.mkdir()
        real_mv = shutil.which("mv")
        wrapper = bin_dir / "mv"
        wrapper.write_text("#!/bin/bash\nfor value in \"$@\"; do\ncase \"$value\" in */new/wp-content/plugins/petish-passport) exit 1;; esac\ndone\nexec '" + real_mv + "' \"$@\"\n")
        wrapper.chmod(0o700)
        self.env["PATH"] = str(bin_dir) + os.pathsep + self.env["PATH"]
        result = self.deploy("apply")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("STAGING_DEPLOY_ROLLED_BACK", result.stderr)
        self.assertEqual(before, hashes(self.target))

    def test_existing_deployment_lock_rejects_concurrency(self):
        lock = self.home / ".petish-staging-releases/deploy.lock"
        lock.mkdir(parents=True)
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("STAGING_DEPLOY_LOCKED", result.stderr)


if __name__ == "__main__":
    unittest.main()
