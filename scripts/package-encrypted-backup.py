#!/usr/bin/env python3
"""Split already verified ciphertext into Git-compatible parts and a manifest."""

import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

PART_BYTES = 48 * 1024 * 1024


def package(encrypted_path, destination, verification_path, part_bytes=PART_BYTES):
    encrypted_path, destination = Path(encrypted_path), Path(destination)
    verification = json.loads(Path(verification_path).read_text())
    if verification.get("archive_verified") is not True \
            or verification.get("private_passport_root_verified") is not True \
            or encrypted_path.stat().st_size == 0:
        raise ValueError("A nonempty verified encrypted backup including private Passport storage is required")
    if part_bytes < 1:
        raise ValueError("Invalid part size")
    smtp_covered = verification.get("private_smtp_config_verified", False)
    smtp_files = verification.get("private_smtp_regular_files", 0)
    smtp_bytes = verification.get("private_smtp_regular_bytes", 0)
    if type(smtp_covered) is not bool or type(smtp_files) is not int or type(smtp_bytes) is not int \
            or (smtp_covered and (smtp_files < 1 or smtp_bytes < 1)) \
            or (not smtp_covered and (smtp_files != 0 or smtp_bytes != 0)):
        raise ValueError("Inconsistent private SMTP backup verification")
    destination.mkdir(mode=0o700, parents=True, exist_ok=False)
    parts, whole_hash = [], hashlib.sha256()
    encrypted_bytes = encrypted_path.stat().st_size
    with encrypted_path.open("rb") as source:
        index = 0
        while source.tell() < encrypted_bytes:
            name = f"site-backup.tar.gz.gpg.part-{index:05d}"
            digest, size = hashlib.sha256(), 0
            with (destination / name).open("xb") as output:
                while size < part_bytes:
                    block = source.read(min(1024 * 1024, part_bytes - size))
                    if not block:
                        break
                    output.write(block)
                    digest.update(block)
                    whole_hash.update(block)
                    size += len(block)
            parts.append({"file": name, "bytes": size, "sha256": digest.hexdigest()})
            index += 1
    manifest = {
        "format": "petish-full-wordpress-backup-v3" if smtp_covered else "petish-full-wordpress-backup-v2",
        "site": "https://petish.biz/",
        "created_utc": datetime.now(timezone.utc).isoformat(),
        "scope": "complete document-root tree, private Passport storage, "
                 + ("private SMTP configuration, " if smtp_covered else "")
                 + "and site SQL database; symlinks preserved without following targets",
        "private_archive_root": "private-files/petish-passport",
        "private_restore_location": "domain-private-directory-outside-document-root",
        "encryption": "OpenPGP symmetric AES256 with integrity protection",
        "encrypted_bytes": encrypted_bytes,
        "encrypted_sha256": whole_hash.hexdigest(),
        "parts": parts,
        "verification": verification,
    }
    if smtp_covered:
        manifest["private_smtp_archive_root"] = "private-files/petish-mailer"
        manifest["private_smtp_restore_location"] = "domain-private-directory-outside-document-root"
    (destination / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    (destination / "SHA256SUMS").write_text("".join(f"{part['sha256']}  {part['file']}\n" for part in parts))
    (destination / "RESTORE.md").write_text(
        "# Restore this full Petish backup\n\n"
        "The ordered parts contain one encrypted tar.gz archive. Keep all parts, "
        "the manifest, and your separate backup passphrase. No password is stored here.\n\n"
        "In a secure working directory, first verify and join the parts:\n\n"
        "```sh\nsha256sum -c SHA256SUMS\n"
        "cat site-backup.tar.gz.gpg.part-* > site-backup.tar.gz.gpg\n"
        "gpg --output site-backup.tar.gz --decrypt site-backup.tar.gz.gpg\n```\n\n"
        "GPG prompts for the passphrase. The combined encrypted file's SHA256 "
        "must also match `encrypted_sha256` in manifest.json.\n\n"
        "Decrypt/extract outside any public web directory. The archive contains "
        "`site-files/` (the full WordPress document root), `database/site.sql`, "
        "`private-files/petish-passport/` (private Passport files), and "
        "`backup-metadata/README.txt`. Read that metadata before restoring.\n\n"
        "Restore `private-files/petish-passport/` into the target domain's sibling "
        "`private/petish-passport/` directory **outside the public document root**. "
        "Never place these private photos under `public_html`, uploads, or another "
        "web-served directory. Preserve restrictive hosting-account permissions "
        "and review the Passport plugin's storage path when migrating.\n\n"
        + ("This backup also contains `private-files/petish-mailer/`, including "
           "private SMTP credentials. Restore it into the target domain's sibling "
           "`private/petish-mailer/` directory **outside the public document root**. "
           "Keep the directory at mode 0700 and `smtp.json` as a regular, unlinked "
           "mode-0600 file. Do not print or publish that file. Keep outbound mail "
           "disabled in staging/recovery and confirm the mailbox before production use.\n\n"
           if smtp_covered else "") +
        "Restore the files and import SQL into an isolated database/site first. "
        "Match database credentials in wp-config.php to the restore destination; "
        "review all symbolic links before extraction, and handle domain changes "
        "and absolute links before a migration. External/dangling links are stored "
        "as references; target files outside the document root and explicitly included "
        "private storage trees are not included. "
        "Essential external targets need separate backup/restoration. "
        "This manifest records encrypted-stream integrity. The workflow separately "
        "attempts a disposable regular-file/database restore smoke test; check its "
        "automation-status/backup result for success. That check skips links and "
        "does not bootstrap WordPress or test external integrations. "
        "Never overwrite a running website/database without a reviewed restore plan.\n\n"
        "This is a live backup; the SQL transaction and filesystem copy are not "
        "one atomic site-wide snapshot. Hostinger account services such as DNS, "
        "mailboxes, and external object storage are outside the document root/database.\n"
    )
    print(f"Prepared {len(parts)} encrypted Git-sized backup parts. No plaintext backup data was published.")


if __name__ == "__main__":
    if len(sys.argv) != 4:
        raise SystemExit("Usage: package-encrypted-backup.py ENCRYPTED_FILE DESTINATION VERIFICATION_JSON")
    package(*sys.argv[1:])
