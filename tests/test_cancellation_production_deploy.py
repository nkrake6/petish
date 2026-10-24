"""Independent scope and transaction boundaries for the approved production upgrade."""
import hashlib
import copy
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/deploy-cancellation-production.php"
PHP = shutil.which("php") or "/workspace/.petish-dev/bin/php"
BASELINE = "baa3e0e90c141fbe312178fd3b8f73820bff4676"
# The prior cost-sheet-only release retained this known historical README.
README_BASELINE = "0f292591a537bfc522d0a3b8b35afcd2c13e5dac"


def live_baseline_ref(name):
    return README_BASELINE if name == "plugins/petish-fulfilment/README.md" else BASELINE
spec = importlib.util.spec_from_file_location("cancellation_deploy_evidence", ROOT / "scripts/validate-cancellation-production.py")
evidence = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evidence)


@unittest.skipUnless(Path(PHP).is_file(), "PHP is required for transaction fixtures")
class CancellationProductionDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="petish-cancellation-production-")
        self.addCleanup(self.temp.cleanup)
        self.work = Path(self.temp.name)
        self.root = self.work / "site"
        self.content = self.root / "wp-content"
        self.payload = self.work / "payload"
        self.payload.mkdir(mode=0o700)
        self.release = self.work / "release"
        self.run_id = "12345-1"
        original = SCRIPT.read_text()
        # The production entry point remains fixed-path. This private copy isolates
        # only the external WordPress bootstrap while exercising actual helpers.
        boot = "static $booted=false;if($booted)return;$booted=true;"
        self.assertEqual(original.count(boot), 1)
        self.script = self.work / "deployer.php"
        self.script.write_text(original.replace(boot, "static $booted=true;if($booted)return;$booted=true;"))
        names = set(subprocess.check_output(["git", "ls-tree", "-r", "--name-only", BASELINE, "wordpress/wp-content"], cwd=ROOT).decode().splitlines())
        self.new_files = []
        manifest = {}
        for name in evidence.FILES:
            target = self.content / name
            target.parent.mkdir(parents=True, exist_ok=True)
            source = "wordpress/wp-content/" + name
            if source in names:
                target.write_bytes(subprocess.check_output(["git", "show", live_baseline_ref(name) + ":" + source], cwd=ROOT))
                target.chmod(0o644)
            else:
                self.new_files.append(name)
            candidate = self.payload / name
            candidate.parent.mkdir(parents=True, exist_ok=True)
            candidate.write_bytes((ROOT / "wordpress/wp-content" / name).read_bytes())
            candidate.chmod(0o600)
            manifest[name] = hashlib.sha256(candidate.read_bytes()).hexdigest()
        (self.payload / "manifest.json").write_text(json.dumps(manifest))
        (self.payload / "manifest.json").chmod(0o600)
        for name, data in {
            "wp-content/themes/dt-the7-child/style.css": b"/* protected theme */",
            "wp-content/plugins/petish-passport/petish-passport.php": b"<?php /* protected Passport bootstrap */",
            "wp-content/mu-plugins/petish-mailer.php": b"<?php /* protected mail */",
            "wp-config.php": b"<?php /* disposable fixture only */",
            ".htaccess": b"# protected routing",
        }.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        private = self.root.parent / "private/petish-passport/photo.png"
        private.parent.mkdir(parents=True)
        private.write_bytes(b"private photo sentinel")
        self.database = {
            "row": {"ID":"1002", "post_author":"1", "post_date":"2026-10-20 00:00:00", "post_date_gmt":"2026-10-20 00:00:00",
                    "post_content":(ROOT / "operations/delivery-page-content.html").read_text(), "post_title":"Delivery & shipping",
                    "post_excerpt":"", "post_status":"publish", "comment_status":"closed", "ping_status":"closed",
                    "post_password":"", "post_name":"delivery-shipping", "to_ping":"", "pinged":"",
                    "post_modified":"2026-10-21 00:00:00", "post_modified_gmt":"2026-10-21 00:00:00",
                    "post_content_filtered":"", "post_parent":"0", "guid":"https://example.invalid/?page_id=1002",
                    "menu_order":"0", "post_type":"page", "post_mime_type":"", "comment_count":"0"},
            "meta": [],
            "flags": {name:{"option_value":"yes" if name == "petish_customer_reviews_enabled" else "no", "autoload":"off"}
                      for name in ("petish_cancellation_requests_enabled", "petish_cancellation_emails_enabled",
                                   "petish_shipment_ledger_enabled", "petish_shipment_notifications_enabled",
                                   "petish_shipment_customer_tracking_enabled", "petish_customer_reviews_enabled")},
            "other": {"unrelated_order":"order sentinel", "unrelated_page":"page sentinel", "private_notes":"note sentinel"},
        }

    def execute(self, operation, *, script=None, release=None, **extra):
        config = {"operation":operation, "script":str(script or self.script), "root":str(self.root),
                  "payload":str(self.payload), "release":str(release or self.release), "run":self.run_id,
                  "basefile":str(ROOT / "operations/delivery-page-content.html"), "database":self.database, **extra}
        path = self.work / "input.json"
        path.write_text(json.dumps(config))
        result = subprocess.run([PHP, str(ROOT / "tests/cancellation-production-fixture.php"), str(path)],
                                cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        report = json.loads(result.stdout)
        self.database = report["database"]
        self.assertTrue(report["lock_released"])
        return report

    def snapshot(self):
        return {str(path.relative_to(self.root)):path.read_bytes() for path in self.root.rglob("*") if path.is_file()}

    def test_exact_twenty_seven_candidate_files_and_fourteen_live_preimages(self):
        result = subprocess.run([PHP, "-r", "define('PETISH_CANCELLATION_RELEASE_LIBRARY',true); require $argv[1]; echo json_encode([PETISH_CANCELLATION_RELEASE_FILES,PETISH_CANCELLATION_RELEASE_BASELINES]);", str(SCRIPT)],
                                cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, "")
        candidate, baseline = json.loads(result.stdout)
        self.assertEqual(set(candidate), set(evidence.FILES))
        self.assertEqual(len(candidate), 27)
        old_names = set(subprocess.check_output(["git", "ls-tree", "-r", "--name-only", BASELINE, "wordpress/wp-content"], cwd=ROOT).decode().splitlines())
        existing = []
        absent = []
        for name in evidence.FILES:
            expected = hashlib.sha256((ROOT / "wordpress/wp-content" / name).read_bytes()).hexdigest()
            self.assertEqual(candidate[name], expected)
            source = "wordpress/wp-content/" + name
            if source in old_names:
                existing.append(name)
                content = subprocess.check_output(["git", "show", live_baseline_ref(name) + ":" + source], cwd=ROOT)
                self.assertEqual(baseline.get(name), hashlib.sha256(content).hexdigest())
            else:
                absent.append(name)
                self.assertIsNone(baseline.get(name))
        self.assertEqual(len(existing), 14)
        self.assertEqual(len(absent), 13)

    def test_inspect_is_read_only_and_payload_extra_or_link_is_rejected(self):
        before = self.snapshot()
        self.assertTrue(self.execute("inspect")["success"])
        self.assertEqual(self.snapshot(), before)
        extra = self.payload / "unexpected.php"
        extra.write_text("<?php /* outside scope */")
        self.assertFalse(self.execute("inspect")["success"])
        self.assertEqual(self.snapshot(), before)
        extra.unlink()
        target = self.payload / evidence.FILES[0]
        content = target.read_bytes()
        target.unlink()
        target.symlink_to(ROOT / "wordpress/wp-content" / evidence.FILES[0])
        self.assertFalse(self.execute("inspect")["success"])
        self.assertEqual(self.snapshot(), before)
        target.unlink()
        target.write_bytes(content)
        target.chmod(0o600)

    def test_new_files_removed_and_preimages_restored_after_full_or_partial_install(self):
        before = self.snapshot()
        flags = copy.deepcopy(self.database["flags"])
        self.assertTrue(self.execute("apply-files")["success"])
        self.assertTrue(self.execute("require-candidate")["success"])
        self.assertTrue(all((self.content / name).is_file() for name in self.new_files))
        result = self.execute("restore-files")
        self.assertTrue(result["success"], result["details"])
        self.assertTrue(result["checks"]["rollback_completed"])
        self.assertEqual(self.snapshot(), before)
        self.assertTrue(all(not (self.content / name).exists() for name in self.new_files))
        self.assertEqual(self.database["flags"], flags)

    def test_partial_install_and_already_missing_new_file_roll_back(self):
        before = self.snapshot()
        self.assertTrue(self.execute("apply-files", partial_after=12)["success"])
        created = [self.content / name for name in self.new_files if (self.content / name).exists()]
        self.assertTrue(created)
        created[0].unlink()
        result = self.execute("restore-files")
        self.assertTrue(result["success"], result["details"])
        self.assertEqual(self.snapshot(), before)

    def test_external_target_and_protected_edits_refuse_before_rollback_writes(self):
        self.assertTrue(self.execute("apply-files")["success"])
        target = self.content / self.new_files[0]
        target.write_text("/* concurrent owner edit */")
        before = self.snapshot()
        self.assertFalse(self.execute("restore-files")["success"])
        self.assertEqual(self.snapshot(), before)
        target.write_bytes((self.payload / self.new_files[0]).read_bytes())
        protected = self.root / "wp-content/themes/dt-the7-child/style.css"
        protected.write_text("/* concurrent theme edit */")
        before = self.snapshot()
        self.assertFalse(self.execute("restore-files")["success"])
        self.assertEqual(self.snapshot(), before)

    def test_flags_use_real_tracking_key_and_never_write_options(self):
        expected = copy.deepcopy(self.database["flags"])
        result = self.execute("flags")
        self.assertTrue(result["success"])
        self.assertEqual(result["details"]["flags"], expected)
        self.assertTrue(result["details"]["disabled"])
        self.database["flags"]["petish_shipment_customer_tracking_enabled"]["option_value"] = "yes"
        self.assertFalse(self.execute("flags")["details"]["disabled"])
        before = copy.deepcopy(self.database)
        self.assertFalse(self.execute("unapproved-option-write")["success"])
        self.assertEqual(self.database, before)

    def test_unexpected_live_file_and_corrupt_private_backup_are_not_overwritten(self):
        new_target = self.content / self.new_files[0]
        new_target.write_text("/* foreign preexisting helper */")
        before = self.snapshot()
        self.assertFalse(self.execute("inspect")["success"])
        self.assertEqual(self.snapshot(), before)
        new_target.unlink()
        self.assertTrue(self.execute("apply-files")["success"])
        backup = next((self.release / "backup").iterdir())
        backup.write_bytes(backup.read_bytes() + b"corrupt checkpoint")
        before = self.snapshot()
        self.assertFalse(self.execute("restore-files")["success"])
        self.assertEqual(self.snapshot(), before)

    def test_faq_append_and_conditional_restore_preserve_live_copy(self):
        self.release.mkdir(mode=0o700)
        before = copy.deepcopy(self.database)
        result = self.execute("faq")
        self.assertTrue(result["success"], result["details"])
        self.assertEqual(result["writes"], ["page", "marker"])
        self.assertTrue(self.database["row"]["post_content"].startswith(before["row"]["post_content"]))
        self.assertEqual(self.database["row"]["post_content"].count("[petish_faqs]"), 1)
        self.assertEqual(self.database["flags"], before["flags"])
        self.assertEqual(self.database["other"], before["other"])
        self.assertEqual((self.release / "page.json").stat().st_mode & 0o777, 0o600)
        result = self.execute("faq-restore")
        self.assertTrue(result["success"], result["details"])
        self.assertEqual(self.database, before)
        self.assertTrue(self.execute("faq-restore")["success"])

    def test_faq_refuses_unknown_copy_and_preserves_manual_changes_on_restore(self):
        self.release.mkdir(mode=0o700)
        original = self.database["row"]["post_content"]
        self.database["row"]["post_content"] += "\nManual page edit."
        before = copy.deepcopy(self.database)
        result = self.execute("faq")
        self.assertFalse(result["success"])
        self.assertEqual(result["writes"], [])
        self.assertEqual(self.database, before)
        self.database["row"]["post_content"] = original
        self.assertTrue(self.execute("faq")["success"])
        self.database["row"]["post_title"] = "Concurrent title edit"
        before = copy.deepcopy(self.database)
        result = self.execute("faq-restore")
        self.assertFalse(result["success"])
        self.assertEqual(result["writes"], [])
        self.assertEqual(self.database, before)

    def test_faq_checkpoint_failure_after_sql_restores_transaction(self):
        self.release.mkdir(mode=0o700)
        statement = "cxl_release_write($release.'/page.json',"
        source = self.script.read_text()
        self.assertEqual(source.count(statement), 1)
        faulty = self.work / "checkpoint-failure.php"
        faulty.write_text(source.replace(statement, "cxl_release_fail();" + statement))
        before = copy.deepcopy(self.database)
        result = self.execute("faq", script=faulty)
        self.assertFalse(result["success"])
        self.assertEqual(result["writes"], ["page", "marker"])
        self.assertEqual(self.database, before)
        self.assertFalse((self.release / "page.json").exists())

    def test_combined_rollback_validates_all_files_before_restoring_page(self):
        self.assertTrue(self.execute("apply-files")["success"])
        self.assertTrue(self.execute("faq")["success"])
        changed = self.content / self.new_files[0]
        changed.write_text("/* concurrent target edit after FAQ commit */")
        before_files = self.snapshot()
        before_database = copy.deepcopy(self.database)
        result = self.execute("restore-files")
        self.assertFalse(result["success"])
        self.assertEqual(result["writes"], [])
        self.assertEqual(self.database, before_database)
        self.assertEqual(self.snapshot(), before_files)

    def test_builder_page_or_duplicate_marker_refuses_without_writes(self):
        self.release.mkdir(mode=0o700)
        before = copy.deepcopy(self.database)
        result = self.execute("faq", builder=True)
        self.assertFalse(result["success"])
        self.assertEqual(result["writes"], [])
        self.assertEqual(self.database, before)
        self.assertTrue(self.execute("faq")["success"])
        self.database["meta"].append(copy.deepcopy(self.database["meta"][0]))
        self.database["meta"][1]["meta_id"] = "901"
        before = copy.deepcopy(self.database)
        result = self.execute("faq-restore")
        self.assertFalse(result["success"])
        self.assertEqual(result["writes"], [])
        self.assertEqual(self.database, before)

    def test_idempotent_preowned_faq_rollback_keeps_earlier_section(self):
        self.release.mkdir(mode=0o700)
        self.assertTrue(self.execute("faq")["success"])
        before = copy.deepcopy(self.database)
        other_release = self.work / "second-release"
        other_release.mkdir(mode=0o700)
        result = self.execute("faq", release=other_release)
        self.assertTrue(result["success"], result["details"])
        self.assertEqual(result["writes"], [])
        self.assertEqual(self.database, before)
        self.assertTrue(self.execute("faq-restore", release=other_release)["success"])
        self.assertEqual(self.database, before)


if __name__ == "__main__":
    unittest.main()
