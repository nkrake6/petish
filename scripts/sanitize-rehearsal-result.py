#!/usr/bin/env python3
"""Exclude all fixture credentials, order keys and raw application output."""
import json
import os
from pathlib import Path
import re
import stat
import sys

CHECKS = {'environment_staging', 'woocommerce_test_mode', 'wcpay_dev_mode',
    'account_is_live', 'guard_runtime_verified', 'suppliers_blocked',
    'mail_capture_verified', 'webhooks_blocked', 'paths_verified',
    'source_guard_sha_verified', 'database_separate', 'runtime_database_staging',
    'only_allowlisted_plugins', 'no_active_webhooks', 'all_http_forced_blocked',
    'browser_input_written', 'wp_cron_disabled', 'async_runner_disabled',
    'loader_requires_private', 'source_environment_staging', 'source_wcpay_dev_mode',
    'source_wp_cron_disabled', 'source_staging_database_literal', 'source_production_database_literal'}
ACCOUNT = {'cached_account_present', 'account_is_live', 'native_connected',
    'test_onboarding', 'dev_mode', 'test_mode', 'gateway_test_mode', 'payments_enabled'}
NATIVE = {'mode_class', 'mode_methods', 'account_service_class', 'account_service_methods',
    'gateway_class', 'gateway_methods', 'helper_method_calls', 'api_client_class', 'api_client_methods', 'api_method_signatures'}
HOSTS = {'public-api.wordpress.com', 'api.wordpress.com', 'wordpress.com',
    'pay.wordpress.com', 'api.stripe.com', 'js.stripe.com', 'r.stripe.com',
    'm.stripe.com', 'm.stripe.network', 'woocommerce.com', 'connect.woocommerce.com',
    'api.woocommerce.com', 'payments.woocommerce.com'}
CATEGORIES = {'complete', 'input', 'paths', 'source_guard', 'source_configuration',
    'database_separation', 'database_configuration', 'bootstrap', 'runtime',
    'runtime_environment', 'runtime_database', 'active_plugins', 'webhooks',
    'supplier_guard', 'mail_capture', 'native_modes', 'account', 'credentials',
    'browser_input', 'configuration', 'unknown', 'preflight', 'checkout',
    'verification', 'refund', 'cleanup', 'provider', 'guard_hash', 'protected_bootstrap',
    'runtime_isolation', 'native_capabilities', 'native_test_mode_unverified', 'sample_product'}


def identifier(value, nullable=False):
    if nullable and value is None:
        return None
    if not isinstance(value, str) or not re.fullmatch(r'(?:->)?[A-Za-z_\\][A-Za-z0-9_\\:>\-]{0,149}', value):
        raise ValueError()
    return value


def boolean_map(raw, allowed):
    if not isinstance(raw, dict) or not set(raw) <= allowed:
        raise ValueError()
    if any(type(value) is not bool and value is not None for value in raw.values()):
        raise ValueError()
    return raw


def sanitize(source):
    result = {'status': 'unavailable', 'category': 'safe_result_unavailable'}
    try:
        path = Path(source)
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_size > 65536:
            raise ValueError()
        raw = json.loads(path.read_text())
        allowed = {'status', 'category', 'run_id', 'checks', 'native', 'account',
                   'structure', 'guard_allowed_hosts'}
        if not isinstance(raw, dict) or not set(raw) <= allowed:
            raise ValueError()
        if raw.get('status') not in {'success', 'unavailable', 'failure'} or raw.get('category') not in CATEGORIES:
            raise ValueError()
        result = {'status': raw['status'], 'category': raw['category']}
        if 'run_id' in raw:
            if raw['run_id'] is not None and (not isinstance(raw['run_id'], str) or not re.fullmatch('[0-9]+-[0-9]+', raw['run_id'])):
                raise ValueError()
            result['run_id'] = raw['run_id']
        if 'checks' in raw:
            result['checks'] = boolean_map(raw['checks'], CHECKS)
        if 'account' in raw:
            result['account'] = boolean_map(raw['account'], ACCOUNT)
        if 'native' in raw:
            native = raw['native']
            if not isinstance(native, dict) or not set(native) <= NATIVE:
                raise ValueError()
            result['native'] = {}
            for key, value in native.items():
                if key.endswith('_class'):
                    result['native'][key] = identifier(value, True)
                else:
                    if not isinstance(value, list) or len(value) > 200:
                        raise ValueError()
                    result['native'][key] = [identifier(name) for name in value]
        if 'structure' in raw:
            structure = raw['structure']
            if not isinstance(structure, dict) or not set(structure) <= {'credentials', 'checkout_fixture', 'captured_mail'}:
                raise ValueError()
            result['structure'] = {}
            for key, rows in structure.items():
                if not isinstance(rows, list) or len(rows) > 200:
                    raise ValueError()
                safe = []
                for row in rows:
                    if not isinstance(row, dict) or set(row) != {'path', 'type'} \
                            or not isinstance(row['path'], str) or not re.fullmatch(r'[A-Za-z0-9_.\[\]\-]{1,150}', row['path']) \
                            or row['type'] not in {'string', 'integer', 'double', 'boolean', 'NULL', 'array', 'object', 'number', 'null', 'int', 'float', 'bool'}:
                        raise ValueError()
                    safe.append(row)
                result['structure'][key] = safe
        if 'guard_allowed_hosts' in raw:
            hosts = raw['guard_allowed_hosts']
            if not isinstance(hosts, list) or len(hosts) > 30 or any(host not in HOSTS for host in hosts):
                raise ValueError()
            result['guard_allowed_hosts'] = hosts
        return result
    except (OSError, ValueError, TypeError, KeyError):
        return {'status': 'unavailable', 'category': 'safe_result_unavailable'}


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Invalid rehearsal report arguments.')
    descriptor = os.open(sys.argv[2], os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as target:
        json.dump(sanitize(sys.argv[1]), target, indent=2)
        target.write('\n')
