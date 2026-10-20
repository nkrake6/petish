#!/usr/bin/env bash
# Render synthetic email previews on private staging; publish screened files only.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
: "${GITHUB_RUN_ID:?}"
: "${GITHUB_RUN_ATTEMPT:?}"
: "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
[[ $run =~ ^[1-9][0-9]*-[1-9][0-9]*$ && $GITHUB_SHA =~ ^[a-f0-9]{40}$ ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-email-preview.XXXXXXXX")
mkdir -m 700 "$work/public"
ssh_ready=0
render_verified=0
html_verified=0
preview_status=unavailable
failure_category=prerequisites

remote_cleanup() {
    "${petish_ssh[@]}" 'set -eu; base="$HOME/.petish-staging"; tools="$base/email-preview-tools-'"$run"'"; work="$base/rehearsal-'"$run"'"; test -d "$base" && test ! -L "$base" && test "$(readlink -f -- "$base")" = "$base" || exit 1; if test ! -e "$tools" && test ! -L "$tools" && test ! -e "$work" && test ! -L "$work"; then exit 0; fi; marker="$tools/.email-preview-owned"; test -d "$tools" && test ! -L "$tools" && test "$(readlink -f -- "$tools")" = "$tools" && test -f "$marker" && test ! -L "$marker" && test "$(cat "$marker")" = '"$run"' || exit 1; if test -e "$work" || test -L "$work"; then test -d "$work" && test ! -L "$work" && test "$(readlink -f -- "$work")" = "$work" || exit 1; fi; rm -rf -- "$work" "$tools"' \
        > "$work/cleanup.private.stdout" 2> "$work/cleanup.private.stderr"
}

publish_result() {
    export PETISH_PREVIEW_WORK=$work PETISH_PREVIEW_RUN=$run
    export PETISH_PREVIEW_STATUS=$preview_status PETISH_PREVIEW_CATEGORY=$failure_category
    python3 - <<'PY' || return 1
import importlib.util,json,os
from pathlib import Path
root=Path(os.environ['PETISH_PREVIEW_WORK'])
spec=importlib.util.spec_from_file_location('safe','scripts/sanitize-rehearsal-result.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
raw=module.sanitize(root/'render.private.json')
preview_names={
 'email_preview_rendered','email_preview_native_renderer','email_preview_unsaved_synthetic_order',
 'email_preview_synthetic_content_only','email_preview_no_order_records_created',
 'email_preview_stock_unchanged','email_preview_html_sanitized','email_preview_branding_logo_present',
 'email_preview_branding_palette_present','email_preview_native_order_details_present',
 'email_preview_four_types_rendered'}
safety_names={'environment_staging','woocommerce_test_mode','wcpay_dev_mode','account_is_live',
 'guard_runtime_verified','suppliers_blocked','mail_capture_verified','webhooks_blocked',
 'database_separate','runtime_database_staging','only_allowlisted_plugins','no_active_webhooks',
 'all_http_forced_blocked'}
checks=raw.get('checks',{})
result={'status':os.environ['PETISH_PREVIEW_STATUS'],'category':os.environ['PETISH_PREVIEW_CATEGORY'],
 'run_id':os.environ['PETISH_PREVIEW_RUN'],'source_commit':os.environ['GITHUB_SHA'],
 'checks':{key:value for key,value in checks.items() if key in preview_names|safety_names and type(value) is bool}}
if raw.get('category') in module.CATEGORIES|{'safe_result_unavailable'}:result['driver_category']=raw['category']
if raw.get('validation_stage') in {'input','schema','status_category','run_id','checks','account','native','structure','provider_hosts','phase'}:
 result['validation_stage']=raw['validation_stage']
try:
 html_report=json.loads((root/'html-validation.private.json').read_text())
 if html_report.get('status')=='failure' and html_report.get('category') in {
  'input','css','html_structure','active_content','attributes','links','images',
  'synthetic_content','size','sensitive_content','filesystem','encoding'}:
  result['html_validation_category']=html_report['category']
except (OSError,ValueError,TypeError):pass
(root/'public/status.json').write_text(json.dumps(result,indent=2)+'\n')
PY
    export GIT_INDEX_FILE="$work/publication.index"
    git read-tree --empty || return 1
    local name blob tree commit
    names=(status.json)
    if [[ $preview_status == success ]]; then
        names+=(processing.html completed.html refunded.html note.html petish-logo-v2.png)
    fi
    for name in "${names[@]}"; do
        blob=$(git hash-object -w "$work/public/$name") || return 1
        git update-index --add --cacheinfo "100644,$blob,$name" || return 1
    done
    tree=$(git write-tree) || return 1
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
    commit=$(printf 'Petish staging email preview %s\n' "$run" | git commit-tree "$tree") || return 1
    git push origin "$commit:refs/heads/email-preview/$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr" || return 1
    unset GIT_INDEX_FILE
    printf 'Email preview result: https://github.com/nkrake6/petish/tree/email-preview/%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
        printf '[Screened email preview result](https://github.com/nkrake6/petish/tree/email-preview/%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
    fi
}

finish() {
    outcome=$?
    trap - EXIT HUP INT TERM
    if [[ $ssh_ready == 1 ]] && ! remote_cleanup; then
        outcome=1
        failure_category=cleanup
    fi
    if [[ $outcome == 0 && $render_verified == 1 && $html_verified == 1 ]]; then
        preview_status=success
        failure_category=complete
    else
        outcome=1
    fi
    if ! publish_result > "$work/publication.private.stdout" 2> "$work/publication.private.stderr"; then
        printf '%s\n' 'Email preview publication failed; no private output was published.' >&2
        outcome=1
    else
        cat "$work/publication.private.stdout"
    fi
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${EMAIL_PREVIEW_PREREQUISITES:-success} == success ]]
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
ssh_ready=1
failure_category=transport
"${petish_ssh[@]}" 'set -eu; umask 077; base="$HOME/.petish-staging"; tools="$base/email-preview-tools-'"$run"'"; work="$base/rehearsal-'"$run"'"; test -d "$base" && test ! -L "$base" && test "$(readlink -f -- "$base")" = "$base" && test "$(stat -c %a "$base")" = 700 || exit 1; test ! -e "$tools" && test ! -L "$tools" && test ! -e "$work" && test ! -L "$work" || exit 1; mkdir -m 700 "$tools"; printf "%s\n" '"$run"' > "$tools/.email-preview-owned"; cat > "$tools/driver.php"; chmod 600 "$tools/driver.php" "$tools/.email-preview-owned"' \
    < scripts/staging-rehearsal-remote.php > "$work/upload-driver.private.stdout" 2> "$work/upload-driver.private.stderr"
"${petish_ssh[@]}" 'set -eu; umask 077; file="$HOME/.petish-staging/email-preview-tools-'"$run"'/email-branding-preview-remote.php"; test ! -e "$file" && test ! -L "$file" || exit 1; cat > "$file"; chmod 600 "$file"' \
    < scripts/email-branding-preview-remote.php > "$work/upload-helper.private.stdout" 2> "$work/upload-helper.private.stderr"
failure_category=rendering
"${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1; for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value; php -d display_errors=0 -d log_errors=0 "$HOME/.petish-staging/email-preview-tools-'"$run"'/driver.php" email-preview '"$run" \
    > "$work/render.private.json" 2> "$work/render.private.stderr"
failure_category=report_validation
python3 - "$work/render.private.json" <<'PY'
import importlib.util,sys
spec=importlib.util.spec_from_file_location('safe','scripts/sanitize-rehearsal-result.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
report=module.sanitize(sys.argv[1]);checks=report.get('checks',{})
required={'environment_staging','woocommerce_test_mode','wcpay_dev_mode',
 'guard_runtime_verified','suppliers_blocked','mail_capture_verified','webhooks_blocked',
 'database_separate','runtime_database_staging','only_allowlisted_plugins','no_active_webhooks',
 'all_http_forced_blocked','email_preview_rendered','email_preview_native_renderer',
 'email_preview_unsaved_synthetic_order','email_preview_synthetic_content_only',
 'email_preview_no_order_records_created','email_preview_stock_unchanged',
 'email_preview_html_sanitized','email_preview_branding_logo_present','email_preview_branding_palette_present',
 'email_preview_native_order_details_present','email_preview_four_types_rendered'}
if report.get('status')!='success' or report.get('category')!='complete' \
 or report.get('phase')!='email-preview' or checks.get('account_is_live') is not False \
 or any(checks.get(key) is not True for key in required):
 raise SystemExit('The synthetic staging email preview did not pass its safety checks.')
PY
render_verified=1
failure_category=transport
for name in processing completed refunded note; do
    "${petish_ssh[@]}" 'set -eu; file="$HOME/.petish-staging/rehearsal-'"$run"'/'"$name"'.html"; test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file" && test "$(stat -c %h "$file")" = 1 && test "$(stat -c %a "$file")" = 600 || exit 1; size=$(stat -c %s "$file"); test "$size" -gt 0 && test "$size" -le 1048576 || exit 1; cat "$file"' \
        > "$work/public/$name.html" 2> "$work/fetch-$name.private.stderr"
done
failure_category=html_validation
python3 scripts/validate-email-previews.py "$work/public" > "$work/html-validation.private.json" 2> "$work/html-validation.private.stderr"
failure_category=logo_validation
python3 - "$work/public/petish-logo-v2.png" <<'PY'
from pathlib import Path
import os,stat,sys
source=Path('wordpress/wp-content/themes/dt-the7-child/assets/brand/repaired/combined-light-v2.png')
info=source.lstat()
if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or not 8<info.st_size<=10485760:
 raise SystemExit('The reviewed local branding asset is unavailable.')
descriptor=os.open(source,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
with os.fdopen(descriptor,'rb') as stream:
 opened=os.fstat(stream.fileno())
 if (opened.st_dev,opened.st_ino)!=(info.st_dev,info.st_ino):raise SystemExit('The local branding asset changed.')
 data=stream.read(10485761)
if len(data)>10485760 or not data.startswith(b'\x89PNG\r\n\x1a\n'):raise SystemExit('The local branding asset is invalid.')
with open(sys.argv[1],'xb') as target:target.write(data)
PY
html_verified=1
