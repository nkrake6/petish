#!/usr/bin/env python3
"""Publish only bounded, explicitly typed readiness metadata; never raw errors."""
import json
import math
import os
from pathlib import Path
import re
import stat
import sys

TRACKING_PLUGINS = {'woocommerce-shipment-tracking', 'woo-shipment-tracking',
    'woo-advanced-shipment-tracking', 'advanced-shipment-tracking',
    'aftership-woocommerce-tracking', 'aftership', 'trackship-for-woocommerce', 'dsers'}
PLUGINS = TRACKING_PLUGINS | {'woocommerce', 'woocommerce-payments', 'woopayments',
    'woocommerce-paypal-payments', 'woocommerce-services', 'woocommerce-tax',
    'petish-passport', 'petish-fulfilment', 'litespeed-cache', 'nextend-facebook-connect',
    'nextend-social-login', 'jetpack', 'mailpoet', 'syncee', 'syncee-premium-dropshipping',
    'google-listings-and-ads', 'pinterest-for-woocommerce', 'reddit-for-woocommerce',
    'snapchat-for-woocommerce', 'woocommerce-shipstation-integration'}
HOOKS = {'pre_wp_mail', 'pre_http_request', 'http_request_args', 'template_redirect',
    'init', 'plugins_loaded', 'muplugins_loaded', 'wp_loaded', 'send_headers',
    'woocommerce_webhook_should_deliver', 'woocommerce_webhook_deliver_async',
    'woocommerce_payment_gateways', 'woocommerce_available_payment_gateways',
    'woocommerce_email_enabled', 'woocommerce_email_enabled_new_order',
    'action_scheduler_run_queue', 'wp_mail', 'rest_authentication_errors',
    'login_init', 'wp_authenticate', 'allowed_http_origins', 'admin_init',
    'action_scheduler_allow_async_request_runner', 'action_scheduler_queue_runner_concurrent_batches',
    'pre_schedule_event', 'pre_reschedule_event', 'cron_request', 'jetpack_sync_sender_should_load',
    'jetpack_sync_should_run', 'rest_pre_dispatch', 'wp_headers', 'wp_robots',
    'pre_option_blog_public', 'xmlrpc_enabled'}
META_KEYS = {'_wc_shipment_tracking_items', '_tracking_number', '_tracking_provider',
    '_tracking_link', '_tracking_url', '_dsers_tracking', '_dsers_tracking_number',
    '_dsers_tracking_info', '_aftership_tracking_items', '_aftership_tracking_number',
    '_ast_tracking_items', 'tracking_number', 'tracking_provider', '_date_shipped',
    '_dsers_tracking_provider', '_dsers_tracking_url', '_aftership_tracking_provider',
    '_wc_ast_tracking_number', '_wc_ast_tracking_provider', '_trackship_tracking_number'}
FLAGS = {'hpos_enabled', 'hpos_compatibility_sync_enabled', 'ast_tracking_info_enabled',
    'ast_tracking_in_order_email_enabled', 'ast_tracking_page_enabled',
    'customer_completed_order_email_enabled', 'customer_processing_order_email_enabled'}
TOPICS = {f'{entity}.{event}' for entity in ('order', 'product', 'customer', 'coupon')
          for event in ('created', 'updated', 'deleted', 'restored')} | {'action', 'other',
          'action.woocommerce_order_status_changed', 'action.woocommerce_order_status_completed',
          'action.woocommerce_order_status_processing'}
FAILURES = {'site_unavailable', 'wpcli_unavailable', 'php_unavailable', 'configuration',
    'database_configuration', 'database_driver', 'database_connection', 'selected_options',
    'paths', 'guard', 'catalogue', 'metadata', 'configuration_parser', 'tracking_metadata',
    'tracking_plugins', 'tracking_settings', 'webhooks', 'api_keys', 'private_config',
    'active_plugins_metadata', 'wpconfig_unreadable', 'php_tokenizer_unavailable',
    'input', 'staging_path_validation', 'source_structure', 'staging_configuration_proof',
    'database_separation', 'staging_options', 'checkout_page', 'catalogue_counts',
    'sample_variations', 'complete', 'table_metadata', 'known_plugin_metadata',
    'api_key_metadata', 'webhook_metadata'}


def exact(value, keys):
    if not isinstance(value, dict) or set(value) != set(keys):
        raise ValueError()
    return value


def boolean(value, nullable=False):
    if type(value) is not bool and not (nullable and value is None):
        raise ValueError()
    return value


def number(value, nullable=False, minimum=0):
    if nullable and value is None:
        return None
    if type(value) is not int or not minimum <= value <= 10_000_000:
        raise ValueError()
    return value


