#!/usr/bin/env python3
"""Bind the bounded production upgrade to exact staging and recovery evidence."""
from datetime import datetime, timezone
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


review_evidence = module('validate-customer-reviews-release.py', 'cancellation_recovery_evidence')
evidence = review_evidence.evidence
screen = module('sanitize-cancellation-requests-result.py', 'cancellation_staging_schema')
STAGING_SOURCE = 'f992218c19db662f25af25af59c6a2398b2d6db3'
STAGING_TOOLS = '4afaa63b6009b9e68636ba9149f6dfaac1a3e5fd'
STAGING_STATUS = 'automation-status/cancellation-requests-40057924326-1'
STAGING_PREPARE = 'automation-status/cancellation-requests-40057865883-1'
STAGING_INSTALL = 'automation-status/staging-40056331426-1'
PREFIX = 'wordpress/wp-content/'
FULFILMENT_FILES = (
    'README.md', 'assets/cancellation-faq.css', 'assets/order-cancellation.css',
    'assets/shipment-tracking.css', 'assets/shipping-quotes.css', 'assets/shipping-quotes.js',
    'includes/class-petish-automatic-shipping.php', 'includes/class-petish-cancellation-policy.php',
    'includes/class-petish-delivery-cost-sheet-admin.php', 'includes/class-petish-delivery-cost-sheet.php',
    'includes/class-petish-delivery-operations-admin.php', 'includes/class-petish-delivery-rate-schedules.php',
    'includes/class-petish-order-cancellation-admin.php', 'includes/class-petish-order-cancellation-customer.php',
    'includes/class-petish-order-cancellation-emails.php', 'includes/class-petish-order-cancellation-requests.php',
    'includes/class-petish-quote-currency.php', 'includes/class-petish-shipment-customer-view.php',
    'includes/class-petish-shipment-ledger.php', 'includes/class-petish-shipment-notifications.php',
    'includes/class-petish-shipping-quote-requests.php', 'includes/class-petish-supplier-quotes.php',
    'includes/class-petish-supplier-shipping.php', 'petish-fulfilment.php',
)
PASSPORT_FILES = (
    'includes/class-petish-customer-reviews-ui.php',
    'templates/customer-reviews-dashboard.php', 'assets/customer-reviews.css',
)
FILES = tuple('plugins/petish-fulfilment/' + name for name in FULFILMENT_FILES) + tuple(
    'plugins/petish-passport/' + name for name in PASSPORT_FILES)
FIELDS = {
    'format', 'staging_source_commit', 'staging_status_branch', 'staging_prepare_branch',
    'staging_install_branch', 'backup_branch', 'backup_result_branch', 'recovery_result_branch',
}


def regular(path, maximum):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= maximum:
        raise ValueError('Invalid release file')
    return path.read_bytes()


def candidate_hashes():
    result = {}
    evidence.git('fetch', '--no-tags', '--depth=1', 'origin', STAGING_SOURCE)
    for name in FILES:
        path = ROOT / (PREFIX + name)
        if path.resolve() != path:
            raise ValueError('Linked application path')
        digest = hashlib.sha256(regular(path, 1048576)).hexdigest()
        for revision in ('HEAD', STAGING_SOURCE):
            if hashlib.sha256(evidence.git('show', revision + ':' + PREFIX + name)).hexdigest() != digest:
                raise ValueError('Candidate differs from installed staging source')
        result[name] = digest
    if len(result) != 27:
        raise ValueError('Changed application scope')
    return result


