#!/usr/bin/env python3
"""Require recent, successful native staging and backup/recovery evidence."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from datetime import datetime, timezone, timedelta

STAGING = '86e315f3daf172e1c9faee9a62d768b48f634bca'


def git(*arguments):
    result = subprocess.run(['git', *arguments], stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=120, check=True)
    return result.stdout


def report(branch, label):
    ref = 'refs/petish-email-evidence/' + label
    git('fetch', '--no-tags', '--depth=1', 'origin', '+' + branch + ':' + ref)
    data = git('show', ref + ':status.json')
    if len(data) > 262144:
        raise ValueError()
    value = json.loads(data)
    if not isinstance(value, dict):
        raise ValueError()
    return value


def validate(source):
    info = source.lstat()
    if not source.is_file() or source.is_symlink() or info.st_nlink != 1 or info.st_size > 4096:
        raise ValueError()
    request = json.loads(source.read_bytes())
    expected = {'format','staging_source_commit','staging_preview_branch',
                'backup_branch','backup_result_branch','recovery_result_branch'}
    if not isinstance(request, dict) or set(request) != expected \
            or request['format'] != 'petish-email-release-v1' or request['staging_source_commit'] != STAGING:
        raise ValueError()
    patterns = {'staging_preview_branch':r'email-preview/[1-9][0-9]*-[1-9][0-9]*',
                'backup_branch':r'full-site-backup/[1-9][0-9]*-[1-9][0-9]*',
                'backup_result_branch':r'automation-status/backup-[1-9][0-9]*-[1-9][0-9]*',
                'recovery_result_branch':r'automation-status/recovery-[1-9][0-9]*-[1-9][0-9]*'}
    for field, pattern in patterns.items():
        if not isinstance(request[field], str) or not re.fullmatch(pattern, request[field]):
            raise ValueError()
    backup_id = request['backup_branch'].split('/')[1]
    if request['backup_result_branch'] != 'automation-status/backup-' + backup_id:
        raise ValueError()
    preview = report(request['staging_preview_branch'], 'preview')
    required = {'email_preview_seven_types_rendered','email_preview_native_renderer',
                'email_preview_no_order_records_created','email_preview_no_user_records_created',
                'email_preview_no_auth_tokens_generated','email_preview_stock_unchanged',
                'email_preview_core_account_branding'}
    if preview.get('status') != 'success' or preview.get('category') != 'complete' \
            or preview.get('source_commit') != STAGING \
            or any(preview.get('checks', {}).get(name) is not True for name in required):
        raise ValueError()
    backup = report(request['backup_result_branch'], 'backup')
    if backup.get('AUTOMATION_KIND') != 'backup' or backup.get('AUTOMATION_RESULT') != 'success' \
            or str(backup.get('GITHUB_RUN_ID')) + '-' + str(backup.get('GITHUB_RUN_ATTEMPT')) != backup_id \
            or backup.get('restore_check', {}).get('restore_smoke_test_passed') is not True \
            or backup.get('restore_check', {}).get('database_import_verified') is not True:
        raise ValueError()
    now = datetime.now(timezone.utc)
    generated = datetime.fromisoformat(backup['generated_utc'])
    if generated.tzinfo is None or not now - timedelta(hours=6) <= generated <= now + timedelta(minutes=5):
        raise ValueError()
    if not re.fullmatch(rb'[a-f0-9]{40}\trefs/heads/' + re.escape(request['backup_branch'].encode()) + rb'\n',
                        git('ls-remote','--exit-code','--heads','origin',request['backup_branch'])):
        raise ValueError()
    recovery = report(request['recovery_result_branch'], 'recovery')
    recovery_checks = {'wordpress_application_recovery_passed','database_import_verified',
                       'wordpress_bootstrap_verified','homepage_http_verified','shop_http_verified',
                       'product_http_verified','cart_http_verified','account_http_verified'}
    if recovery.get('AUTOMATION_KIND') != 'recovery' or recovery.get('AUTOMATION_RESULT') != 'success' \
            or recovery.get('backup_branch') != request['backup_branch'] \
            or any(recovery.get('recovery_check', {}).get(name) is not True for name in recovery_checks):
        raise ValueError()
    return {'verified':True, **{key:request[key] for key in expected - {'format'}}}


if __name__ == '__main__':
    os.umask(0o077)
    try:
        if len(sys.argv) != 3:
            raise ValueError()
        result = validate(Path(sys.argv[1]))
        Path(sys.argv[2]).write_text(json.dumps(result, indent=2) + '\n')
    except Exception:
        print('Successful native staging and a recent verified full backup are required before release.', file=sys.stderr)
        sys.exit(1)
