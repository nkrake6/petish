#!/usr/bin/env python3
"""Export only screened custom source; never execute PHP or reproduce findings.

Usage:
  screen-wordpress-source.py ARCHIVE READY_DIR [--source-sha WORKFLOW_SHA]
  screen-wordpress-source.py quarantine-gitleaks READY_DIR PRIVATE_REPORT

READY_DIR is newly created and contains wordpress/wp-content/... and a safe
source-manifest.json. Incomplete exports still exit zero so their inventory and
the remaining screened files can be reviewed; they must not be deployed as a
complete snapshot. Rejected archives exit 2 and leave a manifest only. Findings
contain paths, rule names and line numbers, never matched values or code.
"""

import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import sys
import tarfile
from datetime import datetime, timezone


MAX_COMPRESSED_BYTES = 150 * 1024 * 1024
MAX_EXPANDED_BYTES = 300 * 1024 * 1024
MAX_MEMBERS = 10_000
MAX_TEXT_BYTES = 8 * 1024 * 1024
MAX_FILE_BYTES = 32 * 1024 * 1024
MAX_METADATA_BYTES = 16 * 1024
SOURCE_SITE = "https://petish.biz"
METADATA_NAME = "source-export-metadata.json"
ROOTS = (
    "wp-content/themes/dt-the7-child",
    "wp-content/plugins/petish-passport",
    "wp-content/plugins/petish-fulfilment",
)
ANCESTORS = {"wp-content", "wp-content/themes", "wp-content/plugins", "wp-content/mu-plugins"}
TEXT_EXTENSIONS = {".php", ".css", ".js", ".json", ".md", ".txt", ".html", ".svg", ".xml", ".pot", ".po"}
BINARY_EXTENSIONS = {".png", ".jpg", ".jpeg", ".webp", ".woff", ".woff2", ".ttf", ".otf", ".ico"}
PRIVATE_DIRECTORIES = {
    "private", "secrets", "credentials", "backups", "backup", "logs", "log",
    "uploads", "cache", "tmp", "temp", "output", "reports", "captures", "fixtures",
}
PRIVATE_SUFFIXES = {
    ".log", ".sql", ".sqlite", ".sqlite3", ".db", ".zip", ".tar", ".gz",
    ".tgz", ".bz2", ".xz", ".7z", ".rar", ".bak", ".backup", ".old",
    ".orig", ".save", ".swp",
}
EXCLUSION_KEYS = {"metadata", "private_payload", "links", "special", "staging_guard"}


class ExportError(Exception):
    """Only fixed, non-sensitive diagnostic codes may escape this exception."""


class LimitedReader:
    def __init__(self, source, limit):
        self.source = source
        self.limit = limit
        self.total = 0

    def read(self, size=-1):
        if size < 0:
            size = self.limit - self.total + 1
        data = self.source.read(min(size, self.limit - self.total + 1))
        self.total += len(data)
        if self.total > self.limit:
            raise ExportError("expanded_size_limit")
        return data


