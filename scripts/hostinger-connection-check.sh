#!/usr/bin/env bash
set -euo pipefail

: "${SSH_HOST:?Missing SSH_HOST}"
: "${SSH_PORT:?Missing SSH_PORT}"
: "${RUNNER_TEMP:?Missing RUNNER_TEMP}"
: "${GITHUB_STEP_SUMMARY:?Missing GITHUB_STEP_SUMMARY}"

candidate_keys="$RUNNER_TEMP/petish-candidate-host-keys"
umask 077

if ! ssh-keyscan -T 15 -p "$SSH_PORT" -t ed25519,ecdsa,rsa "$SSH_HOST" > "$candidate_keys" 2>/dev/null; then
  printf '%s\n' 'SSH connectivity failed. No login was attempted and no site changes were made.' >&2
  exit 1
fi
if [[ ! -s "$candidate_keys" ]]; then
  printf '%s\n' 'The server did not return an SSH host key. No login was attempted.' >&2
  exit 1
fi

fingerprints="$(ssh-keygen -lf "$candidate_keys" -E sha256)"
{
  printf '## Hostinger SSH connectivity\n\n'
  printf 'SSH responds at `%s:%s`. No credentials were used in this check.\n\n' "$SSH_HOST" "$SSH_PORT"
  printf '### Candidate server fingerprints\n\n```text\n%s\n```\n\n' "$fingerprints"
  printf '**These keys were discovered, not independently verified.** Confirm the fingerprint with Hostinger support or an existing trusted connection before setting `HOSTINGER_SSH_FINGERPRINT`.\n'
} >> "$GITHUB_STEP_SUMMARY"
printf '%s\n' 'SSH responds. Candidate server fingerprints:' "$fingerprints"
