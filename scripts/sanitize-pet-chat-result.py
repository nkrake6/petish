#!/usr/bin/env python3
"""Finite, public receipts for the single-flag Pet Chat staging rehearsal."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

PREFIX = 'wordpress/wp-content/plugins/petish-passport/'
REQUIRED_SOURCE = {
    'petish-passport.php', 'includes/class-petish-chat.php',
    'includes/class-petish-chat-ui.php', 'templates/pet-chat.php',
    'assets/pet-chat.css', 'assets/pet-chat.js',
}
SOURCE_PATH = re.compile(r'(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:php|js|css|md|json|svg|png|jpg|jpeg|webp|txt)\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
CHECKS = {
    'target_paths_verified', 'target_environment_verified', 'woocommerce_runtime_verified',
    'staging_database_separate', 'staging_guard_verified', 'jetpack_sync_isolated',
    'all_outbound_http_blocked', 'mail_calls_blocked', 'remote_file_access_disabled',
    'cron_and_async_runner_disabled', 'native_memory_cache_verified',
    'source_manifest_verified', 'source_unchanged', 'chat_classes_loaded',
    'reviewed_class_paths_verified', 'private_topic_registration_verified',
    'chat_defaults_verified', 'ui_routes_registered', 'mutation_hooks_registered',
    'public_reader_verified', 'foreign_pending_reader_denied', 'guest_mutations_denied',
    'request_method_and_nonce_verified', 'synthetic_public_render_verified',
    'chat_assets_scoped', 'flag_enabled', 'single_flag_write_scope_verified',
    'flag_transactional_table_verified', 'flag_no_table_triggers',
    'flag_transaction_committed', 'orders_unchanged', 'users_and_authentication_unchanged',
    'product_prices_and_stock_unchanged', 'existing_passports_unchanged',
    'private_photo_files_unchanged', 'chat_records_unchanged',
    'settings_except_chat_flag_unchanged', 'existing_feature_flags_unchanged',
    'sql_write_attempts_absent',
}
OBSERVATIONS = {
    'persisted_fixtures_not_created', 'message_mutations_not_exercised',
    'browser_interaction_not_exercised', 'production_not_bootstrapped',
}
CATEGORIES = {'input', 'paths', 'source', 'bootstrap', 'runtime', 'snapshot',
              'contracts', 'controls', 'activation', 'complete', 'invalid_report'}


def regular(path, maximum=65536):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= maximum:
        raise ValueError('Invalid bounded file')
    with path.open('rb') as stream:
        value = stream.read(maximum + 1)
    if len(value) != info.st_size or len(value) > maximum:
        raise ValueError('File changed while reading')
    return value


def no_duplicate_keys(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('Duplicate JSON key')
        value[key] = item
    return value


def source_manifest(value):
    if not isinstance(value, dict) or not REQUIRED_SOURCE <= set(value) or not len(REQUIRED_SOURCE) <= len(value) <= 128:
        raise ValueError('Incomplete source manifest')
    for name, digest in value.items():
        if not isinstance(name, str) or not SOURCE_PATH.fullmatch(name) or any(part in {'.', '..'} for part in name.split('/')) \
                or not isinstance(digest, str) or not SHA.fullmatch(digest):
            raise ValueError('Invalid source entry')
    return dict(sorted(value.items()))


def build_manifest(revision):
    """Read only immutable regular Git blobs, without copying the plugin tree."""
    if not isinstance(revision, str) or not re.fullmatch(r'[a-f0-9]{40}', revision):
        raise ValueError('Invalid source revision')
    listing = subprocess.run(['git', 'ls-tree', '-rz', revision, '--', PREFIX], check=True,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
    result, total = {}, 0
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


def invalid():
    return {'schema_version': 1, 'target': 'staging', 'mode': 'verify-enable',
            'status': 'failure', 'category': 'invalid_report', 'checks': {},
            'observations': {}, 'source_hashes': {}}


def screen(path):
    try:
        raw = json.loads(regular(path), object_pairs_hook=no_duplicate_keys)
        if not isinstance(raw, dict) or set(raw) != set(invalid()) \
                or type(raw['schema_version']) is not int or raw['schema_version'] != 1 \
                or raw['target'] != 'staging' or raw['mode'] != 'verify-enable' \
                or raw['status'] not in {'success', 'failure'} or raw['category'] not in CATEGORIES:
            raise ValueError('Invalid report')
        for field, names in [('checks', CHECKS), ('observations', OBSERVATIONS)]:
            if not isinstance(raw[field], dict) or not set(raw[field]) <= names \
                    or any(type(value) is not bool for value in raw[field].values()):
                raise ValueError('Invalid finite checks')
        if raw['source_hashes']:
            raw['source_hashes'] = source_manifest(raw['source_hashes'])
        elif raw['source_hashes'] != {}:
            raise ValueError('Invalid source manifest')
        passed = raw['status'] == 'success' and raw['category'] == 'complete' \
            and bool(raw['source_hashes']) and set(raw['checks']) == CHECKS \
            and all(raw['checks'].values()) and set(raw['observations']) == OBSERVATIONS \
            and all(raw['observations'].values())
        if not passed:
            raw['status'] = 'failure'
        return raw, passed
    except Exception:
        return invalid(), False


if __name__ == '__main__':
    os.umask(0o077)
    if len(sys.argv) != 4 or sys.argv[1] not in {'manifest', 'verify-enable'}:
        raise SystemExit(1)
    if sys.argv[1] == 'manifest':
        result, passed = build_manifest(sys.argv[2]), True
    else:
        result, passed = screen(Path(sys.argv[2]))
    with Path(sys.argv[3]).open('x') as stream:
        stream.write(json.dumps(result, sort_keys=True, indent=2) + '\n')
    raise SystemExit(0 if passed else 1)
