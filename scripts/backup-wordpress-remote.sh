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
passport_path="$domain_path/private/petish-passport"
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
# Passport photos intentionally live outside public_html. Require their exact
# private root rather than silently producing a document-root-only backup.
if [[ -L "$passport_path" ]]; then
  fail 'The required private Passport directory is a symbolic link; backup stopped.'
elif [[ ! -d "$passport_path" ]]; then
  fail 'The required private Passport directory is missing; backup stopped.'
elif [[ ! -r "$passport_path" || ! -x "$passport_path" ]]; then
  fail 'The required private Passport directory is unreadable; backup stopped.'
fi
if ! passport_real="$(cd -- "$passport_path" 2>/dev/null && pwd -P)" ||
   ! private_parent_real="$(cd -- "$domain_path/private" 2>/dev/null && pwd -P)"; then
  fail 'Unable to resolve the private Passport directory safely; backup stopped.'
fi
if [[ "$passport_real" != "$domain_real/private/petish-passport" ||
      "$passport_real" == "$site_real" || "$passport_real" == "$site_real/"* ||
      "$site_real" == "$passport_real/"* ]]; then
  fail 'The private Passport directory resolves outside its expected private boundary; backup stopped.'
fi
if ! command -v wp >/dev/null 2>&1; then
  fail 'WP-CLI is unavailable; no backup was created.'
fi
if ! command -v gzip >/dev/null 2>&1; then
  fail 'Archive diagnostic: compressor_missing. The hosting shell cannot find gzip; no backup was created.'
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
if ! find "$site_real" "$passport_real" -type l -print0 \
  > "$private_work/backup-metadata/symlink-paths.nul" 2> "$private_work/find.stderr"; then
  fail 'Unable to inventory the complete website file tree; backup stopped.'
fi
if ! special_entry="$(find "$site_real" "$passport_real" ! \( -type f -o -type d -o -type l \) -print -quit 2> "$private_work/find.stderr")"; then
  fail 'Unable to inspect the complete website file tree.'
fi
if [[ -n "$special_entry" ]]; then
  fail 'The website contains unsupported special files; backup stopped rather than silently omitting them.'
fi
unset special_entry
if ! find "$passport_real" -type f -print0 2> "$private_work/find.stderr" |
  while IFS= read -r -d '' private_file; do
    [[ -r "$private_file" ]] || exit 1
  done; then
  fail 'The private Passport file tree could not be fully read; backup stopped.'
fi

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
plugin files, every file under the source document root, and private Passport
photos outside the webroot. It must be encrypted
before leaving the private backup pipeline or being stored in Git. Do not publish
the plaintext archive, SQL, or extracted files as a GitHub artifact or commit.

CONTENTS
- site-files/: complete document-root file tree, including hidden files, uploads,
  WordPress core, themes, plugins, configuration and existing backup/log files.
- database/site.sql: complete schema/data dump of the configured database,
  including triggers, stored routines and events.
- private-files/petish-passport/: complete private Passport tree from the sibling
  domain private/petish-passport directory, never followed through symbolic links.
- backup-metadata/: provenance and these restoration notes.

RESTORATION
1. Decrypt and inspect this archive only in a private, trusted environment.
2. Create a separate staging installation/database first. Preserve a backup of
   any destination before restoring; restoration can overwrite existing data.
3. Extract site-files/ into the intended document root. Restore under the target
   hosting account, preserving permissions but mapping owner/group IDs as needed.
   Restore private-files/petish-passport/ to the destination domain's sibling
   private/petish-passport/ directory OUTSIDE its public document root. Never copy
   this directory under public_html, uploads, or another publicly served path.
   Restrict access to the hosting account and preserve Passport's private-storage
   permissions. Review the plugin's storage path on a different account/domain.
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

