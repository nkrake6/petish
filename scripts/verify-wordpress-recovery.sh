#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?Missing RUNNER_TEMP}"
if (( ${#PETISH_BACKUP_PASSPHRASE} < 24 )); then
  printf '%s\n' 'The existing backup passphrase secret is required.' >&2
  exit 1
fi
exec env -u PETISH_BACKUP_PASSPHRASE -u SSHPASS python3 \
  scripts/verify-wordpress-recovery.py \
  --encrypted "$RUNNER_TEMP/petish-recovery-input/site-backup.tar.gz.gpg" \
  --passphrase-fd 3 3<<< "$PETISH_BACKUP_PASSPHRASE"