def staging_report(value, mode, hashes):
    branch = STAGING_PREPARE if mode == 'prepare' else STAGING_STATUS
    expected = {'category', 'mode', 'preparation_stage', 'proof', 'remote_tools_removed',
                'run_id', 'schema_version', 'source_commit', 'source_hashes', 'status',
                'target', 'theme_source_hashes'}
    if not isinstance(value, dict) or set(value) != expected or value['status'] != 'success' \
            or value['category'] != 'complete' or value['mode'] != mode \
            or value['target'] != 'staging' or type(value['schema_version']) is not int \
            or value['schema_version'] != 1 or value['source_commit'] != STAGING_TOOLS \
            or value['preparation_stage'] != 'complete' or value['remote_tools_removed'] is not True \
            or value['run_id'] != branch.removeprefix('automation-status/cancellation-requests-'):
        raise ValueError('Incomplete staging receipt')
    proof = value['proof']
    with tempfile.TemporaryDirectory(prefix='petish-cancellation-release-proof-') as directory:
        path = Path(directory) / 'proof.json'
        path.write_text(json.dumps(proof))
        path.chmod(0o600)
        safe, passed = screen.screen(path, mode)
    required = screen.COMMON | (screen.PREPARE if mode == 'prepare' else screen.VERIFY)
    if not passed or safe != proof or set(proof['checks']) != required \
            or any(item is not True for item in proof['checks'].values()):
        raise ValueError('Missing staging checks')
    plugin = {name: hashes['plugins/petish-fulfilment/' + name] for name in FULFILMENT_FILES}
    candidate_revision = evidence.git('rev-parse', 'HEAD').decode('ascii').strip()
    if not re.fullmatch(r'[a-f0-9]{40}', candidate_revision):
        raise ValueError('Invalid candidate revision')
    if proof['source_hashes'] != plugin or value['source_hashes'] != plugin \
            or proof['theme_source_hashes'] != value['theme_source_hashes'] \
            or proof['theme_source_hashes'] != screen.build_theme_manifest(candidate_revision):
        raise ValueError('Staging source fingerprint mismatch')


def install_report(value):
    steps = {'checkout', 'revision', 'source', 'package', 'screening', 'prerequisites', 'execution', 'summary'}
    if not isinstance(value, dict) or value.get('AUTOMATION_KIND') != 'staging' \
            or value.get('AUTOMATION_RESULT') != 'success' \
            or str(value.get('GITHUB_RUN_ID')) + '-' + str(value.get('GITHUB_RUN_ATTEMPT')) != '40056331426-1' \
            or value.get('GITHUB_SHA') != STAGING_SOURCE \
            or value.get('staging_requested_source_commit') != STAGING_SOURCE \
            or value.get('staging_preparation_stage') != 'complete' or value.get('staging_mode') != 'apply' \
            or not isinstance(value.get('staging_runner_steps'), dict) \
            or set(value['staging_runner_steps']) != steps \
            or any(item != 'success' for item in value['staging_runner_steps'].values()) \
            or value.get('task') != {'phase': 'staging', 'categories': [], 'exit_code': 0}:
        raise ValueError('The installed staging candidate has no successful receipt')


def validate(source):
    request = json.loads(regular(source, 4096), object_pairs_hook=screen.no_duplicate_keys)
    if not isinstance(request, dict) or set(request) != FIELDS \
            or request['format'] != 'petish-cancellation-production-v1' \
            or request['staging_source_commit'] != STAGING_SOURCE \
            or request['staging_status_branch'] != STAGING_STATUS \
            or request['staging_prepare_branch'] != STAGING_PREPARE \
            or request['staging_install_branch'] != STAGING_INSTALL:
        raise ValueError('Unreviewed release request')
    for name, pattern in {
        'backup_branch': r'full-site-backup/[1-9][0-9]*-[1-9][0-9]*',
        'backup_result_branch': r'automation-status/backup-[1-9][0-9]*-[1-9][0-9]*',
        'recovery_result_branch': r'automation-status/recovery-[1-9][0-9]*-[1-9][0-9]*',
    }.items():
        if not isinstance(request[name], str) or not re.fullmatch(pattern, request[name]):
            raise ValueError('Invalid evidence branch')
    if request['backup_result_branch'] != 'automation-status/backup-' + request['backup_branch'].split('/')[1]:
        raise ValueError('Backup evidence mismatch')
    hashes = candidate_hashes()
    install_report(evidence.report(STAGING_INSTALL, 'cancellation-production-install'))
    staging_report(evidence.report(STAGING_PREPARE, 'cancellation-production-prepare'), 'prepare', hashes)
    staging_report(evidence.report(STAGING_STATUS, 'cancellation-production-stage'), 'verify', hashes)
    review_evidence.recoverable_backup(request, datetime.now(timezone.utc))
    return {'verified': True, **{key: request[key] for key in sorted(FIELDS - {'format'})}}


if __name__ == '__main__':
    os.umask(0o077)
    try:
        if len(sys.argv) != 3:
            raise ValueError('Expected release request and output paths')
        Path(sys.argv[2]).write_text(json.dumps(validate(Path(sys.argv[1])), indent=2) + '\n')
    except Exception:
        print('Exact staging and recent matching backup/application recovery evidence are required.', file=sys.stderr)
        sys.exit(1)
