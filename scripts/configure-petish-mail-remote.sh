#!/usr/bin/env bash
# Runs only inside the private, runner-created upload directory.
set +x
set -euo pipefail
umask 077
stage=${1:?}
[[ $stage == "$HOME"/.petish-mail-config.* && ${stage##*/} =~ ^\.petish-mail-config\.[A-Za-z0-9]+$ ]] || exit 1
committed=0
printf '%s\n' '{"configured":false,"smtp_authentication_verified":false,"message_submitted":false,"failure_category":"unknown"}' > "$stage/result.json"
cleanup() {
    result=$?
    trap - EXIT HUP INT TERM
    preserve=0
    if [[ $committed != 1 ]]; then
        if ! php "$stage/configure-petish-mail.php" rollback "$stage" > "$stage/rollback.json" 2>/dev/null; then
            result=1
            preserve=1
            # Keep owner-only snapshots if a concurrent change prevents rollback.
            : > "$stage/.preserve-for-rollback"
            printf '%s\n' '{"configured":false,"smtp_authentication_verified":false,"message_submitted":false,"failure_category":"rollback"}' > "$stage/result.json"
        fi
    fi
    cat "$stage/result.json"
    if [[ $preserve != 1 ]]; then rm -rf -- "$stage"; fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
if ! bash "$stage/inspect-email-settings-remote.sh" > "$stage/metadata.json" 2> "$stage/metadata.stderr"; then
    printf '%s\n' '{"configured":false,"smtp_authentication_verified":false,"message_submitted":false,"failure_category":"metadata"}' > "$stage/result.json"
    exit 1
fi
for file in petish-mailer.php probe-petish-smtp.php configure-petish-mail.php; do
    if ! php -l "$stage/$file" > /dev/null 2>&1; then
        printf '%s\n' '{"configured":false,"smtp_authentication_verified":false,"message_submitted":false,"failure_category":"runtime"}' > "$stage/result.json"
        exit 1
    fi
done
if ! php "$stage/configure-petish-mail.php" prepare "$stage" > "$stage/prepare.json"; then
    cp "$stage/prepare.json" "$stage/result.json"
    exit 1
fi
candidate=$(php "$stage/configure-petish-mail.php" candidate "$stage")
if ! php "$stage/probe-petish-smtp.php" "$candidate" "$HOME/domains/petish.biz/public_html" "$stage/petish-mailer.php" > "$stage/probe.json" 2> "$stage/probe.stderr"; then
    printf '%s\n' '{"configured":false,"smtp_authentication_verified":false,"message_submitted":false,"failure_category":"smtp_authentication"}' > "$stage/result.json"
    exit 1
fi
php "$stage/configure-petish-mail.php" install "$stage" > "$stage/result.json"
committed=1
