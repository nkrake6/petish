#!/usr/bin/env python3
"""Keep homepage release output finite; application output stays private."""
import importlib.util
import json
import math
import os
from pathlib import Path
import sys


def module(name):
    spec=importlib.util.spec_from_file_location(name.replace('-','_'),Path(__file__).with_name(name+'.py'))
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


navigation=module('sanitize-account-navigation-result')
BROWSER={
    'market_flag_assets_verified', 'market_flag_header_verified', 'market_flag_cart_verified', 'market_flag_webkit_verified',
    'theme_assets_http_verified', 'theme_switch_order_verified', 'theme_switch_geometry_verified',
    'theme_switch_pointer_verified', 'theme_switch_keyboard_verified', 'theme_switch_persistence_verified',
    'theme_switch_select_sync_verified', 'theme_switch_system_verified', 'theme_switch_clones_verified',
    'badge_png_http_ok', 'badge_png_image_matches', 'badges_bottom_right',
    'badges_nonoverlap_hot', 'browser_started', 'conditional_section_valid',
    'customer_login_verified', 'home_css_hash_matches', 'home_css_http_ok',
    'homepage_http_ok', 'hot_subtitle_absent', 'personalisation_ajax_verified',
    'personalisation_error_retry_verified', 'personalisation_history_reset_verified', 'personalisation_layout_verified',
    'personalisation_no_store_verified', 'personalisation_script_http_verified', 'personalisation_shell_private_verified',
    'readonly_requests_enforced', 'section_1280_valid', 'section_320_valid',
    'section_390_valid',
}
BROWSER_DIAGNOSTICS={'badge_response_content_type_png','badge_response_content_type_webp',
    'badge_response_content_type_avif','badge_response_content_type_html','badge_response_signature_png',
    'badge_response_signature_webp','badge_response_signature_avif','badge_response_bytes_match',
    'badge_response_size_bounded','badge_fetch_versioned','badge_expected_decode_ok',
    'badge_response_decode_ok','badge_canvas_context_available','badge_dimensions_match',
    'badge_pixels_match','badge_comparison_completed','badge_versioned_image_matches'} | {
    f'theme_{width}_{mode}_{fact}' for width in (320,390,1280)
    for mode in ('light','dark') for fact in ('order','height','fit')}
THEME_GEOMETRY_VIEWS={f'{width}_{mode}' for width in (320,390,1280) for mode in ('light','dark')}
THEME_GEOMETRY_FIELDS={'header_height','baseline_height','header_width','viewport_width',
    'switch_left','switch_right','search_right','market_left','market_right','logo_right','logo_width','visible_count','overflow'}
VERIFY={
    'market_flag_markup_verified',
    'theme_switch_markup_verified', 'theme_switch_assets_enqueued',
    'account_settings_unchanged', 'all_outbound_http_blocked', 'anonymous_render_context_verified',
    'authentication_state_unchanged', 'cron_and_async_runner_disabled', 'for_your_pet_cacheable_shell_verified',
    'for_your_pet_script_enqueued', 'free_delivery_badge_asset_verified', 'free_delivery_helpers_available',
    'free_delivery_ordering_verified', 'free_delivery_section_matches_evidence', 'free_delivery_section_position_verified',
    'full_homepage_rendered', 'homepage_query_verified', 'homepage_stylesheet_enqueued',
    'hot_badges_match_existing_rule', 'hot_subtitle_removed', 'mail_calls_blocked',
    'native_read_only_verified', 'order_counts_unchanged', 'original_hot_rule_preserved',
    'previous_account_and_email_assets_preserved', 'product_badges_match_eligibility', 'product_stock_unchanged',
    'production_data_snapshot_verified', 'protected_site_state_unchanged', 'remote_file_access_disabled',
    'shipping_policy_and_evidence_unchanged', 'stylesheet_version_matches_file', 'target_environment_verified',
    'target_paths_verified', 'tested_theme_and_woocommerce_verified', 'user_counts_unchanged',
}
NATIVE={
    'active_pet_read_only', 'all_outbound_http_blocked', 'cron_and_async_runner_disabled',
    'existing_passports_unchanged', 'fixture_cleanup_verified', 'fixture_rows_absent',
    'fixture_scope_verified', 'for_your_pet_classes_available', 'guest_email_purchases_excluded',
    'jetpack_sync_isolated', 'mail_calls_blocked', 'matching_rules_verified',
    'newest_eligible_first', 'orders_unchanged', 'other_customer_history_isolated',
    'paid_and_refunded_purchases_excluded', 'parent_and_variation_excluded', 'private_photo_files_unchanged',
    'private_profile_session_isolation', 'private_runtime_cache_verified', 'product_prices_and_stock_unchanged',
    'remote_file_access_disabled', 'settings_unchanged', 'staging_database_separate',
    'staging_guard_verified', 'target_environment_verified', 'target_paths_verified',
    'touched_tables_unchanged', 'transaction_rolled_back', 'transaction_tables_verified',
    'unpaid_orders_remain_eligible', 'users_and_authentication_unchanged', 'woocommerce_runtime_verified',
}
CLEANUP={
    'all_outbound_http_blocked', 'cron_and_async_runner_disabled', 'existing_passports_unchanged',
    'fixture_cleanup_verified', 'fixture_rows_absent', 'jetpack_sync_isolated',
    'mail_calls_blocked', 'orders_unchanged', 'private_photo_files_unchanged',
    'private_runtime_cache_verified', 'product_prices_and_stock_unchanged', 'remote_file_access_disabled',
    'settings_unchanged', 'staging_database_separate', 'staging_guard_verified',
    'target_environment_verified', 'target_paths_verified', 'touched_tables_unchanged',
    'transaction_rolled_back', 'transaction_tables_verified', 'users_and_authentication_unchanged',
    'woocommerce_runtime_verified',
}
OBSERVATIONS={'free_delivery_products_available'}
ASSETS={'market_script_http_verified','market_stylesheet_http_verified','country_flag_sprite_http_verified','country_flag_license_http_verified',
    'homepage_country_flags_verified','cart_http_verified','cart_country_flags_verified','theme_switch_public_markup_verified','theme_script_http_verified','theme_stylesheet_http_verified','for_your_pet_script_http_verified','homepage_http_verified','hot_subtitle_absent','home_stylesheet_http_verified','free_delivery_badge_http_verified'}
