#!/usr/bin/env python3
"""Publish only finite Pet Club verification checks; private pet data stays remote."""
import importlib.util
import json
import math
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
DEPLOY = navigation.DEPLOY | {'maintenance_guard_created', 'maintenance_guard_removed', 'maintenance_guard_retained', 'migration_journal_archived'}
PREFLIGHT = navigation.PREFLIGHT
FILE_COUNT = 18
STAGING = {
    'all_outbound_http_blocked', 'anonymous_heart_denied', 'biography_and_traits_saved',
    'cron_and_async_runner_disabled', 'directory_explicit_reshare_filters_verified', 'directory_fixture_scope_verified',
    'directory_index_migration_not_required', 'directory_legacy_snapshot_index_verified', 'directory_literal_search_verified',
    'directory_pagination_verified', 'directory_public_snapshot_filters_verified', 'directory_return_url_verified',
    'directory_visibility_revocation_verified', 'existing_passports_unchanged', 'explicit_public_snapshot_created',
    'favourites_session_isolation_verified', 'fixture_cleanup_verified', 'foreign_owner_denied',
    'heart_idempotent', 'heart_removal_verified', 'jetpack_sync_isolated',
    'index_migration_plan_verified', 'index_migration_apply_verified',
    'index_migration_exact_delta_verified', 'index_migration_rollback_verified',
    'legacy_assets_preserved', 'legacy_defaults_preserved', 'mail_calls_blocked',
    'moderation_hides_profile', 'mutation_hooks_registered', 'only_selected_moments_shared',
    'orders_unchanged', 'owned_fixture_pet_created', 'owned_fixture_users_created',
    'pet_club_assets_enqueued', 'pet_club_classes_loaded', 'photo_change_unpublishes',
    'private_media_owner_scoped', 'private_moments_owner_scoped', 'private_owner_access_verified',
    'private_photo_files_unchanged', 'private_record_registration_verified', 'private_video_owner_scoped',
    'product_prices_and_stock_unchanged', 'public_photos_are_separate', 'public_projection_allowlisted',
    'public_routes_registered', 'public_video_snapshot_separate', 'remote_file_access_disabled',
    'report_deduplicated', 'request_nonce_and_method_verified', 'self_heart_denied',
    'settings_unchanged', 'staging_database_separate', 'staging_guard_verified',
    'synthetic_public_render_verified', 'target_environment_verified', 'target_paths_verified',
    'unpublish_revokes_public_media', 'unshared_pet_not_discoverable', 'users_and_authentication_unchanged',
    'video_inspection_limits_verified', 'video_range_contract_verified', 'video_replacement_revokes_public',
    'video_snapshot_frozen', 'woocommerce_runtime_verified',
}
VERIFY = {
    'all_outbound_http_blocked', 'cron_and_async_runner_disabled', 'directory_index_migration_not_required',
    'existing_passports_unchanged', 'legacy_assets_preserved', 'mail_calls_blocked',
    'mutation_hooks_registered', 'orders_unchanged', 'pet_club_assets_enqueued',
    'pet_club_classes_loaded', 'private_owner_access_verified', 'private_photo_files_unchanged',
    'private_record_registration_verified', 'product_prices_and_stock_unchanged', 'production_read_only_verified',
    'public_routes_registered', 'remote_file_access_disabled', 'settings_unchanged',
    'synthetic_public_render_verified', 'target_environment_verified', 'target_paths_verified',
    'users_and_authentication_unchanged', 'woocommerce_runtime_verified',
}
INDEX = {
    'all_outbound_http_blocked', 'cron_and_async_runner_disabled', 'directory_index_migration_not_required',
    'existing_passports_unchanged', 'mail_calls_blocked', 'orders_unchanged',
    'private_photo_files_unchanged', 'product_prices_and_stock_unchanged', 'production_read_only_verified',
    'remote_file_access_disabled', 'settings_unchanged', 'target_environment_verified',
    'target_paths_verified', 'users_and_authentication_unchanged', 'woocommerce_runtime_verified',
}
MIGRATION_BOOTSTRAP = {
    'target_paths_verified', 'target_environment_verified', 'woocommerce_runtime_verified',
    'all_outbound_http_blocked', 'mail_calls_blocked', 'remote_file_access_disabled',
    'cron_and_async_runner_disabled', 'index_private_runtime_cache_verified',
}
MIGRATION = {
    'index_plan': MIGRATION_BOOTSTRAP | {'index_plan_verified', 'index_read_only_verified'},
    'index_apply': MIGRATION_BOOTSTRAP | {'index_journal_verified', 'index_maintenance_owned',
        'index_lock_owned', 'index_apply_verified', 'index_exact_delta_verified'},
    'index_verify': MIGRATION_BOOTSTRAP | {'index_journal_verified', 'index_maintenance_owned',
        'index_lock_owned', 'index_exact_delta_verified', 'index_read_only_verified'},
    'index_rollback': MIGRATION_BOOTSTRAP | {'index_journal_verified', 'index_maintenance_owned',
        'index_lock_owned', 'index_rollback_verified'},
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
    'account_menu_verified', 'account_menu_colour_track_verified', 'account_menu_height_preserved',
    'anonymous_private_photos_denied', 'browser_started',
    'dark_mode_readable', 'desktop_layout_verified', 'directory_filters_verified',
    'directory_return_preserved', 'edit_form_verified', 'explicit_publish_verified',
    'favourites_responses_uncacheable', 'favourites_session_isolation_verified', 'foreign_private_photos_denied',
    'lightbox_escape_focus_verified', 'lightbox_verified', 'member_heart_post_verified',
    'member_heart_remove_post_verified', 'member_login_verified', 'mobile_320_verified',
    'mobile_390_verified', 'no_js_fallback_verified', 'owned_passport_displayed',
    'owner_login_verified', 'owner_private_photo_http_verified', 'private_preview_verified',
    'private_video_http_verified', 'private_video_playback_verified', 'private_video_unauthorized_denied',
    'public_photo_http_verified', 'public_profile_http_verified', 'public_projection_verified',
    'public_responses_uncacheable', 'public_video_http_verified', 'real_photo_upload_verified',
    'real_video_upload_verified', 'revoked_photo_http_denied', 'revoked_profile_http_denied',
    'revoked_video_http_denied', 'tabs_keyboard_verified', 'tabs_mouse_verified',
    'unpublish_verified', 'unsafe_requests_blocked', 'video_client_limits_verified',
    'video_ranges_verified', 'video_server_limits_verified',
}
# Non-sensitive response and layout facts never substitute for required checks.
MENU_DIAGNOSTICS = {
    'menu_fonts_ready', 'menu_stylesheet_seen', 'menu_stylesheet_bytes_verified',
    'menu_palette_cyan', 'menu_palette_purple', 'menu_palette_pink', 'menu_palette_gold',
} | {f'menu_{width}_{fact}' for width in (320, 390)
     for fact in ('toggle_visible', 'toggle_receives_pointer')} \
  | {f'menu_{width}_{theme}_{fact}' for width in (320, 390, 992, 1440)
     for theme in ('light', 'dark') for fact in ('icons', 'height')}