def enumeration(value, allowed, nullable=False):
    if nullable and value is None:
        return None
    if not isinstance(value, str) or value not in allowed:
        raise ValueError()
    return value


def sequence(value, maximum=100):
    if not isinstance(value, list) or len(value) > maximum:
        raise ValueError()
    return value


def flags(value, names, nullable=False):
    exact(value, names)
    return {key: boolean(value[key], nullable) for key in names}


def staging(raw):
    names = {'status', 'source_only', 'runtime_isolation_verified', 'paths',
             'configuration', 'guard', 'database', 'woocommerce', 'variations'}
    if 'category' in raw:
        names.add('category')
    exact(raw, names)
    if raw['source_only'] is not True or raw['runtime_isolation_verified'] is not False:
        raise ValueError()
    paths = flags(raw['paths'], {'root_canonical', 'loader_canonical',
        'private_directory_canonical', 'private_directory_0700',
        'private_config_canonical', 'private_config_0600'})
    config = raw['configuration']
    exact(config, {'loader_requires_private', 'environment_staging_literal',
        'wcpay_dev_mode_true_literal', 'disable_wp_cron_true_literal', 'defined_constant_names'})
    safe_config = {key: boolean(value) for key, value in config.items() if key != 'defined_constant_names'}
    safe_config['defined_constant_names'] = []
    for name in sequence(config['defined_constant_names']):
        if not isinstance(name, str) or not re.fullmatch(r'[A-Z][A-Z0-9_]{0,99}', name):
            raise ValueError()
        safe_config['defined_constant_names'].append(name)
    guard = raw['guard']
    exact(guard, {'present', 'canonical', 'sha256', 'hooks_registered',
                  'direct_webhook_false_callback', 'declared_function_names'})
    digest = guard['sha256']
    if digest is not None and (not isinstance(digest, str) or not re.fullmatch('[a-f0-9]{64}', digest)):
        raise ValueError()
    safe_guard = {key: boolean(guard[key]) for key in ('present', 'canonical', 'direct_webhook_false_callback')}
    safe_guard['sha256'] = digest
    safe_guard['hooks_registered'] = [enumeration(hook, HOOKS) for hook in sequence(guard['hooks_registered'])]
    safe_guard['declared_function_names'] = []
    for name in sequence(guard['declared_function_names']):
        if not isinstance(name, str) or not re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,99}', name):
            raise ValueError()
        safe_guard['declared_function_names'].append(name)
    database = flags(raw['database'], {'configuration_read', 'name_differs_from_production',
                                      'host_differs_from_production', 'identity_differs_from_production'}, True)
    boolean(database['configuration_read'])
    woo = raw['woocommerce']
    exact(woo, {'active_plugins', 'active_plugins_outside_allowlist', 'enabled_setting',
               'test_mode_setting', 'checkout_mode', 'published_products', 'active_webhooks'})
    safe_woo = {}
    for key in ('active_plugins', 'active_plugins_outside_allowlist'):
        slugs = sequence(woo[key])
        if any(not isinstance(slug, str) or not re.fullmatch('[a-z0-9][a-z0-9_-]{0,99}', slug) for slug in slugs):
            raise ValueError()
        safe_woo[key] = sorted({slug if slug in PLUGINS else 'other' for slug in slugs})
    safe_woo.update(enabled_setting=boolean(woo['enabled_setting'], True),
                    test_mode_setting=boolean(woo['test_mode_setting'], True),
                    checkout_mode=enumeration(woo['checkout_mode'], {'classic', 'block', 'mixed', 'unknown', 'unverified', 'missing', 'other'}),
                    published_products=number(woo['published_products'], True), active_webhooks=number(woo['active_webhooks'], True))
    variations = []
    for row in sequence(raw['variations'], 5):
        exact(row, {'variation_id', 'published', 'parent_published', 'stock_quantity', 'manage_stock', 'stock_status'})
        if row['variation_id'] not in {246, 153, 2137, 858, 1546}:
            raise ValueError()
        stock = row['stock_quantity']
        if stock is not None and (type(stock) not in (int, float) or not math.isfinite(stock) or not -10_000_000 <= stock <= 10_000_000):
            raise ValueError()
        variations.append({'variation_id': number(row['variation_id']),
            'published': boolean(row['published']), 'parent_published': boolean(row['parent_published']),
            'stock_quantity': stock,
            'manage_stock': boolean(row['manage_stock'], True),
            'stock_status': enumeration(row['stock_status'], {'instock', 'outofstock', 'onbackorder', 'unknown'}, True)})
    if len({row['variation_id'] for row in variations}) != 5:
        raise ValueError()
    result = {'status': enumeration(raw['status'], {'success', 'unavailable'}), 'source_only': True, 'runtime_isolation_verified': False,
            'paths': paths, 'configuration': safe_config, 'guard': safe_guard,
            'database': database, 'woocommerce': safe_woo, 'variations': variations}
    if 'category' in raw:
        result['category'] = enumeration(raw['category'], FAILURES)
    return result


