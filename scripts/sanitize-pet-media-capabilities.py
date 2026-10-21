#!/usr/bin/env python3
"""Accept only bounded, nonsecret software capabilities from the fixed host probe."""
import json
import os
from pathlib import Path
import re
import stat
import sys

FUNCTIONS = {'exec', 'shell_exec', 'proc_open', 'proc_close', 'popen', 'pclose', 'system',
             'passthru', 'pcntl_exec', 'pcntl_fork', 'link', 'symlink', 'rename', 'move_uploaded_file', 'finfo_open'}
EXTENSIONS = {'fileinfo', 'gd', 'imagick'}
LIMITS = {'upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time', 'max_input_time'}


def screen(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_mode & 0o7777 != 0o600 \
            or not 0 < info.st_size <= 16384:
        raise ValueError()
    raw = json.loads(path.read_bytes())
    if not isinstance(raw, dict) or set(raw) != {'architecture', 'ffmpeg', 'ffprobe', 'crontab', 'php'}:
        raise ValueError()
    if raw['architecture'] not in {'x86_64', 'aarch64', 'arm64', 'armv7l', 'i686', 'other'}:
        raise ValueError()
    for name in ('ffmpeg', 'ffprobe'):
        value = raw[name]
        if not isinstance(value, dict) or set(value) != {'available', 'version'} \
                or type(value['available']) is not bool:
            raise ValueError()
        version = value['version']
        if version is not None and (not value['available'] or not isinstance(version, str)
                or not re.fullmatch(r'[0-9]{1,3}(?:\.[0-9]{1,3}){1,3}', version)):
            raise ValueError()
    cron = raw['crontab']
    if not isinstance(cron, dict) or set(cron) != {'available', 'list_readable'} \
            or any(type(value) is not bool for value in cron.values()) \
            or (cron['list_readable'] and not cron['available']):
        raise ValueError()
    php = raw['php']
    if not isinstance(php, dict) or set(php) != {'runtime', 'version', 'functions', 'extensions', 'limits'} \
            or php['runtime'] != 'cli' or not isinstance(php['version'], str) or not re.fullmatch(r'[0-9]{1,3}(?:\.[0-9]{1,3}){2}', php['version']):
        raise ValueError()
    for name, allowed in (('functions', FUNCTIONS), ('extensions', EXTENSIONS)):
        if not isinstance(php[name], dict) or set(php[name]) != allowed \
                or any(type(value) is not bool for value in php[name].values()):
            raise ValueError()
    if not isinstance(php['limits'], dict) or set(php['limits']) != LIMITS:
        raise ValueError()
    for key, value in php['limits'].items():
        pattern = r'-1|[0-9]{1,10}' if key in {'max_execution_time', 'max_input_time'} else r'-1|[0-9]{1,10}[KMGkmg]?'
        if not isinstance(value, str) or not re.fullmatch(pattern, value):
            raise ValueError()
    return raw


if __name__ == '__main__':
    os.umask(0o077)
    try:
        if len(sys.argv) != 3:
            raise ValueError()
        result = screen(Path(sys.argv[1]))
        with Path(sys.argv[2]).open('x') as stream:
            stream.write(json.dumps(result, indent=2) + '\n')
    except Exception:
        print('The capability probe did not produce a valid bounded report.', file=sys.stderr)
        raise SystemExit(1)