MENU_CATEGORIES = {
    'menu_ready', 'menu_fonts', 'menu_stylesheet', 'menu_icons', 'menu_height', 'menu_restore',
} | {f'menu_viewport_{width}' for width in (320, 390, 992, 1440)} \
  | {f'menu_measure_{width}_{theme}' for width in (320, 390, 992, 1440)
     for theme in ('light', 'dark')} \
  | {f'menu_{action}_{width}' for width in (320, 390) for action in ('open', 'close')}
BROWSER_DIAGNOSTICS = MENU_DIAGNOSTICS | {
    f'favourites_{actor}_{fact}' for actor in ('member', 'owner', 'guest')
    for fact in ('status_200', 'status_404', 'status_redirect', 'status_403', 'status_other',
                 'no_store_header', 'litespeed_hit', 'redirect_to_staging_login')
}


MENU_GEOMETRY_VIEWS = {'current_light', 'current_dark', 'baseline_light', 'baseline_dark'}
MENU_GEOMETRY_ENDPOINTS = {'dashboard', 'orders', 'downloads', 'edit-address', 'payment-methods',
                           'edit-account', 'my-pets', 'for-my-pet', 'customer-logout'}
MENU_GEOMETRY_FIELDS = {'row_width', 'row_height', 'text_width', 'text_height', 'line_height',
                       'padding_top', 'padding_bottom', 'padding_left', 'padding_right'}


