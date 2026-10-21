#!/usr/bin/env python3
"""Keep only finite production-release facts; customer/server data stays private."""
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import sys

ROOT = Path(__file__).resolve().parents[1]
DEPLOY = ROOT / 'scripts/deploy-customer-reviews-remote.php'
VERIFY = ROOT / 'scripts/verify-customer-reviews.php'
DEPLOY_PHASES = {'inspect', 'apply', 'enable', 'resume', 'rollback', 'rollback-finalize', 'commit'}
PHASES = DEPLOY_PHASES | {'verify', 'public', 'rollback-purge'}


def constant_body(path, name):
    match = re.search(r'const\s+' + re.escape(name) + r'\s*=\s*\[(.*?)\];', path.read_text(), re.S)
    if not match:
        raise ValueError('Missing fixed schema')
    return match.group(1)


def names(raw):
    values = re.findall(r"'([a-z][a-z0-9_]{0,79})'", raw)
    if not values or len(values) != len(set(values)) or re.sub(r"'[a-z][a-z0-9_]{0,79}'|[\s,]", '', raw):
        raise ValueError('Invalid fixed schema')
    return set(values)


def deploy_schema():
    allowed = names(constant_body(DEPLOY, 'PETISH_CUSTOMER_REVIEWS_RELEASE_CHECKS'))
    categories = names(constant_body(DEPLOY, 'PETISH_CUSTOMER_REVIEWS_RELEASE_CATEGORIES'))
    raw = constant_body(DEPLOY, 'PETISH_CUSTOMER_REVIEWS_RELEASE_REQUIRED')
    pattern = r"'([a-z][a-z0-9_-]*)'\s*=>\s*\[([^\[\]]*)\]"
    pairs = re.findall(pattern, raw)
    required = {phase: names(body) for phase, body in pairs}
    if len(pairs) != len(required) or set(required) != DEPLOY_PHASES or re.sub(pattern + r'|[\s,]', '', raw):
        raise ValueError('Invalid phase schema')
    if any(keys - allowed for keys in required.values()):
        raise ValueError('Unknown required check')
    files_raw = constant_body(DEPLOY, 'PETISH_CUSTOMER_REVIEWS_FILES')
    files_pattern = r"'([A-Za-z0-9_./-]+)'\s*=>\s*'([a-f0-9]{64})'"
    files = re.findall(files_pattern, files_raw)
    if not files or len(files) != len(dict(files)) or re.sub(files_pattern + r'|[\s,]', '', files_raw):
        raise ValueError('Invalid file schema')
    return allowed, required, categories, len(files)


def public_checks(probe_only=False):
    spec = importlib.util.spec_from_file_location('public_review_checks', ROOT / 'scripts/verify-customer-reviews-public.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.PROBE_CHECKS if probe_only else module.CHECKS


def unique_object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('Duplicate report field')
        value[key] = item
    return value


def sanitize(phase, source):
    if phase not in PHASES:
        raise ValueError('Unknown phase')
    info = source.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= 65536:
        raise ValueError('Invalid report file')
    value = json.loads(source.read_text(), object_pairs_hook=unique_object)
    if not isinstance(value, dict) or value.get('status') not in {'success', 'failure'} or not isinstance(value.get('checks'), dict):
        raise ValueError('Invalid report shape')
    if phase in DEPLOY_PHASES:
        allowed, requirements, categories, file_count = deploy_schema()
        required = requirements[phase]
    elif phase == 'verify':
        allowed = required = names(constant_body(VERIFY, 'PETISH_CUSTOMER_REVIEWS_VERIFY_CHECKS'))
        categories = {'input', 'bootstrap', 'snapshot', 'contracts', 'complete'}
    else:
        allowed = required = public_checks(phase == 'rollback-purge')
        categories = {'input', 'public_pages', 'product', 'assets', 'upload_capacity', 'cache_probe', 'complete'}
    checks = value['checks']
    if set(checks) - allowed or not all(type(passed) is bool for passed in checks.values()) or value.get('category') not in categories:
        raise ValueError('Non-finite report')
    if value['status'] == 'success' and (
        value['category'] != 'complete' or set(checks) != allowed or any(checks.get(key) is not True for key in required)
    ):
        raise ValueError('Incomplete proof')
    result = {'status': value['status'], 'category': value['category'], 'checks': checks}
    if phase in DEPLOY_PHASES:
        count = value.get('changed_file_count')
        if type(count) is not int or not 0 <= count <= file_count:
            raise ValueError('Invalid changed-file count')
        result['changed_file_count'] = count
    return result


if __name__ == '__main__':
    os.umask(0o077)
    try:
        if len(sys.argv) != 4:
            raise ValueError('Invalid arguments')
        result = sanitize(sys.argv[1], Path(sys.argv[2]))
        output = Path(sys.argv[3])
        descriptor = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'w') as stream:
            stream.write(json.dumps(result, indent=2) + '\n')
    except (ValueError, TypeError, OSError, KeyError):
        raise SystemExit('CUSTOMER_REVIEWS_PRODUCTION_REPORT_REJECTED')
    raise SystemExit(0 if result['status'] == 'success' else 1)
