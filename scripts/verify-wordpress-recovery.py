#!/usr/bin/env python3
"""Recover and boot a stored backup privately; no existing hosted site is contacted."""

import argparse
import contextlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import signal
import subprocess
import sys
import tempfile
import time
import uuid


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


BASE = module("petish_recovery_base", "test-full-backup-restore.py")
APPLICATION = module("petish_recovery_application", "test-restored-wordpress.py")
STAGES = {"input", "decryption", "extraction", "database_start", "database_import",
          "database_verify", "prepare", "image", "container", "table_prefix",
          "database_user", "bootstrap", "http", "complete", "cleanup"}


@contextlib.contextmanager
def database(sql_path, work, state):
    name = "petish-recovery-db-" + uuid.uuid4().hex
    socket_volume = "petish-recovery-socket-" + uuid.uuid4().hex
    password = secrets.token_hex(32)
    environment = work / "database.env"
    environment.write_text("MARIADB_ROOT_PASSWORD=" + password + "\nMARIADB_DATABASE=petish_restore\n")
    client = work / "client.cnf"
    client.write_text("[client]\nuser=root\npassword=" + password + "\nprotocol=socket\n")
    state[0] = "database_start"
    try:
        BASE.run_private(["pull", BASE.MARIADB_IMAGE], work, timeout=300)
        BASE.run_private(["volume", "create", "--label", "org.petish.application-recovery=true", socket_volume], work)
        BASE.run_private(["run", "--detach", "--name", name,
                          "--label", "org.petish.application-recovery=true",
                          "--network", "none", "--cap-drop", "NET_RAW",
                          "--security-opt", "no-new-privileges:true", "--pids-limit", "256",
                          "--memory", "4g", "--cpus", "2",
                          "--tmpfs", "/petish-no-export:ro,nosuid,noexec,size=1m",
                          "--mount", "type=volume,src=" + socket_volume + ",dst=/run/mysqld",
                          "--env-file", str(environment), BASE.MARIADB_IMAGE,
                          "--skip-networking", "--event-scheduler=DISABLED", "--local-infile=0",
                          "--secure-file-priv=/petish-no-export", "--skip-log-bin"], work)
        BASE.run_private(["cp", str(client), name + ":/tmp/petish-restore-client.cnf"], work)
        command = ["exec", "--interactive", name, "mariadb",
                   "--defaults-extra-file=/tmp/petish-restore-client.cnf", "--binary-mode=1",
                   "--local-infile=0", "--skip-reconnect", "--batch", "--skip-column-names",
                   "--database", "petish_restore"]
        for attempt in range(120):
            try:
                if BASE.run_private(command + ["--execute", "SELECT 1"], work, capture=True).strip() == b"1":
                    break
            except BASE.RestoreFailure:
                if BASE.run_private(["inspect", "--format", "{{.State.Running}}", name], work, capture=True).strip() != b"true":
                    raise
            time.sleep(1)
        else:
            raise BASE.RestoreFailure()
        state[0] = "database_import"
        expected = BASE.sql_table_count(sql_path)
        if expected < 1:
            raise BASE.RestoreFailure()
        with sql_path.open("rb") as sql:
            BASE.run_private(command, work, source=sql, timeout=900)
        state[0] = "database_verify"
        values = BASE.run_private(command + ["--execute",
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='petish_restore';"
            "SELECT @@GLOBAL.event_scheduler; SELECT @@GLOBAL.skip_networking;"], work, capture=True).split()
        if len(values) != 3 or not values[0].isdigit() or int(values[0]) != expected \
                or values[1:] != [b"DISABLED", b"1"]:
            raise BASE.RestoreFailure()
        if BASE.run_private(["inspect", "--format", "{{.HostConfig.NetworkMode}}", name], work, capture=True).strip() != b"none":
            raise BASE.RestoreFailure()
        yield name, socket_volume, {"database_import_verified": True,
                                   "restored_database_tables": int(values[0]),
                                   "sql_create_table_statements": expected}
    finally:
        # Backstop cleanup in the workflow also removes containers/volumes by label.
        cleanup_failed = False
        for arguments, existence in (
            (["rm", "--force", "--volumes", name],
             ["ps", "--all", "--quiet", "--filter", "name=^/" + name + "$"]),
            (["volume", "rm", "--force", socket_volume],
             ["volume", "ls", "--quiet", "--filter", "name=^" + socket_volume + "$"])):
            try:
                BASE.run_private(arguments, work, timeout=60)
            except (BASE.RestoreFailure, subprocess.TimeoutExpired, OSError):
                try:
                    cleanup_failed |= bool(BASE.run_private(existence, work, capture=True).strip())
                except (BASE.RestoreFailure, subprocess.TimeoutExpired, OSError):
                    cleanup_failed = True
        if cleanup_failed:
            state[0] = "cleanup"
            raise BASE.RestoreFailure()


def recover(encrypted, passphrase, runner_temp, state):
    runner_temp = runner_temp.resolve(strict=True)
    if not runner_temp.is_dir() or encrypted.is_symlink() or not encrypted.is_file():
        raise BASE.RestoreFailure()
    with tempfile.TemporaryDirectory(prefix="petish-application-recovery-", dir=runner_temp) as temporary:
        work = Path(temporary)
        work.chmod(0o700)
        (work / "gnupg").mkdir(mode=0o700)
        try:
            state[0] = "decryption"
            with BASE.decrypted_stream(encrypted, passphrase, work) as source:
                verified = BASE.VERIFIER.verify(source)
            state[0] = "extraction"
            with BASE.decrypted_stream(encrypted, passphrase, work) as source:
                files = BASE.safe_extract(source, work / "restored")
            if files["private_files_restored"] != verified["private_regular_files"]:
                raise BASE.RestoreFailure()
            with database(work / "restored/database/site.sql", work, state) as (name, socket, restored_db):
                state[0] = "prepare"
                try:
                    app = APPLICATION.verify_application(name, socket, work / "restored", work)
                except APPLICATION.ApplicationRecoveryFailure as error:
                    state[0] = error.stage if error.stage in STAGES else "bootstrap"
                    raise
            result = {"wordpress_application_recovery_passed": True, **files, **restored_db, **app}
        finally:
            subprocess.run(["gpgconf", "--homedir", str(work / "gnupg"), "--kill", "gpg-agent"],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                           env=BASE.child_environment(), timeout=15, check=False)
    result["private_sandbox_removed"] = True
    state[0] = "complete"
    return result


def main():
    os.umask(0o077)
    state = ["input"]
    try:
        parser = argparse.ArgumentParser(description=__doc__)
        parser.add_argument("--encrypted", required=True, type=Path)
        parser.add_argument("--passphrase-fd", required=True, type=int)
        args = parser.parse_args()
        with os.fdopen(args.passphrase_fd, "rb") as source:
            passphrase = source.read(4097).rstrip(b"\n")
        if not 24 <= len(passphrase) <= 4096 or b"\n" in passphrase or b"\r" in passphrase:
            raise BASE.RestoreFailure()
        def interrupted(signum, frame):
            raise BASE.RestoreFailure()
        signal.signal(signal.SIGTERM, interrupted)
        signal.signal(signal.SIGINT, interrupted)
        result = recover(args.encrypted, passphrase, Path(os.environ["RUNNER_TEMP"]), state)
    except Exception:
        print(json.dumps({"wordpress_application_recovery_passed": False,
                          "failure_stage": state[0] if state[0] in STAGES else "input"}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
