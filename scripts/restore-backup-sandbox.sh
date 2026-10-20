#!/usr/bin/env bash
# Restore only into a disposable, disconnected Docker sandbox on this runner.
set +x
set -euo pipefail
umask 077

if [[ -z "${RUNNER_TEMP:-}" || ! -d "$RUNNER_TEMP" || -L "$RUNNER_TEMP" ]]; then
  printf '%s\n' 'A private runner temporary directory is required for the restore smoke test.' >&2
  exit 1
fi
if [[ -z "${PETISH_BACKUP_PASSPHRASE:-}" ]] || (( ${#PETISH_BACKUP_PASSPHRASE} < 24 )) || [[ "$PETISH_BACKUP_PASSPHRASE" == *$'\n'* || "$PETISH_BACKUP_PASSPHRASE" == *$'\r'* ]]; then
  printf '%s\n' 'The backup passphrase secret is required for the restore smoke test.' >&2
  exit 1
fi

# The passphrase is never an argument, file, Docker setting, or child environment
# variable. The original configuration is extracted privately but never loaded.
exec env -u PETISH_BACKUP_PASSPHRASE -u SSHPASS python3 \
  scripts/test-full-backup-restore.py \
  --encrypted "$RUNNER_TEMP/petish-full-backup/site-backup.tar.gz.gpg" \
  --passphrase-fd 3 3<<< "$PETISH_BACKUP_PASSPHRASE"