PREFLIGHT=navigation.PREFLIGHT
FILE_COUNT=15
DEPLOY=navigation.DEPLOY|{'candidate_badge_png_verified','maintenance_guard_created','maintenance_guard_removed'}
REQUIRED={**{phase:navigation.REQUIRED[phase]|({'candidate_badge_png_verified'} if phase in {'inspect','apply'} else set())
    for phase in ('inspect','apply','rollback','commit')},
    'preflight':PREFLIGHT,'native':NATIVE,'cleanup':CLEANUP,'browser':BROWSER,'verify':VERIFY,'health':navigation.email.HEALTH_CHECKS,'assets':ASSETS}
REQUIRED['apply'] |= {'maintenance_guard_created','maintenance_guard_removed'}
REQUIRED['rollback'] |= {'maintenance_guard_removed'}
REQUIRED['commit'] |= {'maintenance_guard_removed'}
CACHE_FAILURES={
    'cache_api_missing_add', 'cache_api_missing_add_global_groups', 'cache_api_missing_add_multiple',
    'cache_api_missing_add_non_persistent_groups', 'cache_api_missing_close', 'cache_api_missing_decr',
    'cache_api_missing_delete', 'cache_api_missing_delete_multiple', 'cache_api_missing_flush',
    'cache_api_missing_flush_group', 'cache_api_missing_flush_runtime', 'cache_api_missing_get',
    'cache_api_missing_get_multiple', 'cache_api_missing_incr', 'cache_api_missing_init',
    'cache_api_missing_replace', 'cache_api_missing_reset', 'cache_api_missing_set',
    'cache_api_missing_set_multiple', 'cache_api_missing_supports', 'cache_api_missing_switch_to_blog',
    'cache_api_source_add', 'cache_api_source_add_global_groups', 'cache_api_source_add_multiple',
    'cache_api_source_add_non_persistent_groups', 'cache_api_source_close', 'cache_api_source_decr',
    'cache_api_source_delete', 'cache_api_source_delete_multiple', 'cache_api_source_flush',
    'cache_api_source_flush_group', 'cache_api_source_flush_runtime', 'cache_api_source_get',
    'cache_api_source_get_multiple', 'cache_api_source_incr', 'cache_api_source_init',
    'cache_api_source_replace', 'cache_api_source_reset', 'cache_api_source_set',
    'cache_api_source_set_multiple', 'cache_api_source_supports', 'cache_api_source_switch_to_blog',
    'cache_class', 'cache_class_source', 'cache_dropin_advanced_loaded',
    'cache_dropin_object_loaded', 'cache_filter_advanced', 'cache_filter_object',
    'cache_flag_enabled', 'cache_flag_invalid', 'cache_flag_missing',
    'cache_path_api', 'cache_path_class',
    'cache_path_root', 'cache_preinitialized', 'cache_runtime',
}
FIXTURE_STEPS={
    'fixture_user_owner_lookup','fixture_user_owner_create','fixture_user_owner_journal',
    'fixture_user_other_lookup','fixture_user_other_create','fixture_user_other_journal',
    'fixture_owner_session','fixture_pet_create','fixture_pet_journal',
    'fixture_product_construct','fixture_product_save','fixture_product_journal',
    'fixture_product_review_input','fixture_product_review_editor','fixture_product_rules_editor',
    'fixture_product_approval_save','fixture_product_review_verify','fixture_variation_construct',
    'fixture_variation_save','fixture_variation_journal','fixture_order_construct',
    'fixture_order_save','fixture_order_journal','fixture_order_verify',
    'fixture_scope',
}
FIXTURE_FAILURE_TYPES={'sql_boundary','runtime'}
FIXTURE_FAILURES=FIXTURE_STEPS|{step+'_'+kind for step in FIXTURE_STEPS for kind in FIXTURE_FAILURE_TYPES}|{'transaction_sql_boundary'}
REVIEW_DIAGNOSTICS={
    'can_manage_woocommerce','can_edit_product','nonce_valid','supported_product_type',
    'autosave_disabled','revision_absent','input_complete','before_save_hook_present','after_save_hook_present',
    'review_class_source_matches','queued_status_eligible','queued_signature_present',
    'raw_status_eligible','raw_record_complete','stored_signature_present','stored_valid_flag',
    'reread_product_available','reread_product_type_matches','reread_signed_review_valid','original_signed_review_valid',
}
CATEGORIES=navigation.CATEGORIES|CACHE_FAILURES|FIXTURE_FAILURES|{'maintenance','native','transaction','fixtures','matching','purchases','cleanup',
    'personalisation','personalisation_error','personalisation_history','bootstrap','isolation','state',
    'market_flag_assets','market_flag_layout','theme_assets','theme_layout','theme_interaction','theme_persistence','theme_system'}|{'query','products','homepage','subtitle','layout','snapshot_shipping'}


