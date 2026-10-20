#!/usr/bin/env bash
# Verify and release the reviewed Pet Club application, preserving existing private pets.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
: "${GITHUB_RUN_ID:?}"
: "${GITHUB_RUN_ATTEMPT:?}"
: "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
mode=${PET_CLUB_MODE:-staging}
source_sha=${PET_CLUB_SOURCE_SHA:-$GITHUB_SHA}
[[ $run =~ ^[1-9][0-9]*-[1-9][0-9]*$ && $source_sha =~ ^[a-f0-9]{40}$ ]]
[[ $mode == staging || $mode == inspect || $mode == apply ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-pet-club.XXXXXXXX")
mkdir -m 700 "$work/public" "$work/bundle" "$work/bundle/payload"
ssh_ready=0
fixture_cleanup_needed=0
fixture_cleanup_verified=0
rollback_needed=0
rollback_attempted=0
rollback_verified=0
staging_source_verified=0
operation_verified=0
result_status=unavailable
failure_category=prerequisites
published_commit=

screen_phase() {
    local phase=$1
    python3 scripts/sanitize-pet-club-result.py "$phase" "$work/$phase.private.json" "$work/$phase.safe.json" \
        > "$work/$phase-validation.private.stdout" 2> "$work/$phase-validation.private.stderr"
}

remote_phase() {
    local phase=$1 status=0 validation=0
    case "$phase" in inspect|apply|rollback|commit) ;; *) return 1 ;; esac
    "${petish_ssh[@]}" 'set -eu; tools="$HOME/.petish-pet-club-deploy-'"$run"'";
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$tools/deploy-pet-club-remote.php" '"$phase $run"' "$tools/payload"' \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

native_verification() {
    local target=$1 phase=verify status=0 validation=0
    [[ $target == staging || $target == production ]] || return 1
    [[ $target != staging ]] || phase=native
    "${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
      for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value;
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-pet-club-deploy-'"$run"'/verify-pet-club.php" '"$run $target" \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

fixture_phase() {
    local operation=$1 phase=fixture status=0 validation=0
    [[ $operation == prepare || $operation == cleanup ]] || return 1
    [[ $operation != cleanup ]] || phase=cleanup
    "${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
      for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value;
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-pet-club-deploy-'"$run"'/pet-club-fixture.php" '"$operation $run" \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

verify_staging_source() {
    "${petish_ssh[@]}" 'set -eu; root="$HOME/domains/staging.petish.biz/public_html/wp-content/plugins/petish-passport";
      test -d "$root" && test ! -L "$root" && test "$(readlink -f -- "$root")" = "$root" || exit 1;
      for relative in includes/class-petish-passport-moments.php includes/class-petish-passport-community.php assets/pet-club.css assets/passport.js templates/public.php templates/community.php templates/moment-form.php includes/class-petish-passport-media.php includes/class-petish-passport-store.php includes/class-petish-passport-ui.php templates/dashboard.php templates/form.php templates/delete.php petish-passport.php; do
        file="$root/$relative";
        test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file" && test "$(stat -c %h "$file")" = 1 || exit 1;
        sha256sum "$file";
      done' > "$work/staging-hashes.private.txt" 2> "$work/staging-hashes.private.stderr"
    python3 - "$work/bundle/payload/manifest.json" "$work/staging-hashes.private.txt" <<'PY_STAGE' > "$work/staging-source.private.stdout" 2> "$work/staging-source.private.stderr"
import importlib.util,json,re,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('pet_club_evidence','scripts/validate-pet-club-release.py')
evidence=importlib.util.module_from_spec(spec);spec.loader.exec_module(evidence)
expected=json.loads(Path(sys.argv[1]).read_text());lines=Path(sys.argv[2]).read_text().splitlines()
if len(lines)!=len(evidence.FILES):raise SystemExit(1)
for name,line in zip(evidence.FILES,lines):
 parts=line.split('  ',1)
 if len(parts)!=2 or not re.fullmatch(r'[a-f0-9]{64}',parts[0]) \
  or not parts[1].endswith('/wp-content/plugins/petish-passport/'+name) or parts[0]!=expected[name]:raise SystemExit(1)
PY_STAGE
}

remote_cleanup() {
    "${petish_ssh[@]}" 'set -eu; tools="$HOME/.petish-pet-club-deploy-'"$run"'";
      if test ! -e "$tools" && test ! -L "$tools"; then exit 0; fi;
      test -d "$tools" && test ! -L "$tools" && test "$(readlink -f -- "$tools")" = "$tools" && test "$(stat -c %a "$tools")" = 700 || exit 1;
      marker="$tools/.pet-club-owned";
      test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = '"$run"' || exit 1;
      unsafe=$(find "$tools" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit) || exit 1;
      test -z "$unsafe" || exit 1;
      work="$HOME/.petish-staging/rehearsal-'"$run"'";
      if test -e "$work" || test -L "$work"; then
        base="$HOME/.petish-staging";
        test -d "$base" && test ! -L "$base" && test "$(readlink -f -- "$base")" = "$base" && test "$(stat -c %a "$base")" = 700 || exit 1;
        test -d "$work" && test ! -L "$work" && test "$(readlink -f -- "$work")" = "$work" && test "$(stat -c %a "$work")" = 700 || exit 1;
        unsafe=$(find "$work" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit) || exit 1;
        test -z "$unsafe" || exit 1;
        rm -rf -- "$work";
      fi;
      rm -rf -- "$tools"' \
        > "$work/cleanup.private.stdout" 2> "$work/cleanup.private.stderr"
}

publish_result() {
    export PETISH_PET_CLUB_WORK=$work PETISH_PET_CLUB_RUN=$run PETISH_PET_CLUB_MODE=$mode
    export PETISH_PET_CLUB_STATUS=$result_status PETISH_PET_CLUB_CATEGORY=$failure_category
    export PETISH_PET_CLUB_SOURCE=$source_sha PETISH_PET_CLUB_STAGE_VERIFIED=$staging_source_verified
    export PETISH_PET_CLUB_ROLLBACK_ATTEMPTED=$rollback_attempted PETISH_PET_CLUB_ROLLBACK_VERIFIED=$rollback_verified
    python3 - <<'PY' || return 1
from datetime import datetime,timezone
import json,os,re,stat
from pathlib import Path
root=Path(os.environ['PETISH_PET_CLUB_WORK'])
result={'status':os.environ['PETISH_PET_CLUB_STATUS'],'category':os.environ['PETISH_PET_CLUB_CATEGORY'],
 'mode':os.environ['PETISH_PET_CLUB_MODE'],'run_id':os.environ['PETISH_PET_CLUB_RUN'],
 'source_commit':os.environ['PETISH_PET_CLUB_SOURCE'],'generated_utc':datetime.now(timezone.utc).isoformat(),
 'source_hashes':{},'staging_source_verified':os.environ['PETISH_PET_CLUB_STAGE_VERIFIED']=='1',
 'rollback_attempted':os.environ['PETISH_PET_CLUB_ROLLBACK_ATTEMPTED']=='1',
 'rollback_verified':os.environ['PETISH_PET_CLUB_ROLLBACK_VERIFIED']=='1','phases':{}}
stage=os.environ.get('PET_CLUB_PREPARATION_STAGE','unknown')
result['preparation_stage']=stage if stage in {'connection','ssh_tools','browser_tools','health_dependencies','complete'} else 'unknown'
manifest=root/'bundle/payload/manifest.json'
if manifest.exists():result['source_hashes']=json.loads(manifest.read_text())
for phase in ('inspect','preflight','native','fixture','browser','cleanup','apply','verify','health','assets','commit','rollback'):
 path=root/(phase+'.safe.json')
 try:
  info=path.lstat()
  if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or info.st_size>65536:raise ValueError()
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
        commit=$(printf 'Petish pet-club checks %s\n' "$run" | git commit-tree "$tree" -p "$published_commit") || return 1
    else
        commit=$(printf 'Petish pet-club checks %s\n' "$run" | git commit-tree "$tree") || return 1
    fi
    git push origin "$commit:refs/heads/automation-status/pet-club-$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr" || return 1
    published_commit=$commit
    unset GIT_INDEX_FILE
    printf 'Screened pet-club report: https://github.com/nkrake6/petish/tree/automation-status/pet-club-%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
        printf '[Screened pet-club report](https://github.com/nkrake6/petish/tree/automation-status/pet-club-%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
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
    if [[ $ssh_ready == 1 && $fixture_cleanup_needed == 1 ]]; then
        if fixture_phase cleanup; then
            fixture_cleanup_verified=1
            if [[ $mode == staging && $operation_verified == 1 ]] && ! verify_staging_source; then
                staging_source_verified=0
                outcome=1
                failure_category=staging_source
            fi
        else
            outcome=1
            failure_category=cleanup
        fi
    fi
    # Preserve exact helpers/payload and owned journals whenever recovery is incomplete.
    if [[ $ssh_ready == 1 && $rollback_needed == 0 && ( $fixture_cleanup_needed == 0 || $fixture_cleanup_verified == 1 ) ]] && ! remote_cleanup; then
        outcome=1
        failure_category=cleanup
    fi
    if [[ $outcome == 0 && $operation_verified == 1 ]]; then
        result_status=success
        failure_category=complete
    else
        result_status=failure
        outcome=1
    fi
    if ! publish_result > "$work/publication.private.stdout" 2> "$work/publication.private.stderr"; then
        printf '%s\n' 'Pet Club report publication failed; private output was not published.' >&2
        outcome=1
    else
        cat "$work/publication.private.stdout"
    fi
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${PET_CLUB_PREREQUISITES:-success} == success ]]
failure_category=source
[[ $(git rev-parse HEAD) == "$source_sha" ]]
git fetch --no-tags --depth=1 origin "$source_sha" > "$work/fetch.private.stdout" 2> "$work/fetch.private.stderr"
python3 - "$source_sha" "$work/bundle/payload" <<'PY' > "$work/payload.private.stdout" 2> "$work/payload.private.stderr"
import hashlib,json,subprocess,sys
from pathlib import Path
revision=sys.argv[1];destination=Path(sys.argv[2]);prefix='wordpress/wp-content/plugins/petish-passport/'
import importlib.util
spec=importlib.util.spec_from_file_location('pet_club_evidence', 'scripts/validate-pet-club-release.py')
evidence=importlib.util.module_from_spec(spec);spec.loader.exec_module(evidence)
names=evidence.FILES
manifest={}
for name in names:
 content=subprocess.run(['git','show',revision+':'+prefix+name],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
 if not 0<len(content)<=1048576:raise ValueError('Candidate size')
 target=destination/name;target.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
 with target.open('xb') as stream:stream.write(content)
 target.chmod(0o600);manifest[name]=hashlib.sha256(content).hexdigest()
with (destination/'manifest.json').open('x') as stream:stream.write(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
(destination/'manifest.json').chmod(0o600)
PY
if [[ $mode == apply ]]; then
    failure_category=evidence
    python3 scripts/validate-pet-club-release.py operations/pet-club-release.json "$work/release-evidence.json" \
        > "$work/evidence.private.stdout" 2> "$work/evidence.private.stderr"
fi
if [[ $mode == staging ]]; then
    cp -- scripts/staging-rehearsal-remote.php "$work/bundle/driver.php"
    chmod 600 "$work/bundle/driver.php"
    for name in verify-pet-club.php pet-club-fixture.php email-branding-preview-remote.php; do
        cp -- "scripts/$name" "$work/bundle/$name"
        chmod 600 "$work/bundle/$name"
    done
    tar -C "$work/bundle" -cf "$work/bundle.tar" driver.php verify-pet-club.php pet-club-fixture.php email-branding-preview-remote.php payload \
        > "$work/archive.private.stdout" 2> "$work/archive.private.stderr"
else
    for name in deploy-pet-club-remote.php verify-pet-club.php email-branding-preview-remote.php; do
        cp -- "scripts/$name" "$work/bundle/$name"
        chmod 600 "$work/bundle/$name"
    done
    tar -C "$work/bundle" -cf "$work/bundle.tar" deploy-pet-club-remote.php verify-pet-club.php email-branding-preview-remote.php payload \
        > "$work/archive.private.stdout" 2> "$work/archive.private.stderr"
fi
failure_category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
ssh_ready=1
"${petish_ssh[@]}" 'set -eu; umask 077; tools="$HOME/.petish-pet-club-deploy-'"$run"'";
  test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME" && test ! -e "$tools" && test ! -L "$tools" || exit 1;
  mkdir -m 700 "$tools"; printf "%s\n" '"$run"' > "$tools/.pet-club-owned"; chmod 600 "$tools/.pet-club-owned";
  tar --extract --no-same-owner --no-same-permissions --directory "$tools"' \
    < "$work/bundle.tar" > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"

if [[ $mode == staging ]]; then
    failure_category=staging_source
    verify_staging_source
    staging_source_verified=1
    failure_category=preflight
    preflight_status=0
    "${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
      for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value;
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-pet-club-deploy-'"$run"'/driver.php" preflight '"$run" \
        > "$work/preflight.private.json" 2> "$work/preflight.private.stderr" || preflight_status=$?
    preflight_validation=0
    screen_phase preflight || preflight_validation=$?
    [[ $preflight_status == 0 && $preflight_validation == 0 ]]
    failure_category=native
    fixture_cleanup_needed=1
    native_verification staging
    failure_category=fixture
    fixture_phase prepare
    "${petish_ssh[@]}" 'set -eu; file="$HOME/.petish-pet-club-deploy-'"$run"'/pet-club-browser-input.json";
      test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file" && test "$(stat -c %a "$file")" = 600 && test "$(stat -c %h "$file")" = 1 || exit 1;
      size=$(stat -c %s "$file"); test "$size" -gt 0 && test "$size" -le 65536 || exit 1; cat "$file"' \
        > "$work/browser-input.json" 2> "$work/input.private.stderr"
    chmod 600 "$work/browser-input.json"
    failure_category=browser
    browser_status=0
    node tools/pet-club/browser.mjs "$work/browser-input.json" "$work/browser.private.json" \
        > "$work/browser.private.stdout" 2> "$work/browser.private.stderr" || browser_status=$?
    browser_validation=0
    screen_phase browser || browser_validation=$?
    [[ $browser_status == 0 && $browser_validation == 0 ]]
    operation_verified=1
    exit 0
fi

failure_category=inspect
remote_phase inspect
result_status=in_progress
publish_result > "$work/progress.private.stdout" 2> "$work/progress.private.stderr"
if [[ $mode == inspect ]]; then
    operation_verified=1
    exit 0
fi
failure_category=apply
rollback_needed=1
remote_phase apply
failure_category=verify
native_verification production
failure_category=health
health_status=0
python3 scripts/verify-live-email-release.py "$work/health.private.json" \
    > "$work/health.private.stdout" 2> "$work/health.private.stderr" || health_status=$?
health_validation=0
screen_phase health || health_validation=$?
[[ $health_status == 0 && $health_validation == 0 ]]
failure_category=assets
assets_status=0
python3 - "$work/assets.private.json" <<'PY_ASSETS' > "$work/assets.private.stdout" 2> "$work/assets.private.stderr" || assets_status=$?
import importlib.util,sys
spec=importlib.util.spec_from_file_location('public_assets','scripts/verify-account-navigation-assets.py')
assets=importlib.util.module_from_spec(spec);spec.loader.exec_module(assets)
assets.ASSETS={'pet_club_script_http_verified':'passport.js','pet_club_stylesheet_http_verified':'pet-club.css'}
raise SystemExit(assets.main([sys.argv[1]]))
PY_ASSETS
assets_validation=0
screen_phase assets || assets_validation=$?
[[ $assets_status == 0 && $assets_validation == 0 ]]
failure_category=commit
remote_phase commit
rollback_needed=0
operation_verified=1
