#!/usr/bin/env python3
"""Bounded receipts for fixed staging FAQ setup and read-only cancellation proof."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

PREFIX = 'wordpress/wp-content/plugins/petish-fulfilment/'
THEME_PREFIX = 'wordpress/wp-content/themes/dt-the7-child/'
REQUIRED_THEME = {
 'includes/header-navigation.php','includes/account-dropdown.php','assets/css/account-dropdown.css','assets/js/account-dropdown.js',
 'includes/hero-images.php','assets/js/hero-images-admin.js','functions.php','front-page.php','assets/css/home.css',
}
SOURCE_PATH = re.compile(r'(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:php|js|css|md|json|svg|png|jpg|jpeg|webp|txt)\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
OBSERVED_STAGING_SHA = '4b6552241b12f4bbafecfed151c0ac177c2a6c9cbabcd785abd9a2bda6be9c10'
REQUIRED_SOURCE = {
 'petish-fulfilment.php','includes/class-petish-cancellation-policy.php',
 'includes/class-petish-order-cancellation-requests.php','includes/class-petish-order-cancellation-customer.php',
 'includes/class-petish-order-cancellation-admin.php','includes/class-petish-order-cancellation-emails.php',
 'assets/order-cancellation.css','assets/cancellation-faq.css',
}
COMMON = {
 'target_paths_verified','target_environment_verified','woocommerce_runtime_verified','staging_database_separate',
 'staging_guard_verified','jetpack_sync_isolated','all_outbound_http_blocked','mail_calls_blocked',
 'remote_file_access_disabled','cron_and_async_runner_disabled','source_manifest_verified','source_unchanged',
 'cancellation_classes_loaded','reviewed_class_paths_verified','request_type_private','customer_hooks_registered',
 'staff_hooks_registered','email_hook_registered','faq_shortcode_registered','feature_flags_disabled',
 'canonical_policy_verified','faq_email_reference_verified','faq_shortcode_rendered','faq_page_verified','faq_marker_verified',
 'cancellation_records_unchanged','settings_options_unchanged','native_memory_cache_verified',
 'orders_unchanged','users_and_authentication_unchanged','product_prices_and_stock_unchanged','settings_unchanged',
 'existing_passports_unchanged','private_photo_files_unchanged',
 'theme_source_verified','theme_source_unchanged','header_dropdown_hooks_verified','header_guest_payload_private','header_reviews_url_verified',
 'hero_settings_hooks_verified','hero_default_resolver_verified','hero_guest_settings_denied','hero_option_unchanged',
 'staff_settings_hook_verified',
}
VERIFY = {
 'customer_inactive_output_absent','customer_style_not_enqueued','customer_guest_denied','customer_nonce_verified',
 'staff_access_denied','staff_nonce_verified','staff_preview_permission_verified','email_preview_permission_verified',
 'disabled_mutations_rejected','manager_access_verified','staff_queue_rendered','sql_write_attempts_absent',
 'staff_settings_nonce_verified',
}
PREPARE = {
 'faq_section_prepared','faq_transactional_tables','faq_no_table_triggers','faq_checkpoint_saved','delivery_page_only_write_scope_verified','faq_existing_content_safe','faq_no_cache_dropins',
 'faq_page_slug_indexed','faq_transaction_isolation_verified',
 'faq_append_plan_verified','faq_prefix_handling_verified',
}
OBSERVATIONS = {'hosted_case_mutations_not_exercised','external_supplier_cancellation_not_exercised',
 'gateway_refunds_not_exercised','customer_email_body_not_exercised','header_authenticated_payload_not_exercised','hero_attachment_selection_not_exercised',
 'faq_appended','faq_already_owned','delivery_copy_aligned','delivery_counterpart_created',
 'delivery_page_present','delivery_page_unique','delivery_page_structure_known','delivery_copy_current','delivery_copy_legacy','delivery_copy_observed'}
DELIVERY_CLASSES={'unchecked','absent','duplicate','structure_unknown','current','legacy','observed_staging_copy','owned','manual_content','marker_unknown'}
DELIVERY_OBSERVATIONS={'faq_appended','faq_already_owned','delivery_copy_aligned','delivery_counterpart_created',
 'delivery_page_present','delivery_page_unique','delivery_page_structure_known','delivery_copy_current','delivery_copy_legacy','delivery_copy_observed'}
CATEGORIES = {'input','paths','source','bootstrap','runtime','snapshot','contracts','controls','prepare','faq','complete','invalid_report'}


def regular(path, maximum=65536):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= maximum:
        raise ValueError('Invalid report file')
    with path.open('rb') as stream:
        value = stream.read(maximum + 1)
    if len(value) != info.st_size or len(value) > maximum:
        raise ValueError('Report changed while reading')
    return value


def no_duplicate_keys(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('Duplicate JSON key')
        value[key] = item
    return value


def source_manifest(value):
    if not isinstance(value, dict) or not REQUIRED_SOURCE <= set(value) or not len(REQUIRED_SOURCE) <= len(value) <= 128:
        raise ValueError('Incomplete source manifest')
    for name, digest in value.items():
        if not isinstance(name, str) or not SOURCE_PATH.fullmatch(name) or any(part in {'.', '..'} for part in name.split('/')) \
                or not isinstance(digest, str) or not SHA.fullmatch(digest):
            raise ValueError('Invalid source entry')
    return dict(sorted(value.items()))


def build_manifest(revision):
    """Hash exact reviewed Git blobs, never working-tree files or symlink targets."""
    if not re.fullmatch(r'[a-f0-9]{40}', revision):
        raise ValueError('Invalid source revision')
    listing = subprocess.run(['git', 'ls-tree', '-rz', revision, '--', PREFIX], check=True,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
    result = {}
    total = 0
    for entry in listing.rstrip(b'\0').split(b'\0'):
        header, raw_name = entry.split(b'\t', 1)
        mode, kind, blob = header.decode('ascii').split(' ')
        path = raw_name.decode('ascii')
        if mode not in {'100644', '100755'} or kind != 'blob' or not path.startswith(PREFIX):
            raise ValueError('Only tracked regular source files are allowed')
        name = path[len(PREFIX):]
        if not SOURCE_PATH.fullmatch(name) or any(part in {'.', '..'} for part in name.split('/')):
            raise ValueError('Invalid tracked source path')
        size = int(subprocess.run(['git', 'cat-file', '-s', blob], check=True,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout)
        if not 0 < size <= 1048576 or total + size > 8388608 or len(result) >= 128:
            raise ValueError('Source bounds exceeded')
        content = subprocess.run(['git', 'cat-file', 'blob', blob], check=True,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
        total += len(content)
        if len(content) != size:
            raise ValueError('Source bounds exceeded')
        result[name] = hashlib.sha256(content).hexdigest()
    return source_manifest(result)


def theme_manifest(value):
    if not isinstance(value,dict) or set(value)!=REQUIRED_THEME or any(not isinstance(v,str) or not SHA.fullmatch(v) for v in value.values()):
        raise ValueError('Invalid fixed header source manifest')
    return dict(sorted(value.items()))


def build_theme_manifest(revision):
    if not re.fullmatch(r'[a-f0-9]{40}',revision):
        raise ValueError('Invalid source revision')
    result={}
    for name in sorted(REQUIRED_THEME):
        path=THEME_PREFIX+name
        listing=subprocess.run(['git','ls-tree','-z',revision,'--',path],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
        entries=listing.rstrip(b'\0').split(b'\0')
        if len(entries)!=1 or b'\t' not in entries[0]:
            raise ValueError('Required fixed header source is missing')
        header,raw_path=entries[0].split(b'\t',1)
        mode,kind,blob=header.decode('ascii').split(' ')
        if mode not in {'100644','100755'} or kind!='blob' or raw_path.decode('ascii')!=path:
            raise ValueError('Only regular fixed header files are permitted')
        size=int(subprocess.run(['git','cat-file','-s',blob],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout)
        if not 0<size<=1048576:
            raise ValueError('Header source bounds exceeded')
        content=subprocess.run(['git','cat-file','blob',blob],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
        if len(content)!=size:
            raise ValueError('Header source bounds exceeded')
        result[name]=hashlib.sha256(content).hexdigest()
    return theme_manifest(result)


def screen(path, mode):
    try:
        raw = json.loads(regular(path), object_pairs_hook=no_duplicate_keys)
        if mode not in {'prepare', 'verify'} or not isinstance(raw, dict) or set(raw) != {'schema_version','target','mode','status','category','checks','observations','source_hashes','theme_source_hashes','delivery_page'} \
                or type(raw['schema_version']) is not int or raw['schema_version'] != 1 or raw['target'] != 'staging' \
                or raw['mode'] != mode or raw['status'] not in {'success','failure'} or raw['category'] not in CATEGORIES:
            raise ValueError('Invalid report')
        for field,names in [('checks', COMMON|VERIFY|PREPARE),('observations',OBSERVATIONS)]:
            if not isinstance(raw[field],dict) or not set(raw[field]) <= names or any(type(value) is not bool for value in raw[field].values()):
                raise ValueError('Invalid finite checks')
        if raw['source_hashes']:
            raw['source_hashes'] = source_manifest(raw['source_hashes'])
        elif raw['source_hashes'] != {}:
            raise ValueError('Invalid manifest')
        if raw['theme_source_hashes']:
            raw['theme_source_hashes']=theme_manifest(raw['theme_source_hashes'])
        elif raw['theme_source_hashes']!={}:
            raise ValueError('Invalid header manifest')
        page=raw['delivery_page']
        if not isinstance(page,dict) or set(page)!={'classification','content_sha256'} or page['classification'] not in DELIVERY_CLASSES \
                or (page['content_sha256'] is not None and (not isinstance(page['content_sha256'],str) or not SHA.fullmatch(page['content_sha256']))):
            raise ValueError('Invalid delivery-page classification')
        if page['classification'] in {'unchecked','absent','duplicate'} and page['content_sha256'] is not None:
            raise ValueError('Invalid missing-page digest')
        if page['classification'] not in {'unchecked','absent','duplicate','structure_unknown'} and page['content_sha256'] is None:
            raise ValueError('Missing content digest')
        if page['classification']=='observed_staging_copy' and page['content_sha256']!=OBSERVED_STAGING_SHA:
            raise ValueError('Unreviewed staging prefix digest')
        required = COMMON | (PREPARE if mode == 'prepare' else VERIFY)
        obs = raw['observations']
        finite=DELIVERY_OBSERVATIONS
        kind=page['classification']
        inspection={'delivery_page_present':kind not in {'unchecked','absent'},
                    'delivery_page_unique':kind not in {'unchecked','absent','duplicate'},
                    'delivery_page_structure_known':kind in {'current','legacy','observed_staging_copy','owned','manual_content','marker_unknown'},
                    'delivery_copy_current':kind=='current','delivery_copy_legacy':kind=='legacy','delivery_copy_observed':kind=='observed_staging_copy'}
        outcome={'faq_appended':mode=='prepare' and kind!='owned','faq_already_owned':kind=='owned',
                 'delivery_copy_aligned':mode=='prepare' and kind=='legacy','delivery_counterpart_created':mode=='prepare' and kind=='absent'}
        passed = raw['status']=='success' and raw['category']=='complete' and bool(raw['source_hashes']) and bool(raw['theme_source_hashes']) \
            and all(raw['checks'].get(key) is True for key in required) and set(obs)==OBSERVATIONS \
            and all(obs.get(key) is True for key in OBSERVATIONS-finite) \
            and all(obs.get(key) is value for key,value in (inspection|outcome).items()) \
            and (kind in {'absent','current','legacy','observed_staging_copy','owned'} if mode=='prepare' else kind=='owned')
        if not passed:
            raw['status']='failure'
        return raw,passed
    except Exception:
        return {'schema_version':1,'target':'staging','mode':mode if mode in {'prepare','verify'} else 'verify',
                'status':'failure','category':'invalid_report','checks':{},'observations':{},'source_hashes':{},'theme_source_hashes':{},
                'delivery_page':{'classification':'unchecked','content_sha256':None}},False


if __name__ == '__main__':
    os.umask(0o077)
    if len(sys.argv)!=4 or sys.argv[1] not in {'manifest','theme-manifest','prepare','verify'}:
        raise SystemExit(1)
    if sys.argv[1]=='manifest':
        result,passed=build_manifest(sys.argv[2]),True
    elif sys.argv[1]=='theme-manifest':
        result,passed=build_theme_manifest(sys.argv[2]),True
    else:
        result,passed=screen(Path(sys.argv[2]),sys.argv[1])
    with Path(sys.argv[3]).open('x') as stream:
        stream.write(json.dumps(result,sort_keys=True,indent=2)+'\n')
    raise SystemExit(0 if passed else 1)
