#!/usr/bin/env bash
# Source from a runner script. Never enable tracing around credentials.
petish_prepare_ssh() {
  set +x
  : "${RUNNER_TEMP:?Missing RUNNER_TEMP}"
  : "${SSH_HOST:?Missing SSH_HOST}"
  : "${SSH_PORT:?Missing SSH_PORT}"
  : "${SSH_USER:?Missing SSH_USER}"
  if [[ -z "${SSHPASS:-}" || ! "${SSH_EXPECTED_FINGERPRINT:-}" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]; then
    printf '%s\n' 'The existing SSH secret and verified fingerprint are required.' >&2
    return 1
  fi
  umask 077
  PETISH_VERIFIED_KEYS="$RUNNER_TEMP/petish-verified-automation-host-keys"
  : > "$PETISH_VERIFIED_KEYS"
  local candidate fingerprint
  while IFS= read -r candidate; do
    [[ -n "$candidate" && "$candidate" != \#* ]] || continue
    fingerprint="$(printf '%s\n' "$candidate" | ssh-keygen -lf - -E sha256 | awk '{print $2}')"
    if [[ "$fingerprint" == "$SSH_EXPECTED_FINGERPRINT" ]]; then
      printf '%s\n' "$candidate" >> "$PETISH_VERIFIED_KEYS"
    fi
  done < "$RUNNER_TEMP/petish-candidate-host-keys"
  if [[ ! -s "$PETISH_VERIFIED_KEYS" ]]; then
    printf '%s\n' 'SSH host-key verification failed. No authentication attempted.' >&2
    return 1
  fi
  petish_ssh=(sshpass -e ssh -F /dev/null -T -p "$SSH_PORT"
    -o ConnectTimeout=15 -o ConnectionAttempts=1 -o NumberOfPasswordPrompts=1
    -o PreferredAuthentications=password -o PubkeyAuthentication=no
    -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$PETISH_VERIFIED_KEYS"
    -o GlobalKnownHostsFile=/dev/null "$SSH_USER@$SSH_HOST")
}
