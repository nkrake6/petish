#!/usr/bin/env python3
"""Check a decrypted full-site archive in memory without extracting or logging it."""

import gzip
import json
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
PRIVATE_ROOT = "private-files/petish-passport"
SMTP_ROOT = "private-files/petish-mailer"
SMTP_CONFIG = SMTP_ROOT + "/smtp.json"
MAILER_MU = "site-files/wp-content/mu-plugins/petish-mailer.php"
MAX_SMTP_CONFIG_BYTES = 8192


def validate_name(name):
    path = PurePosixPath(name.rstrip("/"))
    if path.is_absolute() or ".." in path.parts or not path.parts:
        raise ValueError("Unsafe archive path")
    if path.parts[0] not in {"site-files", "private-files", "database", "backup-metadata"}:
        raise ValueError("Unexpected archive root")
    if path.parts[0] == "private-files" and path.as_posix() != "private-files" \
            and not any(path.as_posix() == root or path.as_posix().startswith(root + "/")
                        for root in (PRIVATE_ROOT, SMTP_ROOT)):
        raise ValueError("Unexpected private archive tree")
    return path.as_posix()


def is_storage_member(name):
    return name.startswith("site-files/") or any(name.startswith(root + "/") for root in (PRIVATE_ROOT, SMTP_ROOT))


def verify_smtp_configuration(data):
    """Validate only in private memory; never return credentials or parsed fields."""
    value = json.loads(data)
    if not isinstance(value, dict) or set(value) != {"host", "port", "encryption", "username", "password"}:
        raise ValueError("Invalid private SMTP configuration")
    if value["host"] not in ("smtp.hostinger.com", "smtp.titan.email") \
            or type(value["port"]) is not int or value["port"] != 465 \
            or value["encryption"] != "smtps" or value["username"] != "orders@petish.biz" \
            or not isinstance(value["password"], str) or not 1 <= len(value["password"].encode("utf-8")) <= 2048 \
            or any(ord(character) < 32 or ord(character) == 127 for character in value["password"]):
        raise ValueError("Invalid private SMTP configuration")


def verify(stream):
    found, names, regular_files, symbolic_links = set(), set(), set(), set()
    hard_links = {}
    count = total_bytes = 0
    private_root_found = False
    private_regular_files = private_regular_bytes = 0
    smtp_root_found = smtp_config_verified = False
    smtp_regular_files = smtp_regular_bytes = 0
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
                if member.size < 0 or count > MAX_ENTRIES or total_bytes > MAX_EXPANDED_BYTES:
                    raise ValueError("Backup exceeds verification capacity")
                if name in {"private-files", PRIVATE_ROOT, SMTP_ROOT} and not member.isdir():
                    raise ValueError("Private storage root must be a directory")
                if name == PRIVATE_ROOT:
                    private_root_found = True
                if name == SMTP_ROOT:
                    if member.mode & 0o7777 != 0o700:
                        raise ValueError("Unsafe private SMTP directory permissions")
                    smtp_root_found = True
                if name == SMTP_CONFIG and (not member.isfile() or member.mode & 0o7777 != 0o600
                                            or not 0 < member.size <= MAX_SMTP_CONFIG_BYTES):
                    raise ValueError("Unsafe private SMTP configuration member")
                if member.isdir():
                    continue
                if member.issym():
                    if not is_storage_member(name):
                        raise ValueError("Unexpected symbolic link")
                    # A backup stores the reference itself, never its target.
                    # No archive is extracted here. Check all members after the
                    # scan so symlink-ancestor traversal is rejected in any order.
                    symbolic_links.add(name)
                    continue
                if member.islnk():
                    target = validate_name(member.linkname)
                    if not is_storage_member(name) or not is_storage_member(target):
                        raise ValueError("External hard link")
                    hard_links[name] = target
                    continue
                if not member.isfile():
                    raise ValueError("Unsupported special file")
                regular_files.add(name)
                if name.startswith(PRIVATE_ROOT + "/"):
                    private_regular_files += 1
                    private_regular_bytes += member.size
                if name.startswith(SMTP_ROOT + "/"):
                    smtp_regular_files += 1
                    smtp_regular_bytes += member.size
                if name in REQUIRED:
                    if member.size == 0:
                        raise ValueError("Required backup member is empty")
                    found.add(name)
                with archive.extractfile(member) as source:
                    remaining = member.size
                    carry = b""
                    smtp_data = bytearray()
                    while remaining:
                        data = source.read(min(1024 * 1024, remaining))
                        if not data:
                            raise ValueError("Truncated archive member")
                        remaining -= len(data)
                        if name == SMTP_CONFIG:
                            smtp_data.extend(data)
                        if name == "database/site.sql":
                            window = carry + data
                            sql_has_schema |= b"CREATE TABLE" in window.upper()
                            carry = window[-32:]
                    if name == SMTP_CONFIG:
                        verify_smtp_configuration(bytes(smtp_data))
                        smtp_config_verified = True
            # Consume trailing gzip data to force CRC/end-of-stream verification.
            while compressed.read(1024 * 1024):
                pass
    for name in names:
        if any(parent.as_posix() in symbolic_links for parent in PurePosixPath(name).parents):
            raise ValueError("Archive member traverses a symbolic link")
    for name, target in hard_links.items():
        visited = {name}
        while target in hard_links:
            if target in visited:
                raise ValueError("Cyclic hard link")
            visited.add(target)
            target = hard_links[target]
        if target not in regular_files:
            raise ValueError("Missing hard-link target")
        if target == SMTP_CONFIG:
            raise ValueError("Private SMTP configuration cannot have hard links")
    if found != REQUIRED or not sql_has_schema or not private_root_found:
        raise ValueError("Incomplete WordPress/database backup")
    smtp_present = any(name == SMTP_ROOT or name.startswith(SMTP_ROOT + "/") for name in names)
    if (smtp_present or MAILER_MU in names) and not (smtp_root_found and smtp_config_verified):
        raise ValueError("Incomplete private SMTP backup")
    return {"archive_verified": True, "archive_entries": count, "uncompressed_bytes": total_bytes,
            "symbolic_links_preserved": len(symbolic_links),
            "private_passport_root_verified": True,
            "private_regular_files": private_regular_files,
            "private_regular_bytes": private_regular_bytes,
            "private_smtp_config_verified": smtp_config_verified,
            "private_smtp_regular_files": smtp_regular_files,
            "private_smtp_regular_bytes": smtp_regular_bytes}


if __name__ == "__main__":
    try:
        result = verify(sys.stdin.buffer)
        print(json.dumps(result))
    except (ValueError, OSError, EOFError, tarfile.TarError, zlib.error):
        # Never print names, contents, SQL, or parser exceptions from a full backup.
        print("Full backup integrity/structure verification failed. Private backup contents were not printed or published.", file=sys.stderr)
        sys.exit(1)
