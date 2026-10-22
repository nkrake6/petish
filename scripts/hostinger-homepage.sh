#!/usr/bin/env bash
# Verify the public homepage on staging or release its reviewed homepage and header files.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
: "${GITHUB_RUN_ID:?}"
: "${GITHUB_RUN_ATTEMPT:?}"
: "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
mode=${HOMEPAGE_MODE:-staging}
source_sha=${HOMEPAGE_SOURCE_SHA:-$GITHUB_SHA}
[[ $run =~ ^[1-9][0-9]*-[1-9][0-9]*$ && $source_sha =~ ^[a-f0-9]{40}$ ]]
[[ $mode == staging || $mode == inspect || $mode == apply ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-homepage.XXXXXXXX")
mkdir -m 700 "$work/public" "$work/bundle" "$work/bundle/payload"
ssh_ready=0
fixture_cleanup_needed=0
fixture_cleanup_verified=0
cart_cleanup_needed=0
cart_cleanup_verified=0
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
    python3 scripts/sanitize-homepage-result.py "$phase" "$work/$phase.private.json" "$work/$phase.safe.json" \
        > "$work/$phase-validation.private.stdout" 2> "$work/$phase-validation.private.stderr"
}

remote_phase() {
    local phase=$1 status=0 validation=0
    case "$phase" in inspect|apply|rollback|commit) ;; *) return 1 ;; esac
    "${petish_ssh[@]}" 'set -eu; tools="$HOME/.petish-homepage-deploy-'"$run"'";
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$tools/deploy-homepage-remote.php" '"$phase $run"' "$tools/payload"' \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

native_verification() {
    local target=$1 status=0 validation=0
    [[ $target == staging || $target == production ]] || return 1
    "${petish_ssh[@]}" 'set -eu; php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-homepage-deploy-'"$run"'/verify-homepage.php" '"$run $target" \
        > "$work/verify.private.json" 2> "$work/verify.private.stderr" || status=$?
    screen_phase verify || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

cache_remote_phase() {
    local operation=$1 step=$2 phase status=0 validation=0
    [[ $operation == apply || $operation == rollback ]] || return 1
    [[ $step == inspect || $step == queue || $step == verify ]] || return 1
    phase=cache_$step
    [[ $step != inspect ]] || phase=cache_preflight
    [[ $operation != rollback ]] || phase=rollback_cache_$step
    "${petish_ssh[@]}" 'set -eu; php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-homepage-deploy-'"$run"'/litespeed-html-purge.php" '"$step site $run $operation" \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

purge_html_cache() {
    local operation=$1 phase=cache_consume status=0 validation=0
    [[ $operation == apply || $operation == rollback ]] || return 1
    [[ $operation != rollback ]] || phase=rollback_cache_consume
    cache_remote_phase "$operation" queue || return 1
    python3 scripts/consume-litespeed-html-purge.py "$work/$phase.private.json" \
        > "$work/$phase.private.stdout" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]] || return 1
    cache_remote_phase "$operation" verify
}

