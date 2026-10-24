#!/usr/bin/env python3
"""Check hero Media Library settings against disposable, disconnected WordPress.

Uses only cached immutable containers, network=none and an owned Unix DB socket.
No existing configuration, uploads, database, host or credentials are used.
"""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("petish_native_helpers", ROOT / "scripts/test-fulfilment-native.py")
native = importlib.util.module_from_spec(spec)
spec.loader.exec_module(native)


def owned(work):
    state = native.read_state(work)
    marker = work / "hero-proof-owner"
    if marker.is_symlink() or not marker.is_file() or marker.read_text() != state["owner"]:
        raise native.Failure("ownership")
    return state


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--wordpress-source", type=Path)
    parser.add_argument("--sandbox", type=Path)
    parser.add_argument("--prepare-only", action="store_true")
    parser.add_argument("--cleanup", action="store_true")
    parser.add_argument("--keep-sandbox", action="store_true")
    args = parser.parse_args()
    if args.sandbox:
        work = args.sandbox.absolute()
        state = owned(work)
    else:
        if not args.wordpress_source or args.cleanup:
            parser.error("A clean WordPress/WooCommerce source or an owned hero sandbox is required.")
        work, state = native.prepare(args.wordpress_source.absolute())
        native.private_write(work / "hero-proof-owner", state["owner"])
    if args.cleanup:
        native.cleanup(work, state)
        print(json.dumps({"status": "success", "private_sandbox_removed": True}))
        return
    if args.prepare_only:
        print(json.dumps({"status": "prepared", "sandbox": str(work), "fresh_database": True,
                          "external_network_disabled": True, "original_configuration_not_used": True}))
        return
    try:
        theme = ROOT / "wordpress/wp-content/themes/dt-the7-child"
        for name in ("includes/hero-images.php", "assets/js/hero-images-admin.js", "front-page.php"):
            source = theme / name
            if source.is_symlink() or not source.is_file() or source.stat().st_size > 1048576:
                raise native.Failure("hero_source")
            native.private_write(work / "native-hero" / name, source.read_bytes())
        # Native get_header/get_footer still run, using intentionally empty local
        # shell templates; the complete real homepage body is executed unmodified.
        native.private_write(work / "native-hero/header.php", "<?php // Disposable empty proof shell.\n")
        native.private_write(work / "native-hero/footer.php", "<?php // Disposable empty proof shell.\n")
        native.wp(state, work, ["plugin", "activate", "petish-fulfilment"])
        data = native.run(["exec", state["application"], "php", "-d", "sendmail_path=/bin/false", "-d",
                           "display_errors=0", "-d", "log_errors=1", "-d", "error_log=/checks/php-errors.log",
                           "/repo/tests/hero-images-native.php"], work, "hero_native_proof", timeout=240)
        report = json.loads(data)
        native.private_write(work / "hero-proof.json", json.dumps(report))
        if report.get("status") != "success" or not report.get("checks") or not all(v is True for v in report["checks"].values()):
            raise native.Failure("hero_native_proof")
        print(json.dumps({"status": "success", "checks_passed": len(report["checks"]),
                          "scope": "native_hero_settings_and_cancellation_activation", "fresh_database": True,
                          "external_network_disabled": True, "original_configuration_not_used": True}))
    finally:
        if not args.keep_sandbox:
            native.cleanup(work, state)


if __name__ == "__main__":
    try:
        main()
    except native.Failure as error:
        print(json.dumps({"status": "failure", "stage": error.stage}), file=sys.stderr)
        sys.exit(1)