The configured WordPress database, document-root tree, and exact sibling private
Passport tree are included. The latter is required: missing/unreadable/aliased
private storage invalidates the backup instead of being silently omitted.
Server-global users/grants, DNS, email, account-level cron/firewall configuration,
remote object storage/CDNs, external databases and other files outside these two
source trees are not captured. All symbolic links are preserved as links,
including external and dangling links; their target data is not dereferenced or
copied from outside the explicitly included trees.
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
  printf 'source_private_root=%s\n' "$passport_real"
  printf 'private_archive_root=private-files/petish-passport\n'
  printf 'private_restore_location=domain-private-directory-outside-document-root\n'
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

report_archive_error() {
  # Only fixed labels and a numeric status may leave the private diagnostic file.
  # Never print matching lines: filenames/paths can contain private information.
  awk -v status="$1" '
    {
      line = tolower($0)
      if (line ~ /file changed as we read it|file shrank by/) changed = 1
      if (line ~ /file removed before we read it|cannot stat.*no such file/) removed = 1
      if (line ~ /permission denied|operation not permitted|cannot open.*access denied/) permission = 1
      if (line ~ /getxattr|setxattr|acl_get|acl_set|xattr support|acl support|extended attribute/) metadata = 1
      if (line ~ /cannot exec|gzip.*not found|gzip.*no such file|child returned status/) compressor = 1
      if (line ~ /unrecognized option|unknown option|invalid option|invalid transform|not supported/) compatibility = 1
      if (line ~ /broken pipe|write error|cannot write|wrote only/) stream = 1
      if (line ~ /cannot chdir|could not chdir/) directory = 1
      if (line ~ /no space left on device|disk quota exceeded/) storage = 1
    }
    END {
      printf "Archive diagnostic: tar_exit=%d\n", status
      if (changed) print "Archive diagnostic: live_file_changed"
      if (removed) print "Archive diagnostic: source_file_removed"
      if (permission) print "Archive diagnostic: source_permission_denied"
      if (metadata) print "Archive diagnostic: acl_or_extended_attributes"
      if (compressor) print "Archive diagnostic: compressor_or_child_process"
      if (compatibility) print "Archive diagnostic: unsupported_archive_option"
      if (stream) print "Archive diagnostic: archive_stream_write_error"
      if (directory) print "Archive diagnostic: archive_directory_unavailable"
      if (storage) print "Archive diagnostic: storage_or_quota_limit"
      if (!(changed || removed || permission || metadata || compressor || compatibility || stream || directory || storage))
        print "Archive diagnostic: unclassified_archive_error"
    }
  ' "$2" >&2
}

# Test the archive options on private metadata before reading website files.
# This distinguishes a tool/jail incompatibility from live-file/read failures.
preflight_status=0
tar --create --gzip --file=/dev/null --format=posix "${tar_metadata[@]}" \
  --directory="$private_work" backup-metadata \
  2> "$private_work/tar-preflight.stderr" || preflight_status=$?
if (( preflight_status != 0 )); then
  report_archive_error "$preflight_status" "$private_work/tar-preflight.stderr"
  fail 'Archive diagnostic: private_metadata_preflight_failed. No complete backup was produced.'
fi

# Transform member/hardlink names, not symlink target text. The two private
# directories do not start with './', so they retain their intended prefixes.
# The private sibling tree receives a separate archive prefix without being
# copied into the document root or followed through symbolic links.
# Never create a plaintext website tar on the remote disk.
tar_status=0
tar --create --gzip --file=- --format=posix "${tar_metadata[@]}" \
  '--transform=flags=rh;s|^\.$|site-files|;s|^\./|site-files/|;s|^petish-passport$|private-files/petish-passport|;s|^petish-passport/|private-files/petish-passport/|' \
  --directory="$site_real" . \
  --directory="$private_parent_real" petish-passport \
  --directory="$private_work" database backup-metadata \
  2> "$private_work/tar.stderr" || tar_status=$?
if (( tar_status != 0 )); then
  report_archive_error "$tar_status" "$private_work/tar.stderr"
  fail 'The complete file archive failed. Only error categories were printed; nothing will be published.'
fi
