#!/usr/bin/env bash
set -euo pipefail

: "${SSH_HOST:?Missing SSH_HOST}"
: "${SSH_PORT:?Missing SSH_PORT}"
: "${SSH_USER:?Missing SSH_USER}"
: "${RUNNER_TEMP:?Missing RUNNER_TEMP}"
: "${GITHUB_STEP_SUMMARY:?Missing GITHUB_STEP_SUMMARY}"

if [[ -z "${SSHPASS:-}" || -z "${SSH_EXPECTED_FINGERPRINT:-}" ]]; then
  printf '%s\n' 'Before inspection, configure the HOSTINGER_SSH_PASSWORD repository secret and the HOSTINGER_SSH_FINGERPRINT repository variable. No login was attempted.' >&2
  exit 1
fi
if [[ ! "$SSH_EXPECTED_FINGERPRINT" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]; then
  printf '%s\n' 'HOSTINGER_SSH_FINGERPRINT must be a single SHA256:... fingerprint verified through Hostinger or an existing trusted connection.' >&2
  exit 1
fi

candidate_keys="$RUNNER_TEMP/petish-candidate-host-keys"
verified_keys="$RUNNER_TEMP/petish-verified-host-keys"
report="$RUNNER_TEMP/petish-wordpress-inspection.txt"
umask 077
: > "$verified_keys"
while IFS= read -r candidate; do
  [[ -n "$candidate" && "$candidate" != \#* ]] || continue
  fingerprint="$(printf '%s\n' "$candidate" | ssh-keygen -lf - -E sha256 | awk '{print $2}')"
  if [[ "$fingerprint" == "$SSH_EXPECTED_FINGERPRINT" ]]; then
    printf '%s\n' "$candidate" >> "$verified_keys"
  fi
done < "$candidate_keys"

if [[ ! -s "$verified_keys" ]]; then
  printf '%s\n' 'The server key does not match the trusted fingerprint. Refusing to authenticate.' >&2
  exit 1
fi

# SSHPASS is read from the environment, never embedded in a command or printed.
# Only the locally checked-in inspection script is sent; no remote files are created.
sshpass -e ssh -F /dev/null -T \
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
  < scripts/inspect-wordpress-remote.sh | tee "$report"

{
  printf '\n## Petish WordPress inspection\n\n'
  printf 'Completed without update, install, activation, import, or deployment commands. The report contains software inventory and selected site configuration only.\n\n'
  printf 'Download the `petish-wordpress-inspection` artifact from this run and share its text report with Codex. No credentials or customer data are included.\n'
} >> "$GITHUB_STEP_SUMMARY"
