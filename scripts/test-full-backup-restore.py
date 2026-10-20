#!/usr/bin/env python3
"""Private file/database recovery smoke test; never boot the restored website.

Only the local Docker socket is used. The database gets no network, host mounts,
published ports, or original database credentials. Archive links are inspected,
counted and skipped. No archive filename, SQL, configuration or private diagnostic
is emitted, including on failure. Requires Python 3.10+, GnuPG and local Docker.
"""

import argparse
import contextlib
import gzip
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import uuid


# Official MariaDB 11.4 multi-platform manifest, resolved 2026-10-20. Updating
# this requires running the restore integration fixture again.
MARIADB_IMAGE = "mariadb:11.4@sha256:250e1609e93c0e6aecf64bfbab2d84d5e89d2481540fe77447ff6c855c3b1873"
DOCKER = ["docker", "--host", "unix:///var/run/docker.sock"]
DATABASE = "petish_restore"
CREATE_TABLE = re.compile(rb"^\s*CREATE\s+TABLE\s+", re.I)
_spec = importlib.util.spec_from_file_location(
    "petish_backup_verifier", Path(__file__).with_name("verify-full-backup.py")
)
VERIFIER = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(VERIFIER)


class RestoreFailure(Exception):
    """Intentionally carries no archive-derived diagnostic."""


def child_environment():
    # Do not propagate application/SSH/cloud secrets or Docker host overrides.
    return {"PATH": os.environ.get("PATH", os.defpath), "LANG": "C.UTF-8"}


@contextlib.contextmanager
def decrypted_stream(encrypted, passphrase, work):
    read_fd, write_fd = os.pipe()
    process = None
    try:
        os.write(write_fd, passphrase + b"\n")
        os.close(write_fd)
        write_fd = None
        with (work / "gpg.stderr").open("ab") as diagnostics:
            process = subprocess.Popen(
                ["gpg", "--no-options", "--homedir", str(work / "gnupg"),
                 "--batch", "--quiet", "--pinentry-mode", "loopback",
                 "--no-symkey-cache", "--passphrase-fd", str(read_fd),
                 "--decrypt", str(encrypted)],
                pass_fds=(read_fd,), stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=diagnostics,
                env=child_environment(),
            )
            os.close(read_fd)
            read_fd = None
            try:
                yield process.stdout
                if process.wait(timeout=30) != 0:
                    raise RestoreFailure()
            finally:
                process.stdout.close()
                if process.poll() is None:
                    process.kill()
                    process.wait(timeout=30)
    finally:
        for fd in (read_fd, write_fd):
            if fd is not None:
                os.close(fd)


def private_parent(root_fd, parts):
    """Open/create ancestors without following any filesystem link."""
    current = os.dup(root_fd)
    try:
        for part in parts:
            try:
                os.mkdir(part, 0o700, dir_fd=current)
            except FileExistsError:
                pass
            following = os.open(
                part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                dir_fd=current,
            )
            os.close(current)
            current = following
        return current
    except BaseException:
        os.close(current)
        raise


