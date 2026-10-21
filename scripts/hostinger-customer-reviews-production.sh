#!/usr/bin/env bash
# Release the staging-proven review MVP. No production fixtures or reward activation.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
source_sha=$GITHUB_SHA
mode=${CUSTOMER_REVIEWS_MODE:-inspect}
[[ $run =~ ^[1-9][0-9]{0,31}-[1-9][0-9]{0,8}$ && $source_sha =~ ^[a-f0-9]{40}$ ]]
[[ $mode == inspect || $mode == apply ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-customer-reviews-production.XXXXXXXX")
remote="/home/u839205597/.petish-customer-reviews-deploy-$run"
mkdir -m 700 "$work/bundle" "$work/bundle/payload"
remote_ready=0
rollback_needed=0
rollback_attempted=0
rollback_verified=0
operation_verified=0
staging_source_verified=0
result_status=failure
category=prerequisites

screen_phase() {
  local phase=$1
  python3 scripts/sanitize-customer-reviews-production.py "$phase" "$work/$phase.private.json" "$work/$phase.safe.json" \
    > "$work/$phase-screen.private.stdout" 2> "$work/$phase-screen.private.stderr"
}

remote_phase() {
  local phase=$1 status=0 validation=0
  case "$phase" in inspect|apply|enable|resume|rollback|rollback-finalize|commit) ;; *) return 1;; esac
  "${petish_ssh[@]}" "set -eu; php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 '$remote/deploy-customer-reviews-remote.php' '$phase' '$run' '$remote/payload'" \
    > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
  screen_phase "$phase" || validation=$?
  [[ $status == 0 && $validation == 0 ]]
}

verify_native() {
  local status=0 validation=0
  "${petish_ssh[@]}" "set -eu; php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 '$remote/verify-customer-reviews.php' '$run'" \
    > "$work/verify.private.json" 2> "$work/verify.private.stderr" || status=$?
  screen_phase verify || validation=$?
  [[ $status == 0 && $validation == 0 ]]
}

verify_staging_source() {
  "${petish_ssh[@]}" 'set -eu; root=/home/u839205597/domains/staging.petish.biz/public_html/wp-content/plugins/petish-passport;
    test -d "$root" && test ! -L "$root" && test "$(readlink -f -- "$root")" = "$root";
    for name in petish-passport.php includes/class-petish-customer-reviews.php includes/class-petish-customer-reviews-ui.php includes/class-petish-customer-review-media.php templates/customer-reviews-card.php templates/customer-reviews-dashboard.php templates/customer-reviews-form.php templates/customer-reviews-product.php templates/customer-reviews-progress.php assets/customer-reviews.css assets/customer-reviews.js includes/class-petish-passport-video-inspector.php includes/class-petish-passport-video.php assets/passport.js templates/moment-form.php; do
      file="$root/$name"; test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file" && test "$(stat -c %h "$file")" = 1;
      sha256sum "$file";
    done' > "$work/staging-hashes.private.txt" 2> "$work/staging-hashes.private.stderr"
  python3 - "$work/bundle/payload/manifest.json" "$work/staging-hashes.private.txt" <<'PY' > "$work/staging-source.private.stdout" 2> "$work/staging-source.private.stderr"
import json,re,sys
from pathlib import Path
expected=json.loads(Path(sys.argv[1]).read_text());lines=Path(sys.argv[2]).read_text().splitlines();actual={}
prefix='/home/u839205597/domains/staging.petish.biz/public_html/wp-content/plugins/petish-passport/'
if len(lines)!=15:raise SystemExit(1)
for line in lines:
 parts=line.split('  ',1)
 if len(parts)!=2 or not re.fullmatch(r'[a-f0-9]{64}',parts[0]) or not parts[1].startswith(prefix):raise SystemExit(1)
 name=parts[1][len(prefix):]
 if name in actual:raise SystemExit(1)
 actual[name]=parts[0]
if actual!=expected:raise SystemExit(1)
PY
}

remove_remote() {
  "${petish_ssh[@]}" 'set -eu; work='"'$remote'"';
    test -d "$work" && test ! -L "$work" && test "$(readlink -f -- "$work")" = "$work" && test "$(stat -c %a "$work")" = 700;
    marker="$work/.owned"; test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = '"'$run'"';
    unsafe=$(find "$work" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit); test -z "$unsafe";
    rm -rf -- "$work"' > "$work/remove.private.stdout" 2> "$work/remove.private.stderr"
}

