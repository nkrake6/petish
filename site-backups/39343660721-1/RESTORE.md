# Restore this full Petish backup

The ordered parts contain one encrypted tar.gz archive. Keep all parts, the manifest, and your separate backup passphrase. No password is stored here.

In a secure working directory, first verify and join the parts:

```sh
sha256sum -c SHA256SUMS
cat site-backup.tar.gz.gpg.part-* > site-backup.tar.gz.gpg
gpg --output site-backup.tar.gz --decrypt site-backup.tar.gz.gpg
```

GPG prompts for the passphrase. The combined encrypted file's SHA256 must also match `encrypted_sha256` in manifest.json.

Decrypt/extract outside any public web directory. The archive contains `site-files/` (the full WordPress document root), `database/site.sql`, and `backup-metadata/README.txt`. Read that metadata before restoring.

Restore the files and import SQL into an isolated database/site first. Match database credentials in wp-config.php to the restore destination; review all symbolic links before extraction, and handle domain changes and absolute links before a migration. External/dangling links are stored as references; their target files outside the document root are not included. Essential external targets need separate backup/restoration. A real restore test has not been performed by the backup workflow. Never overwrite a running website/database without a reviewed restore plan.

This is a live backup; the SQL transaction and filesystem copy are not one atomic site-wide snapshot. Hostinger account services such as DNS, mailboxes, and external object storage are outside the document root/database.
