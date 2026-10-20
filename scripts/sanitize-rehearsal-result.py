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
    'source_wp_cron_disabled', 'source_staging_database_literal', 'source_production_database_literal', 'source_production_database_read'}
MODULE_CHECKS = {'prepared', 'shipping_simulated', 'paid_test_order_verified',
    'decline_stock_unchanged', 'paid_stock_reduced_once', 'provider_test_payment_verified',
    'native_order_emails_captured', 'native_test_refund_verified', 'provider_full_test_refund_verified',
    'completed_email_captured', 'refund_email_captured', 'stock_restored_to_baseline',
    'synthetic_tracking_note_only', 'supplier_action_performed', 'shipping_fixture_removed',
    'qa_cart_cleared', 'order_refunded_before_cleanup', 'recovery_required',
    'native_email_contents_verified', 'customer_note_email_captured',
    'email_preview_rendered', 'email_preview_native_renderer',
    'email_preview_unsaved_synthetic_order', 'email_preview_synthetic_content_only',
    'email_preview_no_order_records_created', 'email_preview_stock_unchanged',
    'email_preview_html_sanitized', 'email_preview_branding_logo_present',
    'email_preview_branding_palette_present', 'email_preview_native_order_details_present',
    'email_preview_four_types_rendered', 'email_preview_account_types_rendered',
    'email_preview_no_user_records_created', 'email_preview_no_auth_tokens_generated',
    'email_preview_verification_available', 'email_preview_seven_types_rendered',
    'email_preview_verification_enabled', 'email_preview_guest_checkout_enabled',
    'email_preview_core_account_branding',
    'tracking_reviewed_source_verified', 'tracking_direct_remote_files_blocked',
    'tracking_carrier_fixture_created', 'tracking_carrier_fixture_removed',
    'tracking_migrations_and_downloads_disabled', 'tracking_stored_plugin_configuration_unchanged',
    'tracking_native_plugin_loaded', 'tracking_synthetic_order_created',
    'tracking_native_rest_registered', 'tracking_anonymous_denied', 'tracking_customer_denied',
    'tracking_first_parcel_partial', 'tracking_two_parcels_preserved', 'tracking_final_parcel_completed',
    'tracking_partial_email_branded', 'tracking_completed_email_branded',
    'tracking_account_widget_two_parcels', 'tracking_synthetic_carrier_link_preserved',
    'tracking_replay_duplicates_observed', 'tracking_replay_email_repeated',
    'tracking_blank_number_accepted', 'tracking_no_payment_performed',
    'tracking_no_product_stock_used', 'tracking_synthetic_order_removed',
    'tracking_supplier_actions_blocked', 'tracking_external_dsers_push_verified',
    'tracking_receiver_guard_active', 'tracking_replay_idempotent',
    'tracking_replay_email_suppressed', 'tracking_blank_number_rejected',
    'tracking_destructive_replace_rejected', 'tracking_premature_completion_rejected',
    'tracking_guard_lock_released'}
TRACKING_REQUIRED = {
    'environment_staging', 'woocommerce_test_mode', 'wcpay_dev_mode',
    'guard_runtime_verified', 'suppliers_blocked', 'mail_capture_verified', 'webhooks_blocked',
    'database_separate', 'runtime_database_staging', 'only_allowlisted_plugins', 'no_active_webhooks',
    'all_http_forced_blocked', 'paths_verified', 'source_guard_sha_verified',
    'tracking_native_rest_registered', 'tracking_anonymous_denied', 'tracking_customer_denied',
    'tracking_first_parcel_partial', 'tracking_two_parcels_preserved', 'tracking_final_parcel_completed',
    'tracking_partial_email_branded', 'tracking_completed_email_branded', 'tracking_account_widget_two_parcels',
    'tracking_no_payment_performed', 'tracking_no_product_stock_used', 'tracking_synthetic_order_removed',
    'tracking_supplier_actions_blocked', 'tracking_reviewed_source_verified',
    'tracking_direct_remote_files_blocked', 'tracking_carrier_fixture_created',
    'tracking_carrier_fixture_removed', 'tracking_migrations_and_downloads_disabled',
    'tracking_stored_plugin_configuration_unchanged', 'tracking_native_plugin_loaded',
    'tracking_synthetic_order_created', 'tracking_synthetic_carrier_link_preserved',
    'tracking_receiver_guard_active', 'tracking_replay_idempotent',
    'tracking_replay_email_suppressed', 'tracking_blank_number_rejected',
    'tracking_destructive_replace_rejected', 'tracking_premature_completion_rejected',
    'tracking_guard_lock_released',
}
TRACKING_REQUIRED_FALSE = {
    'account_is_live', 'tracking_external_dsers_push_verified',
    'tracking_replay_duplicates_observed', 'tracking_replay_email_repeated',
    'tracking_blank_number_accepted',
}
PHASES = {'preflight', 'prepare', 'verify', 'refund', 'cleanup', 'email-preview', 'tracking-rehearsal'}
BROWSER_CHECKS = {'browser_started', 'customer_login_verified', 'cart_prepared',
    'checkout_rendered', 'shipping_verified', 'test_publishable_key_verified',
    'decline_verified', 'paid_receipt_verified', 'native_checkout_used',
    'unsafe_request_blocked', 'same_order_retried', 'cart_page_loaded',
    'cart_api_available', 'cart_empty_verified', 'variation_add_request_completed',
    'expected_variation_in_cart', 'shipping_cart_api_available',
    'shipping_one_package_verified', 'shipping_customer_destination_verified',
    'shipping_package_destination_verified', 'shipping_expected_rate_present',
    'shipping_expected_rate_selected', 'shipping_customer_update_verified',
    'checkout_currency_verified', 'checkout_calc_totals', 'calc_totals_update_allowed'}
