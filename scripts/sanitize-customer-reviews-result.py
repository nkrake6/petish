#!/usr/bin/env python3
"""Publish fixed booleans only; private fixture values never cross this boundary."""
import json
import re
import stat
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHASES = {'prepare','source','enable','verify','cleanup','rollback-flag','rollback-code','commit','native','cleanup-fixture','browser'}


def literal_list(path, name, php=True):
    text = path.read_text()
    pattern = (r'const\s+' + re.escape(name) + r'\s*=\s*(?:array\(|\[)(.*?)(?:\);|\];)') if php else (r'export const\s+' + re.escape(name) + r'\s*=\s*Object\.freeze\(\[(.*?)\]\);')
    match = re.search(pattern, text, re.S)
    if not match:
        raise ValueError('Missing fixed report schema')
    raw = match.group(1)
    names = re.findall(r"'([a-z][a-z0-9_]{0,79})'", raw)
    if not names or len(names) != len(set(names)) or re.sub(r"'[^']+'|[\s,]", '', raw):
        raise ValueError('Invalid fixed report schema')
    return set(names)


def sanitize(phase, source):
    if phase not in PHASES:
        raise ValueError('Invalid report phase')
    info = source.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 1024 * 1024:
        raise ValueError('Invalid report file')
    value = json.loads(source.read_text())
    if not isinstance(value, dict) or value.get('status') not in {'success','failure'} or not isinstance(value.get('checks'), dict):
        raise ValueError('Invalid report structure')
    if phase in {'native','cleanup-fixture'}:
        constant = 'PETISH_CUSTOMER_REVIEWS_PROOF_CHECKS' if phase == 'native' else 'PETISH_CUSTOMER_REVIEWS_CLEANUP_CHECKS'
        allowed = literal_list(ROOT / 'scripts/customer-reviews-proof.php', constant)
        required = allowed
    elif phase == 'browser':
        allowed = literal_list(ROOT / 'tools/customer-reviews-browser.mjs', 'CHECK_KEYS', php=False)
        required = allowed
    else:
        allowed = literal_list(ROOT / 'scripts/customer-reviews-staging-remote.php', 'PCR_CHECKS')
        base = {'fixed_staging_paths','configuration_isolated','guard_pinned'}
        boot = {'environment_staging','database_separate','outbound_blocked','mail_blocked','async_blocked','jetpack_isolated'}
        required = base | {
            'prepare': {'code_snapshot_verified'},
            'source': {'source_verified','protected_files_unchanged'},
            'enable': boot | {'source_verified','browser_guard_installed','upload_limits_sufficient','flag_journal_written','flag_enabled'},
            'verify': boot | {'source_verified','native_proof_passed'},
            'cleanup': boot | {'fixture_cleanup_verified','browser_guard_removed','protected_files_unchanged'},
            'rollback-flag': boot | {'flag_restored','browser_guard_removed'},
            'rollback-code': {'code_restored','protected_files_unchanged'},
            'commit': boot | {'source_verified','protected_files_unchanged','flag_enabled','browser_guard_removed','release_committed'},
        }[phase]
    checks = value['checks']
    if set(checks) - allowed or not all(type(passed) is bool for passed in checks.values()):
        raise ValueError('Non-finite report checks')
    if value['status'] == 'success' and (not required <= set(checks) or not all(checks.values())):
        raise ValueError('Incomplete successful proof')
    result = {'status': value['status'], 'checks': checks}
    failed = value.get('failed_check', '')
    if failed:
        if failed not in allowed:
            raise ValueError('Unknown failed check')
        result['failed_check'] = failed
    return result


if __name__ == '__main__':
    try:
        if len(sys.argv) != 4:
            raise ValueError('Invalid arguments')
        result = sanitize(sys.argv[1], Path(sys.argv[2]))
        Path(sys.argv[3]).write_text(json.dumps(result, indent=2) + '\n')
    except (ValueError, OSError, TypeError, KeyError):
        raise SystemExit('CUSTOMER_REVIEWS_REPORT_REJECTED')
    raise SystemExit(0 if result['status'] == 'success' else 1)
