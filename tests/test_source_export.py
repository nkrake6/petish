#!/usr/bin/env python3
"""Safety fixtures for editable-source export; all credentials are synthetic."""

import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest import mock


REPO = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("source_screen", REPO / "scripts/screen-wordpress-source.py")
SCREEN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SCREEN)
THEME = SCREEN.ROOTS[0]
PASSPORT = SCREEN.ROOTS[1]
FULFILMENT = SCREEN.ROOTS[2]


def metadata(**changes):
    result = {
        "schema_version": 1,
        "source_site": "https://petish.biz",
        "generated_utc": "2026-10-20T00:00:00Z",
        "missing_roots": [],
        "excluded_counts": {key: 0 for key in SCREEN.EXCLUSION_KEYS},
    }
    result.update(changes)
    return json.dumps(result).encode()


def file_entry(path, content):
    entry = tarfile.TarInfo(path)
    entry.size = len(content)
    return entry, content


def make_archive(path, entries=(), include_roots=True, include_metadata=True):
    with tarfile.open(path, "w:gz", format=tarfile.PAX_FORMAT) as archive:
        if include_roots:
            for root in SCREEN.ROOTS:
                entry = tarfile.TarInfo(root)
                entry.type = tarfile.DIRTYPE
                archive.addfile(entry)
        if include_metadata:
            entries = [file_entry(SCREEN.METADATA_NAME, metadata()), *entries]
        for entry, content in entries:
            archive.addfile(entry, io.BytesIO(content) if content is not None else None)


class ScreeningTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.archive = self.root / "source.tar.gz"
        self.ready = self.root / "ready"

    def manifest(self):
        return json.loads((self.ready / "source-manifest.json").read_text())

    def scan(self, entries=(), **kwargs):
        make_archive(self.archive, entries, **kwargs)
        return SCREEN.scan_archive(self.archive, self.ready)

    def test_complete_source_keeps_original_bytes_and_hashes(self):
        source = b"<?php\nreturn getenv('PETISH_GOOGLE_CLIENT_SECRET');\n"
        self.assertEqual(self.scan([file_entry(PASSPORT + "/passport.php", source)]), 0)
        manifest = self.manifest()
        self.assertEqual(manifest["status"], "complete")
        path = "wordpress/" + PASSPORT + "/passport.php"
        self.assertEqual((self.ready / path).read_bytes(), source)
        self.assertEqual(manifest["files"][0]["sha256"], SCREEN.hashlib.sha256(source).hexdigest())
        self.assertEqual(manifest["source_sha"], SCREEN.sha256_file(self.archive))

    def test_secret_files_wholly_quarantined_without_values_or_snippets(self):
        synthetic = "synthetic-credential-for-fixtures"
        provider = "GOCSPX-" + "x" * 24
        entries = [
            file_entry(THEME + "/safe.css", b"body { color: #fff; }"),
            file_entry("wp-content/mu-plugins/petish-google.php", ("<?php\ndefine('PETISH_CLIENT_SECRET', '" + synthetic + "');\n").encode()),
            file_entry(PASSPORT + "/oauth.json", json.dumps({"client_secret": synthetic}).encode()),
            file_entry(FULFILMENT + "/key.php", ("<?php /* " + provider + " */").encode()),
        ]
        self.assertEqual(self.scan(entries), 0)
        manifest = self.manifest()
        self.assertEqual(manifest["status"], "incomplete")
        self.assertEqual(manifest["file_count"], 1)
        self.assertEqual(manifest["quarantined_file_count"], 3)
        published = (self.ready / "source-manifest.json").read_text()
        self.assertNotIn(synthetic, published)
        self.assertNotIn(provider, published)
        self.assertNotIn("define(", published)
        self.assertEqual({item["rule"] for item in manifest["findings"]}, {
            "literal_credential_definition", "literal_credential_assignment", "google_oauth_secret",
        })

    def test_common_assignment_syntaxes_and_placeholders(self):
        for source in [
            "$client_secret = 'synthetic-value';",
            "'client_secret' => 'synthetic-value',",
            '"accessToken": "synthetic-value"',
            "const API_KEY = 'synthetic-value';",
            "define('GOOGLE_CLIENT_SECRET', 'synthetic-value');",
        ]:
            with self.subTest(source=source):
                self.assertTrue(SCREEN.secret_findings(source))
        self.assertFalse(SCREEN.secret_findings("$client_secret = getenv('PETISH_CLIENT_SECRET');"))
        self.assertFalse(SCREEN.secret_findings("$client_secret = '<configure externally>';"))
        self.assertFalse(SCREEN.secret_findings("$client_secret = '';"))

    def test_localisation_xml_and_catalogues_receive_text_screening(self):
        provider = "GOCSPX-" + "q" * 24
        entries = [
            file_entry(THEME + "/wpml-config.xml", b'<?xml version="1.0"?><wpml-config/>'),
            file_entry(THEME + "/languages/theme.pot", b'msgid "Petish"\nmsgstr ""\n'),
            file_entry(THEME + "/languages/en_AU.po", b'msgid "Color"\nmsgstr "Colour"\n'),
            file_entry(THEME + "/languages/private.po", ('msgid "' + provider + '"\nmsgstr ""\n').encode()),
            file_entry(THEME + "/invalid.xml", b"\xff\xfe\x00\x00"),
        ]
        self.assertEqual(self.scan(entries), 0)
        manifest = self.manifest()
        self.assertEqual(manifest["file_count"], 3)
        self.assertEqual(manifest["quarantined_file_count"], 2)
        self.assertEqual({item["rule"] for item in manifest["findings"]}, {"google_oauth_secret", "non_text_source_payload"})
        self.assertNotIn(provider, (self.ready / "source-manifest.json").read_text())

    def test_private_and_metadata_payloads_are_omitted(self):
        paths = [".env", "._passport.php", "logs/debug.txt", "orders.json", "private/credentials.php", "archive.sql"]
        entries = [file_entry(PASSPORT + "/" + path, b"synthetic-private-fixture") for path in paths]
        entries += [file_entry("wp-content/mu-plugins/petish-staging-guard.php", b"<?php // staging only")]
        self.assertEqual(self.scan(entries), 0)
        self.assertEqual(self.manifest()["file_count"], 0)
        self.assertEqual(sum(self.manifest()["excluded_counts"].values()), len(entries))
        self.assertNotIn("synthetic-private-fixture", (self.ready / "source-manifest.json").read_text())

    def test_binary_signatures_and_embedded_ascii_secrets(self):
        provider = ("GOCSPX-" + "z" * 24).encode()
        entries = [
            file_entry(THEME + "/good.png", b"\x89PNG\r\n\x1a\nfixture"),
            file_entry(THEME + "/disguised.png", b"<?php // not an image"),
            file_entry(THEME + "/metadata.png", b"\x89PNG\r\n\x1a\n" + provider),
        ]
        self.assertEqual(self.scan(entries), 0)
        self.assertEqual(self.manifest()["file_count"], 1)
        self.assertEqual(self.manifest()["quarantined_file_count"], 2)

    def test_out_of_scope_and_traversal_never_leave_partial_source(self):
        for path in ["../escape.php", "/etc/passwd", THEME + "/../escape.php", "wp-config.php", "wp-content/plugins/other/plugin.php"]:
            with self.subTest(path=path), tempfile.TemporaryDirectory() as temporary:
                archive = Path(temporary) / "source.tar.gz"
                ready = Path(temporary) / "ready"
                make_archive(archive, [file_entry(THEME + "/safe.css", b"a{}"), file_entry(path, b"fixture")])
                self.assertEqual(SCREEN.scan_archive(archive, ready), 2)
                self.assertFalse((ready / "wordpress").exists())
                report = json.loads((ready / "source-manifest.json").read_text())
                self.assertEqual(report["status"], "rejected")
                self.assertEqual(report["files"], [])

    def test_symlinks_hardlinks_and_special_files_rejected(self):
        for entry_type in [tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.FIFOTYPE]:
            with self.subTest(entry_type=entry_type), tempfile.TemporaryDirectory() as temporary:
                archive = Path(temporary) / "source.tar.gz"
                ready = Path(temporary) / "ready"
                member = tarfile.TarInfo(THEME + "/link.php")
                member.type = entry_type
                member.linkname = "/etc/passwd"
                make_archive(archive, [(member, None)])
                self.assertEqual(SCREEN.scan_archive(archive, ready), 2)
                self.assertFalse((ready / "wordpress").exists())

    def test_duplicate_members_rejected(self):
        self.assertEqual(self.scan([file_entry(THEME + "/same.php", b"a"), file_entry(THEME + "/same.php", b"b")]), 2)
        self.assertEqual(self.manifest()["diagnostics"], ["duplicate_archive_member"])

    def test_missing_component_is_visible_and_incomplete(self):
        self.assertEqual(self.scan([file_entry(THEME + "/safe.css", b"a{}")], include_roots=False), 0)
        self.assertEqual(self.manifest()["status"], "incomplete")
        self.assertEqual(set(self.manifest()["missing_roots"]), {PASSPORT, FULFILMENT})

    def test_corrupt_gzip_and_expansion_count_limits_fail_closed(self):
        make_archive(self.archive, [file_entry(THEME + "/safe.css", b"a{}")])
        original = self.archive.read_bytes()
        self.archive.write_bytes(original[:-4] + b"bad!")
        self.assertEqual(SCREEN.scan_archive(self.archive, self.ready), 2)
        self.assertFalse((self.ready / "wordpress").exists())
        for key, value in [("MAX_COMPRESSED_BYTES", 8), ("MAX_EXPANDED_BYTES", 500), ("MAX_MEMBERS", 2)]:
            with self.subTest(key=key), tempfile.TemporaryDirectory() as temporary:
                archive = Path(temporary) / "source.tar.gz"
                ready = Path(temporary) / "ready"
                make_archive(archive)
                with mock.patch.object(SCREEN, key, value):
                    self.assertEqual(SCREEN.scan_archive(archive, ready), 2)

    def test_cli_provenance_is_allowlisted_and_logs_are_redacted(self):
        make_archive(self.archive, [file_entry(PASSPORT + "/oauth.php", b"<?php $client_secret = 'synthetic-private-fixture';")])
        result = subprocess.run([
            "python3", str(REPO / "scripts/screen-wordpress-source.py"), str(self.archive), str(self.ready),
            "--provenance", "source_site=petish.biz", "--provenance", "workflow_sha=" + "a" * 40,
        ], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)
        self.assertNotIn("synthetic-private-fixture", result.stdout + result.stderr)
        self.assertEqual(self.manifest()["workflow_sha"], "a" * 40)

    def test_gitleaks_path_variants_and_whole_file_quarantine(self):
        for spelling in ["absolute", "wordpress", "wp-content", "ready-prefix"]:
            with self.subTest(spelling=spelling), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                archive, ready, report = root / "source.tar.gz", root / "ready", root / "private-report.json"
                relative = "wordpress/" + THEME + "/safe.css"
                make_archive(archive, [file_entry(THEME + "/safe.css", b"a{}")])
                self.assertEqual(SCREEN.scan_archive(archive, ready), 0)
                path = {"absolute": str(ready / relative), "wordpress": relative, "wp-content": relative[len("wordpress/"):], "ready-prefix": "ready/" + relative}[spelling]
                report.write_text(json.dumps([{"File": path, "RuleID": "fixture-detection", "StartLine": 1, "Secret": "synthetic-private-match", "Match": "synthetic-private-snippet"}]))
                self.assertEqual(SCREEN.apply_gitleaks(ready, report), 0)
                self.assertFalse((ready / relative).exists())
                public = (ready / "source-manifest.json").read_text()
                self.assertNotIn("synthetic-private-match", public)
                self.assertNotIn("synthetic-private-snippet", public)
                self.assertEqual(json.loads(public)["status"], "incomplete")

    def test_bad_external_report_or_changed_source_discards_ready_tree(self):
        for bad_path in ["../outside.php", "/etc/passwd", "wordpress/wp-content/plugins/unknown.php"]:
            with self.subTest(bad_path=bad_path), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                archive, ready, report = root / "source.tar.gz", root / "ready", root / "report.json"
                make_archive(archive, [file_entry(THEME + "/safe.css", b"a{}")])
                SCREEN.scan_archive(archive, ready)
                report.write_text(json.dumps([{"File": bad_path, "Secret": "synthetic-private-match"}]))
                self.assertEqual(SCREEN.apply_gitleaks(ready, report), 2)
                self.assertFalse((ready / "wordpress").exists())
        self.scan([file_entry(THEME + "/safe.css", b"a{}")])
        (self.ready / "wordpress" / THEME / "safe.css").write_bytes(b"changed")
        report = self.root / "report.json"
        report.write_text("[]")
        self.assertEqual(SCREEN.apply_gitleaks(self.ready, report), 2)


