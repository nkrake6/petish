#!/usr/bin/env bash
set -euo pipefail
: "${AUTOMATION_KIND:?Missing AUTOMATION_KIND}"
: "${AUTOMATION_RESULT:?Missing AUTOMATION_RESULT}"
: "${GITHUB_RUN_ID:?Missing GITHUB_RUN_ID}"
: "${GITHUB_RUN_ATTEMPT:?Missing GITHUB_RUN_ATTEMPT}"
: "${GITHUB_SHA:?Missing GITHUB_SHA}"
: "${RUNNER_TEMP:?Missing RUNNER_TEMP}"
[[ "$AUTOMATION_KIND" =~ ^(source-sync|backup|staging)$ ]]
[[ "$AUTOMATION_RESULT" =~ ^(success|failure|cancelled|skipped)$ ]]
[[ "$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT" =~ ^[0-9]+-[0-9]+$ ]]
[[ "$GITHUB_SHA" =~ ^[a-f0-9]{40}$ ]]
export AUTOMATION_KIND AUTOMATION_RESULT
status_file="$RUNNER_TEMP/petish-automation-status.json"
python3 - "$status_file" <<'PY'
import json, os, sys
from datetime import datetime, timezone
data = {key: os.environ[key] for key in (
    'AUTOMATION_KIND', 'AUTOMATION_RESULT', 'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'GITHUB_SHA')}
data['generated_utc'] = datetime.now(timezone.utc).isoformat()
from pathlib import Path
diagnostics = Path(os.environ['RUNNER_TEMP']) / 'petish-task-diagnostics.json'
if diagnostics.is_file() and diagnostics.stat().st_size < 8192:
    try:
        outcome = json.loads(diagnostics.read_text())
        known = {'private_passport_unavailable', 'private_passport_missing', 'private_passport_link',
                 'private_passport_unreadable', 'private_boundary', 'wpcli_missing',
                 'db_export_failed', 'archive_changed', 'archive_error', 'verifier_failed',
                 'passphrase_missing', 'ssh_hostkey_failed', 'ssh_auth_failed',
                 'source_screening_failed', 'staging_validation_failed', 'unknown'}
        if outcome.get('phase') in {'backup-export', 'backup-restore', 'staging', 'source-sync'}:
            data['task'] = {'phase': outcome['phase'],
                            'categories': [c for c in outcome.get('categories', []) if c in known]}
            if type(outcome.get('exit_code')) is int:
                data['task']['exit_code'] = outcome['exit_code']
    except (ValueError, TypeError):
        pass
if data['AUTOMATION_KIND'] == 'backup':
    report = Path(os.environ['RUNNER_TEMP']) / 'petish-backup-restore-result.json'
    if report.is_file() and report.stat().st_size < 8192:
        try:
            restored = json.loads(report.read_text())
            fields = ('restore_smoke_test_passed', 'private_passport_root_restored',
                      'wordpress_application_bootstrap_tested', 'private_sandbox_removed',
                      'regular_files_restored', 'private_files_restored', 'archive_links_skipped',
                      'sql_create_table_statements', 'restored_database_tables',
                      'database_import_verified')
            data['restore_check'] = {key: restored[key] for key in fields
                                     if key in restored and type(restored[key]) in (bool, int)}
        except (ValueError, TypeError):
            pass
with open(sys.argv[1], 'w') as output:
    json.dump(data, output, indent=2)
    output.write('\n')
PY
blob="$(git hash-object -w "$status_file")"
tree="$(printf '100644 blob %s\tstatus.json\n' "$blob" | git mktree)"
export GIT_AUTHOR_NAME='github-actions[bot]' GIT_COMMITTER_NAME='github-actions[bot]'
export GIT_AUTHOR_EMAIL='41898282+github-actions[bot]@users.noreply.github.com'
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
commit="$(printf 'Petish %s result %s\n' "$AUTOMATION_KIND" "$AUTOMATION_RESULT" | git commit-tree "$tree")"
git push origin "$commit:refs/heads/automation-status/$AUTOMATION_KIND-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
