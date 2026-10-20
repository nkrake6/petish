#!/usr/bin/env bash
# Only ciphertext and an ephemeral public key may enter Git. No retry of send.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
[[ ${MAIL_TEST_MODE:-} == prepare || ${MAIL_TEST_MODE:-} == send ]]
work=$RUNNER_TEMP/petish-mail-test
mkdir -m 700 "$work"
result=$RUNNER_TEMP/petish-mail-test-result.json
printf '%s\n' '{"test_message_submitted":false,"failure_category":"ssh_connection"}' > "$result"
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
if [[ $MAIL_TEST_MODE == prepare ]]; then
    token=$(python3 -c 'import secrets; print(secrets.token_hex(16))')
    [[ $token =~ ^[a-f0-9]{32}$ ]]
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 > "$work/key.pem" 2> "$work/private.stderr"
    openssl pkey -in "$work/key.pem" -pubout > "$work/public.pem" 2>> "$work/private.stderr"
    "${petish_ssh[@]}" 'umask 077; stage="$HOME/.petish-mail-test-'"$token"'"; test ! -e "$stage" && test ! -L "$stage" || exit 1; mkdir -m 700 "$stage"; cat > "$stage/key.pem"; chmod 600 "$stage/key.pem"' \
        < "$work/key.pem" > "$work/private.stdout" 2>> "$work/private.stderr"
    export PETISH_MAIL_TEST_TOKEN=$token
    python3 - <<'PY'
import json, os
from pathlib import Path
root=Path(os.environ['RUNNER_TEMP'])
key=(root/'petish-mail-test/public.pem').read_text()
if not key.startswith('-----BEGIN PUBLIC KEY-----\n') or len(key)>1024:
    raise SystemExit('Invalid ephemeral public key.')
(root/'petish-mail-test-result.json').write_text(json.dumps({'test_message_submitted':False,'request_key_prepared':True,'request_token':os.environ['PETISH_MAIL_TEST_TOKEN'],'public_key_pem':key},indent=2)+'\n')
PY
else
    python3 - <<'PY'
import base64, json, os, re
from pathlib import Path
root=Path(os.environ['RUNNER_TEMP'])/'petish-mail-test'
path=Path('operations/petish-mail-test-envelope.json')
if path.is_symlink() or not path.is_file() or path.stat().st_size>4096:
    raise SystemExit('An encrypted one-use test request is required.')
value=json.loads(path.read_text())
if not isinstance(value,dict) or set(value)!={'token','encrypted_recipient_base64'} or not isinstance(value['token'],str) or not re.fullmatch('[a-f0-9]{32}',value['token']):
    raise SystemExit('Invalid encrypted test request.')
try: ciphertext=base64.b64decode(value['encrypted_recipient_base64'],validate=True)
except (ValueError,TypeError): raise SystemExit('Invalid encrypted test request.')
if len(ciphertext)!=256: raise SystemExit('Invalid encrypted test request.')
(root/'recipient.enc').write_bytes(ciphertext)
(root/'token').write_text(value['token'])
PY
    token=$(cat "$work/token")
    [[ $token =~ ^[a-f0-9]{32}$ ]]
    cp scripts/send-petish-test-mail.php "$work/send-test.php"
    status=0
    tar -C "$work" -cf - recipient.enc send-test.php | \
        "${petish_ssh[@]}" 'set -eu; umask 077; stage="$HOME/.petish-mail-test-'"$token"'"; test -d "$stage" && test ! -L "$stage" && test -f "$stage/key.pem" && test ! -L "$stage/key.pem" || exit 1; trap '\''rm -rf -- "$stage"'\'' EXIT; tar -xf - -C "$stage"; chmod 600 "$stage/recipient.enc"; php -d display_errors=0 -d log_errors=0 "$stage/send-test.php" "$HOME/domains/petish.biz/public_html" "$stage/key.pem" "$stage/recipient.enc" --send-one-test' \
        > "$work/private.stdout" 2> "$work/private.stderr" || status=$?
    export PETISH_TEST_EXIT=$status
    python3 - <<'PY'
import json, os
from pathlib import Path
root=Path(os.environ['RUNNER_TEMP']); value={'test_message_submitted':False,'failure_category':'smtp_submission'}
try:
    source=root/'petish-mail-test/private.stdout'
    if source.stat().st_size>4096: raise ValueError()
    raw=json.loads(source.read_text())
    if os.environ['PETISH_TEST_EXIT']=='0' and raw=={'test_message_submitted':True}: value=raw
    elif isinstance(raw,dict) and raw.get('error_category') in {'configuration','runtime','smtp_submission'}: value['failure_category']=raw['error_category']
except (OSError,ValueError,TypeError): pass
(root/'petish-mail-test-result.json').write_text(json.dumps(value,indent=2)+'\n')
if value['test_message_submitted'] is not True: raise SystemExit(1)
PY
fi
