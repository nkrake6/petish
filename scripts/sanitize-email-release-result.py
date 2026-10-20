#!/usr/bin/env python3
"""Publish finite release checks only; raw WordPress output never leaves runner."""
import json
import os
from pathlib import Path
import stat
import sys

DEPLOY_CHECKS = {'paths_verified','candidate_manifest_verified','candidate_syntax_verified',
    'logo_verified','production_config_present','live_files_compatible','protected_state_unchanged',
    'rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned',
    'logo_world_readable','logo_path_world_traversable'}
VERIFY_CHECKS = {'production_paths_verified','approved_full_animal_logo_verified',
    'production_environment_verified','tested_theme_and_woocommerce_verified',
    'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled',
    'cron_and_async_runner_disabled','synthetic_unsaved_order_and_user',
    'seven_native_email_types_available','order_email_html_branded','four_order_types_rendered',
    'account_email_html_branded','verification_button_readable_and_native_url_preserved',
    'three_account_types_rendered','core_welcome_and_reset_filters_verified',
    'order_counts_unchanged','user_counts_unchanged','authentication_state_unchanged',
    'product_stock_unchanged','email_and_account_settings_unchanged',
    'seven_native_emails_rendered_without_dispatch'}
HEALTH_CHECKS = {'homepage_http_verified','shop_http_verified','product_http_verified',
                 'cart_http_verified','account_http_verified','approved_logo_http_verified'}
HEALTH_OBSERVATIONS = {'logo_http_fetch_succeeded','logo_http_is_png','logo_http_is_webp',
                      'logo_http_forbidden','logo_http_not_found','logo_http_server_error',
                      'logo_http_file_bytes_match','logo_http_dimensions_match','logo_http_pixels_match'}
CATEGORIES = {'input','paths','payload','logo','drift','protected_state','lock','snapshot',
              'deployment','rollback','commit','complete','bootstrap','runtime','renderer','verification','http'}
BASE = {'paths_verified','candidate_manifest_verified','candidate_syntax_verified',
        'logo_verified','production_config_present','live_files_compatible'}
REQUIRED = {'inspect':BASE, 'apply':BASE|{'protected_state_unchanged','rollback_snapshot_created','files_deployed','lock_owned'},
            'rollback':{'rollback_completed'}, 'commit':{'protected_state_unchanged','release_committed'},
            'verify':VERIFY_CHECKS, 'health':HEALTH_CHECKS}


def screen(phase, path):
    failure = {'status':'failure','category':'invalid_report','checks':{}}
    try:
        if phase not in REQUIRED:
            raise ValueError()
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 16384:
            raise ValueError()
        fd = os.open(path, os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as source:
            opened = os.fstat(source.fileno())
            if (opened.st_dev,opened.st_ino) != (info.st_dev,info.st_ino):
                raise ValueError()
            data = source.read(16385)
        if len(data) > 16384:
            raise ValueError()
        raw = json.loads(data)
        allowed = {'status','category','checks'} | ({'changed_file_count'} if phase in {'inspect','apply','rollback','commit'} else set())
        if not isinstance(raw, dict) or set(raw) != allowed or raw['status'] not in {'success','failure'} \
                or raw['category'] not in CATEGORIES or not isinstance(raw['checks'], dict):
            raise ValueError()
        names = DEPLOY_CHECKS if phase in {'inspect','apply','rollback','commit'} else VERIFY_CHECKS if phase == 'verify' else HEALTH_CHECKS | HEALTH_OBSERVATIONS
        if not set(raw['checks']) <= names or any(type(value) is not bool for value in raw['checks'].values()):
            raise ValueError()
        if 'changed_file_count' in raw and (type(raw['changed_file_count']) is not int or not 0 <= raw['changed_file_count'] <= 6):
            raise ValueError()
        success = raw['status'] == 'success' and raw['category'] == 'complete' \
            and all(raw['checks'].get(name) is True for name in REQUIRED[phase])
        if not success:
            raw['status'] = 'failure'
        return raw, success
    except Exception:
        return failure, False


if __name__ == '__main__':
    os.umask(0o077)
    if len(sys.argv) != 4:
        sys.exit(1)
    report, passed = screen(sys.argv[1], Path(sys.argv[2]))
    Path(sys.argv[3]).write_text(json.dumps(report, indent=2) + '\n')
    sys.exit(0 if passed else 1)