publish_result() {
  export PCR_PRODUCTION_WORK=$work PCR_PRODUCTION_RUN=$run PCR_PRODUCTION_MODE=$mode
  export PCR_PRODUCTION_SOURCE=$source_sha PCR_PRODUCTION_STATUS=$result_status PCR_PRODUCTION_CATEGORY=$category
  export PCR_PRODUCTION_ROLLBACK_ATTEMPTED=$rollback_attempted PCR_PRODUCTION_ROLLBACK_VERIFIED=$rollback_verified
  export PCR_PRODUCTION_STAGE_VERIFIED=$staging_source_verified
  python3 - <<'PY'
import datetime,json,os,re,stat
from pathlib import Path
work=Path(os.environ['PCR_PRODUCTION_WORK'])
value={'format':'petish-customer-reviews-production-v1','status':os.environ['PCR_PRODUCTION_STATUS'],
 'category':os.environ['PCR_PRODUCTION_CATEGORY'],'mode':os.environ['PCR_PRODUCTION_MODE'],
 'scope':'customer-review-mvp-only','run_id':os.environ['PCR_PRODUCTION_RUN'],
 'source_commit':os.environ['PCR_PRODUCTION_SOURCE'],'generated_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
 'staging_source_verified':os.environ['PCR_PRODUCTION_STAGE_VERIFIED']=='1',
 'rollback_attempted':os.environ['PCR_PRODUCTION_ROLLBACK_ATTEMPTED']=='1',
 'rollback_verified':os.environ['PCR_PRODUCTION_ROLLBACK_VERIFIED']=='1','source_hashes':{},'phases':{}}
manifest=work/'bundle/payload/manifest.json'
if manifest.is_file():value['source_hashes']=json.loads(manifest.read_text())
for phase in ['inspect','apply','enable','verify','resume','public','commit','rollback','rollback-purge','rollback-finalize']:
 path=work/(phase+'.safe.json')
 if path.exists():
  st=path.lstat()
  if not stat.S_ISREG(st.st_mode) or st.st_nlink!=1 or st.st_size>65536:raise ValueError()
  value['phases'][phase]=json.loads(path.read_text())
evidence=work/'release-evidence.json'
if evidence.is_file():
 verified=json.loads(evidence.read_text())
 if verified.get('verified') is not True:raise ValueError()
 for key in ['backup_branch','backup_result_branch','recovery_result_branch','staging_status_branch','staging_source_commit']:
  value[key]=verified[key]
(work/'status.json').write_text(json.dumps(value,indent=2)+'\n')
PY
  local blob tree commit
  blob=$(git hash-object -w "$work/status.json") || return 1
  tree=$(printf '100644 blob %s\tstatus.json\n' "$blob" | git mktree) || return 1
  export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
  export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com' GIT_COMMITTER_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
  commit=$(printf 'Petish customer review production verification\n' | git commit-tree "$tree") || return 1
  git push origin "$commit:refs/heads/automation-status/customer-reviews-production-$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr" || return 1
  printf 'Screened review release report: https://github.com/nkrake6/petish/tree/automation-status/customer-reviews-production-%s\n' "$run"
  if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
    printf '[Customer review release report](https://github.com/nkrake6/petish/tree/automation-status/customer-reviews-production-%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
  fi
}

finish() {
  local status=$?
  trap - EXIT HUP INT TERM
  set +e
  if [[ $rollback_needed == 1 ]]; then
    rollback_attempted=1
    if remote_phase rollback; then
      rollback_ok=1
      if ! python3 - "$work/rollback.safe.json" <<'PY'
import json,sys
from pathlib import Path
raise SystemExit(0 if json.loads(Path(sys.argv[1]).read_text())['checks'].get('rollback_not_needed') is True else 1)
PY
      then
        python3 scripts/verify-customer-reviews-public.py "$work/rollback-purge.private.json" "$run" "$work/bundle/.probe-token" rollback-purge \
          > "$work/rollback-purge.private.stdout" 2> "$work/rollback-purge.private.stderr" || rollback_ok=0
        screen_phase rollback-purge || rollback_ok=0
        if [[ $rollback_ok == 1 ]]; then remote_phase rollback-finalize || rollback_ok=0; fi
      fi
      if [[ $rollback_ok == 1 ]]; then rollback_needed=0; rollback_verified=1;
      else category=recovery_required; fi
    else category=recovery_required; fi
    status=1
  fi
  if [[ $remote_ready == 1 && $rollback_needed == 0 ]]; then
    if ! remove_remote; then category=private_cleanup; status=1; fi
  fi
  if [[ $status == 0 && $operation_verified == 1 ]]; then result_status=success; category=complete;
  else result_status=failure; status=1; fi
  if ! publish_result > "$work/publication.private.stdout" 2> "$work/publication.private.stderr"; then
    printf '%s\n' 'Customer review release report publication failed; private output was not published.' >&2
    status=1
  else cat "$work/publication.private.stdout"; fi
  rm -rf -- "$work"
  exit "$status"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${CUSTOMER_REVIEWS_PREREQUISITES:-failure} == success ]]
