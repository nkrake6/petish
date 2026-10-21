#!/usr/bin/env python3
"""Gate the reviewed customer-review release on exact staging and recovery evidence."""
from datetime import datetime, timedelta, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def module(filename, name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


evidence = module('validate-email-release-request.py', 'customer_reviews_evidence')
checks = module('sanitize-customer-reviews-result.py', 'customer_reviews_checks')
STAGING_SOURCE = 'f16690d087cfe5c5808e65f28e48db1e21525a7c'
STAGING_STATUS = 'automation-status/customer-reviews-39583147075-1'
PREFIX = 'wordpress/wp-content/plugins/petish-passport/'
# The bootstrap is published last by the production runner.
FILES = (
    'includes/class-petish-customer-reviews.php',
    'includes/class-petish-customer-review-media.php',
    'includes/class-petish-customer-reviews-ui.php',
    'includes/class-petish-passport-video-inspector.php',
    'includes/class-petish-passport-video.php',
    'templates/customer-reviews-progress.php',
    'templates/customer-reviews-form.php',
    'templates/customer-reviews-card.php',
    'templates/customer-reviews-product.php',
    'templates/customer-reviews-dashboard.php',
    'templates/moment-form.php',
    'assets/customer-reviews.css',
    'assets/customer-reviews.js',
    'assets/passport.js',
    'petish-passport.php',
)
REQUEST_FIELDS = {
    'format', 'staging_source_commit', 'staging_status_branch', 'backup_branch',
    'backup_result_branch', 'recovery_result_branch',
}
BASE = {'fixed_staging_paths', 'configuration_isolated', 'guard_pinned'}
BOOT = {'environment_staging', 'database_separate', 'outbound_blocked',
        'mail_blocked', 'async_blocked', 'jetpack_isolated'}
PHASE_CHECKS = {
    'prepare': BASE | {'code_snapshot_verified'},
    'source': BASE | {'source_verified', 'protected_files_unchanged'},
    'enable': BASE | BOOT | {'source_verified', 'browser_guard_installed',
                           'upload_limits_sufficient', 'flag_journal_written', 'flag_enabled'},
    'verify': BASE | BOOT | {'source_verified', 'native_proof_passed'},
    'cleanup': BASE | BOOT | {'fixture_cleanup_verified', 'browser_guard_removed',
                            'protected_files_unchanged'},
    'commit': BASE | BOOT | {'source_verified', 'protected_files_unchanged', 'flag_enabled',
                           'browser_guard_removed', 'release_committed'},
    'native': checks.literal_list(ROOT / 'scripts/customer-reviews-proof.php',
                                  'PETISH_CUSTOMER_REVIEWS_PROOF_CHECKS'),
    'cleanup-fixture': checks.literal_list(ROOT / 'scripts/customer-reviews-proof.php',
                                           'PETISH_CUSTOMER_REVIEWS_CLEANUP_CHECKS'),
    'browser': checks.literal_list(ROOT / 'tools/customer-reviews-browser.mjs', 'CHECK_KEYS', php=False),
}
RECOVERY_CHECKS = {
    'wordpress_application_recovery_passed', 'database_import_verified',
    'wordpress_bootstrap_verified', 'homepage_http_verified', 'shop_http_verified',
    'product_http_verified', 'cart_http_verified', 'account_http_verified',
    'anonymous_private_photo_denied', 'application_outbound_http_blocked',
    'application_email_blocked', 'application_container_removed', 'private_sandbox_removed',
}


def regular_file(path, maximum):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= maximum:
        raise ValueError('Invalid evidence or application file')
    return path.read_bytes()


def timestamp(value):
    if not isinstance(value, str):
        raise ValueError('Invalid evidence timestamp')
    result = datetime.fromisoformat(value)
    if result.tzinfo is None:
        raise ValueError('Evidence timestamp needs a timezone')
    return result


def staging_report(stage, request, now):
    expected = {'status', 'scope', 'source_commit', 'run_id', 'generated_utc', 'category',
                'fixture_cleanup_verified', 'rollback_verified', 'production_changed', 'phases'}
    if not isinstance(stage, dict) or set(stage) != expected \
            or stage['status'] != 'success' or stage['category'] != 'complete' \
            or stage['scope'] != 'private-staging-only' \
            or stage['source_commit'] != request['staging_source_commit'] \
            or stage['run_id'] != request['staging_status_branch'].removeprefix('automation-status/customer-reviews-') \
            or stage['fixture_cleanup_verified'] is not True \
            or stage['rollback_verified'] is not False or stage['production_changed'] is not False \
            or not isinstance(stage['phases'], dict) or set(stage['phases']) != set(PHASE_CHECKS) \
            or timestamp(stage['generated_utc']) > now + timedelta(minutes=5):
        raise ValueError('Staging receipt does not identify the reviewed complete run')
    if {phase: len(PHASE_CHECKS[phase]) for phase in ('native', 'browser', 'cleanup-fixture')} != \
            {'native': 47, 'browser': 44, 'cleanup-fixture': 8}:
        raise ValueError('The reviewed acceptance schema changed')
    with tempfile.TemporaryDirectory(prefix='petish-customer-reviews-evidence-') as directory:
        path = Path(directory) / 'phase.json'
        for phase, required in PHASE_CHECKS.items():
            value = stage['phases'][phase]
            fields = {'status', 'checks'} | ({'diagnostics'} if phase == 'browser' else set())
            if not isinstance(value, dict) or set(value) != fields or value['status'] != 'success' \
                    or not isinstance(value['checks'], dict) or set(value['checks']) != required \
                    or any(passed is not True for passed in value['checks'].values()):
                raise ValueError('Missing, failed, or unexpected staging checks')
            path.write_text(json.dumps(value))
            path.chmod(0o600)
            if checks.sanitize(phase, path) != value:
                raise ValueError('Staging evidence contains unsupported fields')


def candidate_matches_staging(request):
    evidence.git('fetch', '--no-tags', '--depth=1', 'origin', request['staging_source_commit'])
    for name in FILES:
        path = ROOT / (PREFIX + name)
        # A linked working-tree path must not select files outside the reviewed tree.
        if path.resolve() != path:
            raise ValueError('Application paths must be ordinary repository files')
        digest = hashlib.sha256(regular_file(path, 2 * 1024 * 1024)).digest()
        for revision in ('HEAD', request['staging_source_commit']):
            blob = evidence.git('show', revision + ':' + PREFIX + name)
            if hashlib.sha256(blob).digest() != digest:
                raise ValueError('Candidate application differs from the reviewed source')


def recoverable_backup(request, now):
    backup_id = request['backup_branch'].split('/')[1]
    backup = evidence.report(request['backup_result_branch'], 'customer-reviews-backup')
    restore = backup.get('restore_check') if isinstance(backup, dict) else None
    if not isinstance(restore, dict) or backup.get('AUTOMATION_KIND') != 'backup' \
            or backup.get('AUTOMATION_RESULT') != 'success' \
            or str(backup.get('GITHUB_RUN_ID')) + '-' + str(backup.get('GITHUB_RUN_ATTEMPT')) != backup_id \
            or any(restore.get(key) is not True for key in (
                'restore_smoke_test_passed', 'database_import_verified', 'private_passport_root_restored')):
        raise ValueError('A successful matching backup restore proof is required')
    generated = timestamp(backup.get('generated_utc'))
    if not now - timedelta(hours=6) <= generated <= now + timedelta(minutes=5):
        raise ValueError('The full backup is not recent')
    branch = request['backup_branch']
    if not re.fullmatch(rb'[a-f0-9]{40}\trefs/heads/' + re.escape(branch.encode()) + rb'\n',
                        evidence.git('ls-remote', '--exit-code', '--heads', 'origin', branch)):
        raise ValueError('The matching full backup branch is unavailable')
    recovery = evidence.report(request['recovery_result_branch'], 'customer-reviews-recovery')
    recovered = recovery.get('recovery_check') if isinstance(recovery, dict) else None
    recovery_id = request['recovery_result_branch'].removeprefix('automation-status/recovery-')
    if not isinstance(recovered, dict) or recovery.get('AUTOMATION_KIND') != 'recovery' \
            or recovery.get('AUTOMATION_RESULT') != 'success' or recovery.get('backup_branch') != branch \
            or str(recovery.get('GITHUB_RUN_ID')) + '-' + str(recovery.get('GITHUB_RUN_ATTEMPT')) != recovery_id \
            or any(recovered.get(key) is not True for key in RECOVERY_CHECKS):
        raise ValueError('Application recovery must cover this exact backup')
    private_references = recovered.get('private_photo_references_verified')
    if type(private_references) is not int or not 1 <= private_references <= 20000:
        raise ValueError('Application recovery must exercise private pet media')
    recovered_at = timestamp(recovery.get('generated_utc'))
    if not generated - timedelta(minutes=5) <= recovered_at <= now + timedelta(minutes=5):
        raise ValueError('Application recovery timestamp does not match this backup')


def validate(source):
    request = json.loads(regular_file(source, 4096))
    if not isinstance(request, dict) or set(request) != REQUEST_FIELDS \
            or request['format'] != 'petish-customer-reviews-v1' \
            or request['staging_source_commit'] != STAGING_SOURCE \
            or request['staging_status_branch'] != STAGING_STATUS:
        raise ValueError('Invalid or unreviewed release request')
    patterns = {
        'backup_branch': r'full-site-backup/[1-9][0-9]*-[1-9][0-9]*',
        'backup_result_branch': r'automation-status/backup-[1-9][0-9]*-[1-9][0-9]*',
        'recovery_result_branch': r'automation-status/recovery-[1-9][0-9]*-[1-9][0-9]*',
    }
    for field, pattern in patterns.items():
        if not isinstance(request[field], str) or not re.fullmatch(pattern, request[field]):
            raise ValueError('Invalid evidence reference')
    if request['backup_result_branch'] != 'automation-status/backup-' + request['backup_branch'].split('/')[1]:
        raise ValueError('Backup evidence refers to a different archive')
    now = datetime.now(timezone.utc)
    staging_report(evidence.report(request['staging_status_branch'], 'customer-reviews-stage'), request, now)
    candidate_matches_staging(request)
    recoverable_backup(request, now)
    return {'verified': True, **{key: request[key] for key in sorted(REQUEST_FIELDS - {'format'})}}


if __name__ == '__main__':
    os.umask(0o077)
    try:
        if len(sys.argv) != 3:
            raise ValueError('Expected request and output paths')
        result = validate(Path(sys.argv[1]))
        Path(sys.argv[2]).write_text(json.dumps(result, indent=2) + '\n')
    except Exception:
        print('The reviewed complete staging proof and a recent matching backup/recovery proof are required.', file=sys.stderr)
        sys.exit(1)
