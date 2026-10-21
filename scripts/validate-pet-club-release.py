#!/usr/bin/env python3
"""Require matching native and browser staging proofs and a recent recoverable full backup."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
from datetime import datetime, timezone, timedelta

spec = importlib.util.spec_from_file_location('email_evidence', Path(__file__).with_name('validate-email-release-request.py'))
evidence = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evidence)
spec2 = importlib.util.spec_from_file_location('pet_club_checks', Path(__file__).with_name('sanitize-pet-club-result.py'))
checks = importlib.util.module_from_spec(spec2)
spec2.loader.exec_module(checks)
FILES = (
    'includes/class-petish-passport-video-inspector.php',
    'includes/class-petish-passport-video.php',
    'includes/class-petish-passport-moments.php',
    'includes/class-petish-passport-community.php',
    'assets/pet-club.css',
    'assets/passport.css',
    'assets/catalogue.css',
    'assets/passport.js',
    'templates/public.php',
    'templates/community.php',
    'templates/moment-form.php',
    'includes/class-petish-passport-media.php',
    'includes/class-petish-passport-store.php',
    'includes/class-petish-passport-ui.php',
    'templates/dashboard.php',
    'templates/form.php',
    'templates/delete.php',
    'petish-passport.php',
)
PREFIX = 'wordpress/wp-content/plugins/petish-passport/'


def validate(source):
    info = source.lstat()
    if not source.is_file() or source.is_symlink() or info.st_nlink != 1 or info.st_size > 4096:
        raise ValueError()
    request = json.loads(source.read_bytes())
    expected = {'format','staging_source_commit','staging_status_branch','backup_branch',
                'backup_result_branch','recovery_result_branch'}
    if not isinstance(request,dict) or set(request) != expected or request['format'] != 'petish-pet-club-v1' \
            or not re.fullmatch(r'[a-f0-9]{40}',request['staging_source_commit']):
        raise ValueError()
    patterns = {'staging_status_branch':r'automation-status/pet-club-[1-9][0-9]*-[1-9][0-9]*',
                'backup_branch':r'full-site-backup/[1-9][0-9]*-[1-9][0-9]*',
                'backup_result_branch':r'automation-status/backup-[1-9][0-9]*-[1-9][0-9]*',
                'recovery_result_branch':r'automation-status/recovery-[1-9][0-9]*-[1-9][0-9]*'}
    for field, pattern in patterns.items():
        if not isinstance(request[field],str) or not re.fullmatch(pattern,request[field]):
            raise ValueError()
    backup_id = request['backup_branch'].split('/')[1]
    if request['backup_result_branch'] != 'automation-status/backup-' + backup_id:
        raise ValueError()
    stage = evidence.report(request['staging_status_branch'],'pet-club-stage')
    expected_hashes = {name:hashlib.sha256(Path(PREFIX + name).read_bytes()).hexdigest() for name in FILES}
    if stage.get('status') != 'success' or stage.get('category') != 'complete' or stage.get('mode') != 'staging' \
            or stage.get('staging_source_verified') is not True \
            or stage.get('source_commit') != request['staging_source_commit'] \
            or stage.get('source_hashes') != expected_hashes:
        raise ValueError()
    for phase, required in [('preflight',checks.PREFLIGHT),('native',checks.STAGING),('fixture',checks.FIXTURE),('browser',checks.BROWSER),('cleanup',checks.CLEANUP)]:
        result = stage.get('phases',{}).get(phase,{})
        fields = {'status','category','checks'} | ({'diagnostics','menu_geometry'} if phase == 'browser' else set())
        if phase == 'browser':
            checks.browser_diagnostics(result.get('diagnostics') if isinstance(result,dict) else None)
            checks.menu_geometry(result.get('menu_geometry') if isinstance(result,dict) else None)
        if not isinstance(result,dict) or set(result) != fields \
                or result.get('status') != 'success' or result.get('category') != 'complete' \
                or not isinstance(result.get('checks'),dict) or set(result['checks']) != required \
                or any(result['checks'].get(key) is not True for key in required):
            raise ValueError()
    # Prove that both the candidate and its reviewed staging commit contain the same bytes.
    evidence.git('fetch','--no-tags','--depth=1','origin',request['staging_source_commit'])
    for name, expected_hash in expected_hashes.items():
        if hashlib.sha256(evidence.git('show',request['staging_source_commit'] + ':' + PREFIX + name)).hexdigest() != expected_hash:
            raise ValueError()
    backup = evidence.report(request['backup_result_branch'],'pet-club-backup')
    if backup.get('AUTOMATION_KIND') != 'backup' or backup.get('AUTOMATION_RESULT') != 'success' \
            or str(backup.get('GITHUB_RUN_ID')) + '-' + str(backup.get('GITHUB_RUN_ATTEMPT')) != backup_id \
            or backup.get('restore_check',{}).get('restore_smoke_test_passed') is not True \
            or backup.get('restore_check',{}).get('database_import_verified') is not True \
            or backup.get('restore_check',{}).get('private_passport_root_restored') is not True:
        raise ValueError()
    now = datetime.now(timezone.utc)
    generated = datetime.fromisoformat(backup['generated_utc'])
    if generated.tzinfo is None or not now - timedelta(hours=6) <= generated <= now + timedelta(minutes=5):
        raise ValueError()
    if not re.fullmatch(rb'[a-f0-9]{40}\trefs/heads/' + re.escape(request['backup_branch'].encode()) + rb'\n',
                        evidence.git('ls-remote','--exit-code','--heads','origin',request['backup_branch'])):
        raise ValueError()
    recovery = evidence.report(request['recovery_result_branch'],'pet-club-recovery')
    required = {'wordpress_application_recovery_passed','database_import_verified','wordpress_bootstrap_verified',
                'homepage_http_verified','shop_http_verified','product_http_verified','cart_http_verified','account_http_verified',
                'anonymous_private_photo_denied','application_outbound_http_blocked',
                'application_email_blocked','application_container_removed','private_sandbox_removed'}
    # The recovery tool reports a count of verified private photo references,
    # not a boolean. This known backup must exercise at least one reference.
    private_references = recovery.get('recovery_check', {}).get('private_photo_references_verified')
    if type(private_references) is not int or not 1 <= private_references <= 20000:
        raise ValueError()
    if recovery.get('AUTOMATION_KIND') != 'recovery' or recovery.get('AUTOMATION_RESULT') != 'success' \
            or recovery.get('backup_branch') != request['backup_branch'] \
            or any(recovery.get('recovery_check',{}).get(key) is not True for key in required):
        raise ValueError()
    return {'verified':True,**{key:request[key] for key in expected - {'format'}}}


if __name__ == '__main__':
    os.umask(0o077)
    try:
        if len(sys.argv) != 3:
            raise ValueError()
        Path(sys.argv[2]).write_text(json.dumps(validate(Path(sys.argv[1])),indent=2) + '\n')
    except Exception:
        print('Matching native staging checks and a recent recoverable full backup are required.',file=sys.stderr)
        sys.exit(1)
