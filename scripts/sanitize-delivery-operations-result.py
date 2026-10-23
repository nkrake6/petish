#!/usr/bin/env python3
"""Finite public receipts for the staging-only delivery operations proof."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

PREFIX = 'wordpress/wp-content/plugins/petish-fulfilment/'
SOURCE_PATH = re.compile(r'(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:php|js|css|md|json|svg|png|jpg|jpeg|webp|txt)\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
REQUIRED_SOURCE = {
    'petish-fulfilment.php', 'includes/class-petish-automatic-shipping.php',
    'includes/class-petish-delivery-rate-schedules.php',
    'includes/class-petish-shipment-ledger.php',
    'includes/class-petish-shipment-notifications.php',
    'includes/class-petish-delivery-operations-admin.php',
}
COMMON = {
    'target_paths_verified', 'target_environment_verified', 'woocommerce_runtime_verified',
    'staging_database_separate', 'staging_guard_verified', 'jetpack_sync_isolated',
    'all_outbound_http_blocked', 'mail_calls_blocked', 'remote_file_access_disabled',
    'cron_and_async_runner_disabled', 'source_manifest_verified', 'source_unchanged',
    'delivery_classes_loaded', 'reviewed_class_paths_verified', 'admin_hooks_registered',
    'anonymous_access_denied', 'customer_access_denied', 'manager_access_verified',
    'invalid_nonce_rejected', 'valid_nonce_accepted', 'notification_request_nonce_verified',
    'feature_flags_disabled', 'notification_sender_unconfigured', 'disabled_mutations_rejected',
    'private_preview_access_denied', 'notification_api_available',
    'admin_preview_rendered', 'csv_formula_injection_prevented', 'csv_limits_enforced',
    'schema_boundaries_verified', 'active_v1_policy_preserved', 'shipping_options_unchanged',
    'orders_unchanged', 'users_and_authentication_unchanged', 'product_prices_and_stock_unchanged',
    'settings_unchanged', 'existing_passports_unchanged', 'private_photo_files_unchanged',
    'sql_write_attempts_absent', 'native_memory_cache_verified',
}
SCENARIOS = {
    'exact_quantity_total_verified', 'quantity_outside_evidence_rejected',
    'first_additional_total_verified', 'invalid_evidence_rejected',
    'stale_mapping_rejected', 'ambiguous_schedule_rejected', 'v1_per_unit_behavior_preserved',
    'currency_context_verified', 'cache_tags_invalidate', 'cached_rate_guard_verified',
    'catalogue_csv_preview_verified',
}
OBSERVATIONS = {'catalogue_scenario_available', 'hosted_order_mutations_not_exercised',
                'external_supplier_tracking_not_exercised'}
CATEGORIES = {'input', 'paths', 'source', 'bootstrap', 'runtime', 'snapshot', 'admin',
              'contracts', 'catalogue', 'verification', 'readonly', 'complete', 'invalid_report'}


def regular(path, maximum=65536):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= maximum:
        raise ValueError('Invalid report file')
    with path.open('rb') as stream:
        value = stream.read(maximum + 1)
    if len(value) != info.st_size or len(value) > maximum:
        raise ValueError('Report changed while reading')
    return value


def no_duplicate_keys(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('Duplicate JSON key')
        value[key] = item
    return value


def source_manifest(value):
    if not isinstance(value, dict) or not REQUIRED_SOURCE <= set(value) or not 6 <= len(value) <= 128:
        raise ValueError('Incomplete source manifest')
    for name, digest in value.items():
        if not isinstance(name, str) or not SOURCE_PATH.fullmatch(name) or any(part in {'.', '..'} for part in name.split('/')) \
                or not isinstance(digest, str) or not SHA.fullmatch(digest):
            raise ValueError('Invalid source entry')
    return dict(sorted(value.items()))


def build_manifest(revision):
    """Hash exact reviewed Git blobs, never working-tree files or symlink targets."""
    if not re.fullmatch(r'[a-f0-9]{40}', revision):
        raise ValueError('Invalid source revision')
    listing = subprocess.run(['git', 'ls-tree', '-rz', revision, '--', PREFIX], check=True,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
    result = {}
    total = 0
    for entry in listing.rstrip(b'\0').split(b'\0'):
        header, raw_name = entry.split(b'\t', 1)
        mode, kind, blob = header.decode('ascii').split(' ')
        path = raw_name.decode('ascii')
        if mode not in {'100644', '100755'} or kind != 'blob' or not path.startswith(PREFIX):
            raise ValueError('Only tracked regular source files are allowed')
        name = path[len(PREFIX):]
        if not SOURCE_PATH.fullmatch(name) or any(part in {'.', '..'} for part in name.split('/')):
            raise ValueError('Invalid tracked source path')
        size = int(subprocess.run(['git', 'cat-file', '-s', blob], check=True,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout)
        if not 0 < size <= 1048576 or total + size > 8388608 or len(result) >= 128:
            raise ValueError('Source bounds exceeded')
        content = subprocess.run(['git', 'cat-file', 'blob', blob], check=True,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
        total += len(content)
        if len(content) != size:
            raise ValueError('Source bounds exceeded')
        result[name] = hashlib.sha256(content).hexdigest()
    return source_manifest(result)


def screen(path):
    try:
        raw = json.loads(regular(path), object_pairs_hook=no_duplicate_keys)
        if not isinstance(raw, dict) or set(raw) != {'schema_version', 'target', 'status', 'category', 'checks', 'observations', 'source_hashes'} \
                or type(raw['schema_version']) is not int or raw['schema_version'] != 1 or raw['target'] != 'staging' \
                or raw['status'] not in {'success', 'failure'} or raw['category'] not in CATEGORIES:
            raise ValueError('Invalid report')
        for field, names in [('checks', COMMON | SCENARIOS), ('observations', OBSERVATIONS)]:
            if not isinstance(raw[field], dict) or not set(raw[field]) <= names \
                    or any(type(value) is not bool for value in raw[field].values()):
                raise ValueError('Invalid finite checks')
        # A failure before source loading may legitimately have an empty manifest.
        if raw['source_hashes']:
            raw['source_hashes'] = source_manifest(raw['source_hashes'])
        elif raw['source_hashes'] != {}:
            raise ValueError('Invalid manifest')
        required = COMMON | (SCENARIOS if raw['observations'].get('catalogue_scenario_available') is True else set())
        passed = raw['status'] == 'success' and raw['category'] == 'complete' \
            and all(raw['checks'].get(key) is True for key in required) \
            and set(raw['observations']) == OBSERVATIONS \
            and raw['observations']['hosted_order_mutations_not_exercised'] is True \
            and raw['observations']['external_supplier_tracking_not_exercised'] is True \
            and bool(raw['source_hashes'])
        if not passed:
            raw['status'] = 'failure'
        return raw, passed
    except Exception:
        return {'schema_version': 1, 'target': 'staging', 'status': 'failure',
                'category': 'invalid_report', 'checks': {}, 'observations': {}, 'source_hashes': {}}, False


if __name__ == '__main__':
    os.umask(0o077)
    if len(sys.argv) != 4 or sys.argv[1] not in {'manifest', 'verify'}:
        raise SystemExit(1)
    if sys.argv[1] == 'manifest':
        result, passed = build_manifest(sys.argv[2]), True
    else:
        result, passed = screen(Path(sys.argv[2]))
    destination = Path(sys.argv[3])
    with destination.open('x') as stream:
        stream.write(json.dumps(result, sort_keys=True, indent=2) + '\n')
    raise SystemExit(0 if passed else 1)
