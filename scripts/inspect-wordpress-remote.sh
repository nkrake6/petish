#!/usr/bin/env bash
set +x
set -euo pipefail
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
export LC_ALL=C

site_path="$HOME/domains/petish.biz/public_html"
if [[ ! -f "$site_path/wp-config.php" || ! -f "$site_path/wp-includes/version.php" ]]; then
  printf '%s\n' 'WordPress was not found at the expected petish.biz document root. Stopping without changing files.' >&2
  exit 1
fi
if ! command -v wp >/dev/null 2>&1; then
  printf '%s\n' 'WP-CLI is unavailable on this hosting account. Stopping without installing anything.' >&2
  exit 1
fi

# Read headers as text; do not include PHP files or load WordPress/plugins/themes.
read_header() {
  awk -v header="$2" '
    NR > 100 { exit }
    {
      line = $0
      sub(/^[ \t\/*#]+/, "", line)
      if (index(tolower(line), tolower(header) ":") == 1) {
        sub(/^[^:]*:[ \t]*/, "", line)
        sub(/[\r\n].*$/, "", line)
        gsub(/\|/, "/", line)
        print line
        exit
      }
    }
  ' "$1"
}

printf '# Petish WordPress inspection\n\n'
printf 'Generated (UTC): %s\n\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
printf '## WordPress version\n\n'
wp --path="$site_path" --no-color core version

printf '\n## Installed themes (file headers)\n\n'
# Use a pipe rather than process substitution: jailed hosting shells may not
# expose /dev/fd. These loops do not need to retain variables in the parent shell.
find "$site_path/wp-content/themes" -mindepth 2 -maxdepth 2 -type f -name style.css -print0 |
while IFS= read -r -d '' file; do
  name="$(read_header "$file" 'Theme Name')"
  [[ -n "$name" ]] || continue
  version="$(read_header "$file" 'Version')"
  printf '%s | version: %s | directory: %s\n' "$name" "${version:-not declared}" "$(basename "$(dirname "$file")")"
done

printf '\n## Installed plugins (file headers)\n\n'
find "$site_path/wp-content/plugins" -mindepth 1 -maxdepth 2 -type f -name '*.php' ! -name '._*' -print0 |
while IFS= read -r -d '' file; do
  name="$(read_header "$file" 'Plugin Name')"
  [[ -n "$name" ]] || continue
  version="$(read_header "$file" 'Version')"
  printf '%s | version: %s\n' "$name" "${version:-not declared}"
done

printf '\n## Active configuration (selected options only)\n\n'
prefix="$(wp --path="$site_path" --no-color config get table_prefix --type=variable)"
if [[ ! "$prefix" =~ ^[A-Za-z0-9_]+$ ]]; then
  printf '%s\n' 'Unexpected table prefix; refusing to construct an inspection query.' >&2
  exit 1
fi
# wp db query launches an external client through PHP's proc_open(), which is
# disabled on this account. Read only the four required connection constants,
# keep their values off stdout/stderr, and use mysqli in a short-lived PHP process.
# WordPress, themes and plugins are not bootstrapped.
if ! command -v php >/dev/null 2>&1; then
  printf '%s\n' 'The PHP CLI is unavailable; stopping without changing server settings.' >&2
  exit 1
fi
for constant in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
  if ! connection_value="$(wp --path="$site_path" --no-color config get "$constant" 2>/dev/null)"; then
    printf '%s\n' 'Unable to read the required database configuration. No configuration values were printed.' >&2
    exit 1
  fi
  printf -v "PETISH_$constant" '%s' "$connection_value"
  export "PETISH_$constant"
done
unset connection_value
export PETISH_DB_PREFIX="$prefix"
PETISH_DB_CLIENT_FLAGS=0
if connection_flags="$(wp --path="$site_path" --no-color config get MYSQL_CLIENT_FLAGS 2>/dev/null)"; then
  PETISH_DB_CLIENT_FLAGS="$connection_flags"
