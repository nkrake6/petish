#!/usr/bin/env python3
"""Keep homepage release output finite; application output stays private."""
import importlib.util
import json
import os
from pathlib import Path
import sys


def module(name):
    spec=importlib.util.spec_from_file_location(name.replace('-','_'),Path(__file__).with_name(name+'.py'))
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


navigation=module('sanitize-account-navigation-result')
BROWSER={'browser_started','customer_login_verified','homepage_http_ok','hot_subtitle_absent',
    'home_css_http_ok','home_css_hash_matches','badge_png_http_ok','badge_png_image_matches',
    'conditional_section_valid','section_320_valid','section_390_valid','section_1280_valid',
    'badges_bottom_right','badges_nonoverlap_hot','readonly_requests_enforced'}
BROWSER_DIAGNOSTICS={'badge_response_content_type_png','badge_response_content_type_webp',
    'badge_response_content_type_avif','badge_response_content_type_html','badge_response_signature_png',
    'badge_response_signature_webp','badge_response_signature_avif','badge_response_bytes_match',
    'badge_response_size_bounded','badge_fetch_versioned','badge_expected_decode_ok',
    'badge_response_decode_ok','badge_canvas_context_available','badge_dimensions_match',
    'badge_pixels_match','badge_comparison_completed','badge_versioned_image_matches'}
VERIFY={'target_paths_verified','target_environment_verified','tested_theme_and_woocommerce_verified',
    'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled',
    'cron_and_async_runner_disabled','production_data_snapshot_verified','anonymous_render_context_verified',
    'homepage_query_verified','free_delivery_helpers_available','original_hot_rule_preserved',
    'full_homepage_rendered','hot_subtitle_removed','free_delivery_section_matches_evidence',
    'free_delivery_section_position_verified','free_delivery_ordering_verified',
    'product_badges_match_eligibility','hot_badges_match_existing_rule','homepage_stylesheet_enqueued',
    'stylesheet_version_matches_file','free_delivery_badge_asset_verified',
    'previous_account_and_email_assets_preserved','order_counts_unchanged','user_counts_unchanged',
    'authentication_state_unchanged','product_stock_unchanged','account_settings_unchanged',
    'shipping_policy_and_evidence_unchanged'}
OBSERVATIONS={'free_delivery_products_available'}
ASSETS={'homepage_http_verified','hot_subtitle_absent','home_stylesheet_http_verified','free_delivery_badge_http_verified'}
PREFLIGHT=navigation.PREFLIGHT
DEPLOY=navigation.DEPLOY|{'candidate_badge_png_verified'}
REQUIRED={**{phase:navigation.REQUIRED[phase]|({'candidate_badge_png_verified'} if phase in {'inspect','apply'} else set())
    for phase in ('inspect','apply','rollback','commit')},
    'preflight':PREFLIGHT,'browser':BROWSER,'verify':VERIFY,'health':navigation.email.HEALTH_CHECKS,'assets':ASSETS}
CATEGORIES=navigation.CATEGORIES|{'query','products','homepage','subtitle','layout','snapshot_shipping'}


def screen(phase,path):
    try:
        if phase not in REQUIRED:raise ValueError()
        if phase=='preflight':return navigation.screen(phase,path)
        raw=navigation.rehearsal.read_report(path)
        deploy=phase in {'inspect','apply','rollback','commit'}
        fields={'status','category','checks'}|({'changed_file_count'} if deploy else {'section_present'} if phase=='browser' else set())
        if phase=='browser' and 'diagnostics' in raw:
            fields.add('diagnostics')
            if not isinstance(raw['diagnostics'],dict) or set(raw['diagnostics'])!=BROWSER_DIAGNOSTICS \
                or any(type(value) is not bool for value in raw['diagnostics'].values()):raise ValueError()
        if phase=='browser' and 'image_dimensions' in raw:
            fields.add('image_dimensions')
            dimensions=raw['image_dimensions']
            if not isinstance(dimensions,dict) or set(dimensions)!= {'expected_width','expected_height','response_width','response_height'} \
                or any(type(value) is not int or not 0<=value<=4096 for value in dimensions.values()):raise ValueError()
        if not isinstance(raw,dict) or set(raw)!=fields or raw['status'] not in {'success','failure'} \
            or raw['category'] not in CATEGORIES or not isinstance(raw['checks'],dict):raise ValueError()
        names=DEPLOY if deploy else BROWSER if phase=='browser' else VERIFY|OBSERVATIONS if phase=='verify' \
            else ASSETS if phase=='assets' else navigation.email.HEALTH_CHECKS|navigation.email.HEALTH_OBSERVATIONS
        if not set(raw['checks'])<=names or any(type(value) is not bool for value in raw['checks'].values()):raise ValueError()
        if deploy and (type(raw['changed_file_count']) is not int or not 0<=raw['changed_file_count']<=4):raise ValueError()
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
