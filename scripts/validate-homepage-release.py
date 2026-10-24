#!/usr/bin/env python3
"""Require matching browser-verified staging and a recent recoverable full backup."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
import tempfile
from datetime import datetime, timezone, timedelta

spec = importlib.util.spec_from_file_location('email_evidence', Path(__file__).with_name('validate-email-release-request.py'))
evidence = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evidence)
spec2 = importlib.util.spec_from_file_location('homepage_checks', Path(__file__).with_name('sanitize-homepage-result.py'))
checks = importlib.util.module_from_spec(spec2)
spec2.loader.exec_module(checks)
FILES = ('assets/images/ui/free-delivery-van.png', 'assets/css/home.css', 'assets/js/for-your-pet.js', 'includes/supplier-popularity.php', 'includes/for-your-pet.php', 'front-page.php', 'functions.php', 'includes/cart-badge.php', 'assets/css/cart-badge.css', 'assets/js/cart-badge.js', 'includes/header-navigation.php', 'assets/js/theme.js', 'assets/css/petish.css', 'includes/market-selector.php', 'assets/js/market-selector.js', 'assets/css/market-selector.css', 'assets/images/ui/country-flags.svg', 'assets/images/ui/country-flags.LICENSE.txt', 'includes/account-dropdown.php', 'assets/css/account-dropdown.css', 'assets/js/account-dropdown.js', 'includes/hero-images.php', 'assets/js/hero-images-admin.js', 'includes/payment-layout.php', 'assets/css/payment-layout.css', 'assets/js/payment-layout.js', 'includes/cart-payment-layout.php', 'assets/css/cart-payment-layout.css', 'assets/css/cart-typography.css', 'assets/js/cart-payment-layout.js')
PREFIX = 'wordpress/wp-content/themes/dt-the7-child/'


def validate(source):
    info = source.lstat()
    if not source.is_file() or source.is_symlink() or info.st_nlink != 1 or info.st_size > 4096:
        raise ValueError()
    request = json.loads(source.read_bytes())
    expected = {'format','staging_source_commit','staging_status_branch','backup_branch',
                'backup_result_branch','recovery_result_branch'}
    if not isinstance(request,dict) or set(request) != expected or request['format'] != 'petish-homepage-v1' \
            or not re.fullmatch(r'[a-f0-9]{40}',request['staging_source_commit']):
        raise ValueError()
    patterns = {'staging_status_branch':r'automation-status/homepage-[1-9][0-9]*-[1-9][0-9]*',
                'backup_branch':r'full-site-backup/[1-9][0-9]*-[1-9][0-9]*',
                'backup_result_branch':r'automation-status/backup-[1-9][0-9]*-[1-9][0-9]*',
                'recovery_result_branch':r'automation-status/recovery-[1-9][0-9]*-[1-9][0-9]*'}
    for field, pattern in patterns.items():
        if not isinstance(request[field],str) or not re.fullmatch(pattern,request[field]):
            raise ValueError()
    backup_id = request['backup_branch'].split('/')[1]
    if request['backup_result_branch'] != 'automation-status/backup-' + backup_id:
        raise ValueError()
    stage = evidence.report(request['staging_status_branch'],'homepage-stage')
    expected_hashes = {name:hashlib.sha256(Path(PREFIX + name).read_bytes()).hexdigest() for name in FILES}
    if stage.get('status') != 'success' or stage.get('category') != 'complete' or stage.get('mode') != 'staging' \
            or stage.get('staging_source_verified') is not True \
            or stage.get('source_commit') != request['staging_source_commit'] \
            or stage.get('source_hashes') != expected_hashes:
        raise ValueError()
    for phase, required in [('preflight',checks.PREFLIGHT),('native',checks.NATIVE),('cleanup',checks.CLEANUP),('verify',checks.VERIFY),('browser',checks.BROWSER),('cart_prepare',checks.CART_PREPARE),('cart_browser',checks.CART_BROWSER),('cart_cleanup',checks.CART_CLEANUP)]:
        result = stage.get('phases',{}).get(phase,{})
        # Preflight has already discarded its private raw fields when published.
        if phase == 'preflight':
            if not isinstance(result,dict) or set(result) != {'status','category','checks'} \
                    or result.get('status') != 'success' or result.get('category') != 'complete' \
                    or not isinstance(result.get('checks'),dict) or set(result['checks']) != required \
                    or any(result['checks'].get(key) is not True for key in required):
                raise ValueError()
            continue
        # Apply the same exact finite schema used when the receipt was published.
        with tempfile.TemporaryDirectory(prefix='petish-homepage-evidence-') as folder:
            path = Path(folder) / 'phase.json'
            path.write_text(json.dumps(result))
            path.chmod(0o600)
            screened, passed = checks.screen(phase, path)
        if not passed or screened != result or any(result.get('checks',{}).get(key) is not True for key in required):
            raise ValueError()
    available=stage['phases']['verify']['checks'].get('free_delivery_products_available')
    present=stage['phases']['browser'].get('section_present')
    if type(available) is not bool or type(present) is not bool or available!=present:
        raise ValueError()
    # Prove that both the candidate and its reviewed staging commit contain the same bytes.
    evidence.git('fetch','--no-tags','--depth=1','origin',request['staging_source_commit'])
    for name, expected_hash in expected_hashes.items():
        if hashlib.sha256(evidence.git('show',request['staging_source_commit'] + ':' + PREFIX + name)).hexdigest() != expected_hash:
            raise ValueError()
    backup = evidence.report(request['backup_result_branch'],'homepage-backup')
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
    recovery = evidence.report(request['recovery_result_branch'],'homepage-recovery')
    required = {'wordpress_application_recovery_passed','database_import_verified','wordpress_bootstrap_verified',
                'homepage_http_verified','shop_http_verified','product_http_verified','cart_http_verified','account_http_verified',
                'anonymous_private_photo_denied','application_outbound_http_blocked',
                'application_email_blocked','application_container_removed','private_sandbox_removed'}
    references = recovery.get('recovery_check',{}).get('private_photo_references_verified')
    if type(references) is not int or not 1 <= references <= 20000:
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
        print('Matching staging browser checks and a recent recoverable full backup are required.',file=sys.stderr)
        sys.exit(1)
