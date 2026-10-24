#!/usr/bin/env python3
"""Verify cancellation requests in a fresh, disconnected native WordPress site.

Cached immutable containers use network=none and a private Unix database socket.
Never reads a real site's configuration/database, sends mail, or calls suppliers.
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
spec = importlib.util.spec_from_file_location("petish_native_helpers", ROOT / "scripts/test-fulfilment-native.py")
native = importlib.util.module_from_spec(spec)
spec.loader.exec_module(native)


def owned(work):
    state = native.read_state(work)
    marker = work / "cancellation-proof-owner"
    if marker.is_symlink() or not marker.is_file() or marker.read_text() != state["owner"]:
        raise native.Failure("ownership")
    return state


def branding(work):
    theme = ROOT / "wordpress/wp-content/themes/dt-the7-child"
    files = {
        "petish-email-branding.php": theme / "inc/petish-email-branding.php",
        "email-header.php": theme / "woocommerce/emails/email-header.php",
        "email-footer.php": theme / "woocommerce/emails/email-footer.php",
        "combined-light-v2.png": theme / "assets/brand/repaired/combined-light-v2.png",
        "theme-palette.css": theme / "assets/css/petish.css",
    }
    for name, source in files.items():
        if source.is_symlink() or not source.is_file() or source.stat().st_size > 2097152:
            raise native.Failure("branding_source")
        native.private_write(work / "native-branding" / name, source.read_bytes())


def export_artifacts(work):
    source = work / "cancellation-artifacts"
    if not source.is_dir() or source.is_symlink():
        raise native.Failure("review_artifacts")
    destination = Path(tempfile.mkdtemp(prefix="petish-cancellation-visual-review-"))
    destination.chmod(0o700)
    total = 0
    names = []
    for path in sorted(source.iterdir()):
        if path.is_symlink() or not path.is_file() or not path.name.endswith(".html") or path.stat().st_size > 4194304:
            raise native.Failure("review_artifacts")
        total += path.stat().st_size
        if total > 33554432 or len(names) >= 16:
            raise native.Failure("review_artifacts")
        native.private_write(destination / path.name, path.read_bytes())
        names.append(path.name)
    return {"directory": str(destination), "files": names, "synthetic_fixture_content_only": True}


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
            parser.error("A clean WordPress/WooCommerce source or an owned cancellation sandbox is required.")
        work, state = native.prepare(args.wordpress_source.absolute())
        native.private_write(work / "cancellation-proof-owner", state["owner"])
    if args.cleanup:
        native.cleanup(work, state)
        print(json.dumps({"status": "success", "private_sandbox_removed": True}))
        return
    if args.prepare_only:
        print(json.dumps({"status": "prepared", "sandbox": str(work), "fresh_database": True,
                          "external_network_disabled": True, "original_configuration_not_used": True}))
        return
    try:
        branding(work)
        for name in ("verify-cancellation-requests.php", "verify-pet-club.php", "email-branding-preview-remote.php"):
            source = ROOT / "scripts" / name
            if source.is_symlink() or not source.is_file() or source.stat().st_size > 2097152:
                raise native.Failure("proof_source")
            native.private_write(work / "native-verifiers" / name, source.read_bytes())
        native.wp(state, work, ["plugin", "activate", "petish-fulfilment"])
        proof = ROOT / "tests/cancellation-native.php"
        if proof.is_symlink() or not proof.is_file():
            raise native.Failure("proof_source")
        reports = []
        for mode in ("no", "yes"):
            native.wp(state, work, ["option", "update", "woocommerce_custom_orders_table_enabled", mode])
            data = native.run(["exec", state["application"], "php", "-d", "sendmail_path=/bin/false", "-d",
                               "display_errors=0", "-d", "log_errors=1", "-d", "error_log=/checks/php-errors.log",
                               "/repo/tests/cancellation-native.php", mode], work, "cancellation_native_proof", timeout=300)
            report = json.loads(data)
            native.private_write(work / ("cancellation-proof-" + mode + ".json"), json.dumps(report))
            if report.get("status") != "success" or not report.get("checks") or not all(v is True for v in report["checks"].values()):
                raise native.Failure("cancellation_native_proof")
            reports.append({"storage": report["storage"], "status": "success", "checks_passed": len(report["checks"])})
        result = {"status": "success", "modes": reports, "fresh_database": True,
                  "external_network_disabled": True, "original_configuration_not_used": True}
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
