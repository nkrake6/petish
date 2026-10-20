#!/usr/bin/env bash
# SMTP password is never put in a command argument, Git object or log.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
result_file=$RUNNER_TEMP/petish-mail-result.json
printf '%s\n' '{"configured":false,"smtp_authentication_verified":false,"message_submitted":false,"failure_category":"credential_missing"}' > "$result_file"
[[ -n ${PETISH_SMTP_PASSWORD:-} ]] || exit 1
bundle=$(mktemp -d "$RUNNER_TEMP/petish-mail-bundle.XXXXXXXX")
trap 'rm -rf -- "$bundle"' EXIT
export PETISH_MAIL_BUNDLE=$bundle
python3 - <<'PY'
import json, os
from pathlib import Path
password=os.environ['PETISH_SMTP_PASSWORD']
if not 1 <= len(password.encode()) <= 2048 or any(ord(c)<32 or ord(c)==127 for c in password):
    raise SystemExit('Mailbox credential format is invalid; nothing was changed.')
path=Path(os.environ['PETISH_MAIL_BUNDLE'])/'credential.json'
with path.open('x') as target:
    target.write(json.dumps({'host':'smtp.hostinger.com','port':465,'encryption':'smtps','username':'administrator@petish.biz','password':password}))
path.chmod(0o600)
PY
unset PETISH_SMTP_PASSWORD PETISH_MAIL_BUNDLE
cp scripts/configure-petish-mail-remote.sh scripts/configure-petish-mail.php scripts/probe-petish-smtp.php scripts/inspect-email-settings-remote.sh "$bundle/"
cp wordpress/wp-content/mu-plugins/petish-mailer.php "$bundle/petish-mailer.php"
source scripts/hostinger-ssh-common.sh
printf '%s\n' '{"configured":false,"smtp_authentication_verified":false,"message_submitted":false,"failure_category":"ssh_connection"}' > "$result_file"
petish_prepare_ssh
status=0
# Fixed bundle members only; the receiver creates an owner-only directory.
tar -C "$bundle" -cf - credential.json configure-petish-mail-remote.sh configure-petish-mail.php probe-petish-smtp.php inspect-email-settings-remote.sh petish-mailer.php | \
    "${petish_ssh[@]}" 'set -eu; umask 077; stage=$(mktemp -d "$HOME/.petish-mail-config.XXXXXXXX"); trap '\''if [ ! -f "$stage/.preserve-for-rollback" ]; then rm -rf -- "$stage"; fi'\'' EXIT HUP INT TERM; tar -xf - -C "$stage"; trap - EXIT HUP INT TERM; exec bash "$stage/configure-petish-mail-remote.sh" "$stage"' \
    > "$RUNNER_TEMP/petish-mail-private.stdout" 2> "$RUNNER_TEMP/petish-mail-private.stderr" || status=$?
export PETISH_MAIL_EXIT=$status
validation=0
python3 - <<'PY' || validation=$?
import json, os, re
from pathlib import Path
root=Path(os.environ['RUNNER_TEMP']); result={'configured':False,'smtp_authentication_verified':False,'message_submitted':False,'failure_category':'ssh_connection'}
try:
    source=root/'petish-mail-private.stdout'
    if source.stat().st_size>8192: raise ValueError()
    raw=json.loads(source.read_text())
    if not isinstance(raw,dict): raise ValueError()
    if os.environ['PETISH_MAIL_EXIT']=='0' and raw=={'configured':True,'smtp_authentication_verified':True,'tls_peer_verification':True,'message_submitted':False}:
        result=raw
    elif raw.get('failure_category') in {'configuration','runtime','smtp_authentication','credential_missing','ssh_connection','existing_mailer','metadata','filesystem','deployment','rollback','unknown'}:
        result['failure_category']=raw['failure_category']
        code=raw.get('smtp_reply_code')
        if type(code) is int and 100<=code<=599: result['smtp_reply_code']=code
        enhanced=raw.get('smtp_enhanced_code')
        if isinstance(enhanced,str) and re.fullmatch(r'[245]\.[0-9]{1,3}\.[0-9]{1,3}',enhanced): result['smtp_enhanced_code']=enhanced
        if raw.get('error_stage') in {'tls_connection','smtp_authentication','runtime','configuration'}: result['error_stage']=raw['error_stage']
except (OSError,ValueError,TypeError): pass
(root/'petish-mail-result.json').write_text(json.dumps(result,indent=2)+'\n')
if result.get('configured') is not True: raise SystemExit(1)
PY
cat "$result_file"
if [[ $validation != 0 && $status == 0 ]]; then status=1; fi
exit "$status"