def safe_extract(stream, destination):
    """Copy regular bytes only; tarfile.extract/extractall are never called."""
    destination.mkdir(mode=0o700)
    root_fd = os.open(destination, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    names, regular, links = set(), set(), set()
    hard_links = {}
    total = files = private_files = private_smtp_files = skipped_links = 0
    try:
        with gzip.GzipFile(fileobj=stream, mode="rb") as compressed:
            with tarfile.open(fileobj=compressed, mode="r|") as archive:
                for member in archive:
                    name = VERIFIER.validate_name(member.name)
                    if name in names:
                        raise RestoreFailure()
                    names.add(name)
                    total += member.size
                    if len(names) > VERIFIER.MAX_ENTRIES or total > VERIFIER.MAX_EXPANDED_BYTES:
                        raise RestoreFailure()
                    if member.size < 0:
                        raise RestoreFailure()
                    if member.issym() or member.islnk():
                        if not VERIFIER.is_storage_member(name):
                            raise RestoreFailure()
                        links.add(name)
                        if member.islnk():
                            target = VERIFIER.validate_name(member.linkname)
                            if not VERIFIER.is_storage_member(target):
                                raise RestoreFailure()
                            hard_links[name] = target
                        skipped_links += 1
                        continue
                    parts = PurePosixPath(name).parts
                    parent_fd = private_parent(root_fd, parts[:-1])
                    try:
                        if member.isdir():
                            try:
                                os.mkdir(parts[-1], 0o700, dir_fd=parent_fd)
                            except FileExistsError:
                                existing = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
                                if not stat.S_ISDIR(existing.st_mode):
                                    raise RestoreFailure()
                            continue
                        if not member.isfile():
                            raise RestoreFailure()
                        fd = os.open(parts[-1], os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                                     0o600, dir_fd=parent_fd)
                        with os.fdopen(fd, "wb") as target, archive.extractfile(member) as source:
                            remaining = member.size
                            while remaining:
                                chunk = source.read(min(1024 * 1024, remaining))
                                if not chunk:
                                    raise RestoreFailure()
                                target.write(chunk)
                                remaining -= len(chunk)
                        regular.add(name)
                        files += 1
                        private_files += int(name.startswith(VERIFIER.PRIVATE_ROOT + "/"))
                        private_smtp_files += int(name.startswith(VERIFIER.SMTP_ROOT + "/"))
                    finally:
                        os.close(parent_fd)
                # Enforce gzip CRC/truncation checks even after tar EOF.
                while compressed.read(1024 * 1024):
                    pass
        # Validate every path after the scan too: order cannot conceal ancestors.
        for name in names:
            if any(parent.as_posix() in links for parent in PurePosixPath(name).parents):
                raise RestoreFailure()
        for name, target in hard_links.items():
            visited = {name}
            while target in hard_links:
                if target in visited:
                    raise RestoreFailure()
                visited.add(target)
                target = hard_links[target]
            if target not in regular:
                raise RestoreFailure()
        if not VERIFIER.REQUIRED.issubset(regular) or VERIFIER.PRIVATE_ROOT not in names:
            raise RestoreFailure()
        if not (destination / VERIFIER.PRIVATE_ROOT).is_dir():
            raise RestoreFailure()
        return {"regular_files_restored": files, "private_files_restored": private_files,
                "private_smtp_files_restored": private_smtp_files,
                "archive_links_skipped": skipped_links}
    finally:
        os.close(root_fd)


def run_private(arguments, work, *, source=None, timeout=120, capture=False):
    """Keep all subprocess output private, even errors that quote SQL or config."""
    with (work / "docker.stderr").open("ab") as errors:
        if capture:
            result = subprocess.run(DOCKER + arguments, stdin=source or subprocess.DEVNULL,
                                    stdout=subprocess.PIPE, stderr=errors, timeout=timeout,
                                    env=child_environment())
        else:
            with (work / "docker.stdout").open("ab") as output:
                result = subprocess.run(DOCKER + arguments, stdin=source or subprocess.DEVNULL,
                                        stdout=output, stderr=errors, timeout=timeout,
                                        env=child_environment())
    if result.returncode:
        raise RestoreFailure()
    return result.stdout if capture else None


def sql_table_count(sql_path):
    # Native dump CREATE TABLE statements begin on their own line. Keep the
    # content private; a count is a sanity check, not a general SQL parser.
    count, at_start = 0, True
    with sql_path.open("rb") as sql:
        while part := sql.readline(65536):
            if at_start and CREATE_TABLE.match(part):
                count += 1
            at_start = part.endswith(b"\n")
    return count


def restore_database(sql_path, work):
    name = "petish-restore-" + uuid.uuid4().hex
    root_password = secrets.token_hex(32)
    env_file = work / "database.env"
    env_file.write_text("MARIADB_ROOT_PASSWORD=" + root_password + "\nMARIADB_DATABASE=" + DATABASE + "\n")
    env_file.chmod(0o600)
    client = work / "client.cnf"
    client.write_text("[client]\nuser=root\npassword=" + root_password + "\nprotocol=socket\n")
    client.chmod(0o600)
    expected = sql_table_count(sql_path)
    if expected < 1:
        raise RestoreFailure()
    cleanup_ok = False
    try:
        run_private(["pull", MARIADB_IMAGE], work, timeout=300)
        run_private(["run", "--detach", "--name", name,
                     "--label", "org.petish.restore-smoke=true",
                     "--network", "none", "--cap-drop", "NET_RAW",
                     "--security-opt", "no-new-privileges:true", "--pids-limit", "256",
                     "--memory", "4g", "--cpus", "2",
                     "--tmpfs", "/petish-no-export:ro,nosuid,noexec,size=1m",
                     "--env-file", str(env_file), MARIADB_IMAGE,
                     # DISABLED cannot be re-enabled by imported SET GLOBAL SQL.
                     "--skip-networking", "--event-scheduler=DISABLED", "--local-infile=0",
                     "--secure-file-priv=/petish-no-export", "--skip-log-bin"], work)
        # The generated file is copied in, never original WordPress credentials.
        run_private(["cp", str(client), name + ":/tmp/petish-restore-client.cnf"], work)
        command = ["exec", "--interactive", name, "mariadb",
                   "--defaults-extra-file=/tmp/petish-restore-client.cnf",
                   "--binary-mode=1", "--local-infile=0", "--skip-reconnect",
                   "--batch", "--skip-column-names", "--database", DATABASE]
        for attempt in range(120):
            try:
                if run_private(command + ["--execute", "SELECT 1"], work, timeout=10, capture=True).strip() == b"1":
                    break
            except RestoreFailure:
                running = run_private(["inspect", "--format", "{{.State.Running}}", name],
                                      work, capture=True).strip()
                if running != b"true":
                    raise RestoreFailure()
            time.sleep(1)
        else:
            raise RestoreFailure()
        with sql_path.open("rb") as sql:
            run_private(command, work, source=sql, timeout=900)
        # No restored application or SQL-derived command is executed. The only
        # post-import query is fixed and its output must be three safe scalars.
        query = ("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" + DATABASE + "';"
                 "SELECT @@GLOBAL.event_scheduler; SELECT @@GLOBAL.skip_networking;")
        values = run_private(command + ["--execute", query], work, capture=True).split()
        if len(values) != 3 or not values[0].isdigit() or int(values[0]) < 1 \
                or values[1] != b"DISABLED" or values[2] != b"1":
            raise RestoreFailure()
        # Confirm the container itself was disconnected (no published ports or
        # bind mounts are requested anywhere in this script).
        network = run_private(["inspect", "--format", "{{.HostConfig.NetworkMode}}", name],
                              work, capture=True).strip()
        if network != b"none":
            raise RestoreFailure()
        result = {"database_import_verified": True, "sql_create_table_statements": expected,
                  "restored_database_tables": int(values[0]), "database_network": "none",
                  "database_events": "disabled"}
    finally:
        try:
            run_private(["rm", "--force", "--volumes", name], work, timeout=60)
            cleanup_ok = True
        except (RestoreFailure, subprocess.TimeoutExpired, OSError):
            # Missing container (for example, image pull failed) needs no removal.
            try:
                present = run_private(["ps", "--all", "--quiet", "--filter", "name=^/" + name + "$"],
                                      work, capture=True).strip()
                cleanup_ok = not present
            except (RestoreFailure, subprocess.TimeoutExpired, OSError):
                pass
        if not cleanup_ok:
            raise RestoreFailure()
    return result


def restore(encrypted, passphrase, runner_temp):
    runner_temp = runner_temp.resolve(strict=True)
    if not runner_temp.is_dir() or not encrypted.is_file() or encrypted.is_symlink():
        raise RestoreFailure()
    with tempfile.TemporaryDirectory(prefix="petish-restore-", dir=runner_temp) as temporary:
        work = Path(temporary)
        work.chmod(0o700)
        (work / "gnupg").mkdir(mode=0o700)
        try:
            # Verify the authenticated decrypted stream in full before writing
            # any restored member. Verification never bootstraps WordPress.
            with decrypted_stream(encrypted, passphrase, work) as source:
                verified = VERIFIER.verify(source)
            with decrypted_stream(encrypted, passphrase, work) as source:
                result = safe_extract(source, work / "restored")
            if result["private_files_restored"] != verified["private_regular_files"]:
                raise RestoreFailure()
            if result["private_smtp_files_restored"] != verified["private_smtp_regular_files"]:
                raise RestoreFailure()
            result["private_smtp_config_verified"] = verified["private_smtp_config_verified"]
            result.update(restore_database(work / "restored/database/site.sql", work))
            result.update({"restore_smoke_test_passed": True,
                           "private_passport_root_restored": True,
                           "wordpress_application_bootstrap_tested": False,
                           "scope": "regular files and isolated database import; links skipped; no application, delivery or payment test"})
        finally:
            # Kill GnuPG's private agent before removing its socket directory.
            subprocess.run(["gpgconf", "--homedir", str(work / "gnupg"), "--kill", "gpg-agent"],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                           env=child_environment(), timeout=15, check=False)
    # TemporaryDirectory cleanup completed before reporting success.
    result["private_sandbox_removed"] = True
    return result


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--encrypted", required=True, type=Path)
    parser.add_argument("--passphrase-fd", required=True, type=int)
    args = parser.parse_args()
    with os.fdopen(args.passphrase_fd, "rb") as source:
        passphrase = source.read(4097).rstrip(b"\n")
    if not 24 <= len(passphrase) <= 4096 or b"\n" in passphrase or b"\r" in passphrase:
        raise RestoreFailure()
    runner_temp = os.environ.get("RUNNER_TEMP")
    if not runner_temp:
        raise RestoreFailure()
    def interrupted(signum, frame):
        raise RestoreFailure()
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    result = restore(args.encrypted, passphrase, Path(runner_temp))
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except Exception:
        print("Isolated backup restore smoke test failed. Private contents and diagnostics were not printed. No existing site or database was targeted.", file=sys.stderr)
        sys.exit(1)
