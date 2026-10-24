#!/usr/bin/env python3
"""Exercise PetChat with fresh native WordPress users and a disconnected database.

Reuses the cached, immutable native fulfilment containers. Only clean WordPress
core/WooCommerce source and this repository's Passport code are copied. No real
site configuration, database, uploads, mail transport or network is available.
"""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("petish_chat_native_helpers", ROOT / "scripts/test-fulfilment-native.py")
native = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(native)


def owned(work):
    state = native.read_state(work)
    marker = work / "pet-chat-proof-owner"
    if marker.is_symlink() or not marker.is_file() or marker.read_text() != state["owner"]:
        raise native.Failure("ownership")
    return state


def refresh_passport(work):
    """Refresh only the plugin copied into this explicitly owned disposable site."""
    owned(work)
    target = work / "site/wp-content/plugins/petish-passport"
    if target.is_symlink() or (target.exists() and not target.is_dir()):
        raise native.Failure("passport_source")
    source = ROOT / "wordpress/wp-content/plugins/petish-passport"
    if target.exists():
        shutil.rmtree(target)
    native.checked_copy_tree(source, target)


def export_artifacts(work):
    source = work / "pet-chat-artifacts"
    if source.is_symlink() or not source.is_dir():
        raise native.Failure("synthetic_artifacts")
    paths = sorted(source.iterdir())
    allowed = {"pet-chat-directory.html", "pet-chat-thread.html", "pet-chat-pending.html"}
    if not paths or len(paths) > len(allowed):
        raise native.Failure("synthetic_artifacts")
    destination = Path(tempfile.mkdtemp(prefix="petish-pet-chat-visual-review-"))
    destination.chmod(0o700)
    try:
        for path in paths:
            info = path.lstat()
            if path.is_symlink() or not path.is_file() or info.st_nlink != 1 or path.name not in allowed or not 1 <= info.st_size <= 4194304:
                raise native.Failure("synthetic_artifacts")
            native.private_write(destination / path.name, path.read_bytes())
    except BaseException:
        shutil.rmtree(destination)
        raise
    return {"directory": str(destination), "files": [path.name for path in paths], "synthetic_fixture_content_only": True}


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--wordpress-source", type=Path)
    parser.add_argument("--sandbox", type=Path)
    parser.add_argument("--prepare-only", action="store_true")
    parser.add_argument("--cleanup", action="store_true")
    parser.add_argument("--keep-sandbox", action="store_true")
    parser.add_argument("--export-visuals", action="store_true")
    args = parser.parse_args()
    if args.sandbox:
        work = args.sandbox.absolute()
        state = owned(work)
    else:
        if not args.wordpress_source or args.cleanup:
            parser.error("A clean WordPress/WooCommerce source or an owned PetChat sandbox is required.")
        work, state = native.prepare(args.wordpress_source.absolute())
        native.private_write(work / "pet-chat-proof-owner", state["owner"])
    if args.cleanup:
        native.cleanup(work, state)
        print(json.dumps({"status": "success", "private_sandbox_removed": True}))
        return
    if args.prepare_only:
        print(json.dumps({"status": "prepared", "sandbox": str(work), "fresh_database": True,
                          "external_network_disabled": True, "original_configuration_not_used": True}))
        return
    try:
        refresh_passport(work)
        for name in ("verify-pet-chat.php", "verify-pet-club.php", "email-branding-preview-remote.php"):
            source = ROOT / "scripts" / name
            if source.is_symlink() or not source.is_file() or source.stat().st_size > 2097152:
                raise native.Failure("proof_source")
            native.private_write(work / "native-verifiers" / name, source.read_bytes())
        native.wp(state, work, ["plugin", "activate", "petish-passport"])
        proof = ROOT / "tests/pet-chat-native.php"
        if proof.is_symlink() or not proof.is_file() or proof.stat().st_size > 262144:
            raise native.Failure("proof_source")
        reports = []
        asset = work / "site/wp-content/plugins/petish-passport/assets/pet-chat.js"
        held = asset.with_name("pet-chat.js.private-held")
        for mode in ("full", "incomplete_ui"):
            if mode == "incomplete_ui":
                if asset.is_symlink() or not asset.is_file() or held.exists() or held.is_symlink():
                    raise native.Failure("incomplete_ui_fixture")
                asset.rename(held)
            try:
                data = native.run(["exec", state["application"], "php", "-d", "sendmail_path=/bin/false", "-d",
                                   "display_errors=0", "-d", "log_errors=1", "-d", "error_log=/checks/php-errors.log",
                                   "/repo/tests/pet-chat-native.php", mode], work, "pet_chat_native_proof", timeout=300)
            finally:
                if mode == "incomplete_ui" and held.is_file() and not held.is_symlink():
                    held.rename(asset)
            report = json.loads(data)
            native.private_write(work / ("pet-chat-proof-" + mode + ".json"), json.dumps(report))
            checks = report.get("checks")
            if report.get("status") != "success" or not isinstance(checks, dict) or not checks or not all(value is True for value in checks.values()):
                raise native.Failure("pet_chat_native_proof")
            reports.append({"mode": mode, "checks_passed": len(checks)})
        result = {"status": "success", "checks_passed": sum(row["checks_passed"] for row in reports), "modes": reports,
                  "scope": "native_pet_chat_security_ui_and_incomplete_transfer",
                  "fresh_database": True, "external_network_disabled": True, "original_configuration_not_used": True}
        if args.export_visuals:
            result["visual_artifacts"] = export_artifacts(work)
        print(json.dumps(result))
    finally:
        if not args.keep_sandbox:
            native.cleanup(work, state)


if __name__ == "__main__":
    try:
        main()
    except native.Failure as error:
        print(json.dumps({"status": "failure", "stage": error.stage}), file=sys.stderr)
        sys.exit(1)
