#!/usr/bin/env python3
"""Publish finite navigation checks, keeping native output and credentials private."""
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


email = module('sanitize-email-release-result')
rehearsal = module('sanitize-rehearsal-result')
DEPLOY = email.DEPLOY_CHECKS - {'logo_verified', 'logo_world_readable', 'logo_path_world_traversable'}
BASE = email.BASE - {'logo_verified'}
BROWSER = {'browser_started', 'customer_login_verified', 'native_navigation_links_present',
    'native_hrefs_unchanged', 'my_pets_390_verified', 'my_pets_320_verified',
    'orders_390_verified', 'orders_320_verified', 'edit_account_390_verified',
    'edit_account_320_verified', 'current_label_verified', 'mobile_compact_closed',
    'toggle_opens', 'escape_closes_focus_returns', 'outside_closes', 'focusout_closes',
    'desktop_visible', 'resize_visible', 'no_js_fallback_visible', 'dark_mode_readable',
    'unsafe_requests_blocked'}
BROWSER_STAGES = {'input','browser','login_page','login_form','login_submit','login_cookie',
    'qa_identity','no_js','navigation','mobile','desktop','dark_mode','complete'}
BROWSER_DIAGNOSTICS = {'login_page_http_ok','login_form_present','login_submit_clicked',
    'login_post_permitted','login_post_blocked','login_cookie_present','login_redirect_account',
    'account_email_field_present','qa_email_matches'}
VERIFY = {'production_paths_verified','production_environment_verified',
    'tested_theme_and_woocommerce_verified','all_outbound_http_blocked','mail_calls_blocked',
    'remote_file_access_disabled','cron_and_async_runner_disabled','production_data_snapshot_verified',
    'anonymous_render_context_verified','navigation_assets_present','account_assets_enqueued',
    'asset_versions_match_files','navigation_strings_localized','native_account_menu_items_preserved',
    'passport_menu_items_present','native_navigation_rendered','current_endpoint_labels_verified',
    'logout_link_preserved','order_counts_unchanged','user_counts_unchanged',
    'authentication_state_unchanged','product_stock_unchanged','account_settings_unchanged'}
PREFLIGHT = {'paths_verified','source_guard_sha_verified','database_separate',
    'runtime_database_staging','environment_staging','woocommerce_test_mode',
    'wcpay_dev_mode','guard_runtime_verified','suppliers_blocked','mail_capture_verified',
    'webhooks_blocked','only_allowlisted_plugins','no_active_webhooks',
    'all_http_forced_blocked','browser_input_written','source_wp_cron_disabled'}
REQUIRED = {'inspect': BASE, 'apply': BASE | {'protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','lock_owned'},
    'rollback': {'rollback_completed'}, 'commit': {'protected_state_unchanged','release_committed'},
    'browser': BROWSER, 'verify': VERIFY, 'preflight': PREFLIGHT, 'health': email.HEALTH_CHECKS}
CATEGORIES = email.CATEGORIES | {'browser','login','navigation','links','mobile','keyboard',
    'desktop','resize','no_js','dark_mode','network','assets','enqueue','menu'} | rehearsal.CATEGORIES


def screen(phase, path):
    try:
        if phase not in REQUIRED:
            raise ValueError()
        if phase == 'preflight':
            raw = rehearsal.sanitize(path)
            checks = raw.get('checks', {})
            if checks.get('account_is_live') is not False:
                raise ValueError()
            raw = {'status': raw['status'], 'category': raw['category'],
                   'checks': {key: checks[key] for key in PREFLIGHT}}
        else:
            raw = rehearsal.read_report(path)
        deploy = phase in {'inspect','apply','rollback','commit'}
        fields = {'status','category','checks'} | ({'changed_file_count'} if deploy else set())
        if not isinstance(raw, dict):
            raise ValueError()
        if phase == 'browser' and set(raw) == fields | {'failure_stage','diagnostics'}:
            if raw['failure_stage'] not in BROWSER_STAGES or not isinstance(raw['diagnostics'],dict) \
                    or set(raw['diagnostics']) != BROWSER_DIAGNOSTICS \
                    or any(type(value) is not bool for value in raw['diagnostics'].values()):
                raise ValueError()
            fields |= {'failure_stage','diagnostics'}
        if set(raw) != fields or raw['status'] not in {'success','failure'} \
                or raw['category'] not in CATEGORIES or not isinstance(raw['checks'],dict):
            raise ValueError()
        names = DEPLOY if deploy else BROWSER if phase == 'browser' else VERIFY if phase == 'verify' \
            else PREFLIGHT if phase == 'preflight' else email.HEALTH_CHECKS | email.HEALTH_OBSERVATIONS
        if not set(raw['checks']) <= names or any(type(value) is not bool for value in raw['checks'].values()):
            raise ValueError()
        if deploy and (type(raw['changed_file_count']) is not int or not 0 <= raw['changed_file_count'] <= 3):
            raise ValueError()
        success = raw['status'] == 'success' and raw['category'] == 'complete' \
            and bool(REQUIRED[phase]) and all(raw['checks'].get(key) is True for key in REQUIRED[phase])
        if not success:
            raw['status'] = 'failure'
        return raw, success
    except Exception:
        return {'status':'failure','category':'invalid_report','checks':{}}, False


if __name__ == '__main__':
    os.umask(0o077)
    if len(sys.argv) != 4:
        sys.exit(1)
    result, passed = screen(sys.argv[1], Path(sys.argv[2]))
    Path(sys.argv[3]).write_text(json.dumps(result,indent=2) + '\n')
    sys.exit(0 if passed else 1)
