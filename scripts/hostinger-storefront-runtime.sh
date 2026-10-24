#!/usr/bin/env bash
# Inspect installed runtime only; tools live in an owned private scratch directory.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${GITHUB_SHA:?}"
[[ ${GITHUB_REPOSITORY:-} == nkrake6/petish ]]
case ${GITHUB_REF_NAME:-} in
    inspect-storefront-native) inspection_scope=native-public-only; browser_required=0 ;;
    inspect-storefront-runtime) inspection_scope=native-public-guest-cart; browser_required=1 ;;
    *) exit 1 ;;
esac
[[ $GITHUB_RUN_ID =~ ^[1-9][0-9]{0,31}$ && $GITHUB_RUN_ATTEMPT =~ ^[1-9][0-9]{0,8}$ && $GITHUB_SHA =~ ^[a-f0-9]{40}$ ]]
[[ $(git rev-parse HEAD) == "$GITHUB_SHA" ]]
[[ ${SSH_HOST:-} == 82.29.81.104 && ${SSH_PORT:-} == 65002 && ${SSH_USER:-} == u839205597 ]]
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
work=$(mktemp -d "$RUNNER_TEMP/petish-storefront-runtime.XXXXXXXX")
remote="/home/u839205597/.petish-storefront-runtime-$run"
mkdir -m 700 "$work/bundle" "$work/public" "$work/browser-home" "$work/browser-tmp"
category=prerequisites
result_status=failure
ssh_ready=0
remote_possible=0
remote_tools_removed=0

cleanup_remote() {
    "${petish_ssh[@]}" 'set -eu; work='"'$remote'"';
      if test ! -e "$work" && test ! -L "$work"; then exit 0; fi;
      test -d "$work" && test ! -L "$work" && test "$(readlink -f -- "$work")" = "$work" && test "$(stat -c %a "$work")" = 700;
      marker="$work/.owned"; test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = '"'$run'"';
      unsafe=$(find "$work" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit); test -z "$unsafe";
      rm -rf -- "$work"' > "$work/cleanup.private.stdout" 2> "$work/cleanup.private.stderr"
}

publish_result() {
    export PETISH_RUNTIME_WORK=$work PETISH_RUNTIME_RUN=$run PETISH_RUNTIME_SHA=$GITHUB_SHA
    export PETISH_RUNTIME_STATUS=$result_status PETISH_RUNTIME_CATEGORY=$category PETISH_RUNTIME_CLEANED=$remote_tools_removed
    export PETISH_RUNTIME_SCOPE=$inspection_scope PETISH_RUNTIME_BROWSER_REQUIRED=$browser_required
    python3 - <<'PY' > "$work/publication.private.stdout" 2> "$work/publication.private.stderr"
from datetime import datetime,timezone
import importlib.util,json,os
from pathlib import Path
spec=importlib.util.spec_from_file_location('storefront_runtime','scripts/inspect-storefront-runtime.py')
screen=importlib.util.module_from_spec(spec);spec.loader.exec_module(screen)
root=Path(os.environ['PETISH_RUNTIME_WORK'])
status=os.environ['PETISH_RUNTIME_STATUS'];category=os.environ['PETISH_RUNTIME_CATEGORY']
if status not in {'success','failure'} or category not in {'prerequisites','source','transport','public_html','native','browser','cleanup','complete'}:raise ValueError()
value={'format':'petish-storefront-runtime-v1','target':'production','run_id':os.environ['PETISH_RUNTIME_RUN'],
 'source_commit':os.environ['PETISH_RUNTIME_SHA'],'generated_utc':datetime.now(timezone.utc).isoformat(),
 'status':status,'category':category,'remote_tools_removed':os.environ['PETISH_RUNTIME_CLEANED']=='1','phases':{}}
scope=os.environ['PETISH_RUNTIME_SCOPE']
if scope not in {'native-public-only','native-public-guest-cart'}:raise ValueError()
value.update(inspection_scope=scope,release_proof=False,browser_required=os.environ['PETISH_RUNTIME_BROWSER_REQUIRED']=='1')
stage=os.environ.get('STOREFRONT_RUNTIME_PREPARATION_STAGE','unknown')
value['preparation_stage']=stage if stage in {'connection','ssh_tools','browser_tools','complete'} else 'unknown'
for phase,validator in [('public',screen.screen_public),('native',screen.screen_native),('browser',screen.screen_browser)]:
 path=root/(phase+'.safe.json')
 if path.exists():
  report=json.loads(screen.regular(path),object_pairs_hook=screen.unique)
  report,passed=validator(report)
  value['phases'][phase]=report
screen.write(root/'public/status.json',value)
PY
    local blob tree commit
    export GIT_INDEX_FILE="$work/publication.index"
    git read-tree --empty
    blob=$(git hash-object -w "$work/public/status.json")
    git update-index --add --cacheinfo "100644,$blob,status.json"
    tree=$(git write-tree)
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
    commit=$(printf 'Petish installed storefront runtime %s\n' "$run" | git commit-tree "$tree")
    git push origin "$commit:refs/heads/automation-status/storefront-runtime-$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr"
    unset GIT_INDEX_FILE
    printf 'Screened installed runtime: https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
        printf '[Screened installed runtime](https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
    fi
}

