#!/usr/bin/env bash
set +x
set -euo pipefail

: "${SSH_HOST:?Missing SSH_HOST}"
: "${SSH_PORT:?Missing SSH_PORT}"
: "${SSH_USER:?Missing SSH_USER}"
: "${RUNNER_TEMP:?Missing RUNNER_TEMP}"
if [[ -z "${SSHPASS:-}" || ! "${SSH_EXPECTED_FINGERPRINT:-}" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]; then
  printf '%s\n' 'The existing SSH password secret and verified fingerprint variable are required.' >&2
  exit 1
fi
if (( ${#PETISH_BACKUP_PASSPHRASE} < 24 )) || [[ "$PETISH_BACKUP_PASSPHRASE" == *$'\n'* || "$PETISH_BACKUP_PASSPHRASE" == *$'\r'* ]]; then
  printf '%s\n' 'A long, single-line backup passphrase is required before connecting.' >&2
  exit 1
fi

umask 077
work_dir="$RUNNER_TEMP/petish-full-backup"
candidate_keys="$RUNNER_TEMP/petish-candidate-host-keys"
verified_keys="$RUNNER_TEMP/petish-verified-backup-host-keys"
mkdir -p "$work_dir/gnupg"
chmod 700 "$work_dir/gnupg"
: > "$verified_keys"
while IFS= read -r candidate; do
  [[ -n "$candidate" && "$candidate" != \#* ]] || continue
  fingerprint="$(printf '%s\n' "$candidate" | ssh-keygen -lf - -E sha256 | awk '{print $2}')"
  if [[ "$fingerprint" == "$SSH_EXPECTED_FINGERPRINT" ]]; then
    printf '%s\n' "$candidate" >> "$verified_keys"
  fi
done < "$candidate_keys"
if [[ ! -s "$verified_keys" ]]; then
  printf '%s\n' 'SSH host-key verification failed. Refusing to authenticate.' >&2
  exit 1
fi

# Stream directly into encryption: no plaintext site archive is saved on the
# runner, printed, committed, or uploaded. The 1.5 GiB ceiling leaves room below
# GitHub's single-push size limit. Oversized sites fail instead of omitting files.
max_bytes=1610612736
encrypted="$work_dir/site-backup.tar.gz.gpg"
if ! env -u PETISH_BACKUP_PASSPHRASE sshpass -e ssh -F /dev/null -T \
  -p "$SSH_PORT" \
  -o ConnectTimeout=15 \
  -o ConnectionAttempts=1 \
  -o NumberOfPasswordPrompts=1 \
  -o PreferredAuthentications=password \
  -o PubkeyAuthentication=no \
  -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$verified_keys" \
  -o GlobalKnownHostsFile=/dev/null \
  "$SSH_USER@$SSH_HOST" 'bash -s' \
  < scripts/backup-wordpress-remote.sh \
  | head -c "$((max_bytes + 1))" \
  | env -u SSHPASS -u PETISH_BACKUP_PASSPHRASE gpg --no-options \
      --homedir "$work_dir/gnupg" --batch --yes --pinentry-mode loopback \
      --no-symkey-cache --passphrase-fd 3 --symmetric --cipher-algo AES256 \
      --force-mdc --compress-algo none --s2k-mode 3 --s2k-digest-algo SHA256 \
      --s2k-count 65011712 --output "$encrypted" \
      3<<< "$PETISH_BACKUP_PASSPHRASE"; then
  printf '%s\n' 'The full backup stream did not complete. Nothing will be published.' >&2
  exit 1
fi
encrypted_bytes="$(wc -c < "$encrypted")"
if (( encrypted_bytes == 0 || encrypted_bytes > max_bytes )); then
  printf '%s\n' 'The full backup exceeded this workflow size limit. No files were omitted and nothing was committed.' >&2
  exit 1
fi

# Verify the password, encrypted integrity, gzip checksum, tar structure, and
# required WordPress/database members without extracting private files.
env -u SSHPASS -u PETISH_BACKUP_PASSPHRASE gpg --no-options \
  --homedir "$work_dir/gnupg" --batch --quiet --pinentry-mode loopback \
  --no-symkey-cache --passphrase-fd 3 --decrypt "$encrypted" \
  3<<< "$PETISH_BACKUP_PASSPHRASE" \
  | python3 scripts/verify-full-backup.py > "$work_dir/verification.json"
unset PETISH_BACKUP_PASSPHRASE

python3 scripts/package-encrypted-backup.py "$encrypted" "$work_dir/publish" "$work_dir/verification.json"
printf '%s\n' 'Full backup encryption and integrity checks passed. Only encrypted parts and restoration instructions are ready for Git.'
