#!/usr/bin/env bash
# Release only the six tested email theme files; retain private rollback copies.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
: "${GITHUB_RUN_ID:?}"
: "${GITHUB_RUN_ATTEMPT:?}"
: "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
mode=${EMAIL_BRANDING_MODE:-inspect}
[[ $run =~ ^[1-9][0-9]*-[1-9][0-9]*$ && $GITHUB_SHA =~ ^[a-f0-9]{40}$ ]]
[[ $mode == inspect || $mode == apply ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-email-release.XXXXXXXX")
mkdir -m 700 "$work/public" "$work/bundle" "$work/bundle/payload"
ssh_ready=0
rollback_needed=0
rollback_attempted=0
rollback_verified=0
operation_verified=0
release_status=unavailable
failure_category=prerequisites
published_commit=

remote_phase() {
    local phase=$1 status=0 validation=0
    case "$phase" in inspect|apply|rollback|commit) ;; *) return 1 ;; esac
    "${petish_ssh[@]}" 'set -eu; tools="$HOME/.petish-email-deploy-'"$run"'";
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$tools/deploy-email-branding-remote.php" '"$phase $run"' "$tools/payload"' \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    python3 scripts/sanitize-email-release-result.py "$phase" "$work/$phase.private.json" "$work/$phase.safe.json" \
        > "$work/$phase-validation.private.stdout" 2> "$work/$phase-validation.private.stderr" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

public_health() {
    local status=0 validation=0
    python3 scripts/verify-live-email-release.py "$work/health.private.json" \
        > "$work/health.private.stdout" 2> "$work/health.private.stderr" || status=$?
    python3 scripts/sanitize-email-release-result.py health "$work/health.private.json" "$work/health.safe.json" \
        > "$work/health-validation.private.stdout" 2> "$work/health-validation.private.stderr" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

remote_cleanup() {
    "${petish_ssh[@]}" 'set -eu; tools="$HOME/.petish-email-deploy-'"$run"'";
      if test ! -e "$tools" && test ! -L "$tools"; then exit 0; fi;
      test -d "$tools" && test ! -L "$tools" && test "$(readlink -f -- "$tools")" = "$tools" && test "$(stat -c %a "$tools")" = 700 || exit 1;
      marker="$tools/.email-deploy-owned";
      test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = '"$run"' || exit 1;
      unsafe=$(find "$tools" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit) || exit 1;
      test -z "$unsafe" || exit 1;
      rm -rf -- "$tools"' \
        > "$work/cleanup.private.stdout" 2> "$work/cleanup.private.stderr"
}

publish_result() {
    export PETISH_EMAIL_RELEASE_WORK=$work PETISH_EMAIL_RELEASE_RUN=$run
    export PETISH_EMAIL_RELEASE_MODE=$mode PETISH_EMAIL_RELEASE_STATUS=$release_status
    export PETISH_EMAIL_RELEASE_CATEGORY=$failure_category
    export PETISH_EMAIL_RELEASE_ROLLBACK_ATTEMPTED=$rollback_attempted
    export PETISH_EMAIL_RELEASE_ROLLBACK_VERIFIED=$rollback_verified
    python3 - <<'PY' || return 1
import json,os,re,stat
from pathlib import Path
root=Path(os.environ['PETISH_EMAIL_RELEASE_WORK'])
result={'status':os.environ['PETISH_EMAIL_RELEASE_STATUS'],
 'category':os.environ['PETISH_EMAIL_RELEASE_CATEGORY'],'mode':os.environ['PETISH_EMAIL_RELEASE_MODE'],
 'run_id':os.environ['PETISH_EMAIL_RELEASE_RUN'],'source_commit':os.environ['GITHUB_SHA'],
 'tested_source_commit':'86e315f3daf172e1c9faee9a62d768b48f634bca',
 'rollback_attempted':os.environ['PETISH_EMAIL_RELEASE_ROLLBACK_ATTEMPTED']=='1',
 'rollback_verified':os.environ['PETISH_EMAIL_RELEASE_ROLLBACK_VERIFIED']=='1','phases':{}}
for phase in ('inspect','apply','verify','health','commit','rollback'):
 path=root/(phase+'.safe.json')
 try:
  info=path.lstat()
  if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or info.st_size>65536:raise ValueError()
  # Only sanitizer output is included; raw PHP and network output stay private.
  result['phases'][phase]=json.loads(path.read_text())
 except FileNotFoundError:pass
evidence=root/'release-evidence.json'
if evidence.exists():
 value=json.loads(evidence.read_text());branch=value.get('backup_branch')
 if value.get('verified') is True and isinstance(branch,str) and re.fullmatch(r'full-site-backup/[1-9][0-9]*-[1-9][0-9]*',branch):
  result['verified_backup_branch']=branch
(root/'public/status.json').write_text(json.dumps(result,indent=2)+'\n')
PY
    local blob tree commit
    export GIT_INDEX_FILE="$work/publication.index"
    git read-tree --empty || return 1
    blob=$(git hash-object -w "$work/public/status.json") || return 1
    git update-index --add --cacheinfo "100644,$blob,status.json" || return 1
    tree=$(git write-tree) || return 1
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
    if [[ -n $published_commit ]]; then
        commit=$(printf 'Petish production email release %s\n' "$run" | git commit-tree "$tree" -p "$published_commit") || return 1
    else
        commit=$(printf 'Petish production email release %s\n' "$run" | git commit-tree "$tree") || return 1
    fi
    git push origin "$commit:refs/heads/production-email-release/$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr" || return 1
    published_commit=$commit
    unset GIT_INDEX_FILE
    printf 'Screened email release report: https://github.com/nkrake6/petish/tree/production-email-release/%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
        printf '[Screened production email release report](https://github.com/nkrake6/petish/tree/production-email-release/%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
    fi
}

finish() {
    outcome=$?
    trap - EXIT HUP INT TERM
    if [[ $rollback_needed == 1 ]]; then
        rollback_attempted=1
        if remote_phase rollback; then
            rollback_verified=1
            rollback_needed=0
        else
            failure_category=rollback
        fi
        outcome=1
    fi
    if [[ $ssh_ready == 1 ]] && ! remote_cleanup; then
        outcome=1
        failure_category=cleanup
    fi
    if [[ $outcome == 0 && $operation_verified == 1 ]]; then
        release_status=success
        failure_category=complete
    else
        release_status=failure
        outcome=1
    fi
    if ! publish_result > "$work/publication.private.stdout" 2> "$work/publication.private.stderr"; then
        printf '%s\n' 'Email release report publication failed; private output was not published.' >&2
        outcome=1
    else
        cat "$work/publication.private.stdout"
    fi
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${EMAIL_BRANDING_PREREQUISITES:-success} == success ]]
failure_category=source_validation
[[ $(git rev-parse HEAD) == "$GITHUB_SHA" ]]
git fetch --no-tags --depth=1 origin 86e315f3daf172e1c9faee9a62d768b48f634bca \
    > "$work/fetch-source.private.stdout" 2> "$work/fetch-source.private.stderr"
python3 - "$work/bundle/payload" <<'PY' > "$work/payload.private.stdout" 2> "$work/payload.private.stderr"
import hashlib,json,os,stat,subprocess,sys
from pathlib import Path
destination=Path(sys.argv[1]);prefix='wordpress/wp-content/themes/dt-the7-child/'
tested='86e315f3daf172e1c9faee9a62d768b48f634bca'
names=('functions.php','inc/petish-email-branding.php','inc/petish-account-email-branding.php',
 'woocommerce/emails/email-header.php','woocommerce/emails/email-footer.php','woocommerce/emails/email-button.php')
manifest={}
for name in names:
 source=Path(prefix+name);info=source.lstat()
 if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or not 0<info.st_size<=1048576:raise ValueError('Candidate source type')
 if source.resolve()!=source.absolute():raise ValueError('Candidate source path')
 content=source.read_bytes()
 approved=subprocess.run(['git','show',tested+':'+prefix+name],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
 if content!=approved:raise ValueError('Candidate differs from tested source')
 target=destination/name;target.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
 with target.open('xb') as stream:stream.write(content)
 target.chmod(0o600);manifest[name]=hashlib.sha256(content).hexdigest()
with (destination/'manifest.json').open('x') as stream:stream.write(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
(destination/'manifest.json').chmod(0o600)
PY
if [[ $mode == apply ]]; then
    failure_category=release_evidence
    python3 scripts/validate-email-release-request.py operations/email-branding-release.json "$work/release-evidence.json" \
        > "$work/evidence.private.stdout" 2> "$work/evidence.private.stderr"
fi
for name in deploy-email-branding-remote.php verify-production-email-branding.php email-branding-preview-remote.php; do
    cp -- "scripts/$name" "$work/bundle/$name"
    chmod 600 "$work/bundle/$name"
done
tar -C "$work/bundle" -cf "$work/bundle.tar" \
    deploy-email-branding-remote.php verify-production-email-branding.php email-branding-preview-remote.php payload \
    > "$work/archive.private.stdout" 2> "$work/archive.private.stderr"
failure_category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
ssh_ready=1
"${petish_ssh[@]}" 'set -eu; umask 077; tools="$HOME/.petish-email-deploy-'"$run"'";
  test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME" && test ! -e "$tools" && test ! -L "$tools" || exit 1;
  mkdir -m 700 "$tools"; printf "%s\n" '"$run"' > "$tools/.email-deploy-owned"; chmod 600 "$tools/.email-deploy-owned";
  tar --extract --no-same-owner --no-same-permissions --directory "$tools"' \
    < "$work/bundle.tar" \
    > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"
failure_category=inspect
remote_phase inspect
failure_category=health
public_health
release_status=in_progress
publish_result > "$work/progress.private.stdout" 2> "$work/progress.private.stderr"
if [[ $mode == inspect ]]; then
    operation_verified=1
    exit 0
fi

failure_category=apply
# Arm rollback before the mutating command, including an ambiguous SSH result.
rollback_needed=1
remote_phase apply
failure_category=verify
verify_status=0
"${petish_ssh[@]}" 'set -eu; tools="$HOME/.petish-email-deploy-'"$run"'";
  php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$tools/verify-production-email-branding.php" '"$run" \
    > "$work/verify.private.json" 2> "$work/verify.private.stderr" || verify_status=$?
verify_validation=0
python3 scripts/sanitize-email-release-result.py verify "$work/verify.private.json" "$work/verify.safe.json" \
    > "$work/verify-validation.private.stdout" 2> "$work/verify-validation.private.stderr" || verify_validation=$?
[[ $verify_status == 0 && $verify_validation == 0 ]]
failure_category=health
public_health
failure_category=commit
remote_phase commit
rollback_needed=0
operation_verified=1