finish() {
    local original=$? failed=0
    trap - EXIT
    set +e
    if [[ $ssh_ready == 1 && $remote_possible == 1 ]]; then
        if cleanup_remote; then remote_tools_removed=1; else category=cleanup; failed=1; fi
    fi
    if [[ $original != 0 || $failed == 1 ]]; then result_status=failure; fi
    publish_result || failed=1
    rm -rf -- "$work"
    if [[ $original != 0 || $failed == 1 || $result_status != success ]]; then exit 1; fi
}
trap finish EXIT

[[ ${STOREFRONT_RUNTIME_PREREQUISITES:-failure} == success ]]
category=source
python3 - "$GITHUB_SHA" "$work/bundle" <<'PY' > "$work/bundle.private.stdout" 2> "$work/bundle.private.stderr"
import hashlib,os,subprocess,sys
from pathlib import Path
revision=sys.argv[1];target=Path(sys.argv[2])
files=['storefront-payment-runtime.php','verify-pet-club.php','email-branding-preview-remote.php']
hashes=[]
for name in files:
 path='scripts/'+name
 entry=subprocess.run(['git','ls-tree',revision,'--',path],check=True,stdout=subprocess.PIPE).stdout.decode().strip().split()
 if len(entry)!=4 or entry[0] not in {'100644','100755'} or entry[1]!='blob' or entry[3]!=path:raise ValueError()
 size=int(subprocess.run(['git','cat-file','-s',entry[2]],check=True,stdout=subprocess.PIPE).stdout)
 if not 0<size<=1048576:raise ValueError()
 body=subprocess.run(['git','cat-file','blob',entry[2]],check=True,stdout=subprocess.PIPE).stdout
 if len(body)!=size:raise ValueError()
 with (target/name).open('xb') as stream:stream.write(body)
 os.chmod(target/name,0o600)
 hashes.append(hashlib.sha256(body).hexdigest()+'  '+name+'\n')
(target/'tools.sha256').write_text(''.join(hashes))
PY
tar -C "$work/bundle" -cf "$work/bundle.tar" storefront-payment-runtime.php verify-pet-club.php email-branding-preview-remote.php tools.sha256
category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh > "$work/ssh.private.stdout" 2> "$work/ssh.private.stderr"
ssh_ready=1
remote_possible=1
"${petish_ssh[@]}" "set -eu; umask 077; work='$remote'; test ! -e \"\$work\" && test ! -L \"\$work\"; mkdir -m 700 \"\$work\"; printf '%s\\n' '$run' > \"\$work/.owned\"; chmod 600 \"\$work/.owned\"; tar -xf - -C \"\$work\"; cd \"\$work\"; sha256sum -c tools.sha256" \
    < "$work/bundle.tar" > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"
failed=0
failed_category=
category=public_html
python3 scripts/inspect-storefront-runtime.py public "$work/public.safe.json" \
    > "$work/public.private.stdout" 2> "$work/public.private.stderr" || { failed=1; failed_category=public_html; }
category=native
native_status=0
"${petish_ssh[@]}" "set -eu; php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 '$remote/storefront-payment-runtime.php' '$run'" \
    > "$work/native.private.json" 2> "$work/native.private.stderr" || native_status=1
python3 scripts/inspect-storefront-runtime.py screen-native "$work/native.private.json" "$work/native.safe.json" \
    > "$work/native-screen.private.stdout" 2> "$work/native-screen.private.stderr" || native_status=1
if [[ $native_status != 0 ]]; then failed=1; [[ -n $failed_category ]] || failed_category=native; fi
if [[ $browser_required == 1 ]]; then
    category=browser
    browser_status=0
    env -i PATH="$PATH" HOME="$work/browser-home" TMPDIR="$work/browser-tmp" \
        PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-$HOME/.cache/ms-playwright}" \
        node tools/storefront-live-inspect.mjs "$work/browser.private.json" \
        > "$work/browser.private.stdout" 2> "$work/browser.private.stderr" || browser_status=1
    python3 scripts/inspect-storefront-runtime.py screen-browser "$work/browser.private.json" "$work/browser.safe.json" \
        > "$work/browser-screen.private.stdout" 2> "$work/browser-screen.private.stderr" || browser_status=1
    if [[ $browser_status != 0 ]]; then failed=1; [[ -n $failed_category ]] || failed_category=browser; fi
fi
if [[ $failed == 0 ]]; then result_status=success; category=complete; else category=$failed_category; fi
[[ $failed == 0 ]]
