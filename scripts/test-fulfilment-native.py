#!/usr/bin/env python3
"""Fresh, disconnected native WordPress/WooCommerce fulfilment verification.

Uses cached images, fresh disposable data and a private Unix socket. Never reads
an existing wp-config, backup or database, publishes ports, or contacts a site.
Only fixed stages/check results are printed; subprocess diagnostics stay private.
"""
import argparse
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
DB_IMAGE = "mariadb:11.4@sha256:250e1609e93c0e6aecf64bfbab2d84d5e89d2481540fe77447ff6c855c3b1873"
APP_IMAGE = "wordpress:cli-php8.3@sha256:36243da432a0f0a3b56dc3240ecee9b159108d71305e7c064d987a20a465bfde"
DOCKER = ["docker", "--host", "unix:///var/run/docker.sock"]
SITE = "/srv/petish-native"
DATABASE = "petish_fulfilment_native"
LABEL = "org.petish.fulfilment-native"

GUARD = r'''<?php
/** Disposable local proof only; never deployed. */
defined('ABSPATH')||exit;
add_filter('pre_http_request',static function(){return new WP_Error('petish_native_http_blocked','Disconnected native proof.');},PHP_INT_MAX);
add_filter('pre_wp_mail',static function(){return false;},PHP_INT_MAX);
add_action('phpmailer_init',static function(){throw new RuntimeException('Native proof cannot use SMTP.');},PHP_INT_MAX);
add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);
add_filter('action_scheduler_queue_runner_concurrent_batches','__return_zero',PHP_INT_MAX);
add_filter('automatic_updater_disabled','__return_true',PHP_INT_MAX);
add_filter('pre_option_blog_public','__return_zero',PHP_INT_MAX);
add_filter('pre_option_woocommerce_coming_soon',static function(){return 'no';},PHP_INT_MAX);
add_action('wp_loaded',static function(){remove_all_actions('action_scheduler_run_queue');},PHP_INT_MAX);
'''


class Failure(Exception):
    def __init__(self, stage):
        self.stage = stage
        super().__init__("Disconnected native fulfilment verification failed.")


def private_write(path, text):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "wb") as stream:
        stream.write(text.encode() if isinstance(text, str) else text)
    path.chmod(0o600)


def run(args, work, stage, *, input_bytes=None, timeout=180):
    try:
        with (work / "docker.stderr").open("ab") as errors:
            result = subprocess.run(DOCKER + args, input=input_bytes, stdout=subprocess.PIPE,
                                    stderr=errors, timeout=timeout,
                                    env={"PATH": os.environ.get("PATH", os.defpath), "LANG": "C.UTF-8"})
    except (OSError, subprocess.TimeoutExpired):
        # Never expose command arguments (including disposable install passwords).
        raise Failure(stage) from None
    if result.returncode:
        with (work / "docker.stdout").open("ab") as output:
            output.write(result.stdout)
        raise Failure(stage)
    return result.stdout


def checked_copy_tree(source, target):
    if source.is_symlink() or not source.is_dir():
        raise Failure("source")
    for directory, folders, files in os.walk(source, followlinks=False):
        for name in folders + files:
            item = Path(directory) / name
            info = item.lstat()
            if stat.S_ISLNK(info.st_mode) or not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)):
                raise Failure("source")
    shutil.copytree(source, target)


def state_file(work):
    return work / "native-sandbox.json"


def read_state(work):
    if work != work.resolve() or work.is_symlink() or not work.is_dir() or not work.name.startswith("petish-fulfilment-native-"):
        raise Failure("ownership")
    info = state_file(work).lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 4096 or stat.S_IMODE(info.st_mode) != 0o600:
        raise Failure("ownership")
    state = json.loads(state_file(work).read_text())
    token = state.get("owner", "")
    if not re.fullmatch(r"[a-f0-9]{32}", token) or state.get("format") != "petish-fulfilment-native-v1":
        raise Failure("ownership")
    expected = {"database": "petish-native-db-" + token, "application": "petish-native-app-" + token,
                "socket": "petish-native-socket-" + token}
    if any(state.get(key) != value for key, value in expected.items()) or state.get("repository") != str(ROOT):
        raise Failure("ownership")
    return state


