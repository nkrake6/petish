#!/usr/bin/env python3
"""Package reviewed custom WordPress code for the existing private staging site.

This never includes a database, configuration, uploads, MU plugins, core, or
third-party code. The commit identifier is checked again by the remote script.
"""

import argparse
import hashlib
import io
import json
import os
import re
import stat
import tarfile
from pathlib import Path

COMPONENTS = (
    "wp-content/themes/dt-the7-child",
    "wp-content/plugins/petish-passport",
    "wp-content/plugins/petish-fulfilment",
)
REQUIRED = (
    COMPONENTS[0] + "/style.css",
    COMPONENTS[0] + "/functions.php",
    COMPONENTS[1] + "/petish-passport.php",
    COMPONENTS[2] + "/petish-fulfilment.php",
)
MANIFEST = "staging-source-manifest.json"
MAX_FILE_BYTES = 32 * 1024 * 1024
MAX_TOTAL_BYTES = 512 * 1024 * 1024
MAX_FILES = 20000
DENIED_SUFFIXES = (".sql", ".sqlite", ".db", ".log", ".pem", ".key", ".p12", ".pfx", ".zip", ".tar", ".gz", ".gpg", ".bak")
SECRET_PATTERNS = (
    re.compile(rb"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    re.compile(rb"GOCSPX-[A-Za-z0-9_-]{10,}"),
    re.compile(rb"(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})"),
    re.compile(rb"(?:sk_live_|sk_test_)[A-Za-z0-9]{12,}"),
    re.compile(rb"AKIA[A-Z0-9]{16}"),
    re.compile(rb"[\"']?(?:client_secret|api_secret|secret_key|api_key|access_token|refresh_token)[\"']?\s*(?:=>|=|:)\s*[\"'][A-Za-z0-9_./+=-]{12,}[\"']", re.I),
)


def reject_links(path):
    path = Path(os.path.abspath(path))
    for part in (path, *path.parents):
        if part.is_symlink():
            raise ValueError("Source and output paths must not contain symbolic links")
    return path


def safe_path(name):
    if not re.fullmatch(r"[A-Za-z0-9_. /@+\-]+", name) or any(p in ("", ".", "..") for p in name.split("/")):
        raise ValueError("Unsupported source path")
    for part in name.split("/"):
        lower = part.lower()
        if lower.startswith(".") or lower in ("wp-config.php", "credentials.json", "config.php") or lower.endswith(DENIED_SUFFIXES):
            raise ValueError("Configuration, private, or archive files are not deployable")


def source_root(path):
    root = reject_links(path)
    candidates = [root, root / "app/public", root / "wordpress", root / "site-files"]
    matches = [candidate for candidate in candidates if all((candidate / component).is_dir() for component in COMPONENTS)]
    if len(matches) != 1:
        raise ValueError("Expected exactly one complete custom-code source tree")
    return matches[0]


def package(source, output, source_sha):
    if not re.fullmatch(r"(?:[0-9a-f]{40}|[0-9a-f]{64})", source_sha):
        raise ValueError("A complete lowercase source commit SHA is required")
    root = source_root(source)
    output = reject_links(output)
    if output.exists():
        raise ValueError("Output must not already exist")
    output.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    reject_links(output.parent)
    entries, contents, total = [], {}, 0
    for component in COMPONENTS:
        base = reject_links(root / component)
        for directory, dirs, files in os.walk(base, followlinks=False):
            for name in sorted(dirs + files):
                item = Path(directory) / name
                info = item.lstat()
                relative = item.relative_to(root).as_posix()
                safe_path(relative)
                if stat.S_ISLNK(info.st_mode) or not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)):
                    raise ValueError("Only ordinary source directories and files are accepted")
                if stat.S_ISDIR(info.st_mode):
                    continue
                if info.st_nlink != 1 or info.st_size > MAX_FILE_BYTES:
                    raise ValueError("Linked or oversized source file rejected")
                # Open without following a raced final symbolic link; verify the
                # opened object still matches the file we screened.
                descriptor = os.open(item, os.O_RDONLY | os.O_NOFOLLOW)
                with os.fdopen(descriptor, "rb") as handle:
                    before = os.fstat(handle.fileno())
                    data = handle.read(MAX_FILE_BYTES + 1)
                    after = os.fstat(handle.fileno())
                identity = lambda state: (state.st_ino, state.st_dev, state.st_mode, state.st_nlink, state.st_size, state.st_mtime_ns, state.st_ctime_ns)
                if identity(before) != identity(info) or identity(before) != identity(after) or len(data) != info.st_size:
                    raise ValueError("Source changed while packaging")
                if any(pattern.search(data) for pattern in SECRET_PATTERNS):
                    raise ValueError("Potential credential found; review source privately")
                total += len(data)
                if total > MAX_TOTAL_BYTES or len(entries) >= MAX_FILES:
                    raise ValueError("Source package exceeds limits")
                contents[relative] = data
                entries.append({"path": relative, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()})
    if not all(name in contents for name in REQUIRED):
        raise ValueError("Required existing component entry points are missing")
    entries.sort(key=lambda entry: entry["path"])
    manifest = {"format": "petish-staging-source-v1", "source_sha": source_sha,
                "components": list(COMPONENTS), "files": entries}
    encoded = (json.dumps(manifest, sort_keys=True, separators=(",", ":")) + "\n").encode()
    if len(encoded) > 4 * 1024 * 1024:
        raise ValueError("Manifest exceeds limits")
    try:
        with output.open("xb") as raw:
            os.chmod(output, 0o600)
            with tarfile.open(fileobj=raw, mode="w:gz", format=tarfile.USTAR_FORMAT) as archive:
                for name, data in [(MANIFEST, encoded), *((entry["path"], contents[entry["path"]]) for entry in entries)]:
                    member = tarfile.TarInfo(name)
                    member.size, member.mode, member.mtime = len(data), 0o644, 0
                    member.uid = member.gid = 0
                    member.uname = member.gname = ""
                    archive.addfile(member, io.BytesIO(data))
    except BaseException:
        output.unlink(missing_ok=True)
        raise
    return manifest


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source")
    parser.add_argument("output")
    parser.add_argument("--source-sha", required=True)
    args = parser.parse_args()
    try:
        package(args.source, args.output, args.source_sha)
    except (ValueError, OSError, tarfile.TarError):
        raise SystemExit("STAGING_PACKAGE_REJECTED")
    print("STAGING_PACKAGE_READY")
