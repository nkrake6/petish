#!/usr/bin/env bash
# Explicit opt-in on existing isolated staging, after immutable code deployment.
set +x
set -Eeuo pipefail
umask 077
: "${RUNNER_TEMP:?}" "${GITHUB_SHA:?}"
[[ $GITHUB_SHA =~ ^[a-f0-9]{40}$ && ${GITHUB_REF_NAME:-} == verify-pet-chat-stream ]]
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
work=$(mktemp -d "$RUNNER_TEMP/petish-chat-stream.XXXXXXXX")
remote_work=''
finish() {
  outcome=$?
  trap - EXIT HUP INT TERM
  if [[ $remote_work =~ ^/home/u839205597/\.petish-chat-stream\.[A-Za-z0-9]+$ ]]; then
    "${petish_ssh[@]}" "test -d '$remote_work' && test ! -L '$remote_work' && test -f '$remote_work/.owned' && test ! -L '$remote_work/.owned' && test \"\$(cat '$remote_work/.owned')\" = '$GITHUB_SHA' && rm -rf -- '$remote_work'" >/dev/null 2>&1 || outcome=1
  fi
  rm -rf -- "$work"
  exit "$outcome"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
python3 scripts/sanitize-pet-chat-result.py manifest "$GITHUB_SHA" "$work/source-manifest.json"
remote_work=$("${petish_ssh[@]}" 'umask 077; mktemp -d "$HOME/.petish-chat-stream.XXXXXXXX"')
[[ $remote_work =~ ^/home/u839205597/\.petish-chat-stream\.[A-Za-z0-9]+$ ]]
"${petish_ssh[@]}" "printf '%s' '$GITHUB_SHA' > '$remote_work/.owned'; chmod 600 '$remote_work/.owned'"
for name in setup-pet-chat-stream-staging.php verify-pet-club.php email-branding-preview-remote.php; do
  "${petish_ssh[@]}" "cat > '$remote_work/$name'; chmod 600 '$remote_work/$name'" < "scripts/$name"
done
"${petish_ssh[@]}" "cat > '$remote_work/source-manifest.json'; chmod 600 '$remote_work/source-manifest.json'" < "$work/source-manifest.json"
status=0
"${petish_ssh[@]}" 'set -eu; export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1;
  for name in DB_NAME DB_HOST; do value=$(wp --path="$HOME/domains/petish.biz/public_html" --no-color config get "$name" --type=constant 2>/dev/null) || exit 1; test -n "$value" || exit 1; export "PETISH_REHEARSAL_PRODUCTION_$name=$value"; done; unset value;
  php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 '"'$remote_work/setup-pet-chat-stream-staging.php'" > "$work/result.private.json" 2> "$work/errors.private.log" || status=$?
python3 - "$work/result.private.json" "$RUNNER_TEMP/pet-chat-stream-result.json" <<'PY'
import json,sys
from pathlib import Path
data=json.loads(Path(sys.argv[1]).read_text())
if set(data)!={'status','category','checks'} or data['status'] not in {'success','failure'} or not isinstance(data['checks'],dict) or len(data['checks'])>80 or any(not isinstance(k,str) or not k.replace('_','').isalnum() or type(v) is not bool for k,v in data['checks'].items()): raise SystemExit('Invalid screened result')
out=Path(sys.argv[2]);out.write_text(json.dumps(data,sort_keys=True,indent=2)+'\n');out.chmod(0o600)
print(json.dumps(data,sort_keys=True))
PY
[[ $status == 0 ]]
