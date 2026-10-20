#!/usr/bin/env bash
set -euo pipefail
: "${AUTOMATION_KIND:?Missing AUTOMATION_KIND}"
: "${AUTOMATION_RESULT:?Missing AUTOMATION_RESULT}"
: "${GITHUB_RUN_ID:?Missing GITHUB_RUN_ID}"
: "${GITHUB_RUN_ATTEMPT:?Missing GITHUB_RUN_ATTEMPT}"
: "${GITHUB_SHA:?Missing GITHUB_SHA}"
: "${RUNNER_TEMP:?Missing RUNNER_TEMP}"
[[ "$AUTOMATION_KIND" =~ ^(source-sync|backup|staging|recovery|mail)$ ]]
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
if data['AUTOMATION_KIND'] == 'mail':
    report = Path(os.environ['RUNNER_TEMP']) / 'petish-mail-result.json'
    if report.is_file() and report.stat().st_size < 4096:
        try:
            configured = json.loads(report.read_text())
            fields = ('configured', 'smtp_authentication_verified', 'tls_peer_verification',
                      'message_submitted')
            data['mail_check'] = {key: configured[key] for key in fields
                                  if key in configured and type(configured[key]) is bool}
            categories = {'configuration', 'runtime', 'smtp_authentication', 'credential_missing',
                          'ssh_connection', 'existing_mailer', 'metadata', 'filesystem',
                          'deployment', 'rollback', 'unknown'}
            if configured.get('failure_category') in categories:
                data['mail_check']['failure_category'] = configured['failure_category']
        except (ValueError, TypeError):
            pass
if data['AUTOMATION_KIND'] == 'recovery':
    import re
    backup_branch = os.environ.get('BACKUP_BRANCH', '')
    if re.fullmatch(r'full-site-backup/[0-9]+-[0-9]+', backup_branch):
        data['backup_branch'] = backup_branch
    report = Path(os.environ['RUNNER_TEMP']) / 'petish-wordpress-recovery-result.json'
    if report.is_file() and report.stat().st_size < 8192:
        try:
            recovered = json.loads(report.read_text())
            fields = ('wordpress_application_recovery_passed', 'regular_files_restored',
                      'private_files_restored', 'private_smtp_files_restored', 'private_smtp_config_verified',
                      'archive_links_skipped', 'database_import_verified',
                      'restored_database_tables', 'wordpress_bootstrap_verified', 'restored_theme_verified',
                      'restored_custom_plugins_verified', 'active_plugins_bootstrapped', 'published_products',
                      'private_photo_references_verified', 'wordpress_application_bootstrap_tested',
                      'homepage_http_verified', 'shop_http_verified', 'product_http_verified',
                      'cart_http_verified', 'account_http_verified', 'restored_media_http_verified',
                      'anonymous_private_photo_denied', 'application_outbound_http_blocked',
                      'application_email_blocked', 'application_original_config_executed',
                      'application_container_removed', 'private_sandbox_removed', 'host_specific_dropins_quarantined')
            data['recovery_check'] = {key: recovered[key] for key in fields
                                      if key in recovered and type(recovered[key]) in (bool, int)}
            stages = {'input', 'decryption', 'extraction', 'database_start', 'database_import',
                      'database_verify', 'prepare', 'image', 'container', 'table_prefix',
                      'database_user', 'bootstrap', 'http', 'complete', 'cleanup'}
            if recovered.get('failure_stage') in stages:
                data['recovery_check']['failure_stage'] = recovered['failure_stage']
        except (ValueError, TypeError):
            pass
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
                      'regular_files_restored', 'private_files_restored', 'private_smtp_files_restored',
                      'private_smtp_config_verified', 'archive_links_skipped',
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
