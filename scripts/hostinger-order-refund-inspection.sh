#!/usr/bin/env bash
# Read-only fixed production inspection. Only public keys/ciphertext reach Git.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
source_sha=$GITHUB_SHA
[[ $run =~ ^[1-9][0-9]{0,31}-[1-9][0-9]{0,8}$ && $source_sha =~ ^[a-f0-9]{40}$ ]]
case ${GITHUB_REF_NAME:-} in
 prepare-order-refund-inspection) phase=prepare ;;
 inspect-order-refund) phase=inspect ;;
 *) exit 1 ;;
esac
work=$(mktemp -d "$RUNNER_TEMP/petish-order-refund.XXXXXXXX")
mkdir -m 700 "$work/bundle" "$work/public"
ssh_ready=0
operation_verified=0
cleaned=0
prepare_run=$run
category=prerequisites

remote_cleanup() {
 "${petish_ssh[@]}" 'set -eu; umask 077;
  for kind in inspect request; do
   if test "$kind" = inspect; then id='"$run"'; else id='"$prepare_run"'; fi;
   root="$HOME/.petish-order-refund-$kind-$id";
   if test ! -e "$root" && test ! -L "$root"; then continue; fi;
   test -d "$root" && test ! -L "$root" && test "$(readlink -f -- "$root")" = "$root" && test "$(stat -c %a "$root")" = 700 || exit 1;
   marker="$root/.owned"; test -f "$marker" && test ! -L "$marker" && test "$(stat -c %h "$marker")" = 1 && test "$(stat -c %a "$marker")" = 600 && test "$(cat "$marker")" = "$id" || exit 1;
   if test "$kind" = request; then
    if test -e "$root/.consumed" || test -L "$root/.consumed"; then
     test -f "$root/.consumed" && test ! -L "$root/.consumed" && test "$(stat -c %h "$root/.consumed")" = 1 && test "$(cat "$root/.consumed")" = '"$run"' || exit 1;
    else (set -C; printf "%s" '"$run"' > "$root/.consumed") || exit 1; chmod 600 "$root/.consumed"; fi;
   fi;
   unsafe=$(find "$root" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit) || exit 1;
   test -z "$unsafe" && test "$(find "$root" -xdev -type f | wc -l)" -le 20 || exit 1;
   rm -rf -- "$root";
  done' > "$work/cleanup.private.stdout" 2> "$work/cleanup.private.stderr"
}

