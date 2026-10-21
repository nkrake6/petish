#!/usr/bin/env python3
"""Publish only finite Pet Club verification checks; private pet data stays remote."""
import importlib.util
import json
import os
import re
from pathlib import Path
import sys


def module(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), Path(__file__).with_name(name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


navigation = module('sanitize-account-navigation-result')
DEPLOY = navigation.DEPLOY | {'maintenance_guard_created', 'maintenance_guard_removed'}
PREFLIGHT = navigation.PREFLIGHT
FILE_COUNT = 15
STAGING = {
    'all_outbound_http_blocked', 'anonymous_heart_denied', 'biography_and_traits_saved',
    'cron_and_async_runner_disabled', 'existing_passports_unchanged', 'explicit_public_snapshot_created',
    'fixture_cleanup_verified', 'foreign_owner_denied', 'heart_idempotent',
    'heart_removal_verified', 'legacy_assets_preserved', 'legacy_defaults_preserved',
    'mail_calls_blocked', 'moderation_hides_profile', 'mutation_hooks_registered',
    'only_selected_moments_shared', 'orders_unchanged', 'owned_fixture_pet_created',
    'owned_fixture_users_created', 'pet_club_assets_enqueued', 'pet_club_classes_loaded',
    'photo_change_unpublishes', 'private_media_owner_scoped', 'private_moments_owner_scoped',
    'private_owner_access_verified', 'private_photo_files_unchanged', 'private_record_registration_verified',
    'product_prices_and_stock_unchanged', 'public_photos_are_separate', 'public_projection_allowlisted',
    'public_routes_registered', 'remote_file_access_disabled', 'report_deduplicated',
    'request_nonce_and_method_verified', 'self_heart_denied', 'settings_unchanged',
    'jetpack_sync_isolated', 'staging_database_separate', 'staging_guard_verified', 'synthetic_public_render_verified',
    'target_environment_verified', 'target_paths_verified', 'unpublish_revokes_public_media',
    'unshared_pet_not_discoverable', 'users_and_authentication_unchanged', 'woocommerce_runtime_verified',
}
VERIFY = {
    'all_outbound_http_blocked', 'cron_and_async_runner_disabled', 'existing_passports_unchanged',
    'legacy_assets_preserved', 'mail_calls_blocked', 'mutation_hooks_registered',
    'orders_unchanged', 'pet_club_assets_enqueued', 'pet_club_classes_loaded',
    'private_owner_access_verified', 'private_photo_files_unchanged', 'private_record_registration_verified',
    'product_prices_and_stock_unchanged', 'production_read_only_verified', 'public_routes_registered',
    'remote_file_access_disabled', 'settings_unchanged', 'synthetic_public_render_verified',
    'target_environment_verified', 'target_paths_verified', 'users_and_authentication_unchanged',
    'woocommerce_runtime_verified',
}
FIXTURE = {
    'all_outbound_http_blocked', 'browser_input_written', 'cron_and_async_runner_disabled',
    'mail_calls_blocked', 'owned_fixture_pet_created', 'owned_fixture_users_created',
    'remote_file_access_disabled', 'jetpack_sync_isolated', 'staging_database_separate', 'staging_guard_verified',
    'target_environment_verified', 'target_paths_verified', 'woocommerce_runtime_verified',
}
CLEANUP = {
    'all_outbound_http_blocked', 'cron_and_async_runner_disabled', 'existing_passports_unchanged',
    'fixture_cleanup_verified', 'mail_calls_blocked', 'orders_unchanged',
    'private_photo_files_unchanged', 'product_prices_and_stock_unchanged', 'remote_file_access_disabled',
    'settings_unchanged', 'jetpack_sync_isolated', 'staging_database_separate', 'staging_guard_verified',
    'target_environment_verified', 'target_paths_verified', 'users_and_authentication_unchanged',
    'woocommerce_runtime_verified',
}
DIAGNOSE = {
    'all_outbound_http_blocked', 'cron_and_async_runner_disabled', 'diagnostic_completed',
    'diagnostic_read_only', 'existing_passports_unchanged', 'fixture_journal_valid',
    'mail_calls_blocked', 'orders_unchanged', 'owned_users_absent',
    'private_photo_files_unchanged', 'product_prices_and_stock_unchanged', 'remote_file_access_disabled',
    'jetpack_sync_isolated', 'staging_database_separate', 'staging_guard_verified', 'target_environment_verified',
    'target_paths_verified', 'users_and_authentication_unchanged', 'woocommerce_runtime_verified',
}
RETIRE = {
    'all_outbound_http_blocked', 'browser_input_retired', 'cron_and_async_runner_disabled',
    'existing_passports_unchanged', 'fixture_journal_valid', 'jetpack_sync_isolated',
    'mail_calls_blocked', 'only_known_runtime_cache_drift', 'orders_unchanged',
    'original_journal_retained', 'owned_guard_retired', 'owned_users_absent',
    'private_photo_files_unchanged', 'product_prices_and_stock_unchanged', 'remote_file_access_disabled',
    'retirement_read_only', 'staging_database_separate', 'staging_guard_verified',
    'target_environment_verified', 'target_paths_verified', 'theme_update_check_timestamp_verified',
    'users_and_authentication_unchanged', 'woocommerce_runtime_verified',
}
DIAGNOSTIC_OBSERVATIONS = {
    'option_names_code_literals_verified',
    'added_option_other', 'added_option_petish',
    'added_option_runtime_action_scheduler', 'added_option_runtime_litespeed',
    'added_option_runtime_transient', 'added_option_woocommerce',
    'changed_known_option_action_scheduler_lock_action_scheduler_run_queue', 'changed_known_option_action_scheduler_lock_async_request_runner',
    'changed_known_option_action_scheduler_migration_status', 'changed_known_option_auth_key',
    'changed_known_option_auth_salt', 'changed_known_option_can_compress_scripts',
    'changed_known_option_db_version', 'changed_known_option_finished_splitting_shared_terms',
    'changed_known_option_initial_db_version', 'changed_known_option_logged_in_key',
    'changed_known_option_logged_in_salt', 'changed_known_option_nonce_key',
    'changed_known_option_nonce_salt', 'changed_known_option_petish_passport_schema_version',
    'changed_known_option_petish_staging_captured_mail', 'changed_known_option_petish_staging_mail_count',
    'changed_known_option_recently_activated', 'changed_known_option_secure_auth_key',
    'changed_known_option_secure_auth_salt', 'changed_known_option_sidebars_widgets',
    'changed_known_option_sticky_posts', 'changed_known_option_uninstall_plugins',
    'changed_known_option_wc_admin_import_customers', 'changed_known_option_wc_admin_import_stats',
    'changed_known_option_wc_admin_import_status', 'changed_known_option_wc_admin_report_customers_update',
    'changed_known_option_wc_admin_report_users_update', 'changed_known_option_widget_block',
    'changed_known_option_woocommerce_admin_customer_import', 'changed_known_option_woocommerce_admin_daily',
    'changed_known_option_woocommerce_admin_import_customers', 'changed_known_option_woocommerce_admin_import_stats',
    'changed_known_option_woocommerce_admin_import_status', 'changed_known_option_woocommerce_admin_install_timestamp',
    'changed_known_option_woocommerce_admin_report_customers_update', 'changed_known_option_woocommerce_admin_report_users_update',
    'changed_known_option_woocommerce_allow_tracking', 'changed_known_option_woocommerce_attribute_taxonomies',
    'changed_known_option_woocommerce_cache_prefix', 'changed_known_option_woocommerce_cot_db_version',
    'changed_known_option_woocommerce_cot_migration_state', 'changed_known_option_woocommerce_db_version',
    'changed_known_option_woocommerce_queue_flush_rewrite_rules', 'changed_known_option_woocommerce_task_list_tracked_completed_tasks',
    'changed_known_option_woocommerce_task_list_tracked_started_tasks', 'changed_known_option_woocommerce_tracker_last_send',
    'changed_known_option_woocommerce_version', 'changed_known_option_wp_user_roles',
    'changed_option_events_found', 'changed_option_other',
    'changed_option_petish', 'changed_option_runtime_action_scheduler',
    'changed_option_runtime_litespeed', 'changed_option_runtime_transient',
    'changed_option_woocommerce', 'diagnostic_write_attempt_blocked',
    'only_new_nonce_key_and_salt_identified', 'option_change_cleanup',
    'option_change_hearts', 'option_change_moderation',
    'option_change_moments', 'option_change_pet_creation',
    'option_change_sharing', 'option_change_user_creation',
    'option_delta_identified', 'option_event_trace_nonempty',
    'option_event_trace_present', 'option_names_redacted',
    'option_names_truncated', 'option_rows_baseline_available',
    'options_added', 'options_modified',
    'options_removed', 'settings_unchanged',
    'single_added_option_identified',
}
OPTION_PREFIXES = {
    '_action_scheduler_', '_as_', '_litespeed_', '_petish_',
    '_wc_', '_woocommerce_', '_wp_', 'action_scheduler_',
    'as_', 'litespeed_', 'petish_', 'wc_',
    'woocommerce_', 'wp_',
}
BROWSER = {
    'account_menu_verified', 'anonymous_private_photos_denied', 'browser_started',
    'dark_mode_readable', 'desktop_layout_verified', 'edit_form_verified',
    'explicit_publish_verified', 'foreign_private_photos_denied', 'lightbox_escape_focus_verified',
    'lightbox_verified', 'member_heart_post_verified', 'member_heart_remove_post_verified',
    'member_login_verified', 'mobile_320_verified', 'mobile_390_verified',
    'no_js_fallback_verified', 'owned_passport_displayed', 'owner_login_verified',
    'owner_private_photo_http_verified', 'private_preview_verified', 'public_photo_http_verified',
    'public_profile_http_verified', 'public_projection_verified', 'public_responses_uncacheable',
    'real_photo_upload_verified', 'revoked_photo_http_denied', 'revoked_profile_http_denied',
    'tabs_keyboard_verified', 'tabs_mouse_verified', 'unpublish_verified',
    'unsafe_requests_blocked',
}
ASSETS = {'pet_club_script_http_verified', 'pet_club_stylesheet_http_verified', 'pet_club_catalogue_stylesheet_http_verified'}
REQUIRED = {
    **{phase: navigation.REQUIRED[phase] for phase in ('inspect', 'apply', 'rollback', 'commit')},
    'preflight': PREFLIGHT, 'native': STAGING, 'verify': VERIFY,
    'fixture': FIXTURE, 'browser': BROWSER, 'cleanup': CLEANUP, 'diagnose': DIAGNOSE, 'retire': RETIRE,
    'health': navigation.email.HEALTH_CHECKS, 'assets': ASSETS,
}
REQUIRED['apply'] = REQUIRED['apply'] | {'maintenance_guard_created', 'maintenance_guard_removed'}
REQUIRED['rollback'] = REQUIRED['rollback'] | {'maintenance_guard_removed'}
REQUIRED['commit'] = REQUIRED['commit'] | {'maintenance_guard_removed'}
CATEGORIES = {'guard_paths', 'guard_link_unavailable', 'guard_cross_device', 'guard_receipt',
    'guard_pending', 'guard_publish', 'guard_verified'} | navigation.CATEGORIES | {'snapshot','verification','renderer','catalogue','fixture',
    'maintenance','native','cleanup','diagnose','retire','isolation','bootstrap','registration','routes','privacy',
    'contracts','render','upload','ownership','sharing','hearts','moderation','delete','mutation','state'} | {'revocation', 'private_photos', 'no_js', 'network', 'login', 'navigation', 'preview', 'public_profile', 'unpublish', 'public_photo', 'tabs', 'complete', 'input', 'lightbox', 'upload', 'browser', 'dark_mode', 'form', 'desktop', 'publish', 'owner', 'mobile'}


def safe_option_name(name):
    if not isinstance(name, str) or len(name) > 100 \
            or not re.fullmatch(r'_?[a-z]{1,24}(?:[_-][a-z]{1,24}){1,15}', name):
        return False
    return not any(len(part) >= 8 and re.fullmatch(r'[a-f]+', part)
                   for part in re.split(r'[_-]+', name.strip('_')))


def diagnostic_names(raw):
    if 'option_names' in raw:
        values = raw['option_names']
        if not isinstance(values, list) or len(values) > 12 \
                or any(not safe_option_name(value) for value in values) or values != sorted(set(values)):
            raise ValueError()
        if values and raw.get('checks', {}).get('option_names_code_literals_verified') is not True:
            raise ValueError()
    if 'option_name_prefixes' in raw:
        values = raw['option_name_prefixes']
        if not isinstance(values, list) or len(values) > len(OPTION_PREFIXES) \
                or any(not isinstance(value, str) or value not in OPTION_PREFIXES for value in values) \
                or values != sorted(set(values)):
            raise ValueError()


def screen(phase, path):
    try:
        if phase not in REQUIRED:
            raise ValueError()
        if phase == 'preflight':
            return navigation.screen(phase, path)
        raw = navigation.rehearsal.read_report(path)
        deploy = phase in {'inspect', 'apply', 'rollback', 'commit'}
        fields = {'status', 'category', 'checks'} | ({'changed_file_count'} if deploy else set())
        if phase == 'diagnose' and isinstance(raw, dict):
            diagnostic_names(raw)
            fields |= set(raw) & {'option_names', 'option_name_prefixes'}
        if not isinstance(raw, dict) or set(raw) != fields \
                or raw['status'] not in {'success', 'failure'} or raw['category'] not in CATEGORIES \
                or not isinstance(raw['checks'], dict):
            raise ValueError()
        names = DEPLOY if deploy else navigation.email.HEALTH_CHECKS | navigation.email.HEALTH_OBSERVATIONS \
            if phase == 'health' else DIAGNOSE | DIAGNOSTIC_OBSERVATIONS if phase == 'diagnose' else REQUIRED[phase]
        if not set(raw['checks']) <= names or any(type(value) is not bool for value in raw['checks'].values()):
            raise ValueError()
        if deploy and (type(raw['changed_file_count']) is not int or not 0 <= raw['changed_file_count'] <= FILE_COUNT):
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
