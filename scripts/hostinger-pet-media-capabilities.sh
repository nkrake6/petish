#!/usr/bin/env bash
# Publish a finite read-only capability receipt; raw command output remains private.
set +x
set -euo pipefail
umask 077
: "${RUNNER_TEMP:?}"
: "${GITHUB_RUN_ID:?}"
: "${GITHUB_RUN_ATTEMPT:?}"
: "${GITHUB_SHA:?}"
run=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
[[ $run =~ ^[1-9][0-9]{0,31}-[1-9][0-9]{0,8}$ && $GITHUB_SHA =~ ^[a-f0-9]{40}$ ]]
work=$(mktemp -d "$RUNNER_TEMP/petish-media-capabilities.XXXXXXXX")
category=prerequisites
publish() {
    export PETISH_MEDIA_WORK=$work PETISH_MEDIA_RUN=$run PETISH_MEDIA_CATEGORY=$category PETISH_MEDIA_RESULT=$result
    python3 - <<'PY' || return 1
from datetime import datetime, timezone
import json, os
from pathlib import Path
root=Path(os.environ['PETISH_MEDIA_WORK'])
report={'format':'petish-media-capabilities-v1','status':os.environ['PETISH_MEDIA_RESULT'],
        'category':os.environ['PETISH_MEDIA_CATEGORY'],'run_id':os.environ['PETISH_MEDIA_RUN'],
        'source_commit':os.environ['GITHUB_SHA'],'generated_utc':datetime.now(timezone.utc).isoformat(),
        'read_only':True,'capabilities':{}}
if report['status']=='success':
    report['capabilities']=json.loads((root/'capabilities.safe.json').read_bytes())
(root/'status.json').write_text(json.dumps(report,indent=2)+'\n')
PY
    export GIT_INDEX_FILE="$work/index"
    git read-tree --empty || return 1
    local blob tree commit
    blob=$(git hash-object -w "$work/status.json") || return 1
    git update-index --add --cacheinfo "100644,$blob,status.json" || return 1
    tree=$(git write-tree) || return 1
    commit=$(printf 'Read-only Petish media capabilities %s\n' "$run" | git -c user.name=petish-automation -c user.email=automation@petish.invalid commit-tree "$tree") || return 1
    git push origin "$commit:refs/heads/automation-status/media-capabilities-$run" > "$work/publish.private.stdout" 2> "$work/publish.private.stderr" || return 1
    unset GIT_INDEX_FILE
    printf 'Capability report: https://github.com/nkrake6/petish/tree/automation-status/media-capabilities-%s\n' "$run"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
        printf '[Read-only media capability report](https://github.com/nkrake6/petish/tree/automation-status/media-capabilities-%s)\n' "$run" >> "$GITHUB_STEP_SUMMARY"
    fi
}
finish() {
    local outcome=$?
    trap - EXIT HUP INT TERM
    result=failure
    if [[ $outcome == 0 && $category == complete ]]; then result=success; fi
    if ! publish; then outcome=1; printf '%s\n' 'The capability receipt could not be published.' >&2; fi
    rm -rf -- "$work"
    exit "$outcome"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
[[ ${PETISH_MEDIA_PREREQUISITES:-success} == success ]]
[[ $(git rev-parse HEAD) == "$GITHUB_SHA" ]]
category=transport
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
category=probe
"${petish_ssh[@]}" 'bash -s' < scripts/inspect-pet-media-remote.sh \
    > "$work/capabilities.private.json" 2> "$work/probe.private.stderr"
category=schema
python3 scripts/sanitize-pet-media-capabilities.py "$work/capabilities.private.json" "$work/capabilities.safe.json" \
    > "$work/sanitize.private.stdout" 2> "$work/sanitize.private.stderr"
category=complete
