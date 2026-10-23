#!/usr/bin/env bash
# Verify already-installed staging source. This script has no deployment/apply mode.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
: "${GITHUB_RUN_ID:?}"
: "${GITHUB_RUN_ATTEMPT:?}"
: "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
source_sha=$GITHUB_SHA
[[ $run =~ ^[1-9][0-9]{0,31}-[1-9][0-9]{0,8}$ && $source_sha =~ ^[a-f0-9]{40}$ ]]
[[ ${GITHUB_REF_NAME:-} == verify-delivery-operations ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-delivery-operations.XXXXXXXX")
mkdir -m 700 "$work/bundle" "$work/public"
ssh_ready=0
remote_tools_removed=0
operation_verified=0
failure_category=prerequisites

remote_cleanup() {
    "${petish_ssh[@]}" 'set -eu; tools="$HOME/.petish-delivery-operations-'"$run"'";
      if test ! -e "$tools" && test ! -L "$tools"; then exit 0; fi;
      test -d "$tools" && test ! -L "$tools" && test "$(readlink -f -- "$tools")" = "$tools" && test "$(stat -c %a "$tools")" = 700 || exit 1;
      marker="$tools/.delivery-operations-owned";
      test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = '"$run"' || exit 1;
      unsafe=$(find "$tools" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit) || exit 1;
      test -z "$unsafe" || exit 1;
      rm -rf -- "$tools"' > "$work/cleanup.private.stdout" 2> "$work/cleanup.private.stderr"
}

publish_result() {
    export PETISH_DELIVERY_OPS_WORK=$work PETISH_DELIVERY_OPS_RUN=$run PETISH_DELIVERY_OPS_SOURCE=$source_sha
    export PETISH_DELIVERY_OPS_CATEGORY=$failure_category PETISH_DELIVERY_OPS_VERIFIED=$operation_verified
    export PETISH_DELIVERY_OPS_CLEANED=$remote_tools_removed
    python3 - <<'PY'
import json,os,re,stat
from pathlib import Path
root=Path(os.environ['PETISH_DELIVERY_OPS_WORK'])
category=os.environ['PETISH_DELIVERY_OPS_CATEGORY']
if category not in {'prerequisites','source','transport','verification','cleanup','complete'}:raise ValueError()
stage=os.environ.get('DELIVERY_OPERATIONS_PREPARATION_STAGE','unknown')
if stage not in {'connection','ssh_tools','complete'}:stage='unknown'
result={'schema_version':1,'target':'staging','run_id':os.environ['PETISH_DELIVERY_OPS_RUN'],
 'source_commit':os.environ['PETISH_DELIVERY_OPS_SOURCE'],
 'status':'success' if category=='complete' and os.environ['PETISH_DELIVERY_OPS_VERIFIED']=='1' and os.environ['PETISH_DELIVERY_OPS_CLEANED']=='1' else 'failure',
 'category':category,'preparation_stage':stage,'remote_tools_removed':os.environ['PETISH_DELIVERY_OPS_CLEANED']=='1',
 'source_hashes':{},'proof':None}
for name,target in [('bundle/source-manifest.json','source_hashes'),('verify.safe.json','proof')]:
 path=root/name
 if not path.exists():continue
 info=path.lstat()
 if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or not 0<info.st_size<=65536:raise ValueError()
 result[target]=json.loads(path.read_text())
if result['proof'] and result['proof']['source_hashes'] and result['proof']['source_hashes']!=result['source_hashes']:raise ValueError()
(root/'public/status.json').write_text(json.dumps(result,sort_keys=True,indent=2)+'\n')
PY
    local blob tree commit
    export GIT_INDEX_FILE="$work/publication.index"
    git read-tree --empty || return 1
    blob=$(git hash-object -w "$work/public/status.json") || return 1
    git update-index --add --cacheinfo "100644,$blob,status.json" || return 1
    tree=$(git write-tree) || return 1
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com' GIT_COMMITTER_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    commit=$(printf 'Petish read-only delivery operations proof %s\n' "$run" | git commit-tree "$tree") || return 1
    git push origin "$commit:refs/heads/automation-status/delivery-operations-$run" \
        > "$work/push.private.stdout" 2> "$work/push.private.stderr" || return 1
    unset GIT_INDEX_FILE
    printf 'Screened staging delivery operations report: https://github.com/nkrake6/petish/tree/automation-status/delivery-operations-%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
        printf '[Screened read-only staging report](https://github.com/nkrake6/petish/tree/automation-status/delivery-operations-%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
    fi
}

finish() {
    local outcome=$?
    trap - EXIT HUP INT TERM
    if [[ $ssh_ready == 1 ]]; then
        if remote_cleanup; then remote_tools_removed=1; else failure_category=cleanup; outcome=1; fi
    fi
    if [[ $outcome == 0 && $operation_verified == 1 && $remote_tools_removed == 1 ]]; then
        failure_category=complete
    else
        outcome=1
    fi
    if publish_result > "$work/publication.private.stdout" 2> "$work/publication.private.stderr"; then
        cat "$work/publication.private.stdout"
    else
        printf '%s\n' 'Staging proof receipt publication failed; no private output was published.' >&2
        outcome=1
    fi
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${DELIVERY_OPERATIONS_PREREQUISITES:-failure} == success ]]
failure_category=source
[[ $(git rev-parse HEAD) == "$source_sha" ]]
python3 scripts/sanitize-delivery-operations-result.py manifest "$source_sha" "$work/bundle/source-manifest.json" \
    > "$work/manifest.private.stdout" 2> "$work/manifest.private.stderr"
for name in verify-delivery-operations.php verify-pet-club.php email-branding-preview-remote.php; do
    cp -- "scripts/$name" "$work/bundle/$name"
    chmod 600 "$work/bundle/$name"
done
chmod 600 "$work/bundle/source-manifest.json"
(cd "$work/bundle" && sha256sum verify-delivery-operations.php verify-pet-club.php email-branding-preview-remote.php source-manifest.json > tools.sha256)
chmod 600 "$work/bundle/tools.sha256"
tar -C "$work/bundle" -cf "$work/bundle.tar" verify-delivery-operations.php verify-pet-club.php email-branding-preview-remote.php source-manifest.json tools.sha256 \
    > "$work/archive.private.stdout" 2> "$work/archive.private.stderr"
failure_category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh > "$work/ssh.private.stdout" 2> "$work/ssh.private.stderr"
ssh_ready=1
"${petish_ssh[@]}" 'set -eu; umask 077; tools="$HOME/.petish-delivery-operations-'"$run"'";
  test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME" && test ! -e "$tools" && test ! -L "$tools" || exit 1;
  mkdir -m 700 "$tools"; printf "%s\n" '"$run"' > "$tools/.delivery-operations-owned"; chmod 600 "$tools/.delivery-operations-owned";
  tar --extract --no-same-owner --no-same-permissions --directory "$tools";
  cd "$tools"; sha256sum --check --strict tools.sha256' \
    < "$work/bundle.tar" > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"
failure_category=verification
remote_status=0
"${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
  PETISH_REHEARSAL_PRODUCTION_DB_NAME=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get DB_NAME --type=constant 2>/dev/null) || exit 1;
  test -n "$PETISH_REHEARSAL_PRODUCTION_DB_NAME" || exit 1; export PETISH_REHEARSAL_PRODUCTION_DB_NAME;
  php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-delivery-operations-'"$run"'/verify-delivery-operations.php" '"$run" \
    > "$work/verify.private.json" 2> "$work/verify.private.stderr" || remote_status=$?
screen_status=0
python3 scripts/sanitize-delivery-operations-result.py verify "$work/verify.private.json" "$work/verify.safe.json" \
    > "$work/screen.private.stdout" 2> "$work/screen.private.stderr" || screen_status=$?
[[ $remote_status == 0 && $screen_status == 0 ]]
operation_verified=1
