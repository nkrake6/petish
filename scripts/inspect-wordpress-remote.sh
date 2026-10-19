#!/usr/bin/env bash
set -euo pipefail
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1

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
find "$site_path/wp-content/plugins" -mindepth 1 -maxdepth 2 -type f -name '*.php' -print0 |
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
# db query runs without bootstrapping WordPress. Only this fixed SELECT is allowed.
wp --path="$site_path" --no-color db query \
  "SELECT option_name, option_value FROM \`${prefix}options\` WHERE option_name IN ('template', 'stylesheet', 'active_plugins', 'show_on_front', 'page_on_front') ORDER BY option_name" \
  --batch

printf '\nInspection completed. No content, files, users, settings, themes, or plugins were changed by this script.\n'
