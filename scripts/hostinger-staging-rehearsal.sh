#!/usr/bin/env bash
# All fixture credentials, provider references and raw output stay private.
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
prepared=0
cleaned=0
remote_phase() {
    local phase=$1 outcome=0
    [[ $phase =~ ^(preflight|prepare|verify|refund|cleanup)$ ]] || return 1
    "${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1; for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value; php -d display_errors=0 -d log_errors=0 "$HOME/.petish-staging/rehearsal-tools-'"$run"'/driver.php" '"$phase $run" \
        > "$RUNNER_TEMP/petish-rehearsal-$phase.json" 2> "$work/$phase.private.stderr" || outcome=$?
    python3 - "$RUNNER_TEMP/petish-rehearsal-$phase.json" <<'PY'
import importlib.util,json,sys
spec=importlib.util.spec_from_file_location('safe','scripts/sanitize-rehearsal-result.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
report=module.sanitize(sys.argv[1])
if report.get('status')!='success' or report.get('category')!='complete':
    raise SystemExit('The staging phase did not pass its private safety checks.')
PY
    local validation=$?
    [[ $outcome == 0 && $validation == 0 ]]
}
checkpoint() {
    local phase=$1 result=${2:-success}
    AUTOMATION_KIND=rehearsal AUTOMATION_RESULT="$result" AUTOMATION_PROGRESS="$phase" \
        bash scripts/publish-automation-status.sh > "$work/progress-$phase.private.stdout" 2> "$work/progress-$phase.private.stderr"
}
cleanup() {
    outcome=$?
    trap - EXIT HUP INT TERM
    if [[ $prepared == 1 && $cleaned == 0 ]]; then
        remote_phase cleanup > "$work/final-cleanup.private.stdout" 2> "$work/final-cleanup.private.stderr" || outcome=1
        checkpoint cleanup failure || outcome=1
    fi
    # Retain the private journal and helper code after an uncertain payment so
    # the same scoped order can be inspected without issuing another payment.
    "${petish_ssh[@]}" 'rm -f -- "$HOME/.petish-staging/rehearsal-'"$run"'/browser-input.json"' \
        > "$work/remove-input.private.stdout" 2> "$work/remove-input.private.stderr" || outcome=1
    if [[ $prepared == 0 || $cleaned == 1 ]]; then
        "${petish_ssh[@]}" 'rm -rf -- "$HOME/.petish-staging/rehearsal-tools-'"$run"'"' \
            > "$work/remove-tools.private.stdout" 2> "$work/remove-tools.private.stderr" || outcome=1
    fi
    exit "$outcome"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
"${petish_ssh[@]}" 'set -eu; umask 077; dir="$HOME/.petish-staging/rehearsal-tools-'"$run"'"; test ! -e "$dir" && test ! -L "$dir" || exit 1; mkdir -m 700 "$dir"; cat > "$dir/driver.php"; chmod 600 "$dir/driver.php"' \
    < scripts/staging-rehearsal-remote.php > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"
for helper in staging-rehearsal-order.php staging-rehearsal-fixture.php; do
    "${petish_ssh[@]}" 'set -eu; umask 077; cat > "$HOME/.petish-staging/rehearsal-tools-'"$run"'/'"$helper"'"; chmod 600 "$HOME/.petish-staging/rehearsal-tools-'"$run"'/'"$helper"'"' \
        < "scripts/$helper" > "$work/upload-$helper.private.stdout" 2> "$work/upload-$helper.private.stderr"
done
remote_phase preflight
python3 - "$RUNNER_TEMP/petish-rehearsal-preflight.json" <<'PY'
import json,sys
from pathlib import Path
report=json.loads(Path(sys.argv[1]).read_text())
required={'environment_staging','woocommerce_test_mode','wcpay_dev_mode',
    'guard_runtime_verified','suppliers_blocked','mail_capture_verified',
    'webhooks_blocked','database_separate','runtime_database_staging',
    'only_allowlisted_plugins','no_active_webhooks','browser_input_written'}
checks=report.get('checks',{})
if checks.get('account_is_live') is not False or any(checks.get(key) is not True for key in required):
    raise SystemExit('Staging runtime isolation and native test-payment mode must be verified before continuing.')
PY
[[ $REHEARSAL_MODE == rehearse ]] || exit 0
checkpoint preflight
export PLAYWRIGHT_BROWSERS_PATH="$work/browsers"
export npm_config_cache="$work/npm-cache"
npm ci --prefix tools/staging-rehearsal --no-audit --no-fund > "$work/npm.private.stdout" 2> "$work/npm.private.stderr"
node tools/staging-rehearsal/node_modules/playwright/cli.js install --with-deps chromium > "$work/browser-install.private.stdout" 2> "$work/browser-install.private.stderr"
prepared=1
remote_phase prepare
checkpoint prepare
"${petish_ssh[@]}" 'cat "$HOME/.petish-staging/rehearsal-'"$run"'/browser-input.json"' \
    > "$work/browser-input.json" 2> "$work/fetch-input.private.stderr"
chmod 600 "$work/browser-input.json"
browser_exit=0
node tools/staging-rehearsal/browser.mjs "$work/browser-input.json" "$work/browser-result.json" \
    > "$RUNNER_TEMP/petish-rehearsal-browser.json" 2> "$work/browser.private.stderr" || browser_exit=$?
if [[ -f $work/browser-result.json && ! -L $work/browser-result.json ]]; then
    "${petish_ssh[@]}" 'set -eu; umask 077; cat > "$HOME/.petish-staging/rehearsal-'"$run"'/browser-result.json"; chmod 600 "$HOME/.petish-staging/rehearsal-'"$run"'/browser-result.json"' \
        < "$work/browser-result.json" > "$work/upload-browser.private.stdout" 2> "$work/upload-browser.private.stderr"
fi
if [[ $browser_exit != 0 ]]; then
    checkpoint browser failure
    exit "$browser_exit"
fi
checkpoint browser
remote_phase verify
checkpoint verify
remote_phase refund
checkpoint refund
remote_phase cleanup
python3 - "$RUNNER_TEMP/petish-rehearsal-cleanup.json" <<'PY'
import json,sys
from pathlib import Path
checks=json.loads(Path(sys.argv[1]).read_text()).get('checks',{})
if checks.get('recovery_required') is not False or any(checks.get(key) is not True for key in
        ('shipping_fixture_removed','qa_cart_cleared','order_refunded_before_cleanup')):
    raise SystemExit('Private staging recovery remains necessary.')
PY
cleaned=1
checkpoint cleanup