publish() {
 export ORI_WORK=$work ORI_RUN=$run ORI_SOURCE=$source_sha ORI_PHASE=$phase ORI_CATEGORY=$category
 export ORI_VERIFIED=$operation_verified ORI_CLEANED=$cleaned
 python3 - <<'PY' > "$work/publish-build.private.stdout" 2> "$work/publish-build.private.stderr" || return 1
import importlib.util,json,os
from pathlib import Path
spec=importlib.util.spec_from_file_location('envelope','scripts/order-refund-envelope.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
root=Path(os.environ['ORI_WORK']);phase=os.environ['ORI_PHASE'];category=os.environ['ORI_CATEGORY']
if category not in {'prerequisites','source','transport','request','collection','encryption','cleanup','complete'}:raise ValueError()
ok=os.environ['ORI_VERIFIED']=='1' and category=='complete' and (phase=='prepare' or os.environ['ORI_CLEANED']=='1')
value={'format':'petish-order-refund-status-v1','phase':phase,'run_id':os.environ['ORI_RUN'],'source_commit':os.environ['ORI_SOURCE'],
 'status':'success' if ok else 'failure','category':category,'temporary_files_removed':os.environ['ORI_CLEANED']=='1',
 'request_key_retained':ok and phase=='prepare','prepared':None,'encrypted_result_present':ok and phase=='inspect','failure_code':None}
if not ok and category=='collection':
 errors={'input','paths','database_driver','database_configuration','database_connection','query','bounds','storage_layout','amount','date','metadata','metadata_missing_order_total','metadata_missing_order_currency','metadata_missing_refund_amount','metadata_duplicate_conflict','metadata_value','storage_configuration','storage_drift','report','output','inspection_failed','origin_mismatch'}
 path=root/'report.private.stderr'
 if path.exists() and 0<path.stat().st_size<=16384:
  lines=m.read_regular(path,16384,True).decode('utf-8','replace').splitlines()
  known=[line for line in lines if line in errors]
  if known:value['failure_code']=known[-1]
if ok and phase=='prepare':
 value['prepared']=m.validate_prepared(m.load(root/'prepared.private.json',16384,True),value['run_id'],value['source_commit'])
elif ok:
 m.validate_envelope(root/'public/order-refund.encrypted.json',Path('operations/order-refund-recipient.pem'),value['run_id'],value['source_commit'])
m.write(root/'public/status.json',value)
PY
 local blob tree commit
 export GIT_INDEX_FILE="$work/publication.index"
 git read-tree --empty || return 1
 for name in status.json; do
  blob=$(git hash-object -w "$work/public/$name") || return 1
  git update-index --add --cacheinfo "100644,$blob,$name" || return 1
 done
 if [[ $phase == inspect && $operation_verified == 1 && $cleaned == 1 && $category == complete ]]; then
  blob=$(git hash-object -w "$work/public/order-refund.encrypted.json") || return 1
  git update-index --add --cacheinfo "100644,$blob,order-refund.encrypted.json" || return 1
 fi
 tree=$(git write-tree) || return 1
 export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
 export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com' GIT_COMMITTER_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
 commit=$(printf 'Private order inspection %s\n' "$run" | git commit-tree "$tree") || return 1
 git push origin "$commit:refs/heads/automation-status/order-refund-$run" > "$work/push.private.stdout" 2> "$work/push.private.stderr" || return 1
 unset GIT_INDEX_FILE
 printf 'Encrypted order inspection result: https://github.com/nkrake6/petish/tree/automation-status/order-refund-%s\n' "$run"
}

finish() {
 local outcome=$?
 trap - EXIT HUP INT TERM
 if [[ $ssh_ready == 1 && ( $phase == inspect || $operation_verified != 1 ) ]]; then
  if remote_cleanup; then cleaned=1; else category=cleanup; outcome=1; fi
 fi
 if [[ $outcome == 0 && $operation_verified == 1 && ( $phase == prepare || $cleaned == 1 ) ]]; then category=complete; else outcome=1; fi
 if ! publish; then
  printf '%s\n' 'Private inspection publication failed; no private output was printed.' >&2; outcome=1
  if [[ $phase == prepare && $ssh_ready == 1 && $operation_verified == 1 ]]; then remote_cleanup || true; fi
 fi
 rm -rf -- "$work"
 exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${ORDER_REFUND_PREREQUISITES:-failure} == success ]]
category=source
[[ $(git rev-parse HEAD) == "$source_sha" ]]
cp scripts/order-refund-request.php "$work/bundle/"
chmod 600 "$work/bundle/order-refund-request.php"
if [[ $phase == prepare ]]; then
 openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 > "$work/bundle/request-key.pem" 2> "$work/keygen.private.stderr"
 openssl pkey -in "$work/bundle/request-key.pem" -pubout > "$work/bundle/request-public.pem" 2> "$work/public-key.private.stderr"
 chmod 600 "$work/bundle/"*.pem
 names=(order-refund-request.php request-key.pem request-public.pem)
else
 category=request
 python3 - "$work/bundle" <<'PY' > "$work/request.private.stdout" 2> "$work/request.private.stderr"