BROWSER_HTTP_FIELDS = {'cart_page_http_status', 'cart_api_http_status', 'shipping_api_http_status'}
BROWSER_COUNT_LIMITS = {'shipping_package_count': 20, 'shipping_rate_count': 50}
BROWSER_REQUEST_ENUMS = {
    'unsafe_reason': {'live_key_observed', 'live_response_observed',
                      'checkout_method_unexpected', 'checkout_test_key_missing'},
    'checkout_request_method': {'expected', 'empty', 'missing', 'express', 'other'},
    'checkout_body_format': {'json', 'form', 'unparseable'},
    'checkout_method_override': {'none', 'put', 'patch', 'delete', 'other'},
    'stripe_endpoint': {'payment_methods', 'payment_intents', 'setup_intents', 'elements_sessions', 'other'},
}
BROWSER_CART_STEPS = {'open', 'empty', 'add', 'verify', 'api'}
BROWSER_CART_ERRORS = {'none', 'rest_not_logged_in', 'rest_cookie_invalid_nonce',
    'rest_forbidden', 'rest_cannot_view', 'woocommerce_rest_authentication_error',
    'woocommerce_rest_missing_session', 'woocommerce_rest_cart_error',
    'woocommerce_rest_cart_item_error', 'woocommerce_rest_missing_nonce',
    'woocommerce_rest_invalid_nonce', 'wp_rest_nonce_unavailable', 'network',
    'invalid_response', 'other', 'rest_invalid_param', 'woocommerce_rest_invalid_param',
    'woocommerce_rest_customer_error', 'woocommerce_rest_invalid_address'}
BROWSER_STAGES = {'input', 'browser', 'login', 'cart', 'checkout', 'address',
    'shipping', 'test_key', 'decline', 'retry', 'receipt', 'complete', 'network'}
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
    'runtime_isolation', 'native_capabilities', 'native_test_mode_unverified', 'sample_product',
    'prepare', 'verify', 'provider_identity', 'provider_order_reference',
    'provider_request', 'native_provider_response', 'email_preview', 'tracking_bootstrap', 'tracking_rehearsal'}
STRUCTURES = {'credentials', 'checkout_fixture', 'captured_mail', 'cached_account', 'provider_charge'}
PRIVATE_STRUCTURE_ID = re.compile(
    r'(?:^|[.\[\]])(?:(?:acct|ch|pi|pm|cus|re|tok|src|seti|cs|txn|tr|po|dp|sub|in|il|ba|wc_order)_'
    r'[A-Za-z0-9_]{8,}|(?:sk|pk|rk)_(?:test|live)_[A-Za-z0-9_]+|whsec_[A-Za-z0-9_]+)(?=$|[.\[\]])'
)


