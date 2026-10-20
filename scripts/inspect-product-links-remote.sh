#!/usr/bin/env bash
# Read only five known variation/parent records. Never bootstrap WordPress.
set +x
set -euo pipefail
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
export LC_ALL=C
site_path=$HOME/domains/petish.biz/public_html
unavailable() {
    printf '{"status":"unavailable","category":"%s"}\n' "$1"
    exit 1
}
[[ -f $site_path/wp-config.php ]] || unavailable site_unavailable
command -v wp >/dev/null 2>&1 || unavailable wpcli_unavailable
command -v php >/dev/null 2>&1 || unavailable php_unavailable
if ! prefix=$(wp --path="$site_path" --no-color config get table_prefix --type=variable 2>/dev/null); then
    unavailable database_configuration
fi
[[ $prefix =~ ^[A-Za-z0-9_]+$ ]] || unavailable database_configuration
for constant in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
    if ! connection_value=$(wp --path="$site_path" --no-color config get "$constant" 2>/dev/null); then
        unavailable database_configuration
    fi
    printf -v "PETISH_$constant" '%s' "$connection_value"
    export "PETISH_$constant"
done
unset connection_value
export PETISH_DB_PREFIX=$prefix
PETISH_DB_CLIENT_FLAGS=0
if connection_flags=$(wp --path="$site_path" --no-color config get MYSQL_CLIENT_FLAGS 2>/dev/null); then
    PETISH_DB_CLIENT_FLAGS=$connection_flags
fi
export PETISH_DB_CLIENT_FLAGS
unset connection_flags

php <<'PETISH_PRODUCT_LINKS'
<?php
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);
$category = 'database_driver';
try {
    if (!function_exists('mysqli_init')) throw new RuntimeException();
    $category = 'database_configuration';
    $prefix = getenv('PETISH_DB_PREFIX');
    if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix)) throw new RuntimeException();
    $host = getenv('PETISH_DB_HOST'); $socket = null; $port = 3306;
    if (!is_string($host) || $host === '' || preg_match('/[\x00-\x20]/', $host)) throw new RuntimeException();
    $offset = strpos($host, ':/');
    if ($offset !== false) { $socket = substr($host, $offset + 1); $host = substr($host, 0, $offset); }
    if (preg_match('/\A\[([^\]]+)\](?::([0-9]+))?\z/D', $host, $parts)) {
        if (!filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) throw new RuntimeException();
        $host = $parts[1]; $port = isset($parts[2]) ? (int)$parts[2] : 3306;
    } elseif (preg_match('/\A([A-Za-z0-9_.-]+):([0-9]+)\z/D', $host, $parts)) {
        $host = $parts[1]; $port = (int)$parts[2];
    } elseif (!preg_match('/\A[A-Za-z0-9_.-]+\z/D', $host) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) throw new RuntimeException();
    if ($port < 1 || $port > 65535) throw new RuntimeException();
    $setting = trim((string)getenv('PETISH_DB_CLIENT_FLAGS'));
    if (preg_match('/\A[0-9]+\z/D', $setting)) $flags = (int)$setting;
    elseif ($setting === 'MYSQLI_CLIENT_SSL' && defined('MYSQLI_CLIENT_SSL')) $flags = MYSQLI_CLIENT_SSL;
    else throw new RuntimeException();
    if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT') && ($flags & MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT)) throw new RuntimeException();
    $category = 'database_connection';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = mysqli_init();
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (defined('MYSQLI_CLIENT_SSL') && ($flags & MYSQLI_CLIENT_SSL)) $database->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    $database->real_connect($host, getenv('PETISH_DB_USER'), getenv('PETISH_DB_PASSWORD'), getenv('PETISH_DB_NAME'), $port, $socket, $flags);
    $database->set_charset('utf8mb4');
    $category = 'product_lookup';
    // This is the only application query: no options, customers, orders,
    // titles, or credentials are selected or published.
    $rows = $database->query(
        "SELECT v.ID AS variation_id, p.post_name FROM `{$prefix}posts` v "
        . "INNER JOIN `{$prefix}posts` p ON p.ID = v.post_parent "
        . "WHERE v.ID IN (246,153,2137,858,1546) "
        . "AND v.post_type = 'product_variation' AND v.post_status = 'publish' "
        . "AND p.post_type = 'product' AND p.post_status = 'publish' ORDER BY v.ID"
    );
    $products = []; $ids = [];
    while ($row = $rows->fetch_assoc()) {
        $id = (int)$row['variation_id']; $slug = $row['post_name'];
        if (!is_string($slug) || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) || strlen($slug) > 200) throw new RuntimeException();
        $ids[] = $id;
        $products[] = ['variation_id' => $id, 'url' => 'https://petish.biz/product/' . rawurlencode($slug) . '/'];
    }
    $rows->free(); $database->close();
    if ($ids !== [153,246,858,1546,2137]) throw new RuntimeException();
    echo json_encode($products, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    echo json_encode(['status'=>'unavailable', 'category'=>$category], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
PETISH_PRODUCT_LINKS