fi
export PETISH_DB_CLIENT_FLAGS
unset connection_flags

php <<'PETISH_DATABASE_READ'
<?php
// Standalone, fixed SELECT only. Never include wp-config.php or print exceptions.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$inspectionStage = 'database driver check';
try {
    if (!function_exists('mysqli_init')) {
        throw new RuntimeException('Database driver unavailable');
    }
    $inspectionStage = 'connection settings validation';
    $prefix = getenv('PETISH_DB_PREFIX');
    if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]+\z/', $prefix)) {
        throw new RuntimeException('Unsupported table prefix');
    }

    $host = getenv('PETISH_DB_HOST');
    $socket = null;
    $port = 3306;
    if (!is_string($host) || $host === '' || preg_match('/[\x00-\x20]/', $host)) {
        throw new RuntimeException('Unsupported database host');
    }
    $socketOffset = strpos($host, ':/');
    if ($socketOffset !== false) {
        $socket = substr($host, $socketOffset + 1);
        $host = substr($host, 0, $socketOffset);
    }
    if (preg_match('/\A\[([^\]]+)\](?::([0-9]+))?\z/', $host, $parts)) {
        if (!filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new RuntimeException('Unsupported IPv6 host');
        }
        $host = $parts[1];
        $port = isset($parts[2]) ? (int) $parts[2] : 3306;
    } elseif (preg_match('/\A([A-Za-z0-9_.-]+):([0-9]+)\z/', $host, $parts)) {
        $host = $parts[1];
        $port = (int) $parts[2];
    } elseif (!preg_match('/\A[A-Za-z0-9_.-]+\z/', $host)
        && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        throw new RuntimeException('Unsupported database host syntax');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Unsupported database port');
    }

    // Preserve supported client flags without evaluating configuration expressions.
    $flagSetting = trim((string) getenv('PETISH_DB_CLIENT_FLAGS'));
    if (ctype_digit($flagSetting)) {
        $flags = (int) $flagSetting;
    } elseif ($flagSetting === 'MYSQLI_CLIENT_SSL' && defined('MYSQLI_CLIENT_SSL')) {
        $flags = MYSQLI_CLIENT_SSL;
    } else {
        throw new RuntimeException('Custom database client flags require review');
    }
    if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')
        && ($flags & MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT)) {
        throw new RuntimeException('Unverified database TLS is unsupported');
    }

    $inspectionStage = 'database connection';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = mysqli_init();
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (defined('MYSQLI_CLIENT_SSL') && ($flags & MYSQLI_CLIENT_SSL)) {
        $database->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    }
    $database->real_connect(
        $host,
        getenv('PETISH_DB_USER'),
        getenv('PETISH_DB_PASSWORD'),
        getenv('PETISH_DB_NAME'),
        $port,
        $socket,
        $flags
    );
    $database->set_charset('utf8mb4');
    $inspectionStage = 'selected-options query';
    $rows = $database->query(
        "SELECT option_name, option_value FROM `{$prefix}options` "
        . "WHERE option_name IN ('template', 'stylesheet', 'active_plugins', 'show_on_front', 'page_on_front') "
        . 'ORDER BY option_name'
    );
    echo "option_name\toption_value\n";
    while ($row = $rows->fetch_assoc()) {
        echo $row['option_name'], "\t", json_encode($row['option_value'], JSON_UNESCAPED_SLASHES), "\n";
    }
    $rows->free();
    $database->close();
} catch (Throwable $error) {
    file_put_contents('php://stderr', "The read-only database check failed during {$inspectionStage}. No database credentials or exception details were printed.\n");
    exit(1);
}
PETISH_DATABASE_READ
unset PETISH_DB_HOST PETISH_DB_NAME PETISH_DB_USER PETISH_DB_PASSWORD PETISH_DB_PREFIX PETISH_DB_CLIENT_FLAGS

printf '\nInspection completed. No content, files, users, settings, themes, or plugins were changed by this script.\n'