def menu_geometry(value):
    if not isinstance(value, dict) or set(value) != MENU_GEOMETRY_VIEWS:
        raise ValueError()
    for view in value.values():
        if not isinstance(view, dict) or set(view) != MENU_GEOMETRY_ENDPOINTS:
            raise ValueError()
        for row in view.values():
            if not isinstance(row, dict) or set(row) != MENU_GEOMETRY_FIELDS:
                raise ValueError()
            for measure in row.values():
                if type(measure) not in (int, float) or not math.isfinite(measure) \
                        or not 0 <= measure <= 2000 or round(measure, 2) != measure:
                    raise ValueError()


def browser_diagnostics(value):
    if not isinstance(value, dict) or set(value) != BROWSER_DIAGNOSTICS \
            or any(type(fact) is not bool for fact in value.values()):
        raise ValueError()


ASSETS = {'pet_club_navigation_stylesheet_http_verified', 'pet_club_script_http_verified', 'pet_club_stylesheet_http_verified', 'pet_club_catalogue_stylesheet_http_verified'}
REQUIRED = {
    **{phase: navigation.REQUIRED[phase] for phase in ('inspect', 'apply', 'rollback', 'commit')},
    **MIGRATION,
    'preflight': PREFLIGHT, 'native': STAGING, 'verify': VERIFY, 'index': INDEX,
    'fixture': FIXTURE, 'browser': BROWSER, 'cleanup': CLEANUP, 'diagnose': DIAGNOSE, 'retire': RETIRE,
    'health': navigation.email.HEALTH_CHECKS, 'assets': ASSETS,
}
REQUIRED['apply_index'] = REQUIRED['apply'] | {'maintenance_guard_created', 'maintenance_guard_retained'}
REQUIRED['pause'] = REQUIRED['inspect'] | {'files_deployed', 'protected_state_unchanged', 'lock_owned', 'maintenance_guard_created', 'maintenance_guard_retained'}
REQUIRED['resume'] = REQUIRED['inspect'] | {'files_deployed', 'protected_state_unchanged', 'lock_owned', 'maintenance_guard_removed'}
REQUIRED['apply'] = REQUIRED['apply'] | {'maintenance_guard_created', 'maintenance_guard_removed'}
REQUIRED['rollback'] = REQUIRED['rollback'] | {'maintenance_guard_removed'}
REQUIRED['commit'] = REQUIRED['commit'] | {'maintenance_guard_removed', 'migration_journal_archived'}
CATEGORIES = MENU_CATEGORIES | set(MIGRATION) | {'guard_paths', 'guard_link_unavailable', 'guard_cross_device', 'guard_receipt',
    'guard_pending', 'guard_publish', 'guard_verified'} | navigation.CATEGORIES | {'snapshot','verification','renderer','catalogue','fixture',
    'maintenance','apply-index','pause','resume','native','cleanup','diagnose','retire','isolation','bootstrap','registration','routes','privacy',
    'index','directory','video','favourites','video_input','video_limits','video_upload','video_http','video_playback','contracts','render','upload','ownership','sharing','hearts','moderation','delete','mutation','state'} | {'revocation', 'private_photos', 'no_js', 'network', 'login', 'navigation', 'preview', 'public_profile', 'unpublish', 'public_photo', 'tabs', 'complete', 'input', 'lightbox', 'upload', 'browser', 'dark_mode', 'form', 'desktop', 'publish', 'owner', 'mobile'} | {
    'form_identity', 'form_values', 'variety_default', 'variety_enhancement', 'variety_hidden', 'checkbox_spacing',
    'variety_other', 'variety_species_reset', 'variety_selection', 'form_saved',
    'video_nojs_context',
} | {f'video_{limit}_{step}' for limit in ('duration', 'size')
     for step in ('form', 'form_invalid', 'submit', 'click', 'navigation', 'post_blocked', 'response', 'notice_missing', 'notice_content', 'unchanged')}
CATEGORIES |= {f'video_{limit}_post_{reason}' for limit in ('duration', 'size')
               for reason in ('body_unavailable', 'multipart', 'request', 'window', 'action', 'identity', 'nonce', 'kind', 'caption', 'unknown')}


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
        deploy = phase in {'inspect', 'apply', 'apply_index', 'pause', 'resume', 'rollback', 'commit'}
        fields = {'status', 'category', 'checks'} | ({'changed_file_count'} if deploy else set())
        if phase == 'browser' and isinstance(raw, dict):
            browser_diagnostics(raw.get('diagnostics'))
            menu_geometry(raw.get('menu_geometry'))
            fields |= {'diagnostics', 'menu_geometry'}
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
