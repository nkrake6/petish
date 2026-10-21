#!/usr/bin/env bash
# Read-only host capabilities. No WordPress bootstrap or remote file/cron writes.
set +x
set -euo pipefail
export LC_ALL=C

command -v php >/dev/null 2>&1 || exit 1
bounded_version() {
    local program=$1 banner=
    command -v "$program" >/dev/null 2>&1 || { printf 'false\tnull\n'; return; }
    # A missing timeout utility leaves version unknown; never run an unbounded probe.
    if command -v timeout >/dev/null 2>&1; then
        banner=$(timeout --signal=KILL 5 "$program" -version 2>/dev/null | head -c 16384) || true
    fi
    if [[ $banner =~ ^$program[[:space:]]version[[:space:]]([0-9]{1,3}(\.[0-9]{1,3}){1,3})([^0-9.]|$) ]]; then
        printf 'true\t"%s"\n' "${BASH_REMATCH[1]}"
    else
        printf 'true\tnull\n'
    fi
}
version_probe=$(bounded_version ffmpeg)
read -r ffmpeg_available ffmpeg_version <<< "$version_probe"
version_probe=$(bounded_version ffprobe)
read -r ffprobe_available ffprobe_version <<< "$version_probe"
unset version_probe
cron_available=false
cron_readable=false
if command -v crontab >/dev/null 2>&1; then
    cron_available=true
    # Never capture or publish any installed cron command or environment value.
    if command -v timeout >/dev/null 2>&1 && timeout --signal=KILL 5 crontab -l >/dev/null 2>&1; then
        cron_readable=true
    fi
fi
architecture=$(uname -m 2>/dev/null) || architecture=other
case "$architecture" in x86_64|aarch64|arm64|armv7l|i686) ;; *) architecture=other ;; esac
php_result=$(php -d display_errors=0 -d log_errors=0 -r '
$functions=[];foreach(["exec","shell_exec","proc_open","proc_close","popen","pclose","system","passthru","pcntl_exec","pcntl_fork","link","symlink","rename","move_uploaded_file","finfo_open"]as$name)$functions[$name]=function_exists($name);
$extensions=[];foreach(["fileinfo","gd","imagick"]as$name)$extensions[$name]=extension_loaded($name);
$limits=[];foreach(["upload_max_filesize","post_max_size","memory_limit","max_execution_time","max_input_time"]as$name)$limits[$name]=ini_get($name);
echo json_encode(["runtime"=>PHP_SAPI,"version"=>PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION,"functions"=>$functions,"extensions"=>$extensions,"limits"=>$limits],JSON_THROW_ON_ERROR);
')
printf '{"architecture":"%s","ffmpeg":{"available":%s,"version":%s},"ffprobe":{"available":%s,"version":%s},"crontab":{"available":%s,"list_readable":%s},"php":%s}\n' \
    "$architecture" "$ffmpeg_available" "$ffmpeg_version" "$ffprobe_available" "$ffprobe_version" "$cron_available" "$cron_readable" "$php_result"