def screen(phase,path):
    try:
        if phase not in REQUIRED:raise ValueError()
        if phase=='preflight':return navigation.screen(phase,path)
        raw=navigation.rehearsal.read_report(path)
        deploy=phase in {'inspect','apply','rollback','commit'}
        fields={'status','category','checks'}|({'changed_file_count'} if deploy else {'section_present'} if phase=='browser' else set())
        if phase=='native' and 'review_diagnostics' in raw:
            fields.add('review_diagnostics')
            if not isinstance(raw['review_diagnostics'],dict) or set(raw['review_diagnostics'])!=REVIEW_DIAGNOSTICS \
                or any(type(value) is not bool for value in raw['review_diagnostics'].values()):raise ValueError()
        if phase=='browser' and 'diagnostics' in raw:
            fields.add('diagnostics')
            if not isinstance(raw['diagnostics'],dict) or set(raw['diagnostics'])!=BROWSER_DIAGNOSTICS \
                or any(type(value) is not bool for value in raw['diagnostics'].values()):raise ValueError()
        if phase=='browser' and 'theme_geometry' in raw:
            fields.add('theme_geometry')
            geometry=raw['theme_geometry']
            if not isinstance(geometry,dict) or set(geometry)!=THEME_GEOMETRY_VIEWS:raise ValueError()
            for measurements in geometry.values():
                if not isinstance(measurements,dict) or set(measurements)!=THEME_GEOMETRY_FIELDS \
                    or any(type(value) not in (int,float) or not math.isfinite(value) or not -4096<=value<=4096
                           for value in measurements.values()):raise ValueError()
        if phase=='browser' and 'image_dimensions' in raw:
            fields.add('image_dimensions')
            dimensions=raw['image_dimensions']
            if not isinstance(dimensions,dict) or set(dimensions)!= {'expected_width','expected_height','response_width','response_height'} \
                or any(type(value) is not int or not 0<=value<=4096 for value in dimensions.values()):raise ValueError()
        if not isinstance(raw,dict) or set(raw)!=fields or raw['status'] not in {'success','failure'} \
            or raw['category'] not in CATEGORIES or not isinstance(raw['checks'],dict):raise ValueError()
        names=DEPLOY if deploy else VERIFY|OBSERVATIONS if phase=='verify' \
            else navigation.email.HEALTH_CHECKS|navigation.email.HEALTH_OBSERVATIONS if phase=='health' else REQUIRED[phase]
        if not set(raw['checks'])<=names or any(type(value) is not bool for value in raw['checks'].values()):raise ValueError()
        if deploy and (type(raw['changed_file_count']) is not int or not 0<=raw['changed_file_count']<=FILE_COUNT):raise ValueError()
        if phase=='browser' and type(raw['section_present']) is not bool:raise ValueError()
        if phase=='verify' and ('free_delivery_products_available' in raw['checks'] or raw['status']=='success') \
            and type(raw['checks'].get('free_delivery_products_available')) is not bool:raise ValueError()
        passed=raw['status']=='success' and raw['category']=='complete' \
            and all(raw['checks'].get(key) is True for key in REQUIRED[phase])
        if not passed:raw['status']='failure'
        return raw,passed
    except Exception:return {'status':'failure','category':'invalid_report','checks':{}},False


if __name__=='__main__':
    os.umask(0o077)
    if len(sys.argv)!=4:raise SystemExit(1)
    result,passed=screen(sys.argv[1],Path(sys.argv[2]))
    Path(sys.argv[3]).write_text(json.dumps(result,indent=2)+'\n')
    raise SystemExit(0 if passed else 1)