def tracking(raw):
    exact(raw, {'status', 'tracking_plugins', 'dsers_api_keys', 'webhooks',
                'tracking_metadata', 'tracking_settings'})
    plugins = []
    for row in sequence(raw['tracking_plugins'], 20):
        exact(row, {'slug', 'installed', 'active'})
        plugins.append({'slug': enumeration(row['slug'], TRACKING_PLUGINS),
                        'installed': boolean(row['installed']), 'active': boolean(row['active'])})
    api = raw['dsers_api_keys']
    exact(api, {'table_present', 'count', 'permissions', 'last_access_present'})
    safe_api = {'table_present': boolean(api['table_present']), 'count': number(api['count']),
        'permissions': [enumeration(scope, {'read', 'write', 'read_write', 'other'}) for scope in sequence(api['permissions'], 4)],
        'last_access_present': boolean(api['last_access_present'])}
    hooks = raw['webhooks']
    exact(hooks, {'table_present', 'total', 'dsers_active_count', 'dsers_topics', 'callback_classifiers'})
    safe_hooks = {'table_present': boolean(hooks['table_present']), 'total': number(hooks['total']),
        'dsers_active_count': number(hooks['dsers_active_count']),
        'dsers_topics': [enumeration(topic, TOPICS) for topic in sequence(hooks['dsers_topics'], 30)],
        'callback_classifiers': [enumeration(kind, {'dsers', 'other'}) for kind in sequence(hooks['callback_classifiers'], 2)]}
    meta = raw['tracking_metadata']
    exact(meta, {'legacy_table_present', 'hpos_table_present', 'legacy', 'hpos'})
    safe_meta = {key: boolean(meta[key]) for key in ('legacy_table_present', 'hpos_table_present')}
    for storage in ('legacy', 'hpos'):
        safe_meta[storage] = []
        for row in sequence(meta[storage], 30):
            exact(row, {'key', 'count'})
            safe_meta[storage].append({'key': enumeration(row['key'], META_KEYS), 'count': number(row['count'])})
    settings = []
    for row in sequence(raw['tracking_settings'], 20):
        exact(row, {'flag', 'enabled'})
        settings.append({'flag': enumeration(row['flag'], FLAGS), 'enabled': boolean(row['enabled'])})
    return {'status': 'success', 'tracking_plugins': plugins, 'dsers_api_keys': safe_api,
            'webhooks': safe_hooks, 'tracking_metadata': safe_meta, 'tracking_settings': settings}


def sanitize(kind, source):
    result = {'status': 'unavailable', 'category': 'safe_metadata_unavailable'}
    try:
        path = Path(source)
        mode = path.lstat()
        if not stat.S_ISREG(mode.st_mode) or mode.st_size > 65536:
            raise ValueError()
        raw = json.loads(path.read_text())
        if isinstance(raw, dict) and set(raw) == {'status', 'category'} and raw['status'] == 'unavailable':
            result['category'] = enumeration(raw['category'], FAILURES)
            return result
        if kind == 'staging' and isinstance(raw, dict) and raw.get('status') == 'unavailable' and 'paths' in raw:
            return staging(raw)
        if kind == 'staging' and isinstance(raw, dict) and set(raw) == {'status', 'category', 'source_only', 'runtime_isolation_verified'} \
                and raw['status'] == 'unavailable' and raw['source_only'] is True and raw['runtime_isolation_verified'] is False:
            return {'status': 'unavailable', 'category': enumeration(raw['category'], FAILURES), 'source_only': True, 'runtime_isolation_verified': False}
        if not isinstance(raw, dict) or raw.get('status') != 'success':
            raise ValueError()
        return {'staging': staging, 'tracking': tracking}[kind](raw)
    except (OSError, ValueError, TypeError, KeyError):
        return result


if __name__ == '__main__':
    if len(sys.argv) != 4 or sys.argv[1] not in {'staging', 'tracking'}:
        raise SystemExit('Invalid readiness report arguments.')
    result = sanitize(sys.argv[1], sys.argv[2])
    descriptor = os.open(sys.argv[3], os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as target:
        json.dump(result, target, indent=2)
        target.write('\n')