import base64,importlib.util,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('envelope','scripts/order-refund-envelope.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
root=Path(sys.argv[1]);public=Path('operations/order-refund-recipient.pem')
v=m.request_payload(m.load(Path('operations/order-refund-inspection.json'),4096),public)
m.write(root/'request-envelope.json',v)
for name,data in [('selector.enc',base64.b64decode(v['encrypted_selector_base64'],validate=True)),('report-public.pem',m.read_regular(public,8192)),('prepare-run',v['prepare_run_id'].encode())]:
 with (root/name).open('xb') as f:f.write(data)
 (root/name).chmod(0o600)
PY
 prepare_run=$(cat "$work/bundle/prepare-run")
 [[ $prepare_run =~ ^[1-9][0-9]{0,31}-[1-9][0-9]{0,8}$ && $prepare_run != "$run" ]]
 cp scripts/inspect-order-refund.php "$work/bundle/"
 chmod 600 "$work/bundle/inspect-order-refund.php"
 names=(order-refund-request.php inspect-order-refund.php request-envelope.json selector.enc report-public.pem)
fi
(cd "$work/bundle" && sha256sum "${names[@]}" > tools.sha256)
chmod 600 "$work/bundle/tools.sha256"
tar -C "$work/bundle" -cf "$work/bundle.tar" "${names[@]}" tools.sha256 > "$work/tar.private.stdout" 2> "$work/tar.private.stderr"
category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh > "$work/ssh.private.stdout" 2> "$work/ssh.private.stderr"
ssh_ready=1
kind=request
[[ $phase == prepare ]] || kind=inspect
"${petish_ssh[@]}" 'set -eu; umask 077; root="$HOME/.petish-order-refund-'"$kind-$run"'";
 test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME" && test ! -e "$root" && test ! -L "$root" || exit 1;
 mkdir -m 700 "$root"; printf "%s" '"$run"' > "$root/.owned"; chmod 600 "$root/.owned";
 tar --extract --no-same-owner --no-same-permissions --directory "$root";
 cd "$root"; sha256sum --check --strict tools.sha256' \
 < "$work/bundle.tar" > "$work/upload.private.stdout" 2> "$work/upload.private.stderr"
if [[ $phase == prepare ]]; then
 "${petish_ssh[@]}" 'set -eu; php -d display_errors=0 -d log_errors=0 "$HOME/.petish-order-refund-request-'"$run"'/order-refund-request.php" prepare '"$run $source_sha" \
  > "$work/prepared.private.json" 2> "$work/prepared.private.stderr"
 chmod 600 "$work/prepared.private.json"
 python3 - "$work/prepared.private.json" "$run" "$source_sha" <<'PY' > "$work/prepared-check.private.stdout" 2> "$work/prepared-check.private.stderr"
import importlib.util,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('envelope','scripts/order-refund-envelope.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
m.validate_prepared(m.load(Path(sys.argv[1]),16384,True),sys.argv[2],sys.argv[3])
PY
else
 category=collection
 "${petish_ssh[@]}" 'set -eu; umask 077; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
  tools="$HOME/.petish-order-refund-inspect-'"$run"'"; key="$HOME/.petish-order-refund-request-'"$prepare_run"'";
  php -d display_errors=0 -d log_errors=0 "$tools/order-refund-request.php" claim '"$run $prepare_run"';
  command -v openssl >/dev/null;
  openssl pkeyutl -decrypt -inkey "$key/request-key.pem" -in "$tools/selector.enc" -out "$tools/selector.private.json" -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256;
  chmod 600 "$tools/selector.private.json";
  php -d display_errors=0 -d log_errors=0 "$tools/order-refund-request.php" selector '"$run $prepare_run"';
  site="$HOME/domains/petish.biz/public_html";
  for constant in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
   value=$(wp --path="$site" --no-color config get "$constant" --type=constant 2>/dev/null) || exit 1; test -n "$value" || exit 1;
   export "PETISH_$constant=$value";
  done; unset value;
  prefix=$(wp --path="$site" --no-color config get table_prefix --type=variable 2>/dev/null) || exit 1;
  export PETISH_DB_PREFIX=$prefix;
  flags=$(wp --path="$site" --no-color config get MYSQL_CLIENT_FLAGS --type=constant 2>/dev/null) || flags=0;
  export PETISH_DB_CLIENT_FLAGS=$flags; unset flags prefix;
  php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$tools/inspect-order-refund.php" "$tools/request.json" "$tools/report.private.json";
  test -f "$tools/report.private.json" && test ! -L "$tools/report.private.json" && test "$(stat -c %h "$tools/report.private.json")" = 1 && test "$(stat -c %a "$tools/report.private.json")" = 600 && test "$(stat -c %s "$tools/report.private.json")" -le 65536 || exit 1;
  cat "$tools/report.private.json"' > "$work/report.private.json" 2> "$work/report.private.stderr"
 chmod 600 "$work/report.private.json"
 category=encryption
 python3 scripts/order-refund-envelope.py encrypt "$work/report.private.json" operations/order-refund-recipient.pem "$work/public/order-refund.encrypted.json" "$run" "$source_sha" \
  > "$work/encryption.private.stdout" 2> "$work/encryption.private.stderr"
fi
operation_verified=1
