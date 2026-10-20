#!/usr/bin/env python3
"""Reassemble only checksum-verified encrypted backup parts from an exact Git branch."""

import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

MAX_BYTES = 1610612736


def prepare(branch, directory):
    if not re.fullmatch(r"full-site-backup/[0-9]+-[0-9]+", branch):
        raise ValueError()
    directory.mkdir(mode=0o700, parents=True, exist_ok=False)
    ref = "refs/remotes/origin/recovery-input"
    with (directory / "git.stderr").open("wb") as diagnostics:
        subprocess.run(["git", "fetch", "--no-tags", "--depth=1", "origin", branch + ":" + ref],
                       stdout=diagnostics, stderr=diagnostics, check=True, timeout=900)
        source = "site-backups/" + branch.split("/")[1] + "/"
        manifest_size = subprocess.run(["git", "cat-file", "-s", ref + ":" + source + "manifest.json"],
                                       stdout=subprocess.PIPE, stderr=diagnostics, check=True, timeout=120).stdout.strip()
        if not manifest_size.isdigit() or not 0 < int(manifest_size) <= 131072:
            raise ValueError()
        result = subprocess.run(["git", "show", ref + ":" + source + "manifest.json"],
                                stdout=subprocess.PIPE, stderr=diagnostics, check=True, timeout=120)
        if len(result.stdout) > 131072:
            raise ValueError()
        manifest = json.loads(result.stdout)
        if manifest.get("format") != "petish-full-wordpress-backup-v2":
            raise ValueError()
        size = manifest.get("encrypted_bytes")
        if type(size) is not int or not 0 < size <= MAX_BYTES:
            raise ValueError()
        if not re.fullmatch(r"[a-f0-9]{64}", manifest.get("encrypted_sha256", "")):
            raise ValueError()
        parts = manifest.get("parts")
        if not isinstance(parts, list) or not 1 <= len(parts) <= 64:
            raise ValueError()
        whole, total = hashlib.sha256(), 0
        destination = directory / "site-backup.tar.gz.gpg"
        with destination.open("xb") as output:
            for index, part in enumerate(parts):
                expected_name = "site-backup.tar.gz.gpg.part-" + str(index).zfill(5)
                if not isinstance(part, dict) or part.get("file") != expected_name:
                    raise ValueError()
                expected_size = part.get("bytes")
                if type(expected_size) is not int or not 0 < expected_size <= 48 * 1024 * 1024:
                    raise ValueError()
                if not re.fullmatch(r"[a-f0-9]{64}", part.get("sha256", "")):
                    raise ValueError()
                digest, count = hashlib.sha256(), 0
                process = subprocess.Popen(["git", "show", ref + ":" + source + expected_name],
                                           stdout=subprocess.PIPE, stderr=diagnostics)
                try:
                    while block := process.stdout.read(1024 * 1024):
                        count += len(block)
                        total += len(block)
                        if count > expected_size or total > size:
                            raise ValueError()
                        output.write(block)
                        digest.update(block)
                        whole.update(block)
                    if process.wait(timeout=120) != 0:
                        raise ValueError()
                finally:
                    process.stdout.close()
                    if process.poll() is None:
                        process.kill()
                        process.wait(timeout=30)
                if count != expected_size or digest.hexdigest() != part["sha256"]:
                    raise ValueError()
        if total != size or whole.hexdigest() != manifest["encrypted_sha256"]:
            raise ValueError()
    return destination


if __name__ == "__main__":
    os.umask(0o077)
    try:
        if len(sys.argv) != 3:
            raise ValueError()
        prepare(sys.argv[1], Path(sys.argv[2]))
    except Exception:
        print("Encrypted recovery input could not be verified. No backup contents were printed.", file=sys.stderr)
        sys.exit(1)
    print("Encrypted recovery input and all part checksums verified.")
