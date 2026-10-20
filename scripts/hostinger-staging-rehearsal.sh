#!/usr/bin/env bash
# Never copy private fixture credentials or raw browser output to Git/artifacts.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
[[ ${REHEARSAL_MODE:-} == preflight || ${REHEARSAL_MODE:-} == rehearse ]]
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
[[ $run =~ ^[0-9]+-[0-9]+$ ]]
work=$RUNNER_TEMP/petish-staging-rehearsal
mkdir -m 700 "$work"
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
cleanup() {
    outcome=$?
    trap - EXIT HUP INT TERM
    "${petish_ssh[@]}" 'rm -f -- "$HOME/.petish-staging/rehearsal-'"$run"'/browser-input.json"; rm -rf -- "$HOME/.petish-staging/rehearsal-tools-'"$run"'"' \
        > "$work/cleanup.private.stdout" 2> "$work/cleanup.private.stderr" || outcome=1
    exit "$outcome"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
"${petish_ssh[@]}" 'set -eu; umask 077; dir="$HOME/.petish-staging/rehearsal-tools-'"$run"'"; test ! -e "$dir" && test ! -L "$dir" || exit 1; mkdir -m 700 "$dir"; cat > "$dir/driver.php"; chmod 600 "$dir/driver.php"' \
    < scripts/staging-rehearsal-remote.php > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"
result=0
"${petish_ssh[@]}" 'php -d display_errors=0 -d log_errors=0 "$HOME/.petish-staging/rehearsal-tools-'"$run"'/driver.php" preflight '"$run" \
    > "$RUNNER_TEMP/petish-rehearsal-preflight.json" 2> "$work/preflight.private.stderr" || result=$?
python3 scripts/sanitize-rehearsal-result.py "$RUNNER_TEMP/petish-rehearsal-preflight.json" "$RUNNER_TEMP/petish-rehearsal-result.json"
[[ $result == 0 ]] || exit "$result"
if [[ $REHEARSAL_MODE == rehearse ]]; then
    printf '%s\n' 'The checkout driver must be prepared and validated before applying the rehearsal.' >&2
    exit 1
fi
