#!/usr/bin/env bash
set +x
set -euo pipefail
export LC_ALL=C
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
umask 077
unset MYSQL_PWD

# Stdout is exclusively a complete-site tar.gz stream. The receiver must encrypt
# it before publication and discard partial output if this script exits nonzero.
# No WordPress code is bootstrapped and no live site files/settings are changed.

fail() {
  printf '%s\n' "$1" >&2
  exit 1
}

private_work=''
cleanup() {
  if [[ -n "$private_work" && -d "$private_work" ]]; then
    rm -rf -- "$private_work" 2>/dev/null || true
  fi
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

site_path="$HOME/domains/petish.biz/public_html"
domain_path="$HOME/domains/petish.biz"
if [[ ! -d "$site_path" || ! -f "$site_path/wp-config.php" || ! -f "$site_path/wp-includes/version.php" ]]; then
  fail 'The expected Petish WordPress document root is unavailable.'
fi
if ! domain_real="$(cd -- "$domain_path" 2>/dev/null && pwd -P)" ||
   ! site_real="$(cd -- "$site_path" 2>/dev/null && pwd -P)" ||
   ! home_real="$(cd -- "$HOME" 2>/dev/null && pwd -P)"; then
  fail 'Unable to resolve the source or private backup location safely.'
fi
if [[ "$site_real" != "$domain_real/"* || "$home_real" == "$site_real" || "$home_real" == "$site_real/"* ]]; then
  fail 'The website or private backup location resolves outside its expected boundary.'
fi
if ! command -v wp >/dev/null 2>&1; then
  fail 'WP-CLI is unavailable; no backup was created.'
fi
if command -v mariadb-dump >/dev/null 2>&1; then
  dump_client="$(command -v mariadb-dump)"
elif command -v mysqldump >/dev/null 2>&1; then
  dump_client="$(command -v mysqldump)"
else
  fail 'A native MariaDB/MySQL dump client is unavailable; no backup was created.'
fi

if ! private_work="$(mktemp -d "$home_real/.petish-full-backup.XXXXXXXX" 2>/dev/null)"; then
  fail 'Unable to create a private temporary directory outside the website.'
fi
if ! mkdir -m 700 -- "$private_work/database" "$private_work/backup-metadata" 2>/dev/null; then
  fail 'Unable to prepare the private temporary backup directory.'
fi

# Preserve link objects exactly as standard tar does. Do not resolve/dereference
# them: provider links can be external or dangling, and copying their targets
# could read unrelated server files. Keep their inventory inside encryption.
# find's default traversal does not follow symbolic links.
if ! find "$site_real" -type l -print0 \
  > "$private_work/backup-metadata/symlink-paths.nul" 2> "$private_work/find.stderr"; then
  fail 'Unable to inventory the complete website file tree; backup stopped.'
fi
if ! special_entry="$(find "$site_real" ! \( -type f -o -type d -o -type l \) -print -quit 2> "$private_work/find.stderr")"; then
  fail 'Unable to inspect the complete website file tree.'
fi
if [[ -n "$special_entry" ]]; then
  fail 'The website contains unsupported special files; backup stopped rather than silently omitting them.'
fi
unset special_entry

# Targeted config reads keep values in memory, never in logs or command arguments.
for constant in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
  if ! connection_value="$(wp --path="$site_real" --no-color config get "$constant" 2>/dev/null)"; then
    fail 'Unable to read required database configuration; no configuration values were printed.'
  fi
  printf -v "backup_$constant" '%s' "$connection_value"
done
unset connection_value

db_host="$backup_DB_HOST"
db_port=3306
db_socket=''
if [[ -z "$db_host" || "$db_host" =~ [[:cntrl:]] ]]; then
  fail 'Unsupported database connection syntax; backup stopped.'
fi
if [[ "$db_host" == *:/* ]]; then
  db_socket="/${db_host#*:/}"
  db_host="${db_host%%:/*}"
fi
if [[ "$db_host" =~ ^\[([0-9A-Fa-f:.]+)\](:([0-9]+))?$ ]]; then
  db_host="${BASH_REMATCH[1]}"
  db_port="${BASH_REMATCH[3]:-3306}"
elif [[ "$db_host" =~ ^([A-Za-z0-9_.-]+):([0-9]+)$ ]]; then
  db_host="${BASH_REMATCH[1]}"
  db_port="${BASH_REMATCH[2]}"
elif [[ ! "$db_host" =~ ^[A-Za-z0-9_.-]+$ ]]; then
  if [[ ! "$db_host" =~ ^[0-9A-Fa-f:.]+$ || "$db_host" != *:*:* ]]; then
    fail 'Unsupported database host syntax; backup stopped.'
  fi
fi
if [[ ${#db_port} -gt 5 ]] || (( 10#$db_port < 1 || 10#$db_port > 65535 )); then
  fail 'Unsupported database port; backup stopped.'
fi

# Read no client option files: those can silently omit tables/data. The dump's
# transport requirements come from WordPress's explicit MYSQL_CLIENT_FLAGS.
# Unsupported custom flags stop the backup instead of weakening their meaning.
client_flags=0
if flag_value="$(wp --path="$site_real" --no-color config get MYSQL_CLIENT_FLAGS 2>/dev/null)"; then
  client_flags="$flag_value"
fi
unset flag_value
case "$client_flags" in
  0) require_tls=false ;;
  2048|MYSQLI_CLIENT_SSL|MYSQL_CLIENT_SSL) require_tls=true ;;
  *) fail 'Custom database client flags require review before a complete backup can run.' ;;
esac
if ! dump_help="$("$dump_client" --no-defaults --help 2> "$private_work/client.stderr")"; then
  fail 'Unable to inspect supported database dump options.'
fi

dump_options=(
  --no-defaults
  "--host=$db_host"
  "--port=$db_port"
  "--user=$backup_DB_USER"
  --default-character-set=utf8mb4
  --single-transaction
  --quick
  --skip-lock-tables
  --hex-blob
  --triggers
  --routines
  --events
)
if [[ -n "$db_socket" ]]; then
  dump_options+=("--socket=$db_socket")
fi
if [[ "$dump_help" == *'--no-tablespaces'* ]]; then
  dump_options+=(--no-tablespaces)
fi
if [[ "$dump_help" == *'--column-statistics'* ]]; then
  dump_options+=(--column-statistics=0)
fi
if [[ "$require_tls" == true ]]; then
  if [[ "$dump_help" == *'--ssl-mode'* ]]; then
    dump_options+=(--ssl-mode=VERIFY_IDENTITY)
  elif [[ "$dump_help" == *'--ssl-verify-server-cert'* ]]; then
    dump_options+=(--ssl --ssl-verify-server-cert)
  else
    fail 'The dump client cannot enforce the required verified database TLS connection.'
  fi
fi
unset dump_help

# Native Bash launches the dump client directly; PHP proc_open/proc_close are
# neither used nor enabled. MYSQL_PWD exists only for this child process.
if ! MYSQL_PWD="$backup_DB_PASSWORD" "$dump_client" "${dump_options[@]}" -- "$backup_DB_NAME" \
  > "$private_work/database/site.sql" 2> "$private_work/dump.stderr"; then
  fail 'The full database export failed; review database permissions or client compatibility. Private diagnostics were not printed.'
fi
unset backup_DB_HOST backup_DB_NAME backup_DB_USER backup_DB_PASSWORD
if [[ ! -s "$private_work/database/site.sql" ]]; then
  fail 'The database export was empty; backup stopped.'
fi

cat > "$private_work/backup-metadata/README.txt" <<'PETISH_RESTORE_NOTES'
PETISH FULL WORDPRESS BACKUP

PRIVATE DATA: This archive contains wp-config.php, database credentials, the full
site database (potentially users, orders and customer data), licensed theme and
plugin files, and every file under the source document root. It must be encrypted
before leaving the private backup pipeline or being stored in Git. Do not publish
the plaintext archive, SQL, or extracted files as a GitHub artifact or commit.

CONTENTS
- site-files/: complete document-root file tree, including hidden files, uploads,
  WordPress core, themes, plugins, configuration and existing backup/log files.
- database/site.sql: complete schema/data dump of the configured database,
  including triggers, stored routines and events.
- backup-metadata/: provenance and these restoration notes.

RESTORATION
1. Decrypt and inspect this archive only in a private, trusted environment.
2. Create a separate staging installation/database first. Preserve a backup of
   any destination before restoring; restoration can overwrite existing data.
3. Extract site-files/ into the intended document root. Restore under the target
   hosting account, preserving permissions but mapping owner/group IDs as needed.
4. Import database/site.sql into an empty target database using a compatible
   MySQL/MariaDB client. Review DEFINER clauses, routine/event permissions, SQL
   modes and server-version compatibility rather than silently dropping objects.
5. Update wp-config.php for the target database securely. Never commit its values.
6. Check internal symlinks. Absolute symlink targets retain their original source
   paths and may need deliberate remapping on another hosting account.
7. If the domain/path changes, use a WordPress-aware serialized-data replacement
   procedure in staging. Check media, permalinks, integrations and payment modes
   before switching traffic or accepting real payments.

CONSISTENCY AND SCOPE
This is a live, non-atomic backup: the SQL export and file traversal occur at
different times. --single-transaction provides a consistent InnoDB data snapshot,
but nontransactional tables and concurrent schema/file changes need an appropriate
maintenance window for strict consistency. No maintenance mode or write lock was
enabled. Any reported dump/tar failure invalidates the backup.

Only the configured WordPress database and document-root tree are included.
Server-global users/grants, DNS, email, account-level cron/firewall configuration,
remote object storage/CDNs, external databases and files outside the document root
are not captured. All symbolic links are preserved as links, including external
and dangling links; their target data outside the document root is not copied.
backup-metadata/symlink-paths.nul lists source link paths as NUL-delimited records;
the tar entries preserve each link's literal target. Review those dependencies
before extraction/restoration. If an external target provides essential site
content, back it up separately before treating this as a self-contained restore.
Unsupported special files cause failure. Independently back up remote services.

Ordinary file permissions, timestamps, ownership and internal links are retained.
ACLs and extended attributes are included when supported by the archive tool.
The private temporary SQL/log files are outside the website and are removed when
the backup command exits. A hard kill/server crash may require administrator
cleanup of a remaining private .petish-full-backup.* directory in the SSH home.
PETISH_RESTORE_NOTES

{
  printf 'source_site=petish.biz\n'
  printf 'generated_utc=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  printf 'source_document_root=%s\n' "$site_real"
  printf 'database_export=full-schema-data-triggers-routines-events\n'
  printf 'database_export_exit=0\n'
  printf 'database_export_bytes=%s\n' "$(wc -c < "$private_work/database/site.sql")"
  printf 'consistency=live-files-and-database-not-atomic\n'
} > "$private_work/backup-metadata/metadata.txt"

if ! tar_help="$(tar --help 2> "$private_work/tar.stderr")"; then
  fail 'Unable to inspect archive-tool capabilities.'
fi
tar_metadata=()
if [[ "$tar_help" == *'--acls'* ]]; then
  tar_metadata+=(--acls)
fi
if [[ "$tar_help" == *'--xattrs'* ]]; then
  tar_metadata+=(--xattrs '--xattrs-include=*')
fi
unset tar_help

# Transform member/hardlink names, not symlink target text. The two private
# directories do not start with './', so they retain their intended prefixes.
# Never create a plaintext website tar on the remote disk.
if ! tar --create --gzip --file=- --format=posix "${tar_metadata[@]}" \
  '--transform=flags=rh;s|^\.$|site-files|;s|^\./|site-files/|' \
  --directory="$site_real" . \
  --directory="$private_work" database backup-metadata \
  2> "$private_work/tar.stderr"; then
  fail 'The complete file archive failed or files changed during export; discard all output and retry during a stable period.'
fi
