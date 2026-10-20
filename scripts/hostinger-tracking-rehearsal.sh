#!/usr/bin/env bash
# Exercise native tracking on private staging; publish finite checks only.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
: "${GITHUB_RUN_ID:?}"
: "${GITHUB_RUN_ATTEMPT:?}"
: "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
[[ $run =~ ^[1-9][0-9]*-[1-9][0-9]*$ && $GITHUB_SHA =~ ^[a-f0-9]{40}$ ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-tracking-rehearsal.XXXXXXXX")
mkdir -m 700 "$work/public"
ssh_ready=0
rehearsal_verified=0
rehearsal_status=unavailable
failure_category=prerequisites

reviewed_file_digest() {
    local file=$1 digest
    [[ -f $file && ! -L $file && $(stat -c %h -- "$file") == 1 ]] || return 1
    local size
    size=$(stat -c %s -- "$file")
    [[ $size =~ ^[0-9]+$ && $size -gt 0 && $size -le 1048576 ]] || return 1
    digest=$(sha256sum -- "$file") || return 1
    digest=${digest%% *}
    [[ $digest =~ ^[a-f0-9]{64}$ ]] || return 1
    printf '%s' "$digest"
}

remote_cleanup() {
    "${petish_ssh[@]}" 'set -eu; base="$HOME/.petish-staging"; tools="$base/tracking-rehearsal-tools-'"$run"'"; work="$base/rehearsal-'"$run"'"; plugins="$HOME/domains/staging.petish.biz/public_html/wp-content/plugins"; plugin="$plugins/woo-advanced-shipment-tracking";
      test -d "$base" && test ! -L "$base" && test "$(readlink -f -- "$base")" = "$base" || exit 1;
      if test ! -e "$tools" && test ! -L "$tools" && test ! -e "$work" && test ! -L "$work"; then exit 0; fi;
      marker="$tools/.tracking-rehearsal-owned";
      test -d "$tools" && test ! -L "$tools" && test "$(readlink -f -- "$tools")" = "$tools" && test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = '"$run"' || exit 1;
      if test -e "$plugin" || test -L "$plugin"; then
        test -d "$plugins" && test ! -L "$plugins" && test "$(readlink -f -- "$plugins")" = "$plugins" || exit 1;
        test -d "$plugin" && test ! -L "$plugin" && test "$(readlink -f -- "$plugin")" = "$plugin" || exit 1;
        marker="$plugin/.petish-tracking-rehearsal-owned";
        test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = '"$run"' || exit 1;
        unsafe=$(find "$plugin" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit) || exit 1;
        test -z "$unsafe" || exit 1;
        rm -rf -- "$plugin";
      fi;
      if test -e "$work" || test -L "$work"; then test -d "$work" && test ! -L "$work" && test "$(readlink -f -- "$work")" = "$work" || exit 1; fi;
      rm -rf -- "$work" "$tools"' \
        > "$work/cleanup.private.stdout" 2> "$work/cleanup.private.stderr"
}

publish_result() {
    export PETISH_TRACKING_WORK=$work PETISH_TRACKING_RUN=$run
    export PETISH_TRACKING_STATUS=$rehearsal_status PETISH_TRACKING_CATEGORY=$failure_category
    python3 - <<'PY' || return 1
import importlib.util,json,os
from pathlib import Path
root=Path(os.environ['PETISH_TRACKING_WORK'])
spec=importlib.util.spec_from_file_location('safe','scripts/sanitize-rehearsal-result.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
raw=module.sanitize(root/'rehearsal.private.json')
safety={'environment_staging','woocommerce_test_mode','wcpay_dev_mode','account_is_live',
 'guard_runtime_verified','suppliers_blocked','mail_capture_verified','webhooks_blocked',
 'database_separate','runtime_database_staging','only_allowlisted_plugins','no_active_webhooks',
 'all_http_forced_blocked','paths_verified','source_guard_sha_verified'}
checks=raw.get('checks',{})
result={'status':os.environ['PETISH_TRACKING_STATUS'],'category':os.environ['PETISH_TRACKING_CATEGORY'],
 'run_id':os.environ['PETISH_TRACKING_RUN'],'source_commit':os.environ['GITHUB_SHA'],
 'ast_source_commit':'c22a8dad01045779ec247fe830943eaddeaa266f',
 'checks':{key:value for key,value in checks.items()
   if (key in safety or key.startswith('tracking_')) and type(value) is bool}}
if raw.get('category') in module.CATEGORIES|{'safe_result_unavailable'}:result['driver_category']=raw['category']
if raw.get('validation_stage') in {'input','schema','status_category','run_id','checks','account','native','structure','provider_hosts','phase'}:
 result['validation_stage']=raw['validation_stage']
(root/'public/status.json').write_text(json.dumps(result,indent=2)+'\n')
PY
    export GIT_INDEX_FILE="$work/publication.index"
    git read-tree --empty || return 1
    local blob tree commit
    blob=$(git hash-object -w "$work/public/status.json") || return 1
    git update-index --add --cacheinfo "100644,$blob,status.json" || return 1
    tree=$(git write-tree) || return 1
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
    commit=$(printf 'Petish private staging tracking rehearsal %s\n' "$run" | git commit-tree "$tree") || return 1
    git push origin "$commit:refs/heads/tracking-rehearsal/$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr" || return 1
    unset GIT_INDEX_FILE
    printf 'Tracking rehearsal result: https://github.com/nkrake6/petish/tree/tracking-rehearsal/%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
        printf '[Screened private staging tracking checks](https://github.com/nkrake6/petish/tree/tracking-rehearsal/%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
    fi
}

finish() {
    outcome=$?
    trap - EXIT HUP INT TERM
    if [[ $ssh_ready == 1 ]] && ! remote_cleanup; then
        outcome=1
        failure_category=cleanup
    fi
    if [[ $outcome == 0 && $rehearsal_verified == 1 ]]; then
        rehearsal_status=success
        failure_category=complete
    else
        outcome=1
    fi
    if ! publish_result > "$work/publication.private.stdout" 2> "$work/publication.private.stderr"; then
        printf '%s\n' 'Tracking rehearsal publication failed; no private output was published.' >&2
        outcome=1
    else
        cat "$work/publication.private.stdout"
    fi
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${TRACKING_REHEARSAL_PREREQUISITES:-success} == success ]]
failure_category=source
[[ $(git rev-parse HEAD) == "$GITHUB_SHA" ]]

# Only the immutable, reviewed official release is prepared for the server.
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 --retry 3 \
    --max-time 120 --max-filesize 2097152 \
    'https://github.com/zorem/woo-advanced-shipment-tracking/archive/c22a8dad01045779ec247fe830943eaddeaa266f.zip' \
    -o "$work/ast.zip" > "$work/download.private.stdout" 2> "$work/download.private.stderr"
python3 - "$work" <<'PY' > "$work/archive.private.stdout" 2> "$work/archive.private.stderr"
import hashlib,io,json,re,stat,sys,tarfile,zipfile
from pathlib import Path,PurePosixPath
root=Path(sys.argv[1]);data=(root/'ast.zip').read_bytes()
if len(data)!=1779522 or hashlib.sha256(data).hexdigest()!='353805462f5d88f064fb032c016fb947c3610531d0b59eae026d808be86f3736':
 raise ValueError('Unreviewed tracking source')
prefix='woo-advanced-shipment-tracking-c22a8dad01045779ec247fe830943eaddeaa266f/'
manifest={};seen=set();total=0
with zipfile.ZipFile(io.BytesIO(data)) as archive,tarfile.open(root/'ast.tar.gz','w:gz') as output:
 for entry in archive.infolist():
  if not entry.filename.startswith(prefix):raise ValueError('Archive root')
  name=entry.filename[len(prefix):].rstrip('/')
  if not name:
   if not entry.is_dir():raise ValueError('Archive root type')
   continue
  path=PurePosixPath(name)
  if str(path)!=name or path.is_absolute() or any(part in {'','..','.'} for part in path.parts) \
    or not re.fullmatch(r'[A-Za-z0-9_./-]{1,300}',name) or name in seen \
    or name=='.petish-tracking-rehearsal-owned':raise ValueError('Archive path')
  seen.add(name);mode=(entry.external_attr>>16)&0xffff
  if stat.S_ISLNK(mode) or (stat.S_IFMT(mode) not in {0,stat.S_IFREG,stat.S_IFDIR}):raise ValueError('Archive type')
  item=tarfile.TarInfo(name);item.uid=item.gid=0;item.mtime=0
  if entry.is_dir():
   if mode and stat.S_IFMT(mode) not in {0,stat.S_IFDIR}:raise ValueError('Directory type')
   item.type=tarfile.DIRTYPE;item.mode=0o700;output.addfile(item)
  else:
   if mode and stat.S_IFMT(mode) not in {0,stat.S_IFREG}:raise ValueError('File type')
   if not 0<=entry.file_size<=2097152:raise ValueError('Archive file size')
   total+=entry.file_size
   if total>20971520:raise ValueError('Archive size')
   contents=archive.read(entry)
   if len(contents)!=entry.file_size:raise ValueError('Archive file length')
   manifest[name]=hashlib.sha256(contents).hexdigest()
   item.size=len(contents);item.mode=0o600;output.addfile(item,io.BytesIO(contents))
if not 1<=len(manifest)<=2000 or 'woocommerce-advanced-shipment-tracking.php' not in manifest:raise ValueError('Archive inventory')
encoded=(json.dumps(manifest,sort_keys=True,indent=2)+'\n').encode()
if len(encoded)>1048576:raise ValueError('Manifest size')
(root/'ast-manifest.json').write_bytes(encoded)
PY

source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
ssh_ready=1
failure_category=transport
driver_digest=$(reviewed_file_digest scripts/staging-rehearsal-remote.php)
"${petish_ssh[@]}" 'set -eu; umask 077; base="$HOME/.petish-staging"; tools="$base/tracking-rehearsal-tools-'"$run"'"; work="$base/rehearsal-'"$run"'"; plugins="$HOME/domains/staging.petish.biz/public_html/wp-content/plugins"; plugin="$plugins/woo-advanced-shipment-tracking";
  test -d "$base" && test ! -L "$base" && test "$(readlink -f -- "$base")" = "$base" && test "$(stat -c %a "$base")" = 700 || exit 1;
  test -d "$plugins" && test ! -L "$plugins" && test "$(readlink -f -- "$plugins")" = "$plugins" || exit 1;
  test ! -e "$tools" && test ! -L "$tools" && test ! -e "$work" && test ! -L "$work" && test ! -e "$plugin" && test ! -L "$plugin" || exit 1;
  mkdir -m 700 "$tools"; printf "%s\n" '"$run"' > "$tools/.tracking-rehearsal-owned";
  cat > "$tools/driver.php"; chmod 600 "$tools/driver.php" "$tools/.tracking-rehearsal-owned";
  actual=$(sha256sum -- "$tools/driver.php"); actual=${actual%% *}; test "$actual" = '"$driver_digest"' || exit 1' \
    < scripts/staging-rehearsal-remote.php > "$work/upload-driver.private.stdout" 2> "$work/upload-driver.private.stderr"
for name in tracking-rehearsal-remote.php tracking-rehearsal-bootstrap.php petish-tracking-receiver-guard.php ast-manifest.json; do
    if [[ $name == ast-manifest.json ]]; then source_file="$work/$name"; else source_file="scripts/$name"; fi
    source_digest=$(reviewed_file_digest "$source_file")
    "${petish_ssh[@]}" 'set -eu; umask 077; file="$HOME/.petish-staging/tracking-rehearsal-tools-'"$run"'/'"$name"'"; test ! -e "$file" && test ! -L "$file" || exit 1; cat > "$file"; chmod 600 "$file";
      actual=$(sha256sum -- "$file"); actual=${actual%% *}; test "$actual" = '"$source_digest"' || exit 1' \
        < "$source_file" > "$work/upload-$name.private.stdout" 2> "$work/upload-$name.private.stderr"
done
"${petish_ssh[@]}" 'set -eu; umask 077; plugins="$HOME/domains/staging.petish.biz/public_html/wp-content/plugins"; plugin="$plugins/woo-advanced-shipment-tracking";
  test -d "$plugins" && test ! -L "$plugins" && test "$(readlink -f -- "$plugins")" = "$plugins" && test ! -e "$plugin" && test ! -L "$plugin" || exit 1;
  mkdir -m 700 "$plugin"; printf "%s\n" '"$run"' > "$plugin/.petish-tracking-rehearsal-owned"; chmod 600 "$plugin/.petish-tracking-rehearsal-owned";
  tar --extract --gzip --no-same-owner --no-same-permissions --directory "$plugin"' \
    < "$work/ast.tar.gz" > "$work/upload-plugin.private.stdout" 2> "$work/upload-plugin.private.stderr"
failure_category=rehearsal
"${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
  for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value;
  php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-staging/tracking-rehearsal-tools-'"$run"'/driver.php" tracking-rehearsal '"$run" \
    > "$work/rehearsal.private.json" 2> "$work/rehearsal.private.stderr"
failure_category=report_validation
python3 - "$work/rehearsal.private.json" <<'PY' > "$work/validation.private.stdout" 2> "$work/validation.private.stderr"
import importlib.util,sys
spec=importlib.util.spec_from_file_location('safe','scripts/sanitize-rehearsal-result.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
report=module.sanitize(sys.argv[1])
if not module.tracking_rehearsal_passed(report):
 raise SystemExit('The isolated native tracking rehearsal did not pass its required checks.')
PY
rehearsal_verified=1
