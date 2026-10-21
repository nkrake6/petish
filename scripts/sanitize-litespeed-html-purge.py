#!/usr/bin/env python3
"""Publish fixed boolean HTML-purge evidence, never queue headers or options."""
import json
import os
from pathlib import Path
import stat
import sys

COMMON = {'target_paths_verified', 'target_environment_verified', 'woocommerce_runtime_verified',
          'all_outbound_http_blocked', 'mail_calls_blocked', 'remote_file_access_disabled',
          'cron_and_async_runner_disabled', 'jetpack_sync_isolated', 'purge_runtime_verified'}
IDLE = {'purge_queue_idle', 'purge_queue2_idle', 'purge_transactional_options'}
REQUIRED = {
    'inspect': COMMON | IDLE,
    'queue': COMMON | (IDLE - {'purge_queue_idle'}) | {'purge_queue_available', 'purge_native_scope_verified', 'purge_exact_queue_write_verified',
                            'purge_other_queue_unchanged', 'purge_receipt_saved'},
    'verify': COMMON | {'purge_receipt_verified', 'purge_queue_consumed', 'purge_other_queue_unchanged'},
    'consume': {'fixed_anonymous_get_verified', 'native_ajax_response_verified'},
}
CATEGORIES = {'input', 'paths', 'bootstrap', 'runtime', 'cache_runtime', 'cache_queue',
              'cache_receipt', 'http', 'complete', 'invalid_report'}


def screen(phase, path):
    try:
        if phase not in REQUIRED:
            raise ValueError()
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= 65536:
            raise ValueError()
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(descriptor, 'rb') as stream:
            opened = os.fstat(stream.fileno())
            if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                raise ValueError()
            raw = json.loads(stream.read(65537))
        if not isinstance(raw, dict) or set(raw) != {'status', 'category', 'checks'} \
                or raw['status'] not in {'success', 'failure'} or raw['category'] not in CATEGORIES \
                or not isinstance(raw['checks'], dict) or not set(raw['checks']) <= REQUIRED[phase] \
                or any(type(value) is not bool for value in raw['checks'].values()):
            raise ValueError()
        passed = raw['status'] == 'success' and raw['category'] == 'complete' \
            and all(raw['checks'].get(key) is True for key in REQUIRED[phase])
        if not passed:
            raw['status'] = 'failure'
        return raw, passed
    except (OSError, ValueError, TypeError, KeyError):
        return {'status': 'failure', 'category': 'invalid_report', 'checks': {}}, False


def main(arguments):
    if len(arguments) != 3:
        return 1
    result, passed = screen(arguments[0], Path(arguments[1]))
    try:
        destination = Path(arguments[2]).absolute()
        if destination.parent.resolve(strict=True) != destination.parent:
            raise ValueError()
        descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'w') as stream:
            stream.write(json.dumps(result, indent=2) + '\n')
    except (OSError, ValueError):
        return 1
    return 0 if passed else 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
