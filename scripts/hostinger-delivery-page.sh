#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
mode=${DELIVERY_PAGE_MODE:-inspect}
[[ $run =~ ^[1-9][0-9]*-[1-9][0-9]*$ && $GITHUB_SHA =~ ^[a-f0-9]{40}$ && $mode =~ ^(inspect|rehearse|apply)$ ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-delivery-page.XXXXXXXX")
mkdir -m 700 "$work/bundle" "$work/public"
connected=0
apply_armed=0
rollback_verified=0
category=prerequisites
native() {
    local operation=$1
    "${petish_ssh[@]}" 'php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-delivery-page-'"$run"'/delivery-page-remote.php" '"$operation"' '"$run" > "$work/$operation-native.json" 2> "$work/$operation-native.stderr"
}
purge() {
    local phase=$1 operation
    for operation in queue verify; do
        "${petish_ssh[@]}" 'php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-delivery-page-'"$run"'/litespeed-html-purge.php" '"$operation"' delivery '"$run $phase" > "$work/purge-$phase-$operation.json" 2> "$work/purge-$phase-$operation.stderr" || return 1
        python3 scripts/sanitize-litespeed-html-purge.py "$operation" "$work/purge-$phase-$operation.json" "$work/purge-$phase-$operation-safe.json" > "$work/purge-$phase-$operation.stdout" 2> "$work/purge-$phase-$operation-screen.stderr" || return 1
        if [[ $operation == queue ]]; then
            python3 scripts/consume-litespeed-html-purge.py "$work/purge-$phase-consume.json" > "$work/purge-$phase-kick.stdout" 2> "$work/purge-$phase-kick.stderr" || return 1
            python3 scripts/sanitize-litespeed-html-purge.py consume "$work/purge-$phase-consume.json" "$work/purge-$phase-consume-safe.json" > "$work/purge-$phase-consume.stdout" 2> "$work/purge-$phase-consume.stderr" || return 1
        fi
    done
}
finish() {
    local outcome=$? blob tree commit retain=0
    trap - EXIT HUP INT TERM
    if [[ $outcome != 0 && $apply_armed == 1 ]]; then
        if native rollback && python3 scripts/delivery-page-report.py "$work/rollback-native.json" "$work/rollback-native-safe.json" rollback native-only > "$work/rollback-screen.stdout" 2> "$work/rollback-screen.stderr" \
            && purge rollback && python3 scripts/delivery-page-report.py "$work/rollback-native.json" "$work/rollback-safe.json" rollback > "$work/rollback-public.stdout" 2> "$work/rollback-public.stderr"; then
            rollback_verified=1
        else
            retain=1
            category=rollback_requires_review
        fi
    fi
    if [[ $connected == 1 && $retain == 0 ]]; then
        "${petish_ssh[@]}" 'set -eu; dir="$HOME/.petish-delivery-page-'"$run"'";
          if test ! -e "$dir" && test ! -L "$dir"; then exit 0; fi;
          test -d "$dir" && test ! -L "$dir" && test "$(readlink -f -- "$dir")" = "$dir" && test "$(stat -c %a "$dir")" = 700 || exit 1;
          test -f "$dir/owner" && test ! -L "$dir/owner" && test "$(stat -c %a "$dir/owner")" = 600 && test "$(stat -c %h "$dir/owner")" = 1 || exit 1;
          test "$(cat "$dir/owner")" = '"$run"' || exit 1;
          bad=$(find "$dir" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit);
          test -z "$bad" || exit 1; rm -rf -- "$dir"' > "$work/cleanup.stdout" 2> "$work/cleanup.stderr" || { outcome=1; category=cleanup; }
    fi
    python3 - "$work" "$outcome" "$category" "$run" "$GITHUB_SHA" "$mode" "$rollback_verified" "$retain" <<'PY'
import json,sys,importlib.util
from pathlib import Path
from datetime import datetime,timezone
root=Path(sys.argv[1]);out={'status':'success' if sys.argv[2]=='0' else 'failure','category':sys.argv[3],
 'mode':sys.argv[6],'run_id':sys.argv[4],'source_commit':sys.argv[5],'generated_utc':datetime.now(timezone.utc).isoformat()}
if (root/'safe.json').exists():out['inspection' if sys.argv[6]=='inspect' else 'operation']=json.loads((root/'safe.json').read_text())
if sys.argv[2]!='0':
 try:
  spec=importlib.util.spec_from_file_location('delivery_report','scripts/delivery-page-report.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
  raw=root/(sys.argv[6]+'-native.json')
  if raw.stat().st_size<=16384:out['native_diagnostic']=m.diagnostic(json.loads(raw.read_text()))
 except Exception:pass
if sys.argv[6]=='apply':
 out['rollback_verified']=sys.argv[7]=='1';out['manual_recovery_required']=sys.argv[8]=='1'
 if (root/'rollback-safe.json').exists():out['rollback']=json.loads((root/'rollback-safe.json').read_text())
(root/'public/status.json').write_text(json.dumps(out,indent=2)+'\n')
PY
    export GIT_INDEX_FILE="$work/publication.index"
    git read-tree --empty
    blob=$(git hash-object -w "$work/public/status.json")
    git update-index --add --cacheinfo "100644,$blob,status.json"
    tree=$(git write-tree)
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com' GIT_COMMITTER_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    commit=$(printf 'Petish delivery page %s %s\n' "$mode" "$run" | git commit-tree "$tree")
    git push origin "$commit:refs/heads/automation-status/delivery-page-$run" > "$work/publish.stdout" 2> "$work/publish.stderr" || outcome=1
    unset GIT_INDEX_FILE
    printf 'Delivery page report: https://github.com/nkrake6/petish/tree/automation-status/delivery-page-%s\n' "$run"
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${DELIVERY_PAGE_PREREQUISITES:-success} == success && $(git rev-parse HEAD) == "$GITHUB_SHA" ]]
if [[ $mode == apply ]]; then
    category=rehearsal_evidence
    git ls-remote --heads origin 'refs/heads/automation-status/delivery-page-*' > "$work/reports.txt" 2> "$work/reports.stderr"
    python3 - "$work/reports.txt" > "$work/candidates.txt" <<'PY'
import re,sys
items=[]
for line in open(sys.argv[1]):
 match=re.fullmatch(r'[a-f0-9]{40}\s+(refs/heads/automation-status/delivery-page-([1-9][0-9]*)-([1-9][0-9]*))\s*',line)
 if match:items.append((int(match[2]),int(match[3]),match[1]))
for _,_,ref in sorted(items,reverse=True)[:20]:print(ref)
PY
    evidence=0
    while IFS= read -r ref; do
        git fetch --no-tags --depth=1 origin "$ref" > "$work/evidence-fetch.stdout" 2> "$work/evidence-fetch.stderr"
        git show FETCH_HEAD:status.json > "$work/evidence.json"
        if python3 - "$work/evidence.json" "$GITHUB_SHA" <<'PY'
import json,sys,datetime,importlib.util
from pathlib import Path
spec=importlib.util.spec_from_file_location('delivery_report','scripts/delivery-page-report.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
try:
 v=json.loads(Path(sys.argv[1]).read_text());manifest=json.loads(Path('operations/delivery-page-release.json').read_text())
 assert v['status']=='success' and v['category']=='complete' and v['mode']=='rehearse' and v['source_commit']==sys.argv[2]
 age=datetime.datetime.now(datetime.timezone.utc)-datetime.datetime.fromisoformat(v['generated_utc']);assert datetime.timedelta(0)<=age<=datetime.timedelta(hours=6)
 p=v['operation'];assert p['canonical_public_copy_verified_twice'] is True and p['page']['content_sha256']==manifest['old_content_sha256']
 m.screen({k:p[k] for k in ['status','category','checks','page']},'rehearse')
except Exception:sys.exit(1)
PY
        then evidence=1;break;fi
    done < "$work/candidates.txt"
    [[ $evidence == 1 ]]
fi
category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
connected=1
for file in delivery-page-remote.php delivery-page-update.php verify-pet-club.php email-branding-preview-remote.php; do
    cp -- "scripts/$file" "$work/bundle/$file"
done
if [[ $mode != inspect ]]; then
    cp -- operations/delivery-page-content.html operations/delivery-page-release.json "$work/bundle/"
fi
if [[ $mode == apply ]]; then
    cp -- scripts/litespeed-html-purge.php "$work/bundle/"
fi
chmod 600 "$work/bundle/"*
tar -C "$work/bundle" -cf "$work/bundle.tar" .
"${petish_ssh[@]}" 'set -eu; umask 077; dir="$HOME/.petish-delivery-page-'"$run"'";
  test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME" && test ! -e "$dir" && test ! -L "$dir" || exit 1;
  mkdir -m 700 "$dir"; printf "%s\n" '"$run"' > "$dir/owner"; chmod 600 "$dir/owner";
  tar --extract --no-same-owner --no-same-permissions --directory "$dir"' < "$work/bundle.tar" > "$work/upload.stdout" 2> "$work/upload.stderr"
category=native
if [[ $mode == apply ]]; then apply_armed=1;fi
native "$mode"
python3 scripts/delivery-page-report.py "$work/$mode-native.json" "$work/native-safe.json" "$mode" native-only > "$work/native-screen.stdout" 2> "$work/native-screen.stderr"
if [[ $mode == apply ]]; then
    category=targeted_cache_purge
    purge apply
fi
category=public_copy
python3 scripts/delivery-page-report.py "$work/$mode-native.json" "$work/safe.json" "$mode" > "$work/public.stdout" 2> "$work/public.stderr"
category=complete
