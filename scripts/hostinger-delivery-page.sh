#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
[[ $run =~ ^[1-9][0-9]*-[1-9][0-9]*$ && $GITHUB_SHA =~ ^[a-f0-9]{40}$ && ${DELIVERY_PAGE_MODE:-inspect} == inspect ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-delivery-page.XXXXXXXX")
mkdir -m 700 "$work/bundle" "$work/public"
connected=0
category=prerequisites
finish() {
    local outcome=$? blob tree commit
    trap - EXIT HUP INT TERM
    if [[ $connected == 1 ]]; then
        "${petish_ssh[@]}" 'set -eu; dir="$HOME/.petish-delivery-page-'"$run"'";
          if test ! -e "$dir" && test ! -L "$dir"; then exit 0; fi;
          test -d "$dir" && test ! -L "$dir" && test "$(readlink -f -- "$dir")" = "$dir" && test "$(stat -c %a "$dir")" = 700 || exit 1;
          test -f "$dir/owner" && test ! -L "$dir/owner" && test "$(stat -c %a "$dir/owner")" = 600 && test "$(stat -c %h "$dir/owner")" = 1 || exit 1;
          test "$(cat "$dir/owner")" = '"$run"' || exit 1;
          bad=$(find "$dir" -xdev \( -type l -o \( -type f -links +1 \) -o \( ! -type f ! -type d \) \) -print -quit);
          test -z "$bad" || exit 1; rm -rf -- "$dir"' > "$work/cleanup.stdout" 2> "$work/cleanup.stderr" || { outcome=1; category=cleanup; }
    fi
    python3 - "$work" "$outcome" "$category" "$run" "$GITHUB_SHA" <<'PY'
import json,sys
from pathlib import Path
from datetime import datetime,timezone
root=Path(sys.argv[1]);out={'status':'success' if sys.argv[2]=='0' else 'failure','category':sys.argv[3],
 'mode':'inspect','run_id':sys.argv[4],'source_commit':sys.argv[5],'generated_utc':datetime.now(timezone.utc).isoformat()}
if (root/'safe.json').exists():out['inspection']=json.loads((root/'safe.json').read_text())
(root/'public/status.json').write_text(json.dumps(out,indent=2)+'\n')
PY
    export GIT_INDEX_FILE="$work/publication.index"
    git read-tree --empty
    blob=$(git hash-object -w "$work/public/status.json")
    git update-index --add --cacheinfo "100644,$blob,status.json"
    tree=$(git write-tree)
    export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
    export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com' GIT_COMMITTER_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
    commit=$(printf 'Petish delivery page inspection %s\n' "$run" | git commit-tree "$tree")
    git push origin "$commit:refs/heads/automation-status/delivery-page-$run" > "$work/publish.stdout" 2> "$work/publish.stderr" || outcome=1
    unset GIT_INDEX_FILE
    printf 'Delivery page report: https://github.com/nkrake6/petish/tree/automation-status/delivery-page-%s\n' "$run"
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${DELIVERY_PAGE_PREREQUISITES:-success} == success && $(git rev-parse HEAD) == "$GITHUB_SHA" ]]
category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
connected=1
for file in delivery-page-remote.php verify-pet-club.php email-branding-preview-remote.php; do
    cp -- "scripts/$file" "$work/bundle/$file"
    chmod 600 "$work/bundle/$file"
done
tar -C "$work/bundle" -cf "$work/bundle.tar" delivery-page-remote.php verify-pet-club.php email-branding-preview-remote.php
"${petish_ssh[@]}" 'set -eu; umask 077; dir="$HOME/.petish-delivery-page-'"$run"'";
  test -d "$HOME" && test ! -L "$HOME" && test "$(readlink -f -- "$HOME")" = "$HOME" && test ! -e "$dir" && test ! -L "$dir" || exit 1;
  mkdir -m 700 "$dir"; printf "%s\n" '"$run"' > "$dir/owner"; chmod 600 "$dir/owner";
  tar --extract --no-same-owner --no-same-permissions --directory "$dir"' < "$work/bundle.tar" > "$work/upload.stdout" 2> "$work/upload.stderr"
category=native
"${petish_ssh[@]}" 'php -d display_errors=0 -d log_errors=0 -d allow_url_fopen=0 "$HOME/.petish-delivery-page-'"$run"'/delivery-page-remote.php" inspect '"$run" > "$work/native.json" 2> "$work/native.stderr"
category=public_copy
python3 scripts/delivery-page-report.py "$work/native.json" "$work/safe.json" > "$work/public.stdout" 2> "$work/public.stderr"
category=complete
