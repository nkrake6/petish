#!/usr/bin/env python3
"""Publish only the strictly bounded facts returned by the read-only probe."""
import json
import re
import sys
from pathlib import Path

METHODS = {
    'alipay', 'amazon_pay', 'apple_pay', 'sofort', 'giropay', 'google_pay',
    'bancontact', 'eps', 'ideal', 'p24', 'card', 'sepa_debit', 'au_becs_debit',
    'link', 'affirm', 'afterpay_clearpay', 'klarna', 'grabpay', 'jcb',
    'multibanco', 'wechat_pay',
}


def keys(value, expected):
    if not isinstance(value, dict) or set(value) != set(expected.split()):
        raise ValueError()


def boolean(value, nullable=False):
    if type(value) is not bool and not (nullable and value is None):
        raise ValueError()


def enum(value, choices):
    if not isinstance(value, str) or value not in choices.split():
        raise ValueError()


def code(value, length):
    if not isinstance(value, str) or not (value == 'unknown' or re.fullmatch(r'[A-Z]{%d}' % length, value)):
        raise ValueError()


def validate(value):
    keys(value, 'status store_country store_currency checkout_page_content active_plugins wcpay cached_account source_matches_11_2_0')
    enum(value['status'], 'success')
    code(value['store_country'], 2)
    code(value['store_currency'], 3)
    enum(value['checkout_page_content'], 'classic block mixed other missing')
    plugins = value['active_plugins']
    keys(plugins, 'woocommerce_payments woocommerce_stripe afterpay_plugin woocommerce_paypal_payments')
    for item in plugins.values():
        boolean(item)
    settings = value['wcpay']
    keys(settings, 'installed_version settings_present enabled test_mode manual_capture enabled_methods enabled_methods_setting_present unknown_method_present afterpay_gateway_setting_present afterpay_gateway_enabled afterpay_gateway_method_selected')
    version = settings['installed_version']
    if not isinstance(version, str) or not (version == 'unknown' or re.fullmatch(r'[0-9]{1,3}(?:\.[0-9]{1,3}){1,3}', version)):
        raise ValueError()
    for name in ('settings_present', 'enabled_methods_setting_present', 'unknown_method_present', 'afterpay_gateway_setting_present'):
        boolean(settings[name])
    for name in ('enabled', 'test_mode', 'manual_capture', 'afterpay_gateway_enabled', 'afterpay_gateway_method_selected'):
        enum(settings[name], 'yes no unknown')
    methods = settings['enabled_methods']
    if not isinstance(methods, list) or len(methods) > len(METHODS) or any(not isinstance(m, str) or m not in METHODS for m in methods) or len(methods) != len(set(methods)):
        raise ValueError()
    account = value['cached_account']
    keys(account, 'present country default_currency payments_enabled afterpay_capability afterpay_requirements_present age_seconds errored')
    boolean(account['present'])
    code(account['country'], 2)
    code(account['default_currency'], 3)
    boolean(account['payments_enabled'], nullable=True)
    enum(account['afterpay_capability'], 'active inactive pending unrequested missing unknown')
    boolean(account['afterpay_requirements_present'])
    boolean(account['errored'], nullable=True)
    if type(account['age_seconds']) is not int or not -1 <= account['age_seconds'] <= 315360000:
        raise ValueError()
    hashes = value['source_matches_11_2_0']
    keys(hashes, 'definition gateway method messaging')
    for item in hashes.values():
        boolean(item)
    return value


def main():
    try:
        if len(sys.argv) != 3:
            raise ValueError()
        source, target = map(Path, sys.argv[1:])
        if source.stat().st_size > 8192:
            raise ValueError()
        value = validate(json.loads(source.read_text()))
        target.write_text(json.dumps(value, indent=2, sort_keys=True) + '\n')
    except (OSError, ValueError, TypeError, KeyError, OverflowError):
        raise SystemExit('Afterpay report validation failed; private output was not published.')


if __name__ == '__main__':
    main()
