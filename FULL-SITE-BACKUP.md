# Full Petish website backup in Git

This workflow backs up the **complete WordPress document root and complete site database**, including uploads, WordPress core, all themes/plugins, wp-config.php, users, orders, settings, and page/product content. The full archive is encrypted **before** it is committed to Git. The earlier code-only snapshot approach is not used.

## One-time setup

1. Generate a long, random, single-line passphrase in your password manager (32 or more random characters recommended; at least 24 required).
2. Keep that passphrase in your password manager. GitHub cannot show a saved secret back to you, and losing the passphrase makes the backup unrecoverable.
3. In [GitHub repository secrets](https://github.com/nkrake6/petish/settings/secrets/actions), add **New repository secret** named `PETISH_BACKUP_PASSPHRASE`, with the passphrase as its value. Do not send it in chat or put it in a repository file.

The existing `HOSTINGER_SSH_PASSWORD` secret and `HOSTINGER_SSH_FINGERPRINT` variable are reused.

## Run it

Open [Back up the full Petish site to Git](https://github.com/nkrake6/petish/actions/workflows/backup-full-site.yml), choose **Run workflow → main → Run workflow**.

The workflow:

1. Verifies the SSH server identity and logs in through GitHub's runner.
2. Reads Petish's document-root files and makes a complete SQL dump (schema/data, views, triggers, routines, events) into a temporary private directory outside the webroot.
3. Streams the full archive into AES256 encryption, never saving a plaintext site archive on the runner.
4. Decrypts it through a streaming integrity/structure check without extracting private files or printing their contents.
5. Splits the encrypted archive into ordered 48 MiB parts with checksums and restoration instructions.
6. Commits those encrypted parts on a new **`full-site-backup/<run-id>-<attempt>`** Git branch. Nothing is deployed or merged into main.

The final run summary links to the backup branch. Share that branch name with Codex to confirm the backup files and checksums. Full private data is inside the encrypted parts; no raw source, SQL, credentials, or private filenames are published as artifacts or logs.

## What this does and does not guarantee

- The website is not put into maintenance mode or reconfigured. Only private temporary SQL/diagnostic files are created on the hosting account and removed when the remote script exits normally or receives a catchable termination signal.
- The database uses a single transaction where supported. The SQL dump and filesystem are not one atomic site-wide snapshot; concurrent file changes can make the backup fail rather than silently omit files. Plan a quiet period for the most consistent backup.
- Symlinks outside the document root and unsupported special files cause the workflow to stop rather than claim their target data was backed up. Hostinger account services and external object storage are not part of the WordPress document root/database.
- A failed SQL dump, encryption/integrity check, or archive size check prevents publication. The compressed/encrypted backup must fit the workflow's 1.5 GiB ceiling below GitHub's single-push limit. Exceeding it stops the backup; files are never silently dropped.
- Repeated full backups consume Git repository storage. Older backup branches are not deleted automatically.
- Successful validation is not a successful restore test. Restore into an isolated site/database and verify it before relying on any disaster recovery plan.
- Because credentials and licensed software are present, keep the passphrase separate from Git even if the repository is private. Do not replace encrypted parts with a plaintext SQL/archive.

Saving the workflow does not execute a backup. Only a completed run and pushed backup branch establish that a backup was stored.