def assert_container(name, work, owner):
    # Inspect only the two safe properties, never container environment values.
    network = run(["inspect", "--format", "{{.HostConfig.NetworkMode}}", name], work, "isolation").strip()
    label = run(["inspect", "--format", '{{index .Config.Labels "' + LABEL + '"}}', name], work, "isolation").strip()
    if network != b"none" or label != owner.encode():
        raise Failure("isolation")


def wp(state, work, arguments, *, timeout=180):
    return run(["exec", "--workdir", SITE, state["application"], "wp", "--allow-root", "--path=" + SITE,
                "--skip-themes"] + arguments, work, "wordpress", timeout=timeout)


def prepare(source):
    work = Path(tempfile.mkdtemp(prefix="petish-fulfilment-native-"))
    work.chmod(0o700)
    token = uuid.uuid4().hex
    state = {"format": "petish-fulfilment-native-v1", "owner": token, "repository": str(ROOT),
             "database": "petish-native-db-" + token, "application": "petish-native-app-" + token,
             "socket": "petish-native-socket-" + token}
    private_write(state_file(work), json.dumps(state))
    site = work / "site"
    site.mkdir(mode=0o700)
    try:
        version = (source / "wp-includes/version.php").read_text()
        if not re.search(r"\$wp_version\s*=\s*['\"]7\.1\.3['\"]", version):
            raise Failure("wordpress_version")
        checked_copy_tree(source / "wp-admin", site / "wp-admin")
        checked_copy_tree(source / "wp-includes", site / "wp-includes")
        for name in ("index.php", "wp-activate.php", "wp-blog-header.php", "wp-comments-post.php", "wp-cron.php",
                     "wp-links-opml.php", "wp-load.php", "wp-login.php", "wp-mail.php", "wp-settings.php",
                     "wp-signup.php", "wp-trackback.php", "xmlrpc.php"):
            original = source / name
            if original.is_symlink() or not original.is_file():
                raise Failure("source")
            shutil.copyfile(original, site / name)
        plugins = site / "wp-content/plugins"
        plugins.mkdir(parents=True, mode=0o700)
        checked_copy_tree(source / "wp-content/plugins/woocommerce", plugins / "woocommerce")
        (plugins / "petish-fulfilment").mkdir(mode=0o700)
        # Only stock themes are copied; no original drop-ins, MU guards, uploads or config.
        themes = site / "wp-content/themes"
        themes.mkdir(mode=0o700)
        for name in ("twentytwentyfive", "twentytwentyfour", "twentytwentysix"):
            if (source / "wp-content/themes" / name).is_dir():
                checked_copy_tree(source / "wp-content/themes" / name, themes / name)
        private_write(site / "wp-content/mu-plugins/000-native-guard.php", GUARD)
        password, root_password = secrets.token_hex(32), secrets.token_hex(32)
        private_write(work / "database.env", "MARIADB_DATABASE=" + DATABASE + "\nMARIADB_USER=petish_native\nMARIADB_PASSWORD="
                      + password + "\nMARIADB_ROOT_PASSWORD=" + root_password + "\n")
        private_write(work / "client.cnf", "[client]\nuser=root\npassword=" + root_password + "\nprotocol=socket\n")
        config = "<?php\n" + "\n".join("define(" + repr(key) + "," + repr(value) + ");" for key, value in {
            "DB_NAME": DATABASE, "DB_USER": "petish_native", "DB_PASSWORD": password,
            "DB_HOST": "localhost:/run/mysqld/mysqld.sock", "DB_CHARSET": "utf8mb4", "DB_COLLATE": "",
            "WP_ENVIRONMENT_TYPE": "local", "WP_HOME": "http://petish-fulfilment.invalid",
            "WP_SITEURL": "http://petish-fulfilment.invalid", "PETISH_FULFILMENT_NATIVE_OWNER": token,
        }.items()) + "\n"
        config += "define('DISABLE_WP_CRON',true);define('AUTOMATIC_UPDATER_DISABLED',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_DEBUG',false);define('WP_DEBUG_DISPLAY',false);\n$table_prefix='wp_';\nif(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';\n"
        private_write(site / "wp-config.php", config)
        for image in (DB_IMAGE, APP_IMAGE):
            run(["image", "inspect", "--format", "{{.Id}}", image], work, "cached_images")
        run(["volume", "create", "--label", LABEL + "=" + token, state["socket"]], work, "socket")
        run(["run", "--detach", "--pull", "never", "--name", state["database"], "--label", LABEL + "=" + token,
             "--network", "none", "--cap-drop", "NET_RAW", "--security-opt", "no-new-privileges:true",
             "--pids-limit", "256", "--memory", "1g", "--cpus", "2", "--env-file", str(work / "database.env"),
             "--mount", "type=volume,src=" + state["socket"] + ",dst=/run/mysqld", DB_IMAGE,
             "--skip-networking", "--event-scheduler=DISABLED", "--local-infile=0", "--skip-log-bin"], work, "database")
        run(["cp", str(work / "client.cnf"), state["database"] + ":/tmp/petish-native-client.cnf"], work, "database")
        query = ["exec", state["database"], "mariadb", "--defaults-extra-file=/tmp/petish-native-client.cnf",
                 "--batch", "--skip-column-names", "--execute", "SELECT 1"]
        for attempt in range(45):
            try:
                if run(query, work, "database_ready", timeout=5).strip() == b"1":
                    break
            except Failure:
                pass
            time.sleep(1)
        else:
            raise Failure("database_ready")
        run(["run", "--detach", "--pull", "never", "--name", state["application"], "--label", LABEL + "=" + token,
             "--network", "none", "--user", str(os.getuid()) + ":" + str(os.getgid()),
             "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true", "--pids-limit", "128", "--memory", "1g",
             "--env", "HOME=/tmp", "--env", "WP_CLI_CACHE_DIR=/tmp/wp-cli-cache",
             "--mount", "type=bind,src=" + str(site) + ",dst=" + SITE,
             "--mount", "type=bind,src=" + str(ROOT / "tests") + ",dst=/repo/tests,readonly",
             "--mount", "type=bind,src=" + str(ROOT / "wordpress/wp-content/plugins/petish-fulfilment")
             + ",dst=" + SITE + "/wp-content/plugins/petish-fulfilment,readonly",
             "--mount", "type=bind,src=" + str(work) + ",dst=/checks",
             "--mount", "type=volume,src=" + state["socket"] + ",dst=/run/mysqld,readonly",
             "--entrypoint", "php", APP_IMAGE, "-r", "sleep(7200);"], work, "application")
        for name in (state["database"], state["application"]):
            assert_container(name, work, token)
        wp(state, work, ["core", "install", "--url=http://petish-fulfilment.invalid", "--title=Petish isolated fulfilment",
                         "--admin_user=fixture-admin", "--admin_password=" + secrets.token_hex(32),
                         "--admin_email=fixture-admin@example.invalid", "--skip-email", "--skip-plugins"])
        wp(state, work, ["plugin", "activate", "woocommerce"], timeout=240)
        for key, value in (("woocommerce_currency", "AUD"), ("woocommerce_default_country", "AU:VIC"),
                           ("woocommerce_custom_orders_table_data_sync_enabled", "no")):
            wp(state, work, ["option", "update", key, value])
        return work, state
    except BaseException:
        cleanup(work, state, remove_files=False)
        raise