def now_utc():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def sha256_file(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def safe_member_path(name):
    if not isinstance(name, str) or not name or len(name) > 1024:
        raise ExportError("invalid_member_path")
    if name.startswith("./"):
        name = name[2:]
    name = name.rstrip("/")
    if not name or name.startswith("/") or "\\" in name:
        raise ExportError("invalid_member_path")
    if any(ord(char) < 32 or ord(char) == 127 for char in name):
        raise ExportError("invalid_member_path")
    if any(part in {"", ".", ".."} for part in name.split("/")):
        raise ExportError("invalid_member_path")
    # Reject surrogate-escaped invalid UTF-8 rather than publishing it.
    try:
        name.encode("utf-8", "strict")
    except UnicodeError:
        raise ExportError("invalid_member_path") from None
    return name


def in_scope(path, is_directory=False):
    if path == METADATA_NAME:
        return not is_directory
    if is_directory and path in ANCESTORS:
        return True
    if any(path == root or path.startswith(root + "/") for root in ROOTS):
        return True
    parts = path.split("/")
    if len(parts) >= 3 and parts[:2] == ["wp-content", "mu-plugins"]:
        if not re.fullmatch(r"petish-[A-Za-z0-9_.-]+", parts[2]):
            return False
        return len(parts) > 3 or is_directory or parts[2].endswith(".php")
    return False


def exclusion_reason(path):
    parts = PurePosixPath(path).parts
    lowered = [part.lower() for part in parts]
    if any(part.startswith("petish-staging-guard") for part in lowered):
        return "staging_guard"
    if any(part.startswith(".") or part == "__macosx" for part in lowered):
        return "metadata"
    if any(part in PRIVATE_DIRECTORIES for part in lowered):
        return "private_payload"
    basename = lowered[-1]
    if PurePosixPath(basename).suffix in PRIVATE_SUFFIXES or basename.endswith("~"):
        return "private_payload"
    if re.match(r"^(credentials|secrets|passwords|captured-mail|checkout-fixture)\.", basename):
        return "private_payload"
    if basename in {"users.json", "orders.json", "customers.json", "contacts.json", "sessions.json"}:
        return "private_payload"
    return None


PROVIDER_RULES = (
    ("private_key", re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----")),
    ("google_oauth_secret", re.compile(r"\bGOCSPX-[A-Za-z0-9_-]{12,}")),
    ("google_api_key", re.compile(r"\bAIza[A-Za-z0-9_-]{30,}")),
    ("github_access_token", re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{22,})")),
    ("stripe_secret_key", re.compile(r"\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{12,}")),
    ("aws_access_key", re.compile(r"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b")),
    ("slack_access_token", re.compile(r"\bxox[baprs]-[A-Za-z0-9-]{15,}")),
    ("credential_in_url", re.compile(r"\b(?:https?|mysql|postgres(?:ql)?|redis|ftp)://[^\s/'\"@:]+:[^\s/'\"@]+@", re.I)),
    ("literal_bearer_token", re.compile(r"\bBearer\s+[A-Za-z0-9._~+/-]{16,}", re.I)),
)
SENSITIVE_NAME = r"[A-Za-z0-9_.-]*(?:password|passwd|secret|token|api[_-]?key|private[_-]?key)[A-Za-z0-9_.-]*"
ASSIGNMENT_RULE = re.compile(
    r"(?:[\"']?(?P<name>" + SENSITIVE_NAME + r")[\"']?\s*(?:=>|:|(?<![=!])=(?!=))\s*)"
    r"(?P<quote>[\"'])(?P<value>(?:\\.|(?!\2)[^\\\r\n])*)(?P=quote)",
    re.I,
)
DEFINE_RULE = re.compile(
    r"\bdefine\s*\(\s*([\"'])(" + SENSITIVE_NAME + r")\1\s*,\s*([\"'])([^\r\n]*?)\3",
    re.I,
)
PLACEHOLDER_RULE = re.compile(r"^(?:<[^<>]+>|\$\{[^{}]+\}|\{\{[^{}]+\}\}|(?:your[_ -])?[A-Z_]*(?:PLACEHOLDER|CHANGEME|REPLACE_ME)[A-Z_]*)$", re.I)


def secret_findings(text):
    by_rule = {}

    def add(rule, offset):
        by_rule.setdefault(rule, set()).add(text.count("\n", 0, offset) + 1)

    for rule, pattern in PROVIDER_RULES:
        for match in pattern.finditer(text):
            add(rule, match.start())
    for match in ASSIGNMENT_RULE.finditer(text):
        value = match.group("value").strip()
        if value and not PLACEHOLDER_RULE.fullmatch(value):
            add("literal_credential_assignment", match.start())
    for match in DEFINE_RULE.finditer(text):
        value = match.group(4).strip()
        if value and not PLACEHOLDER_RULE.fullmatch(value):
            add("literal_credential_definition", match.start())
    return by_rule


def binary_signature_ok(extension, data):
    if extension == ".png":
        return data.startswith(b"\x89PNG\r\n\x1a\n")
    if extension in {".jpg", ".jpeg"}:
        return data.startswith(b"\xff\xd8\xff")
    if extension == ".webp":
        return data.startswith(b"RIFF") and data[8:12] == b"WEBP"
    signatures = {
        ".woff": b"wOFF", ".woff2": b"wOF2", ".ttf": b"\x00\x01\x00\x00",
        ".otf": b"OTTO", ".ico": b"\x00\x00\x01\x00",
    }
    return extension in signatures and data.startswith(signatures[extension])


def read_metadata(payload):
    try:
        data = json.loads(payload.decode("utf-8"))
        if not isinstance(data, dict) or data.get("schema_version") != 1 or data.get("source_site") != SOURCE_SITE:
            raise ValueError
        generated = data["generated_utc"]
        if not isinstance(generated, str) or not re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", generated):
            raise ValueError
        datetime.strptime(generated, "%Y-%m-%dT%H:%M:%SZ")
        missing = data["missing_roots"]
        if not isinstance(missing, list) or any(root not in ROOTS for root in missing):
            raise ValueError
        counts = data["excluded_counts"]
        if not isinstance(counts, dict) or set(counts) != EXCLUSION_KEYS:
            raise ValueError
        if any(type(value) is not int or not 0 <= value <= MAX_MEMBERS for value in counts.values()):
            raise ValueError
        # Construct a new safe object; arbitrary extra source fields never pass.
        return {"generated_utc": generated, "missing_roots": sorted(set(missing)), "excluded_counts": counts}
    except (ValueError, KeyError, TypeError, UnicodeError):
        raise ExportError("invalid_remote_metadata") from None


def base_manifest():
    return {
        "schema_version": 1,
        "status": "rejected",
        "source_site": SOURCE_SITE,
        "generated_utc": now_utc(),
        "source_sha": None,
        "files": [],
        "findings": [],
        "excluded_counts": {key: 0 for key in sorted(EXCLUSION_KEYS)},
        "missing_roots": [],
        "diagnostics": [],
    }


def add_finding(manifest, path, rule, lines=()):
    safe_rule = rule if re.fullmatch(r"[A-Za-z0-9_.-]{1,120}", rule) else "external_secret_finding"
    safe_lines = sorted({number for number in lines if type(number) is int and 0 < number < 10**9})
    manifest["findings"].append({"path": path, "rule": safe_rule, "lines": safe_lines, "line_count": len(safe_lines), "disposition": "quarantined"})


def write_manifest(destination, manifest):
    manifest["files"] = sorted(manifest["files"], key=lambda entry: entry["path"])
    manifest["findings"] = sorted(manifest["findings"], key=lambda entry: (entry["path"], entry["rule"]))
    manifest["file_count"] = len(manifest["files"])
    manifest["source_bytes"] = sum(entry["bytes"] for entry in manifest["files"])
    manifest["quarantined_file_count"] = len({entry["path"] for entry in manifest["findings"]})
    manifest["complete"] = manifest["status"] == "complete"
    temporary = destination / ".source-manifest.tmp"
    temporary.write_text(json.dumps(manifest, indent=2, ensure_ascii=True) + "\n", encoding="utf-8")
    temporary.chmod(0o600)
    temporary.replace(destination / "source-manifest.json")


def scan_archive(archive, destination, workflow_sha=None, provenance=None):
    if destination.exists() or destination.is_symlink():
        raise ExportError("destination_already_exists")
    destination.mkdir(mode=0o700)
    manifest = base_manifest()
    for item in provenance or []:
        key, separator, value = item.partition("=")
        if not separator or key not in {"source_site", "workflow_sha"}:
            manifest["diagnostics"].append("invalid_provenance")
            write_manifest(destination, manifest)
            return 2
        if key == "source_site" and value not in {"petish.biz", SOURCE_SITE}:
            manifest["diagnostics"].append("invalid_provenance")
            write_manifest(destination, manifest)
            return 2
        if key == "workflow_sha":
            if workflow_sha is not None and workflow_sha != value:
                manifest["diagnostics"].append("conflicting_workflow_revision")
                write_manifest(destination, manifest)
                return 2
            workflow_sha = value
    if workflow_sha is not None:
        if not re.fullmatch(r"[a-fA-F0-9]{40,64}", workflow_sha):
            manifest["diagnostics"].append("invalid_workflow_revision")
            write_manifest(destination, manifest)
            return 2
        manifest["workflow_sha"] = workflow_sha.lower()
    try:
        source_stat = archive.lstat()
        if not stat.S_ISREG(source_stat.st_mode):
            raise ExportError("archive_not_regular_file")
        if not 0 < source_stat.st_size <= MAX_COMPRESSED_BYTES:
            raise ExportError("compressed_size_limit")
        manifest["source_sha"] = sha256_file(archive)
        manifest["source_archive_bytes"] = source_stat.st_size
        seen = set()
        seen_roots = set()
        metadata = None
        with archive.open("rb") as raw, gzip.GzipFile(fileobj=raw, mode="rb") as uncompressed:
            limited = LimitedReader(uncompressed, MAX_EXPANDED_BYTES)
            with tarfile.open(fileobj=limited, mode="r|") as members:
                for member_index, member in enumerate(members, 1):
                    if member_index > MAX_MEMBERS:
                        raise ExportError("member_count_limit")
                    path = safe_member_path(member.name)
                    if path in seen:
                        raise ExportError("duplicate_archive_member")
                    seen.add(path)
                    if not member.isdir() and not member.isreg():
                        raise ExportError("non_regular_archive_member")
                    if not in_scope(path, member.isdir()):
                        raise ExportError("member_outside_source_scope")
                    if member.size < 0 or member.size > MAX_FILE_BYTES:
                        raise ExportError("member_size_limit")
                    for root in ROOTS:
                        if path == root or path.startswith(root + "/"):
                            seen_roots.add(root)
                    if member.isdir():
                        if member.size:
                            raise ExportError("directory_with_payload")
                        continue
                    reason = exclusion_reason(path)
                    if reason:
                        manifest["excluded_counts"][reason] += 1
                        continue
                    handle = members.extractfile(member)
                    if handle is None:
                        raise ExportError("member_unreadable")
                    if path == METADATA_NAME and member.size > MAX_METADATA_BYTES:
                        raise ExportError("metadata_size_limit")
                    data = handle.read(member.size + 1)
                    if len(data) != member.size:
                        raise ExportError("member_length_mismatch")
                    if path == METADATA_NAME:
                        metadata = read_metadata(data)
                        continue
                    destination_path = "wordpress/" + path
                    extension = PurePosixPath(path).suffix.lower()
                    if extension not in TEXT_EXTENSIONS | BINARY_EXTENSIONS:
                        add_finding(manifest, destination_path, "unsupported_file_type")
                        continue
                    if extension in TEXT_EXTENSIONS:
                        if len(data) > MAX_TEXT_BYTES:
                            add_finding(manifest, destination_path, "text_file_size_limit")
                            continue
                        try:
                            text = data.decode("utf-8-sig", "strict")
                            if "\x00" in text:
                                raise UnicodeError
                        except UnicodeError:
                            add_finding(manifest, destination_path, "non_text_source_payload")
                            continue
                        findings = secret_findings(text)
                    else:
                        if not binary_signature_ok(extension, data):
                            add_finding(manifest, destination_path, "binary_signature_mismatch")
                            continue
                        # Detect ASCII credentials in image/font metadata too.
                        findings = secret_findings(data.decode("latin-1"))
                    if findings:
                        for rule, lines in findings.items():
                            add_finding(manifest, destination_path, rule, lines)
                        continue
                    target = destination / destination_path
                    target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                    with target.open("xb") as output:
                        output.write(data)
                    target.chmod(0o600)
                    manifest["files"].append({"path": destination_path, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()})
            # Consume through gzip EOF: verify its CRC/trailer and enforce the
            # expansion cap even if tar stopped at an early end marker.
            while limited.read(1024 * 1024):
                pass
        if metadata is None:
            manifest["diagnostics"].append("remote_metadata_missing")
        else:
            manifest["source_generated_utc"] = metadata["generated_utc"]
            manifest["missing_roots"] = metadata["missing_roots"]
            for key, count in metadata["excluded_counts"].items():
                manifest["excluded_counts"][key] += count
        manifest["missing_roots"] = sorted(set(manifest["missing_roots"]) | (set(ROOTS) - seen_roots))
        incomplete = bool(manifest["findings"] or manifest["missing_roots"] or manifest["diagnostics"])
        incomplete = incomplete or bool(manifest["excluded_counts"]["links"] or manifest["excluded_counts"]["special"])
        manifest["status"] = "incomplete" if incomplete else "complete"
        write_manifest(destination, manifest)
        return 0
    except Exception as error:
        # Do not include exception strings: library exceptions often contain a
        # source filename, secret-bearing code, or hostile archive header.
        manifest["diagnostics"] = [str(error) if isinstance(error, ExportError) else "archive_or_io_validation_failed"]
        manifest["status"] = "rejected"
        manifest["files"] = []
        manifest["findings"] = []
        shutil.rmtree(destination / "wordpress", ignore_errors=True)
        write_manifest(destination, manifest)
        return 2


def apply_gitleaks(destination, report):
    """Consume a private report, remove complete files, publish safe metadata."""
    if destination.is_symlink() or not destination.is_dir():
        raise ExportError("invalid_ready_directory")
    manifest_path = destination / "source-manifest.json"
    if manifest_path.is_symlink() or not manifest_path.is_file():
        raise ExportError("invalid_source_manifest")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        if manifest.get("schema_version") != 1 or manifest.get("status") not in {"complete", "incomplete"}:
            raise ExportError("invalid_source_manifest")
        if not report.is_file() or report.is_symlink() or report.stat().st_size > MAX_TEXT_BYTES:
            raise ExportError("invalid_external_report")
        findings = json.loads(report.read_text(encoding="utf-8"))
        if findings is None:
            findings = []
        if not isinstance(findings, list) or len(findings) > MAX_MEMBERS:
            raise ExportError("invalid_external_report")
        known = {entry["path"]: entry for entry in manifest["files"]}
        if len(known) != len(manifest["files"]):
            raise ExportError("invalid_source_manifest")
        for relative, entry in known.items():
            if safe_member_path(relative) != relative or not relative.startswith("wordpress/"):
                raise ExportError("invalid_source_manifest")
            if not in_scope(relative[len("wordpress/"):]):
                raise ExportError("invalid_source_manifest")
            target = destination / relative
            if any(parent.is_symlink() for parent in target.parents if parent != destination.parent):
                raise ExportError("screened_source_changed")
            if target.is_symlink() or not target.is_file() or sha256_file(target) != entry["sha256"]:
                raise ExportError("screened_source_changed")
        removals = set()
        # Validate every report path before deleting anything. No value from a
        # Gitleaks Match/Secret/Fingerprint field is copied or logged.
        safe_findings = []
        ready_absolute = destination.resolve()
        for item in findings:
            if not isinstance(item, dict) or not isinstance(item.get("File"), str):
                raise ExportError("invalid_external_report")
            supplied = Path(item["File"])
            if supplied.is_absolute():
                try:
                    relative = supplied.relative_to(ready_absolute).as_posix()
                except ValueError:
                    raise ExportError("external_report_path_outside_export") from None
            else:
                relative = supplied.as_posix()
                # gitleaks dir ROOT may emit a path relative to its scan root.
                if relative.startswith("wp-content/"):
                    relative = "wordpress/" + relative
                elif not relative.startswith("wordpress/"):
                    root_name = destination.name + "/"
                    if relative.startswith(root_name):
                        relative = relative[len(root_name):]
                    else:
                        # Gitleaks versions can preserve the relative scan
                        # target prefix (e.g. runner-temp/ready/wordpress/...).
                        candidate = Path.cwd() / supplied
                        try:
                            relative = candidate.absolute().relative_to(ready_absolute).as_posix()
                        except ValueError:
                            raise ExportError("external_report_path_outside_export") from None
            relative = safe_member_path(relative)
            if relative not in known:
                raise ExportError("external_report_unknown_source_file")
            target = destination / relative
            if target.is_symlink() or not target.is_file() or sha256_file(target) != known[relative]["sha256"]:
                raise ExportError("screened_source_changed")
            rule = item.get("RuleID", "external_secret_finding")
            if not isinstance(rule, str):
                rule = "external_secret_finding"
            start = item.get("StartLine")
            lines = [start] if type(start) is int and start > 0 else []
            safe_findings.append((relative, "gitleaks." + rule, lines))
            removals.add(relative)
        for relative in removals:
            (destination / relative).unlink()
        for relative, rule, lines in safe_findings:
            add_finding(manifest, relative, rule, lines)
        manifest["files"] = [entry for entry in manifest["files"] if entry["path"] not in removals]
        manifest["external_secret_screen"] = "gitleaks"
        if removals:
            manifest["status"] = "incomplete"
        write_manifest(destination, manifest)
        return 0
    except Exception as error:
        # Never leave a publishable source tree after a malformed/ambiguous
        # external scanner result or an unexpected post-scan mutation.
        manifest = base_manifest()
        manifest["diagnostics"] = [str(error) if isinstance(error, ExportError) else "external_report_validation_failed"]
        shutil.rmtree(destination / "wordpress", ignore_errors=True)
        write_manifest(destination, manifest)
        return 2


def main(argv=None):
    os.umask(0o077)
    arguments = list(sys.argv[1:] if argv is None else argv)
    if arguments and arguments[0] == "quarantine-gitleaks":
        parser = argparse.ArgumentParser(description="Apply private Gitleaks findings without exposing matched data.")
        parser.add_argument("command")
        parser.add_argument("ready_dir", type=Path)
        parser.add_argument("report", type=Path)
        parsed = parser.parse_args(arguments)
        result = apply_gitleaks(parsed.ready_dir, parsed.report)
    else:
        parser = argparse.ArgumentParser(description=__doc__)
        parser.add_argument("archive", type=Path)
        parser.add_argument("ready_dir", type=Path)
        parser.add_argument("--source-sha", help="Git revision of the export workflow/tools, not a source credential.")
        parser.add_argument("--provenance", action="append", default=[], help="Only source_site=petish.biz and workflow_sha=HEX are accepted.")
        parsed = parser.parse_args(arguments)
        result = scan_archive(parsed.archive, parsed.ready_dir, parsed.source_sha, parsed.provenance)
    print("Source screening finished; consult source-manifest.json. No source contents or matched values were printed.")
    return result


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:
        print("Source screening stopped: invalid invocation or unavailable private workspace. No private diagnostics were printed.", file=sys.stderr)
        sys.exit(2)
