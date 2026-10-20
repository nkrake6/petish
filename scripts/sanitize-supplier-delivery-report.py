#!/usr/bin/env python3
"""Screen the product-only aggregate audit; never publish raw values or errors."""
import json
import os
from pathlib import Path
import re
import stat
import sys

CATEGORIES = {'input','site_unavailable','wpcli_unavailable','php_unavailable',
    'database_driver','database_configuration','database_connection','published_products',
    'metadata_fields','supplier_orders','shipping_policy','theme_files','complete'}
THEME_FILES = {'front-page.php','includes/supplier-popularity.php','assets/css/home.css'}
FIELD_COUNTS = {'rows','products','variations','empty','true_like','false_like','decimal_zero','structured'}


def read_report(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 65536:
        raise ValueError()
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
            raise ValueError()
        raw = stream.read(65537)
    if len(raw) > 65536:
        raise ValueError()
    return json.loads(raw)


def count(value, maximum=1000000000):
    if type(value) is not int or not 0 <= value <= maximum:
        raise ValueError()
    return value


def counts(raw, names, maximum=1000000000):
    if not isinstance(raw, dict) or set(raw) != names:
        raise ValueError()
    return {name: count(value, maximum) for name, value in raw.items()}


def metadata_name(value):
    return isinstance(value, str) and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_-]{0,79}', value) \
        and re.search(r'supplier|shipping|freight|dsers|ali', value, re.I) \
        and not re.search(r'token|password|secret|credential|auth|session|customer|email|phone|address', value, re.I)


def book(raw, kind):
    names = {'present','schema_valid','rows','zero_rows'} | (
        {'unexpired_zero_rows','explicit_free_unexpired_zero_rows'} if kind == 'automatic' else {'current_zero_rows'})
    if not isinstance(raw, dict) or set(raw) != names \
            or any(type(raw[name]) is not bool for name in ('present','schema_valid')):
        raise ValueError()
    values = counts({name: value for name, value in raw.items() if name not in {'present','schema_valid'}},
                    names - {'present','schema_valid'}, 10000)
    if values['zero_rows'] > values['rows'] or (not raw['schema_valid'] and any(values.values())) \
            or (raw['schema_valid'] and not raw['present']):
        raise ValueError()
    if kind == 'automatic':
        if not values['explicit_free_unexpired_zero_rows'] <= values['unexpired_zero_rows'] <= values['zero_rows']:
            raise ValueError()
    elif values['current_zero_rows'] > values['zero_rows']:
        raise ValueError()
    return raw


def screen(path):
    try:
        raw = read_report(path)
        if not isinstance(raw, dict) or raw.get('status') not in {'success','unavailable'} \
                or raw.get('category') not in CATEGORIES:
            raise ValueError()
        if raw['status'] == 'unavailable':
            if set(raw) != {'status','category'}:
                raise ValueError()
            return raw
        if set(raw) != {'status','category','published_counts','metadata_fields',
                'excluded_metadata_field_count','supplier_orders','shipping_policy','live_theme_hashes'} \
                or raw['category'] != 'complete':
            raise ValueError()
        published = counts(raw['published_counts'], {'products','variations'})
        fields = raw['metadata_fields']
        excluded = count(raw['excluded_metadata_field_count'], 128)
        if not isinstance(fields, list) or len(fields) + excluded > 128:
            raise ValueError()
        seen = set()
        for field in fields:
            if not isinstance(field, dict) or set(field) != FIELD_COUNTS | {'name'} \
                    or not metadata_name(field['name']) or field['name'] in seen:
                raise ValueError()
            seen.add(field['name'])
            values = counts({name: value for name, value in field.items() if name != 'name'}, FIELD_COUNTS)
            if values['rows'] < 1 or values['products'] > published['products'] \
                    or values['variations'] > published['variations'] \
                    or values['products'] + values['variations'] > values['rows'] \
                    or any(value > values['rows'] for value in values.values()) \
                    or values['empty'] + values['true_like'] + values['false_like'] > values['rows']:
                raise ValueError()
        supplier = counts(raw['supplier_orders'], {'products_with_numeric_count',
            'products_with_fresh_observation','hot_eligible_products'}, published['products'])
        if supplier['hot_eligible_products'] > min(supplier['products_with_numeric_count'],supplier['products_with_fresh_observation']):
            raise ValueError()
        policy = raw['shipping_policy']
        if not isinstance(policy, dict) or set(policy) != {'mode','automatic','quotes'} \
                or policy['mode'] not in {'automatic','quote','missing','other'}:
            raise ValueError()
        book(policy['automatic'], 'automatic'); book(policy['quotes'], 'quotes')
        hashes = raw['live_theme_hashes']
        if not isinstance(hashes, dict) or set(hashes) != THEME_FILES \
                or any(not isinstance(value,str) or not re.fullmatch(r'[a-f0-9]{64}',value) for value in hashes.values()):
            raise ValueError()
        return raw
    except Exception:
        return {'status':'unavailable','category':'invalid_report'}


if __name__ == '__main__':
    os.umask(0o077)
    if len(sys.argv) != 3:
        raise SystemExit(1)
    Path(sys.argv[2]).write_text(json.dumps(screen(Path(sys.argv[1])),indent=2) + '\n')