class RemoteExportTests(unittest.TestCase):
    def test_remote_scope_no_bootstrap_or_link_following_and_private_pruning(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            fake_home = root / "home"
            site = fake_home / "domains/petish.biz/public_html"
            for relative in SCREEN.ROOTS:
                (site / relative).mkdir(parents=True)
            mu = site / "wp-content/mu-plugins"
            mu.mkdir()
            (site / THEME / "safe.css").write_text("a{}")
            (site / PASSPORT / "passport.php").write_text("<?php // source only")
            (site / FULFILMENT / "fulfilment.php").write_text("<?php // source only")
            (site / "wp-config.php").write_text("<?php file_put_contents('bootstrap-was-executed', 'yes');")
            (site / "wp-content/uploads").mkdir()
            (site / "wp-content/uploads/private.txt").write_text("synthetic-private-fixture")
            (mu / "petish-good.php").write_text("<?php // include")
            (mu / "other.php").write_text("<?php // exclude")
            (mu / "petish-staging-guard.php").write_text("<?php // exclude")
            (mu / "petish-staging-guard").mkdir()
            (mu / "petish-staging-guard/inner.php").write_text("<?php // exclude")
            (site / PASSPORT / "logs").mkdir()
            (site / PASSPORT / "logs/private.php").write_text("synthetic-private-fixture")
            (site / PASSPORT / ".env").write_text("synthetic-private-fixture")
            (site / PASSPORT / "._mac.php").write_text("synthetic-private-fixture")
            (site / PASSPORT / "outside.php").symlink_to(site / "wp-config.php")
            os.mkfifo(site / PASSPORT / "private-pipe")
            completed = subprocess.run(["bash", str(REPO / "scripts/export-wordpress-source-remote.sh")], env={**os.environ, "HOME": str(fake_home)}, capture_output=True, timeout=15)
            self.assertEqual(completed.returncode, 0, completed.stderr.decode())
            archive = root / "source.tar.gz"
            archive.write_bytes(completed.stdout)
            with tarfile.open(archive) as content:
                names = content.getnames()
                self.assertIn("wp-content/mu-plugins/petish-good.php", names)
                self.assertNotIn("wp-config.php", names)
                self.assertNotIn("wp-content/mu-plugins/other.php", names)
                self.assertFalse(any("staging-guard" in name for name in names))
                self.assertFalse(any("outside.php" in name or "/logs/" in name or ".env" in name or "._" in name for name in names))
                self.assertTrue(all(member.isfile() or member.isdir() for member in content.getmembers()))
                remote_metadata = json.load(content.extractfile(SCREEN.METADATA_NAME))
                self.assertEqual(remote_metadata["excluded_counts"]["links"], 1)
                self.assertEqual(remote_metadata["excluded_counts"]["special"], 1)
            self.assertFalse((site / "bootstrap-was-executed").exists())
            self.assertEqual(list(fake_home.glob(".petish-source-export.*")), [])
            self.assertEqual(SCREEN.scan_archive(archive, root / "ready"), 0)
            self.assertEqual(json.loads((root / "ready/source-manifest.json").read_text())["status"], "incomplete")


if __name__ == "__main__":
    unittest.main()
