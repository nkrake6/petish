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
python3 - "$RUNNER_TEMP/petish-rehearsal-result.json" <<'PY'
import json,sys
from pathlib import Path
report=json.loads(Path(sys.argv[1]).read_text())
required={'environment_staging','woocommerce_test_mode','wcpay_dev_mode',
    'guard_runtime_verified','suppliers_blocked','mail_capture_verified',
    'webhooks_blocked','database_separate','runtime_database_staging',
    'only_allowlisted_plugins','no_active_webhooks','browser_input_written'}
checks=report.get('checks',{})
if report.get('status')!='success' or report.get('category')!='complete' \
        or checks.get('account_is_live') is not False or any(checks.get(key) is not True for key in required):
    raise SystemExit('Staging runtime isolation and native test-payment mode must be verified before continuing.')
PY
if [[ $REHEARSAL_MODE == rehearse ]]; then
    printf '%s\n' 'The checkout driver must be prepared and validated before applying the rehearsal.' >&2
    exit 1
fi
