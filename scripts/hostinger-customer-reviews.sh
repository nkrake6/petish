#!/usr/bin/env bash
# Staging-only application acceptance. No production deploy mode exists.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
[[ $run =~ ^[1-9][0-9]*-[1-9][0-9]*$ && $GITHUB_SHA =~ ^[a-f0-9]{40}$ ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-customer-reviews.XXXXXXXX")
remote="/home/u839205597/.petish-customer-reviews-$run"
ssh_ready=0
remote_ready=0
snapshot_ready=0
apply_started=0
flag_started=0
proof_started=0
completed=0
cleanup_verified=0
rollback_verified=0
category=prerequisites

remote_phase() {
  local phase=$1 status=0
  case "$phase" in prepare|source|enable|verify|cleanup|rollback-flag|rollback-code|commit) ;; *) return 1;; esac
  "${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
    for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value;
    php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 '"'$remote/customer-reviews-staging-remote.php' '$phase' '$run' '$GITHUB_SHA'" \
    > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
  python3 scripts/sanitize-customer-reviews-result.py "$phase" "$work/$phase.private.json" "$work/$phase.safe.json" \
    > "$work/$phase-screen.private.stdout" 2> "$work/$phase-screen.private.stderr" || return 1
  [[ $status == 0 ]]
}
upload_private() {
  local name=$1 source=$2
  case "$name" in customer-reviews-staging-remote.php|customer-reviews-proof.php|deploy-staging-remote.sh|source.tar.gz|manifest.json) ;; *) return 1;; esac
  # Every SSH command starts a fresh shell; runner/setup umasks do not carry over.
  "${petish_ssh[@]}" "set -eu; umask 077; set -C; cat > '$remote/$name'; chmod 600 '$remote/$name'" < "$source" \
    > "$work/upload-$name.private.stdout" 2> "$work/upload-$name.private.stderr"
}
fetch_private() {
  local name=$1
  case "$name" in browser-input.json|native-result.json|cleanup-result.json) ;; *) return 1;; esac
  "${petish_ssh[@]}" 'set -eu; file='"'$remote/$name'"';
    test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file";
    test "$(stat -c %a "$file")" = 600 && test "$(stat -c %h "$file")" = 1;
    size=$(stat -c %s "$file"); test "$size" -gt 0 && test "$size" -le 1048576; cat "$file"' \
    > "$work/$name" 2> "$work/fetch-$name.private.stderr"
}
remove_remote() {
  "${petish_ssh[@]}" 'set -eu; work='"'$remote'"';
    test -d "$work" && test ! -L "$work" && test "$(readlink -f -- "$work")" = "$work" && test "$(stat -c %a "$work")" = 700;
    marker="$work/.owned"; test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(cat "$marker")" = '"'$run'"';
    unsafe=$(find "$work" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit); test -z "$unsafe";
    rm -rf -- "$work"' > "$work/remove.private.stdout" 2> "$work/remove.private.stderr"
}
publish() {
  export PCR_WORK=$work PCR_RUN=$run PCR_SOURCE=$GITHUB_SHA PCR_CATEGORY=$category
  export PCR_COMPLETED=$completed PCR_CLEANUP=$cleanup_verified PCR_ROLLBACK=$rollback_verified
  python3 - <<'PY'
import datetime,json,os,re
from pathlib import Path
root=Path(os.environ['PCR_WORK'])
report={'status':'success' if os.environ['PCR_COMPLETED']=='1' else 'failure',
 'scope':'private-staging-only','source_commit':os.environ['PCR_SOURCE'],'run_id':os.environ['PCR_RUN'],
 'generated_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
 'category':os.environ['PCR_CATEGORY'],'fixture_cleanup_verified':os.environ['PCR_CLEANUP']=='1',
 'rollback_verified':os.environ['PCR_ROLLBACK']=='1','production_changed':False,'phases':{}}
for phase in ['prepare','source','enable','verify','native','browser','cleanup','cleanup-fixture','rollback-flag','rollback-code','commit']:
 path=root/(phase+'.safe.json')
 if path.is_file():report['phases'][phase]=json.loads(path.read_text())
(root/'status.json').write_text(json.dumps(report,indent=2)+'\n')
PY
  local blob tree commit
  blob=$(git hash-object -w "$work/status.json")
  tree=$(printf '100644 blob %s\tstatus.json\n' "$blob" | git mktree)
  export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
  export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
  export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
  commit=$(printf 'Verify customer reviews on private staging\n' | git commit-tree "$tree")
  git push origin "$commit:refs/heads/automation-status/customer-reviews-$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr"
  cat "$work/status.json"
}
finish() {
  local status=$? cleanup_ok=1 flag_ok=1 code_ok=1
  trap - EXIT INT TERM
  set +e
  if [[ $proof_started == 1 && $cleanup_verified != 1 ]]; then
    remote_phase cleanup || cleanup_ok=0
    if [[ $cleanup_ok == 1 ]]; then cleanup_verified=1; fi
    fetch_private cleanup-result.json && python3 scripts/sanitize-customer-reviews-result.py cleanup-fixture "$work/cleanup-result.json" "$work/cleanup-fixture.safe.json"
  fi
  if [[ $completed != 1 && $remote_ready == 1 ]]; then
    if [[ $flag_started == 1 ]]; then remote_phase rollback-flag || flag_ok=0; fi
    if [[ $apply_started == 1 && $snapshot_ready == 1 && $cleanup_ok == 1 ]]; then remote_phase rollback-code || code_ok=0; fi
    if [[ $cleanup_ok != 1 ]]; then code_ok=0; fi
    if [[ $flag_ok == 1 && $code_ok == 1 && $cleanup_ok == 1 ]]; then rollback_verified=1; fi
  fi
  if [[ $remote_ready == 1 && ( $completed == 1 || ( $rollback_verified == 1 && $cleanup_ok == 1 ) ) ]]; then
    if ! remove_remote; then completed=0; category=private_cleanup; status=1; fi
  fi
  if [[ $cleanup_ok != 1 || $flag_ok != 1 || $code_ok != 1 ]]; then completed=0; category=recovery_required; status=1; fi
  if [[ $completed != 1 ]]; then status=1; fi
  publish || status=1
  rm -rf -- "$work"
  exit "$status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
[[ ${REVIEWS_PREREQUISITES:-failure} == success ]]
category=source_package
python3 scripts/package-staging-source.py wordpress "$work/source.tar.gz" --source-sha "$GITHUB_SHA" > "$work/package.private.stdout" 2> "$work/package.private.stderr"
"$RUNNER_TEMP/petish-gitleaks/gitleaks" dir wordpress --no-banner --redact=100 --exit-code=1 > "$work/secrets.private.stdout" 2> "$work/secrets.private.stderr"
python3 - "$work/source.tar.gz" "$work/manifest.json" <<'PY'
import sys,tarfile
from pathlib import Path
with tarfile.open(sys.argv[1],'r:gz') as archive:
 member=archive.next()
 if member.name!='staging-source-manifest.json' or not member.isfile() or member.size>4194304:raise SystemExit(1)
 Path(sys.argv[2]).write_bytes(archive.extractfile(member).read())
PY
category=connection
ssh-keyscan -T 15 -p "$SSH_PORT" -t ed25519,ecdsa,rsa "$SSH_HOST" > "$RUNNER_TEMP/petish-candidate-host-keys" 2> "$work/key-discovery.private.stderr"
[[ -s "$RUNNER_TEMP/petish-candidate-host-keys" ]]
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
ssh_ready=1
category=private_workspace
"${petish_ssh[@]}" 'set -eu; umask 077; work='"'$remote'"';
  test ! -e "$work" && test ! -L "$work"; mkdir -m 700 "$work"; printf "%s\n" '"'$run'"' > "$work/.owned"' > "$work/setup.private.stdout" 2> "$work/setup.private.stderr"
remote_ready=1
for name in customer-reviews-staging-remote.php customer-reviews-proof.php deploy-staging-remote.sh; do
  upload_private "$name" "scripts/$name"
done
upload_private source.tar.gz "$work/source.tar.gz"
upload_private manifest.json "$work/manifest.json"
category=deployment_preflight
"${petish_ssh[@]}" "bash '$remote/deploy-staging-remote.sh' '$remote/source.tar.gz' check '$GITHUB_SHA'" > "$work/deploy-check.private.stdout" 2> "$work/deploy-check.private.stderr"
category=code_snapshot
remote_phase prepare
snapshot_ready=1
category=deployment
apply_started=1
"${petish_ssh[@]}" "bash '$remote/deploy-staging-remote.sh' '$remote/source.tar.gz' apply '$GITHUB_SHA'" > "$work/deploy.private.stdout" 2> "$work/deploy.private.stderr"
remote_phase source
category=feature_enable
flag_started=1
remote_phase enable
category=native_proof
proof_started=1
native_status=0
remote_phase verify || native_status=$?
fetch_private native-result.json
python3 scripts/sanitize-customer-reviews-result.py native "$work/native-result.json" "$work/native.safe.json"
[[ $native_status == 0 ]]
category=browser_input
fetch_private browser-input.json
category=browser
browser_status=0
node tools/customer-reviews-browser.mjs "$work/browser-input.json" "$work/browser.private.json" > "$work/browser.private.stdout" 2> "$work/browser.private.stderr" || browser_status=$?
python3 scripts/sanitize-customer-reviews-result.py browser "$work/browser.private.json" "$work/browser.safe.json"
[[ $browser_status == 0 ]]
category=fixture_cleanup
remote_phase cleanup
cleanup_verified=1
fetch_private cleanup-result.json
python3 scripts/sanitize-customer-reviews-result.py cleanup-fixture "$work/cleanup-result.json" "$work/cleanup-fixture.safe.json"
category=commit
remote_phase commit
completed=1
category=complete
