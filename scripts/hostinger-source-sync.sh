#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
work_dir="$RUNNER_TEMP/petish-source-sync"
mkdir -m 700 "$work_dir"
# Keep unscreened code private and ephemeral. Do not publish it as an artifact.
if ! "${petish_ssh[@]}" 'bash -s' < scripts/export-wordpress-source-remote.sh \
  | head -c 157286401 > "$work_dir/source.tar.gz"; then
  printf '%s\n' 'The read-only custom source export failed. No source was published.' >&2
  exit 1
fi
if (( $(wc -c < "$work_dir/source.tar.gz") > 157286400 )); then
  printf '%s\n' 'The custom source export exceeded the size limit. No source was published.' >&2
  exit 1
fi
python3 scripts/screen-wordpress-source.py "$work_dir/source.tar.gz" "$work_dir/ready" \
  --provenance "source_site=petish.biz" --provenance "workflow_sha=$GITHUB_SHA"
scan_status=0
"$RUNNER_TEMP/petish-gitleaks/gitleaks" dir "$work_dir/ready/wordpress" \
  --no-banner --redact=100 --exit-code=1 --report-format=json \
  --report-path="$work_dir/gitleaks.json" \
  > "$work_dir/gitleaks.stdout" 2> "$work_dir/gitleaks.stderr" || scan_status=$?
if (( scan_status > 1 )); then
  printf '%s\n' 'The secondary secret scanner failed. No source was published.' >&2
  exit 1
fi
python3 scripts/screen-wordpress-source.py quarantine-gitleaks "$work_dir/ready" "$work_dir/gitleaks.json"
printf '%s\n' 'Read-only custom source recovery and secret screening completed. Review the manifest before deployment.'
