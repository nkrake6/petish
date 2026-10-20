#!/usr/bin/env bash
# Stdout is a gzip tar stream. Never bootstrap WordPress or execute site code.
set +x
set -euo pipefail
export LC_ALL=C
umask 077

fail() {
  printf '%s\n' "Source export stopped: $1. No private diagnostics were printed." >&2
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
if ! site_real="$(cd -- "$site_path" 2>/dev/null && pwd -P)" ||
   ! domain_real="$(cd -- "$domain_path" 2>/dev/null && pwd -P)"; then
  fail source_root_unavailable
fi
[[ "$site_real" == "$domain_real/"* ]] || fail source_boundary_invalid
for parent in wp-content wp-content/themes wp-content/plugins wp-content/mu-plugins; do
  [[ ! -L "$site_real/$parent" ]] || fail source_parent_is_link
done
for command_name in find tar gzip date; do
  command -v "$command_name" >/dev/null 2>&1 || fail required_archive_tool_missing
done

if ! private_work="$(mktemp -d "$HOME/.petish-source-export.XXXXXXXX" 2>/dev/null)"; then
  fail private_workspace_unavailable
fi
file_list="$private_work/paths.nul"
: > "$file_list"
declare -a source_roots=(
  wp-content/themes/dt-the7-child
  wp-content/plugins/petish-passport
  wp-content/plugins/petish-fulfilment
)
missing_roots=''
for relative_root in "${source_roots[@]}"; do
  if [[ -L "$site_real/$relative_root" ]]; then
    fail required_source_is_link
  elif [[ ! -d "$site_real/$relative_root" ]]; then
    [[ -z "$missing_roots" ]] || missing_roots+=','
    missing_roots+="\"$relative_root\""
  fi
done

excluded_metadata=0
excluded_private=0
excluded_links=0
excluded_special=0
excluded_staging=0

# This filter is duplicated defensively in the runner scanner. In particular,
# log/config/backup payloads must not become editable source merely because
# somebody happened to put them in a custom plugin directory.
private_basename() {
  local name="${1,,}"
  case "$name" in
    private|secrets|credentials|backups|backup|logs|log|uploads|cache|tmp|temp|output|reports|captures|fixtures) return 0 ;;
    *.log|*.sql|*.sqlite|*.sqlite3|*.db|*.zip|*.tar|*.gz|*.tgz|*.bz2|*.xz|*.7z|*.rar|*.bak|*.backup|*.old|*.orig|*.save|*~|*.swp) return 0 ;;
    credentials.*|secrets.*|passwords.*|captured-mail.*|checkout-fixture.*|users.json|orders.json|customers.json|contacts.json|sessions.json) return 0 ;;
  esac
  return 1
}

append_root() {
  local root="$1" absolute relative basename
  [[ -e "$site_real/$root" || -L "$site_real/$root" ]] || return 0
  # -P never follows links. Private directories are pruned before traversal.
  if ! find -P "$site_real/$root" \
    \( -name '.*' -o -name '__MACOSX' -o -name 'petish-staging-guard*' -o -iname private -o -iname secrets \
       -o -iname credentials -o -iname backups -o -iname backup -o -iname logs \
       -o -iname log -o -iname uploads -o -iname cache -o -iname tmp -o -iname temp \
       -o -iname output -o -iname reports -o -iname captures -o -iname fixtures \) \
    -prune -print0 -o -print0 > "$private_work/found.nul" 2> "$private_work/find.stderr"; then
    fail source_inventory_failed
  fi
  while IFS= read -r -d '' absolute; do
    relative="${absolute#"$site_real/"}"
    [[ "$relative" != "$absolute" && "$relative" != /* && "$relative" != *$'\n'* && "$relative" != *$'\r'* ]] || fail source_path_invalid
    basename="${relative##*/}"
    if [[ "$basename" == petish-staging-guard* ]]; then
      ((excluded_staging+=1))
    elif [[ "$basename" == .* || "$basename" == __MACOSX ]]; then
      ((excluded_metadata+=1))
    elif private_basename "$basename"; then
      ((excluded_private+=1))
    elif [[ -L "$absolute" ]]; then
      ((excluded_links+=1))
    elif [[ -f "$absolute" || -d "$absolute" ]]; then
      printf '%s\0' "$relative" >> "$file_list"
    else
      ((excluded_special+=1))
    fi
  done < "$private_work/found.nul"
}

for relative_root in "${source_roots[@]}"; do
  append_root "$relative_root"
done
if [[ -d "$site_real/wp-content/mu-plugins" ]]; then
  for mu_path in "$site_real/wp-content/mu-plugins"/petish-*; do
    [[ -e "$mu_path" || -L "$mu_path" ]] || continue
    if [[ -L "$mu_path" || -d "$mu_path" || "$mu_path" == *.php ]]; then
      append_root "${mu_path#"$site_real/"}"
    fi
  done
fi

generated_utc="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
cat > "$private_work/source-export-metadata.json" <<EOF
{"schema_version":1,"source_site":"https://petish.biz","generated_utc":"$generated_utc","missing_roots":[$missing_roots],"excluded_counts":{"metadata":$excluded_metadata,"private_payload":$excluded_private,"links":$excluded_links,"special":$excluded_special,"staging_guard":$excluded_staging}}
EOF

# --no-recursion is essential: the NUL list already contains each permitted
# entry. Tar must not independently recurse into a pruned private directory.
# The archive contains link-free paths; do not add --dereference/-h here.
if ! tar --create --gzip --file=- --format=posix --numeric-owner --owner=0 --group=0 \
  --no-recursion --null --verbatim-files-from \
  -C "$private_work" source-export-metadata.json \
  -C "$site_real" --files-from="$file_list" 2> "$private_work/tar.stderr"; then
  fail source_archive_failed_or_changed
fi
