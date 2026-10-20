#!/usr/bin/env python3
"""Screen cost-sheet release proofs; never publish catalogue or private costs."""
import importlib.util
import json
import os
from pathlib import Path
import sys


def module(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), Path(__file__).with_name(name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


navigation = module('sanitize-account-navigation-result')
DEPLOY = navigation.DEPLOY
PREFLIGHT = navigation.PREFLIGHT
VERIFY = {
    'target_paths_verified', 'target_environment_verified', 'woocommerce_runtime_verified',
    'all_outbound_http_blocked', 'mail_calls_blocked', 'remote_file_access_disabled',
    'cron_and_async_runner_disabled', 'cost_sheet_classes_loaded', 'admin_hooks_registered',
    'anonymous_access_denied', 'customer_access_denied', 'manager_access_verified',
    'invalid_nonce_rejected', 'valid_nonce_accepted', 'catalogue_template_verified',
    'csv_formula_injection_prevented', 'csv_limits_enforced',
    'exact_identity_and_quantity_verified', 'unknown_cost_not_zero',
    'estimate_not_verified_rate', 'known_calculation_verified', 'admin_preview_rendered',
    'orders_unchanged', 'users_and_authentication_unchanged',
    'product_prices_and_stock_unchanged', 'shipping_policy_and_settings_unchanged',
}
REQUIRED = {
    **{phase: navigation.REQUIRED[phase] for phase in ('inspect', 'apply', 'rollback', 'commit')},
    'preflight': PREFLIGHT, 'verify': VERIFY, 'health': navigation.email.HEALTH_CHECKS,
}
CATEGORIES = navigation.CATEGORIES | {'snapshot', 'verification', 'renderer', 'catalogue', 'fixture'}


def screen(phase, path):
    try:
        if phase not in REQUIRED:
            raise ValueError()
        if phase == 'preflight':
            return navigation.screen(phase, path)
        raw = navigation.rehearsal.read_report(path)
        deploy = phase in {'inspect', 'apply', 'rollback', 'commit'}
        fields = {'status', 'category', 'checks'} | ({'changed_file_count'} if deploy else set())
        if not isinstance(raw, dict) or set(raw) != fields \
                or raw['status'] not in {'success', 'failure'} or raw['category'] not in CATEGORIES \
                or not isinstance(raw['checks'], dict):
            raise ValueError()
        names = DEPLOY if deploy else VERIFY if phase == 'verify' \
            else navigation.email.HEALTH_CHECKS | navigation.email.HEALTH_OBSERVATIONS
        if not set(raw['checks']) <= names or any(type(value) is not bool for value in raw['checks'].values()):
            raise ValueError()
        if deploy and (type(raw['changed_file_count']) is not int or not 0 <= raw['changed_file_count'] <= 3):
            raise ValueError()
        passed = raw['status'] == 'success' and raw['category'] == 'complete' \
            and all(raw['checks'].get(key) is True for key in REQUIRED[phase])
        if not passed:
            raw['status'] = 'failure'
        return raw, passed
    except Exception:
        return {'status': 'failure', 'category': 'invalid_report', 'checks': {}}, False


if __name__ == '__main__':
    os.umask(0o077)
    if len(sys.argv) != 4:
        raise SystemExit(1)
    result, passed = screen(sys.argv[1], Path(sys.argv[2]))
    Path(sys.argv[3]).write_text(json.dumps(result, indent=2) + '\n')
    raise SystemExit(0 if passed else 1)
