#!/usr/bin/env python3
"""Publish fixed diagnostic categories without copying private task logs.

Usage: record-task-result.py PHASE EXITCODE STDOUTLOG STDERRLOG OUTPUTJSON
Only phase, numeric exit code, and allowlisted categories leave this process.
"""

import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile


PHASES = frozenset({"backup-export", "backup-restore", "staging", "source-sync"})
CATEGORIES = (
    "private_passport_missing", "private_passport_link", "private_passport_unreadable",
    "private_passport_unavailable", "private_boundary", "wpcli_missing",
    "db_export_failed", "archive_changed", "archive_error", "verifier_failed",
    "passphrase_missing", "ssh_hostkey_failed", "ssh_auth_failed",
    "source_screening_failed", "staging_validation_failed", "unknown",
)
MAX_LOG_BYTES = 1024 * 1024

# Every match maps to constants; captured values and source lines are never used.
MESSAGES = {
    "private_passport_missing": (
        "the required private passport directory is missing; backup stopped.",
    ),
    "private_passport_link": (
        "the required private passport directory is a symbolic link; backup stopped.",
    ),
    "private_passport_unreadable": (
        "the required private passport directory is unreadable; backup stopped.",
    ),
    "private_passport_unavailable": (
        "the required private passport directory is missing, unreadable, or a symbolic link",
        "unable to resolve the private passport directory safely",
        "the private passport file tree could not be fully read",
    ),
    "private_boundary": (
        "the private passport directory resolves outside its expected private boundary",
        "the website or private backup location resolves outside its expected boundary",
    ),
    "wpcli_missing": ("wp-cli is unavailable",),
    "db_export_failed": (
        "the full database export failed", "the database export was empty",
        "a native mariadb/mysql dump client is unavailable",
        "unable to read required database configuration",
        "unable to inspect supported database dump options",
        "unsupported database connection syntax", "unsupported database host syntax",
        "unsupported database port", "custom database client flags require review",
        "the dump client cannot enforce the required verified database tls connection",
    ),
    "archive_changed": (),
    "archive_error": (
        "the complete file archive failed", "unable to inspect archive-tool capabilities",
        "the full backup exceeded this workflow size limit",
    ),
    "verifier_failed": (
        "full backup integrity/structure verification failed",
        "isolated backup restore smoke test failed",
        "a nonempty verified encrypted backup including private passport storage is required",
    ),
    "passphrase_missing": (
        "a long, single-line backup passphrase is required",
        "the backup passphrase secret is required for the restore smoke test",
        "add petish_backup_passphrase as a repository secret",
        "petish_backup_passphrase: unbound variable",
    ),
    "ssh_hostkey_failed": (
        "ssh host-key verification failed", "host key verification failed",
        "the ssh server key does not match the trusted fingerprint",
        "remote host identification has changed",
    ),
    "ssh_auth_failed": (
        "permission denied, please try again", "too many authentication failures",
        "the existing ssh password secret and verified fingerprint variable are required",
        "the existing ssh secret and verified fingerprint are required",
    ),
    "source_screening_failed": (
        "the secondary secret scanner failed", "source screening stopped:",
        "the read-only custom source export failed",
        "the custom source export exceeded the size limit", "source export stopped:",
    ),
    "staging_validation_failed": (
        "staging_validation_rejected", "staging_package_rejected",
        "a private deployment directory could not be verified",
    ),
}
ARCHIVE_LABELS = {
    "live_file_changed": "archive_changed",
    "source_file_removed": "archive_changed",
    "source_permission_denied": "archive_error",
    "acl_or_extended_attributes": "archive_error",
    "compressor_missing": "archive_error",
    "compressor_or_child_process": "archive_error",
    "unsupported_archive_option": "archive_error",
    "archive_stream_write_error": "archive_error",
    "archive_directory_unavailable": "archive_error",
    "storage_or_quota_limit": "archive_error",
    "unclassified_archive_error": "archive_error",
    "private_metadata_preflight_failed": "archive_error",
    "git_backup_size_limit": "archive_error",
}
STAGING_ERROR_LABELS = (
    "staging_deploy_arguments_rejected", "staging_deploy_guard_changed",
    "staging_deploy_home_rejected", "staging_deploy_lint_failed",
    "staging_deploy_locked", "staging_deploy_mode_rejected",
    "staging_deploy_php_required", "staging_deploy_private_directory_failed",
    "staging_deploy_rollback_requires_review", "staging_deploy_source_rejected",
    "staging_deploy_validation_failed",
)


def read_private_log(filename):
    """Read bounded regular-file text without following links or blocking on FIFOs."""
    descriptor = os.open(filename, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode):
            raise ValueError("Private log is not a regular file")
        with os.fdopen(descriptor, "rb", closefd=False) as source:
            if metadata.st_size <= MAX_LOG_BYTES:
                data = source.read(MAX_LOG_BYTES)
            else:
                head = source.read(MAX_LOG_BYTES // 2)
                source.seek(-MAX_LOG_BYTES // 2, os.SEEK_END)
                data = head + b"\n" + source.read(MAX_LOG_BYTES // 2)
        return data.decode("utf-8", errors="replace").casefold()
    finally:
        os.close(descriptor)


def classify(text):
    found = {
        category for category, messages in MESSAGES.items()
        if any(message in text for message in messages)
    }
    for label in re.findall(r"(?m)^archive diagnostic: ([a-z0-9_]+)\b", text):
        if label in ARCHIVE_LABELS:
            found.add(ARCHIVE_LABELS[label])
    if re.search(r"permission denied \((?:publickey|password|keyboard-interactive)[a-z, -]*\)", text):
        found.add("ssh_auth_failed")
    if re.search(r"\b(?:" + "|".join(STAGING_ERROR_LABELS) + r")\b", text):
        found.add("staging_validation_failed")
    if not found and (
        re.search(r"(?m)^archive diagnostic: tar_exit=[0-9]+\s*$", text)
        or "the full backup stream did not complete" in text
    ):
        found.add("archive_error")
    return [category for category in CATEGORIES if category in found] or ["unknown"]


def task_result(phase, exit_code, stdout_log, stderr_log):
    if phase not in PHASES or type(exit_code) is not int or not 0 <= exit_code <= 255:
        raise ValueError("Invalid diagnostic invocation")
    if exit_code == 0:
        categories = []
    else:
        try:
            categories = classify(read_private_log(stdout_log) + "\n" + read_private_log(stderr_log))
        except Exception:
            categories = ["unknown"]
    return {"phase": phase, "exit_code": exit_code, "categories": categories}


def write_result(result, filename):
    """Atomic replacement avoids following an output symlink into private logs."""
    destination = Path(filename)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", prefix=".task-result-",
                                         dir=destination.parent, delete=False) as output:
            temporary = Path(output.name)
            os.chmod(temporary, 0o600)
            json.dump(result, output, sort_keys=True)
            output.write("\n")
        os.replace(temporary, destination)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def main(arguments):
    if len(arguments) != 5 or arguments[0] not in PHASES:
        raise ValueError("Invalid diagnostic invocation")
    phase, raw_exit, stdout_log, stderr_log, output_json = arguments
    if not re.fullmatch(r"[0-9]{1,3}", raw_exit):
        raise ValueError("Invalid diagnostic invocation")
    result = task_result(phase, int(raw_exit), stdout_log, stderr_log)
    write_result(result, output_json)


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except Exception:
        print("Task diagnostic recording failed; private log contents and arguments were not printed.", file=sys.stderr)
        sys.exit(1)