def read_report(source):
    """Bound input without following links or blocking on a substituted FIFO."""
    path = Path(source)
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 65536:
        raise ValueError()
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        opened = os.fstat(descriptor)
        if not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1 \
                or (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
            raise ValueError()
        with os.fdopen(descriptor, 'rb') as stream:
            descriptor = None
            contents = stream.read(65537)
        if len(contents) > 65536:
            raise ValueError()
        return json.loads(contents)
    finally:
        if descriptor is not None:
            os.close(descriptor)


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
    validation_stage = 'input'
    try:
        raw = read_report(source)
        validation_stage = 'schema'
        allowed = {'status', 'category', 'run_id', 'checks', 'native', 'account',
                   'structure', 'guard_allowed_hosts', 'phase'}
        if not isinstance(raw, dict) or not set(raw) <= allowed:
            raise ValueError()
        validation_stage = 'status_category'
        if raw.get('status') not in {'success', 'unavailable', 'failure'} or raw.get('category') not in CATEGORIES:
            raise ValueError()
        result = {'status': raw['status'], 'category': raw['category']}
        if 'phase' in raw:
            validation_stage = 'phase'
            if raw['phase'] not in PHASES:
                raise ValueError()
            result['phase'] = raw['phase']
        if 'run_id' in raw:
            validation_stage = 'run_id'
            if raw['run_id'] is not None and (not isinstance(raw['run_id'], str) or not re.fullmatch('[0-9]+-[0-9]+', raw['run_id'])):
                raise ValueError()
            result['run_id'] = raw['run_id']
        if 'checks' in raw:
            validation_stage = 'checks'
            result['checks'] = boolean_map(raw['checks'], CHECKS | MODULE_CHECKS)
            if any(type(value) is not bool for key, value in raw['checks'].items() if key in MODULE_CHECKS):
                raise ValueError()
        if 'account' in raw:
            validation_stage = 'account'
            result['account'] = boolean_map(raw['account'], ACCOUNT)
        if 'native' in raw:
            validation_stage = 'native'
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
            validation_stage = 'structure'
            structure = raw['structure']
            if not isinstance(structure, dict) or not set(structure) <= STRUCTURES:
                raise ValueError()
            result['structure'] = {}
            for key, rows in structure.items():
                if not isinstance(rows, list) or len(rows) > 200:
                    raise ValueError()
                safe = []
                for row in rows:
                    if not isinstance(row, dict) or set(row) != {'path', 'type'} \
                            or not isinstance(row['path'], str) or not re.fullmatch(r'[A-Za-z0-9_.\[\]\-]{1,150}', row['path']) \
                            or PRIVATE_STRUCTURE_ID.search(row['path']) \
                            or row['type'] not in {'string', 'integer', 'double', 'boolean', 'NULL', 'array', 'object', 'number', 'null', 'int', 'float', 'bool'}:
                        raise ValueError()
                    safe.append(row)
                result['structure'][key] = safe
        if 'guard_allowed_hosts' in raw:
            validation_stage = 'provider_hosts'
            hosts = raw['guard_allowed_hosts']
            if not isinstance(hosts, list) or len(hosts) > 30 or any(not isinstance(host, str) or (host not in HOSTS and not re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]{0,200})\.(?:wordpress\.com|woocommerce\.com|wp\.com|stripe\.com|wcpayments\.com)', host)) for host in hosts):
                raise ValueError()
            result['guard_allowed_hosts'] = hosts
        return result
    except (OSError, ValueError, TypeError, KeyError, RecursionError):
        return {'status': 'unavailable', 'category': 'safe_result_unavailable', 'validation_stage': validation_stage}


def tracking_rehearsal_passed(report):
    """A protected rehearsal must prevent the previously observed receiver risks."""
    if not isinstance(report, dict) or not isinstance(report.get('checks'), dict):
        return False
    checks = report['checks']
    return (report.get('status') == 'success' and report.get('category') == 'complete'
            and report.get('phase') == 'tracking-rehearsal'
            and all(checks.get(key) is True for key in TRACKING_REQUIRED)
            and all(checks.get(key) is False for key in TRACKING_REQUIRED_FALSE))


def sanitize_browser(source):
    """Accept exactly the finite browser publicReport fields, never its private file."""
    validation_stage = 'input'
    try:
        raw = read_report(source)
        validation_stage = 'browser'
        if not isinstance(raw, dict) or not set(raw) <= BROWSER_CHECKS | BROWSER_HTTP_FIELDS | set(BROWSER_COUNT_LIMITS) | set(BROWSER_REQUEST_ENUMS) \
                | {'payment_attempts', 'failure_stage', 'cart_failure_step', 'cart_api_error_code', 'shipping_api_error_code'}:
            raise ValueError()
        if any(type(value) is not bool for key, value in raw.items() if key in BROWSER_CHECKS):
            raise ValueError()
        if type(raw.get('payment_attempts')) is not int or not 0 <= raw['payment_attempts'] <= 2:
            raise ValueError()
        if 'failure_stage' in raw and raw['failure_stage'] not in BROWSER_STAGES:
            raise ValueError()
        for field in BROWSER_HTTP_FIELDS:
            if field in raw and (type(raw[field]) is not int or not (raw[field] == 0 or 100 <= raw[field] <= 599)):
                raise ValueError()
        for field, maximum in BROWSER_COUNT_LIMITS.items():
            if field in raw and (type(raw[field]) is not int or not 0 <= raw[field] <= maximum):
                raise ValueError()
        for field, allowed in BROWSER_REQUEST_ENUMS.items():
            if field in raw and raw[field] not in allowed:
                raise ValueError()
        if 'cart_failure_step' in raw and raw['cart_failure_step'] not in BROWSER_CART_STEPS:
            raise ValueError()
        for field in ('cart_api_error_code', 'shipping_api_error_code'):
            if field in raw and raw[field] not in BROWSER_CART_ERRORS:
                raise ValueError()
        return raw
    except (OSError, ValueError, TypeError, KeyError, RecursionError):
        return {'status': 'unavailable', 'category': 'safe_result_unavailable', 'validation_stage': validation_stage}


if __name__ == '__main__':
    browser = len(sys.argv) == 4 and sys.argv[1] == '--browser'
    if not browser and len(sys.argv) != 3:
        raise SystemExit('Invalid rehearsal report arguments.')
    source, output = sys.argv[2:] if browser else sys.argv[1:]
    try:
        descriptor = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'w') as target:
            json.dump(sanitize_browser(source) if browser else sanitize(source), target, indent=2)
            target.write('\n')
    except (OSError, ValueError):
        raise SystemExit('Rehearsal report output could not be created.')