fixture_phase() {
    local operation=$1 phase=native status=0 validation=0
    [[ $operation == verify || $operation == cleanup ]] || return 1
    [[ $operation != cleanup ]] || phase=cleanup
    "${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
      for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value;
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-homepage-deploy-'"$run"'/for-your-pet-proof.php" '"$run $operation" \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

cart_fixture_phase() {
    local operation=$1 phase status=0 validation=0
    [[ $operation == prepare || $operation == cleanup ]] || return 1
    phase=cart_$operation
    "${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
      for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value;
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-homepage-deploy-'"$run"'/cart-badge-staging-proof.php" '"$operation $run" \
        > "$work/$phase.private.json" 2> "$work/$phase.private.stderr" || status=$?
    screen_phase "$phase" || validation=$?
    [[ $status == 0 && $validation == 0 ]]
}

verify_staging_source() {
    "${petish_ssh[@]}" 'set -eu; root="$HOME/domains/staging.petish.biz/public_html/wp-content/themes/dt-the7-child";
      test -d "$root" && test ! -L "$root" && test "$(readlink -f -- "$root")" = "$root" || exit 1;
      for relative in assets/images/ui/free-delivery-van.png assets/css/home.css assets/js/for-your-pet.js includes/supplier-popularity.php includes/for-your-pet.php front-page.php functions.php includes/cart-badge.php assets/css/cart-badge.css assets/js/cart-badge.js includes/header-navigation.php assets/js/theme.js assets/css/petish.css includes/market-selector.php assets/js/market-selector.js assets/css/market-selector.css assets/images/ui/country-flags.svg assets/images/ui/country-flags.LICENSE.txt; do
        file="$root/$relative";
        test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file" && test "$(stat -c %h "$file")" = 1 || exit 1;
        sha256sum "$file";
      done' > "$work/staging-hashes.private.txt" 2> "$work/staging-hashes.private.stderr"
    python3 - "$work/bundle/payload/manifest.json" "$work/staging-hashes.private.txt" <<'PY' > "$work/staging-source.private.stdout" 2> "$work/staging-source.private.stderr"
import json,re,sys
from pathlib import Path
expected=json.loads(Path(sys.argv[1]).read_text());lines=Path(sys.argv[2]).read_text().splitlines()
names=('assets/images/ui/free-delivery-van.png', 'assets/css/home.css', 'assets/js/for-your-pet.js', 'includes/supplier-popularity.php', 'includes/for-your-pet.php', 'front-page.php', 'functions.php', 'includes/cart-badge.php', 'assets/css/cart-badge.css', 'assets/js/cart-badge.js', 'includes/header-navigation.php', 'assets/js/theme.js', 'assets/css/petish.css', 'includes/market-selector.php', 'assets/js/market-selector.js', 'assets/css/market-selector.css', 'assets/images/ui/country-flags.svg', 'assets/images/ui/country-flags.LICENSE.txt')
if len(lines)!=len(names):raise SystemExit(1)
for name,line in zip(names,lines):
 parts=line.split('  ',1)
 if len(parts)!=2 or not re.fullmatch(r'[a-f0-9]{64}',parts[0]) \
  or not parts[1].endswith('/wp-content/themes/dt-the7-child/'+name) or parts[0]!=expected[name]:raise SystemExit(1)
PY
}

remote_cleanup() {
    "${petish_ssh[@]}" 'set -eu; tools="$HOME/.petish-homepage-deploy-'"$run"'";
      if test ! -e "$tools" && test ! -L "$tools"; then exit 0; fi;
      test -d "$tools" && test ! -L "$tools" && test "$(readlink -f -- "$tools")" = "$tools" && test "$(stat -c %a "$tools")" = 700 || exit 1;
      marker="$tools/.homepage-owned";
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
    export PETISH_HOMEPAGE_WORK=$work PETISH_HOMEPAGE_RUN=$run PETISH_HOMEPAGE_MODE=$mode
    export PETISH_HOMEPAGE_STATUS=$result_status PETISH_HOMEPAGE_CATEGORY=$failure_category
    export PETISH_HOMEPAGE_SOURCE=$source_sha PETISH_HOMEPAGE_STAGE_VERIFIED=$staging_source_verified
    export PETISH_HOMEPAGE_ROLLBACK_ATTEMPTED=$rollback_attempted PETISH_HOMEPAGE_ROLLBACK_VERIFIED=$rollback_verified
    python3 - <<'PY' || return 1
from datetime import datetime,timezone
import json,os,re,stat
from pathlib import Path
root=Path(os.environ['PETISH_HOMEPAGE_WORK'])
result={'status':os.environ['PETISH_HOMEPAGE_STATUS'],'category':os.environ['PETISH_HOMEPAGE_CATEGORY'],
 'mode':os.environ['PETISH_HOMEPAGE_MODE'],'run_id':os.environ['PETISH_HOMEPAGE_RUN'],
 'source_commit':os.environ['PETISH_HOMEPAGE_SOURCE'],'generated_utc':datetime.now(timezone.utc).isoformat(),
 'source_hashes':{},'staging_source_verified':os.environ['PETISH_HOMEPAGE_STAGE_VERIFIED']=='1',
 'rollback_attempted':os.environ['PETISH_HOMEPAGE_ROLLBACK_ATTEMPTED']=='1',
 'rollback_verified':os.environ['PETISH_HOMEPAGE_ROLLBACK_VERIFIED']=='1','phases':{}}
stage=os.environ.get('HOMEPAGE_PREPARATION_STAGE','unknown')
result['preparation_stage']=stage if stage in {'connection','ssh_tools','npm_tools','browser_tools','health_dependencies','complete'} else 'unknown'
manifest=root/'bundle/payload/manifest.json'
if manifest.exists():result['source_hashes']=json.loads(manifest.read_text())
for phase in ('inspect','preflight','native','cleanup','browser','cart_prepare','cart_browser','cart_cleanup','cache_preflight','apply','verify',
 'cache_queue','cache_consume','cache_verify','health','assets','commit','rollback',
 'rollback_cache_queue','rollback_cache_consume','rollback_cache_verify'):
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
        commit=$(printf 'Petish homepage checks %s\n' "$run" | git commit-tree "$tree" -p "$published_commit") || return 1
    else
        commit=$(printf 'Petish homepage checks %s\n' "$run" | git commit-tree "$tree") || return 1
    fi
    git push origin "$commit:refs/heads/automation-status/homepage-$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr" || return 1
    published_commit=$commit
    unset GIT_INDEX_FILE
    printf 'Screened homepage report: https://github.com/nkrake6/petish/tree/automation-status/homepage-%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
        printf '[Screened homepage report](https://github.com/nkrake6/petish/tree/automation-status/homepage-%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
    fi
}

publish_badge_diagnostic() {
    # Only a decoded image from the fixed public badge URL may leave private work.
    python3 - "$work/badge-response.png" "$work/badge-dimensions.json" "$run" <<'PY' \
        > "$work/badge-validation.private.stdout" 2> "$work/badge-validation.private.stderr"
import json,stat,struct,sys,zlib
from pathlib import Path
source=Path(sys.argv[1]);info=source.lstat()
if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or stat.S_IMODE(info.st_mode)!=0o600 \
 or not 33<=info.st_size<=8388608:raise ValueError()
data=source.read_bytes()
if data[:8]!=b'\x89PNG\r\n\x1a\n':raise ValueError()
offset=8;width=height=None;idat=False;ended=False
while offset+12<=len(data):
 size=struct.unpack('>I',data[offset:offset+4])[0];kind=data[offset+4:offset+8];end=offset+12+size
 if end>len(data) or zlib.crc32(data[offset+4:end-4])&0xffffffff!=struct.unpack('>I',data[end-4:end])[0]:raise ValueError()
 if offset==8:
  if kind!=b'IHDR' or size!=13:raise ValueError()
  width,height=struct.unpack('>II',data[offset+8:offset+16])
  if not 1<=width<=4096 or not 1<=height<=4096:raise ValueError()
 elif kind==b'IHDR':raise ValueError()
 if kind==b'IDAT':idat=True
 if kind in {b'acTL',b'fcTL',b'fdAT'}:raise ValueError()
 if kind==b'IEND':
  if size!=0 or end!=len(data):raise ValueError()
  ended=True
 offset=end
 if ended:break
if not ended or not idat or offset!=len(data):raise ValueError()
Path(sys.argv[2]).write_text(json.dumps({'format':'petish-homepage-badge-diagnostic-v1',
 'run_id':sys.argv[3],'width':width,'height':height},indent=2)+'\n')
PY
    local blob tree commit name
    export GIT_INDEX_FILE="$work/badge-publication.index"
    git read-tree --empty || return 1
    for name in badge-response.png badge-dimensions.json; do
        blob=$(git hash-object -w "$work/$name") || return 1
        git update-index --add --cacheinfo "100644,$blob,$name" || return 1
    done
    tree=$(git write-tree) || return 1
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
    commit=$(printf 'Inspect fixed public homepage badge %s\n' "$run" | git commit-tree "$tree") || return 1
    git push origin "$commit:refs/heads/homepage-badge-diagnostic-$run" \
        > "$work/badge-publication.private.stdout" 2> "$work/badge-publication.private.stderr" || return 1
    unset GIT_INDEX_FILE
}

finish() {
    outcome=$?
    trap - EXIT HUP INT TERM
    if [[ $rollback_needed == 1 ]]; then
        rollback_attempted=1
        if remote_phase rollback && purge_html_cache rollback; then
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
        else
            outcome=1
            failure_category=cleanup
        fi
    fi
    if [[ $ssh_ready == 1 && $cart_cleanup_needed == 1 ]]; then
        if cart_fixture_phase cleanup; then
            cart_cleanup_verified=1
        else
            outcome=1
            failure_category=cart_cleanup
        fi
    fi
    if [[ $mode == staging && $operation_verified == 1 ]] && ! verify_staging_source; then
        staging_source_verified=0
        outcome=1
        failure_category=staging_source
    fi
    # Retain exact recovery helpers and journals until rollback and fixture cleanup pass.
    if [[ $ssh_ready == 1 && $rollback_needed == 0 && ( $fixture_cleanup_needed == 0 || $fixture_cleanup_verified == 1 ) && ( $cart_cleanup_needed == 0 || $cart_cleanup_verified == 1 ) ]] && ! remote_cleanup; then
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
        printf '%s\n' 'Homepage report publication failed; private output was not published.' >&2
        outcome=1
    else
        cat "$work/publication.private.stdout"
    fi
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${HOMEPAGE_PREREQUISITES:-success} == success ]]
failure_category=source
[[ $(git rev-parse HEAD) == "$source_sha" ]]
git fetch --no-tags --depth=1 origin "$source_sha" > "$work/fetch.private.stdout" 2> "$work/fetch.private.stderr"
python3 - "$source_sha" "$work/bundle/payload" <<'PY' > "$work/payload.private.stdout" 2> "$work/payload.private.stderr"
import hashlib,json,subprocess,sys
from pathlib import Path
revision=sys.argv[1];destination=Path(sys.argv[2]);prefix='wordpress/wp-content/themes/dt-the7-child/'
names=('assets/images/ui/free-delivery-van.png', 'assets/css/home.css', 'assets/js/for-your-pet.js', 'includes/supplier-popularity.php', 'includes/for-your-pet.php', 'front-page.php', 'functions.php', 'includes/cart-badge.php', 'assets/css/cart-badge.css', 'assets/js/cart-badge.js', 'includes/header-navigation.php', 'assets/js/theme.js', 'assets/css/petish.css', 'includes/market-selector.php', 'assets/js/market-selector.js', 'assets/css/market-selector.css', 'assets/images/ui/country-flags.svg', 'assets/images/ui/country-flags.LICENSE.txt')
manifest={}
for name in names:
 content=subprocess.run(['git','show',revision+':'+prefix+name],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
 if not 0<len(content)<=(8388608 if name.endswith('.png') else 1048576):raise ValueError('Candidate size')
 target=destination/name;target.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
 with target.open('xb') as stream:stream.write(content)
 target.chmod(0o600);manifest[name]=hashlib.sha256(content).hexdigest()
with (destination/'manifest.json').open('x') as stream:stream.write(json.dumps(manifest,sort_keys=True,indent=2)+'\n')
(destination/'manifest.json').chmod(0o600)
PY
if [[ $mode == apply ]]; then
    failure_category=evidence
    python3 scripts/validate-homepage-release.py operations/homepage-release.json "$work/release-evidence.json" \
        > "$work/evidence.private.stdout" 2> "$work/evidence.private.stderr"
fi
if [[ $mode == staging ]]; then
    cp -- scripts/staging-rehearsal-remote.php "$work/bundle/driver.php"
    chmod 600 "$work/bundle/driver.php"
    for name in verify-homepage.php verify-pet-club.php for-your-pet-proof.php cart-badge-staging-proof.php email-branding-preview-remote.php; do
        cp -- "scripts/$name" "$work/bundle/$name"
        chmod 600 "$work/bundle/$name"
    done
    tar -C "$work/bundle" -cf "$work/bundle.tar" driver.php verify-homepage.php verify-pet-club.php for-your-pet-proof.php cart-badge-staging-proof.php email-branding-preview-remote.php \
        > "$work/archive.private.stdout" 2> "$work/archive.private.stderr"
else
    for name in deploy-homepage-remote.php verify-homepage.php verify-pet-club.php email-branding-preview-remote.php litespeed-html-purge.php; do
        cp -- "scripts/$name" "$work/bundle/$name"
        chmod 600 "$work/bundle/$name"
    done
    tar -C "$work/bundle" -cf "$work/bundle.tar" deploy-homepage-remote.php verify-homepage.php verify-pet-club.php email-branding-preview-remote.php litespeed-html-purge.php payload \
        > "$work/archive.private.stdout" 2> "$work/archive.private.stderr"
fi
failure_category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
ssh_ready=1
"${petish_ssh[@]}" 'set -eu; umask 077; tools="$HOME/.petish-homepage-deploy-'"$run"'";
  test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME" && test ! -e "$tools" && test ! -L "$tools" || exit 1;
  mkdir -m 700 "$tools"; printf "%s\n" '"$run"' > "$tools/.homepage-owned"; chmod 600 "$tools/.homepage-owned";
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
      php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-homepage-deploy-'"$run"'/driver.php" preflight '"$run" \
        > "$work/preflight.private.json" 2> "$work/preflight.private.stderr" || preflight_status=$?
    preflight_validation=0
    screen_phase preflight || preflight_validation=$?
    [[ $preflight_status == 0 && $preflight_validation == 0 ]]
    failure_category=native
    fixture_cleanup_needed=1
    fixture_phase verify
    failure_category=cleanup
    fixture_phase cleanup
    fixture_cleanup_verified=1
    fixture_cleanup_needed=0
    failure_category=verify
    native_verification staging
    "${petish_ssh[@]}" 'set -eu; file="$HOME/.petish-staging/rehearsal-'"$run"'/browser-input.json";
      test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file" && test "$(stat -c %a "$file")" = 600 && test "$(stat -c %h "$file")" = 1 || exit 1;
      size=$(stat -c %s "$file"); test "$size" -gt 0 && test "$size" -le 65536 || exit 1; cat "$file"' \
        > "$work/browser-input.json" 2> "$work/input.private.stderr"
    chmod 600 "$work/browser-input.json"
    python3 - "$work/browser-input.json" "$work/verify.safe.json" <<'PY' > "$work/browser-context.private.stdout" 2> "$work/browser-context.private.stderr"
import json,sys
from pathlib import Path
path=Path(sys.argv[1]);value=json.loads(path.read_text())
available=json.loads(Path(sys.argv[2]).read_text()).get('checks',{}).get('free_delivery_products_available')
if type(available) is not bool:raise SystemExit(1)
value['expected_section_present']=available
value['capture_badge_response']=True
path.write_text(json.dumps(value)+'\n')
PY
    failure_category=browser
    browser_status=0
    node tools/homepage/browser.mjs "$work/browser-input.json" "$work/browser.private.json" \
        > "$work/browser.private.stdout" 2> "$work/browser.private.stderr" || browser_status=$?
    browser_validation=0
    screen_phase browser || browser_validation=$?
    if [[ -e "$work/badge-response.png" || -L "$work/badge-response.png" ]]; then
        publish_badge_diagnostic
    fi
    [[ $browser_status == 0 && $browser_validation == 0 ]]
    failure_category=cart_prepare
    cart_cleanup_needed=1
    cart_fixture_phase prepare
    "${petish_ssh[@]}" 'set -eu; file="$HOME/.petish-staging/rehearsal-'"$run"'/cart-badge/browser-input.json";
      test -f "$file" && test ! -L "$file" && test "$(readlink -f -- "$file")" = "$file" && test "$(stat -c %a "$file")" = 600 && test "$(stat -c %h "$file")" = 1 || exit 1;
      size=$(stat -c %s "$file"); test "$size" -gt 0 && test "$size" -le 32768 || exit 1; cat "$file"' \
        > "$work/cart-browser-input.json" 2> "$work/cart-input.private.stderr"
    chmod 600 "$work/cart-browser-input.json"
    failure_category=cart_browser
    cart_status=0
    node tools/homepage/cart-badge-browser.mjs "$work/cart-browser-input.json" "$work/cart_browser.private.json" \
        > "$work/cart_browser.private.stdout" 2> "$work/cart_browser.private.stderr" || cart_status=$?
    cart_validation=0
    screen_phase cart_browser || cart_validation=$?
    [[ $cart_status == 0 && $cart_validation == 0 ]]
    failure_category=cart_cleanup
    cart_fixture_phase cleanup
    cart_cleanup_verified=1
    cart_cleanup_needed=0
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
failure_category=cache_preflight
cache_remote_phase apply inspect
failure_category=apply
rollback_needed=1
remote_phase apply
failure_category=verify
native_verification production
failure_category=html_cache
purge_html_cache apply
failure_category=health
health_status=0
python3 scripts/verify-live-email-release.py "$work/health.private.json" \
    > "$work/health.private.stdout" 2> "$work/health.private.stderr" || health_status=$?
health_validation=0
screen_phase health || health_validation=$?
[[ $health_status == 0 && $health_validation == 0 ]]
failure_category=assets
assets_status=0
python3 scripts/verify-homepage-assets.py "$work/assets.private.json" \
    > "$work/assets.private.stdout" 2> "$work/assets.private.stderr" || assets_status=$?
assets_validation=0
screen_phase assets || assets_validation=$?
[[ $assets_status == 0 && $assets_validation == 0 ]]
failure_category=commit
remote_phase commit
rollback_needed=0
operation_verified=1
