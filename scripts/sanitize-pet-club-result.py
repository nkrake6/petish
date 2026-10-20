#!/usr/bin/env python3
"""Publish only finite Pet Club verification checks; private pet data stays remote."""
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
DEPLOY = navigation.DEPLOY | {'maintenance_guard_created', 'maintenance_guard_removed'}
PREFLIGHT = navigation.PREFLIGHT
FILE_COUNT = 14
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
    'staging_database_separate', 'staging_guard_verified', 'synthetic_public_render_verified',
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
    'remote_file_access_disabled', 'staging_database_separate', 'staging_guard_verified',
    'target_environment_verified', 'target_paths_verified', 'woocommerce_runtime_verified',
}
CLEANUP = {
    'all_outbound_http_blocked', 'cron_and_async_runner_disabled', 'existing_passports_unchanged',
    'fixture_cleanup_verified', 'mail_calls_blocked', 'orders_unchanged',
    'private_photo_files_unchanged', 'product_prices_and_stock_unchanged', 'remote_file_access_disabled',
    'settings_unchanged', 'staging_database_separate', 'staging_guard_verified',
    'target_environment_verified', 'target_paths_verified', 'users_and_authentication_unchanged',
    'woocommerce_runtime_verified',
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
ASSETS = {'pet_club_script_http_verified', 'pet_club_stylesheet_http_verified'}
REQUIRED = {
    **{phase: navigation.REQUIRED[phase] for phase in ('inspect', 'apply', 'rollback', 'commit')},
    'preflight': PREFLIGHT, 'native': STAGING, 'verify': VERIFY,
    'fixture': FIXTURE, 'browser': BROWSER, 'cleanup': CLEANUP,
    'health': navigation.email.HEALTH_CHECKS, 'assets': ASSETS,
}
REQUIRED['apply'] = REQUIRED['apply'] | {'maintenance_guard_created', 'maintenance_guard_removed'}
REQUIRED['rollback'] = REQUIRED['rollback'] | {'maintenance_guard_removed'}
REQUIRED['commit'] = REQUIRED['commit'] | {'maintenance_guard_removed'}
CATEGORIES = navigation.CATEGORIES | {'snapshot','verification','renderer','catalogue','fixture',
    'maintenance','native','cleanup','isolation','bootstrap','registration','routes','privacy',
    'contracts','render','upload','ownership','sharing','hearts','moderation','delete','mutation','state'} | {'revocation', 'private_photos', 'no_js', 'network', 'login', 'navigation', 'preview', 'public_profile', 'unpublish', 'public_photo', 'tabs', 'complete', 'input', 'lightbox', 'upload', 'browser', 'dark_mode', 'form', 'desktop', 'publish', 'owner', 'mobile'}


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
        names = DEPLOY if deploy else navigation.email.HEALTH_CHECKS | navigation.email.HEALTH_OBSERVATIONS \
            if phase == 'health' else REQUIRED[phase]
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