category=source
[[ $(git rev-parse HEAD) == "$source_sha" ]]
python3 - "$source_sha" "$work/bundle/payload" <<'PY' > "$work/package.private.stdout" 2> "$work/package.private.stderr"
import hashlib,importlib.util,json,secrets,subprocess,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('review_evidence','scripts/validate-customer-reviews-release.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
if len(module.FILES)!=15 or len(set(module.FILES))!=15 or module.PREFIX!='wordpress/wp-content/plugins/petish-passport/':raise ValueError()
root=Path(sys.argv[2]);manifest={}
for name in module.FILES:
 content=subprocess.run(['git','show',sys.argv[1]+':'+module.PREFIX+name],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
 if not 0<len(content)<=1048576:raise ValueError()
 target=root/name;target.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
 with target.open('xb') as stream:stream.write(content)
 target.chmod(0o600);manifest[name]=hashlib.sha256(content).hexdigest()
with (root/'manifest.json').open('x') as stream:stream.write(json.dumps(manifest,indent=2,sort_keys=True)+'\n')
(root/'manifest.json').chmod(0o600)
with (root.parent/'.probe-token').open('x') as stream:stream.write(secrets.token_hex(32)+'\n')
(root.parent/'.probe-token').chmod(0o600)
PY
if [[ $mode == apply ]]; then
  category=evidence
  python3 scripts/validate-customer-reviews-release.py operations/customer-reviews-release.json "$work/release-evidence.json" \
    > "$work/evidence.private.stdout" 2> "$work/evidence.private.stderr"
fi
for name in deploy-customer-reviews-remote.php verify-customer-reviews.php; do
  git show "$source_sha:scripts/$name" > "$work/bundle/$name"
  chmod 600 "$work/bundle/$name"
done
tar -C "$work/bundle" -cf "$work/bundle.tar" deploy-customer-reviews-remote.php verify-customer-reviews.php .probe-token payload
category=connection
ssh-keyscan -T 15 -p "$SSH_PORT" -t ed25519,ecdsa,rsa "$SSH_HOST" > "$RUNNER_TEMP/petish-candidate-host-keys" 2> "$work/key-discovery.private.stderr"
[[ -s "$RUNNER_TEMP/petish-candidate-host-keys" ]]
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
category=private_workspace
"${petish_ssh[@]}" 'set -eu; umask 077; work='"'$remote'"';
  test "$HOME" = /home/u839205597 && test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME";
  test ! -e "$work" && test ! -L "$work"; mkdir -m 700 "$work"; printf "%s\n" '"'$run'"' > "$work/.owned"; chmod 600 "$work/.owned"' \
  > "$work/setup.private.stdout" 2> "$work/setup.private.stderr"
remote_ready=1
"${petish_ssh[@]}" "set -eu; umask 077; tar --extract --no-same-owner --no-same-permissions --directory '$remote'" \
  < "$work/bundle.tar" > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"
category=inspect
remote_phase inspect
if [[ $mode == inspect ]]; then operation_verified=1; exit 0; fi
category=staging_source
verify_staging_source
staging_source_verified=1
category=apply
rollback_needed=1
remote_phase apply
category=enable
remote_phase enable
category=verify
verify_native
category=resume
remote_phase resume
category=public
public_status=0
python3 scripts/verify-customer-reviews-public.py "$work/public.private.json" "$run" "$work/bundle/.probe-token" > "$work/public.private.stdout" 2> "$work/public.private.stderr" || public_status=$?
public_validation=0
screen_phase public || public_validation=$?
[[ $public_status == 0 && $public_validation == 0 ]]
category=commit
remote_phase commit
rollback_needed=0
operation_verified=1
