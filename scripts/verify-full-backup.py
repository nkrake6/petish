#!/usr/bin/env python3
"""Check a decrypted full-site archive in memory without extracting or logging it."""

import gzip
import json
import posixpath
import sys
import tarfile
import zlib
from pathlib import PurePosixPath

REQUIRED = {
    "site-files/wp-config.php",
    "site-files/wp-includes/version.php",
    "database/site.sql",
    "backup-metadata/README.txt",
}
MAX_EXPANDED_BYTES = 20 * 1024 * 1024 * 1024
MAX_ENTRIES = 1_000_000
ORIGINAL_DOCUMENT_ROOT = "/home/u839205597/domains/petish.biz/public_html"


def validate_name(name):
    path = PurePosixPath(name.rstrip("/"))
    if path.is_absolute() or ".." in path.parts or not path.parts:
        raise ValueError("Unsafe archive path")
    if path.parts[0] not in {"site-files", "database", "backup-metadata"}:
        raise ValueError("Unexpected archive root")
    return path.as_posix()


def verify(stream):
    found, names, regular_files = set(), set(), set()
    hard_links = {}
    count = total_bytes = 0
    sql_has_schema = False
    with gzip.GzipFile(fileobj=stream, mode="rb") as compressed:
        with tarfile.open(fileobj=compressed, mode="r|") as archive:
            for member in archive:
                name = validate_name(member.name)
                if name in names:
                    raise ValueError("Duplicate archive member")
                names.add(name)
                count += 1
                total_bytes += member.size
                if count > MAX_ENTRIES or total_bytes > MAX_EXPANDED_BYTES:
                    raise ValueError("Backup exceeds verification capacity")
                if member.isdir():
                    continue
                if member.issym():
                    if not name.startswith("site-files/"):
                        raise ValueError("Unexpected symbolic link")
                    if posixpath.isabs(member.linkname):
                        target = posixpath.normpath(member.linkname)
                        if not target.startswith(ORIGINAL_DOCUMENT_ROOT + "/") and target != ORIGINAL_DOCUMENT_ROOT:
                            raise ValueError("External symbolic link")
                    else:
                        target = posixpath.normpath(posixpath.join(posixpath.dirname(name), member.linkname))
                        if target != "site-files" and not target.startswith("site-files/"):
                            raise ValueError("Escaping symbolic link")
                    continue
                if member.islnk():
                    target = validate_name(member.linkname)
                    if not name.startswith("site-files/") or not target.startswith("site-files/"):
                        raise ValueError("External hard link")
                    hard_links[name] = target
                    continue
                if not member.isfile():
                    raise ValueError("Unsupported special file")
                regular_files.add(name)
                if name in REQUIRED:
                    if member.size == 0:
                        raise ValueError("Required backup member is empty")
                    found.add(name)
                with archive.extractfile(member) as source:
                    remaining = member.size
                    carry = b""
                    while remaining:
                        data = source.read(min(1024 * 1024, remaining))
                        if not data:
                            raise ValueError("Truncated archive member")
                        remaining -= len(data)
                        if name == "database/site.sql":
                            window = carry + data
                            sql_has_schema |= b"CREATE TABLE" in window.upper()
                            carry = window[-32:]
            # Consume trailing gzip data to force CRC/end-of-stream verification.
            while compressed.read(1024 * 1024):
                pass
    for name, target in hard_links.items():
        visited = {name}
        while target in hard_links:
            if target in visited:
                raise ValueError("Cyclic hard link")
            visited.add(target)
            target = hard_links[target]
        if target not in regular_files:
            raise ValueError("Missing hard-link target")
    if found != REQUIRED or not sql_has_schema:
        raise ValueError("Incomplete WordPress/database backup")
    return {"archive_verified": True, "archive_entries": count, "uncompressed_bytes": total_bytes}


if __name__ == "__main__":
    try:
        result = verify(sys.stdin.buffer)
        print(json.dumps(result))
    except (ValueError, OSError, EOFError, tarfile.TarError, zlib.error):
        # Never print names, contents, SQL, or parser exceptions from a full backup.
        print("Full backup integrity/structure verification failed. Private backup contents were not printed or published.", file=sys.stderr)
        sys.exit(1)