def cleanup(work, state, *, remove_files=True):
    # Names and ownership are generated independently of WordPress/test results.
    for key in ("application", "database"):
        name = state[key]
        found = run(["ps", "--all", "--quiet", "--filter", "name=^/" + name + "$"], work, "cleanup").strip()
        if found:
            assert_container(name, work, state["owner"])
            run(["rm", "--force", "--volumes", name], work, "cleanup", timeout=60)
    exists = run(["volume", "ls", "--quiet", "--filter", "name=^" + state["socket"] + "$"], work, "cleanup").strip()
    if exists:
        label = run(["volume", "inspect", "--format", '{{index .Labels "' + LABEL + '"}}', state["socket"]], work, "cleanup").strip()
        if label != state["owner"].encode():
            raise Failure("ownership")
        run(["volume", "rm", state["socket"]], work, "cleanup")
    if remove_files:
        shutil.rmtree(work)


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
        state = read_state(work)
    else:
        if not args.wordpress_source or args.cleanup:
            parser.error("A fresh WordPress source or an owned sandbox is required.")
        work, state = prepare(args.wordpress_source.absolute())
    if args.cleanup:
        cleanup(work, state)
        print(json.dumps({"status": "success", "private_sandbox_removed": True}))
        return
    if args.prepare_only:
        print(json.dumps({"status": "prepared", "sandbox": str(work), "external_network_disabled": True,
                          "fresh_database": True, "original_configuration_not_used": True}))
        return
    try:
        wp(state, work, ["plugin", "activate", "petish-fulfilment"])
        for name in ("verify-delivery-operations.php", "verify-pet-club.php"):
            source = ROOT / "scripts" / name
            if source.is_symlink() or not source.is_file():
                raise Failure("proof_source")
            private_write(work / "native-verifiers" / name, source.read_text())
        theme = ROOT / "wordpress/wp-content/themes/dt-the7-child"
        branding_files = {
            "petish-email-branding.php": theme / "inc/petish-email-branding.php",
            "email-header.php": theme / "woocommerce/emails/email-header.php",
            "email-footer.php": theme / "woocommerce/emails/email-footer.php",
            "combined-light-v2.png": theme / "assets/brand/repaired/combined-light-v2.png",
            "theme-palette.css": theme / "assets/css/petish.css",
        }
        # Select only the real shared email shell; never execute child-theme functions.
        for name, source in branding_files.items():
            if source.is_symlink() or not source.is_file() or source.stat().st_size > 2097152:
                raise Failure("branding_source")
            private_write(work / "native-branding" / name, source.read_bytes())
        proof = ROOT / "tests/fulfilment-native.php"
        if not proof.is_file():
            raise Failure("proof_source")
        reports = []
        for mode in ("no", "yes"):
            wp(state, work, ["option", "update", "woocommerce_custom_orders_table_enabled", mode])
            data = run(["exec", state["application"], "php", "-d", "sendmail_path=/bin/false", "-d",
                        "display_errors=0", "-d", "log_errors=1", "-d", "error_log=/checks/php-errors.log",
                        "/repo/tests/fulfilment-native.php", mode], work, "native_proof", timeout=240)
            report = json.loads(data)
            private_write(work / ("proof-" + mode + ".json"), json.dumps(report))
            if report.get("status") != "success" or not report.get("checks") or not all(value is True for value in report["checks"].values()):
                raise Failure("native_proof")
            reports.append({"storage": report["storage"], "status": "success", "checks_passed": len(report["checks"])})
        print(json.dumps({"status": "success", "modes": reports, "external_network_disabled": True,
                          "fresh_database": True, "original_configuration_not_used": True}))
    finally:
        if not args.keep_sandbox:
            cleanup(work, state)


if __name__ == "__main__":
    try:
        main()
    except Failure as error:
        print(json.dumps({"status": "failure", "stage": error.stage}), file=sys.stderr)
        sys.exit(1)
