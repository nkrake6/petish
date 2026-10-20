#!/usr/bin/env bash
# Private, reproducible PHP CLI for the Debian 13 amd64 cloud workspace.
# No apt install, root privileges, system files, or WordPress execution.
#
# Package SHA256 pins below were authenticated on 2026-10-20 through Debian's
# signed trixie/InRelease -> SHA256 main/binary-amd64/Packages.xz -> package
# SHA256 records, using the image's /usr/share/keyrings/debian-archive-keyring.gpg.
# Verified Debian 13 signer fingerprints:
#   04B54C3CDCA79751B16BC6B5225629DF75B188BD (archive automatic key)
#   41587F7DB8C774BCCF131416762F67A0B2C39DE4 (stable release key)
# To update pins, verify InRelease with that trusted keyring first, verify the
# Packages.xz SHA256 from InRelease, then obtain package hashes from Packages.
# Never replace these pins using an unsigned directory listing/checksum page.
#
# Usage: bash scripts/setup-cloud-php.sh
# Then add /workspace/.petish-dev/bin to PATH for tests that require PHP.
set -Eeuo pipefail
umask 077

fail() { printf '%s\n' "$1" >&2; exit 1; }
[[ $# == 0 ]] || fail CLOUD_PHP_ARGUMENTS_REJECTED
[[ $(uname -m) == x86_64 ]] || fail CLOUD_PHP_AMD64_REQUIRED
for dependency in curl sha256sum dpkg-deb mktemp install find sort cmp stat; do
    command -v "$dependency" >/dev/null 2>&1 || fail CLOUD_PHP_DEPENDENCY_MISSING
done

dev_root=/workspace/.petish-dev
runtime=$dev_root/php
bin_dir=$dev_root/bin
wrapper=$bin_dir/php
[[ -d /workspace && ! -L /workspace ]] || fail CLOUD_PHP_WORKSPACE_REJECTED
[[ ! -L $dev_root ]] || fail CLOUD_PHP_DESTINATION_REJECTED
if [[ -e $dev_root ]]; then
    [[ -d $dev_root ]] || fail CLOUD_PHP_DESTINATION_REJECTED
else
    mkdir -m 700 "$dev_root" || fail CLOUD_PHP_DIRECTORY_FAILED
fi
chmod 700 "$dev_root" || fail CLOUD_PHP_DIRECTORY_FAILED
[[ ! -L $bin_dir && ! -L $runtime && ! -L $wrapper ]] || fail CLOUD_PHP_DESTINATION_REJECTED
if [[ -e $bin_dir ]]; then
    [[ -d $bin_dir ]] || fail CLOUD_PHP_DESTINATION_REJECTED
else
    mkdir -m 700 "$bin_dir" || fail CLOUD_PHP_DIRECTORY_FAILED
fi
chmod 700 "$bin_dir" || fail CLOUD_PHP_DIRECTORY_FAILED
lock=$dev_root/.php-setup.lock
mkdir -m 700 "$lock" 2>/dev/null || fail CLOUD_PHP_SETUP_LOCKED
work=
finish() {
    status=$?
    trap - EXIT HUP INT TERM
    if [[ -n $work ]]; then rm -rf -- "$work"; fi
    rmdir "$lock" 2>/dev/null || true
    exit "$status"
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
work=$(mktemp -d "$dev_root/.php-setup.XXXXXXXX") || fail CLOUD_PHP_DIRECTORY_FAILED

cat > "$work/php-wrapper" <<'WRAPPER'
#!/usr/bin/env sh
# Managed by scripts/setup-cloud-php.sh; private verified runtime only.
LD_LIBRARY_PATH=/workspace/.petish-dev/php/usr/lib/x86_64-linux-gnu exec /workspace/.petish-dev/php/usr/bin/php8.4 -n -d extension=/workspace/.petish-dev/php/usr/lib/php/20240924/tokenizer.so "$@"
WRAPPER
chmod 700 "$work/php-wrapper"

verify_runtime() {
    local candidate=$1
    [[ -d $candidate && ! -L $candidate ]] || return 1
    # Only these three authenticated runtime files and the ownership marker
    # are installed. Package tools, configurations, docs and links are omitted.
    [[ -z $(find "$candidate" ! -type d ! -type f -print -quit) ]] || return 1
    [[ -z $(find "$candidate" -type f -links +1 -print -quit) ]] || return 1
    [[ $(find "$candidate" -type f -printf '%P\n' | sort) == $'.petish-cloud-php-v1\nusr/bin/php8.4\nusr/lib/php/20240924/tokenizer.so\nusr/lib/x86_64-linux-gnu/libargon2.so.1' ]] || return 1
    [[ $(cat "$candidate/.petish-cloud-php-v1") == petish-cloud-php-8.4.24-debian13-amd64-v1 ]] || return 1
    (
        cd "$candidate"
        sha256sum --check --status <<'RUNTIME_HASHES'
d19d3ae21e55362a2a116a60209f4e84c1cd23dd96bca09b4e6b17aed6156fb2  usr/bin/php8.4
1e3c3671d064b327165dca80223ac63d01e96cb99a3a338922251402a2d1b3c5  usr/lib/php/20240924/tokenizer.so
94b5c0be9d7f09f1b4550ff15422d2ac8a6392c3471c88b93ee0370698388097  usr/lib/x86_64-linux-gnu/libargon2.so.1
RUNTIME_HASHES
    )
}

if [[ -e $runtime ]]; then
    verify_runtime "$runtime" || fail CLOUD_PHP_EXISTING_RUNTIME_REJECTED
    if [[ -e $wrapper ]]; then
        [[ -f $wrapper && $(stat -c %h "$wrapper") == 1 ]] && cmp -s "$wrapper" "$work/php-wrapper" || fail CLOUD_PHP_EXISTING_WRAPPER_REJECTED
    else
        mv -T -- "$work/php-wrapper" "$wrapper" || fail CLOUD_PHP_WRAPPER_FAILED
    fi
    chmod 700 "$wrapper"
else
    [[ ! -e $wrapper ]] || fail CLOUD_PHP_EXISTING_WRAPPER_REJECTED
    mkdir -m 700 "$work/packages" "$work/extracted" "$work/runtime"
    package_paths=(
        'pool/main/p/php8.4/php8.4-cli_8.4.24-1~deb13u1_amd64.deb'
        'pool/main/p/php8.4/php8.4-common_8.4.24-1~deb13u1_amd64.deb'
        'pool/main/a/argon2/libargon2-1_0~20190702+dfsg-4+b2_amd64.deb'
    )
    for index in "${!package_paths[@]}"; do
        curl --proto '=https' --proto-redir '=https' --tlsv1.2 --fail --silent --show-error --location \
            --connect-timeout 20 --max-time 180 \
            "https://deb.debian.org/debian/${package_paths[index]}" \
            --output "$work/packages/$index.deb" || fail CLOUD_PHP_DOWNLOAD_FAILED
    done
    # Authenticate ALL downloads before extracting any package or running PHP.
    (
        cd "$work/packages"
        sha256sum --check --status <<'PACKAGE_HASHES'
8205f927545c28a03fdcc1bd1fc83c1aa9615d2ee1f7cec4cd5c86e59716782f  0.deb
fe81bee8ec155ed0ba14a2d1330f52f5ad3da2e7446ab74858f61c9e36202052  1.deb
a54a6640be69c29c1e43b14ee464484a6f20e33fe73200c02949cfeb03228547  2.deb
PACKAGE_HASHES
    ) || fail CLOUD_PHP_PACKAGE_HASH_REJECTED
    for index in "${!package_paths[@]}"; do
        dpkg-deb --extract "$work/packages/$index.deb" "$work/extracted" || fail CLOUD_PHP_EXTRACTION_FAILED
    done
    mkdir -p -m 700 "$work/runtime/usr/bin" "$work/runtime/usr/lib/php/20240924" "$work/runtime/usr/lib/x86_64-linux-gnu"
    install -m 700 "$work/extracted/usr/bin/php8.4" "$work/runtime/usr/bin/php8.4"
    install -m 600 "$work/extracted/usr/lib/php/20240924/tokenizer.so" "$work/runtime/usr/lib/php/20240924/tokenizer.so"
    install -m 600 "$work/extracted/usr/lib/x86_64-linux-gnu/libargon2.so.1" "$work/runtime/usr/lib/x86_64-linux-gnu/libargon2.so.1"
    printf '%s\n' petish-cloud-php-8.4.24-debian13-amd64-v1 > "$work/runtime/.petish-cloud-php-v1"
    verify_runtime "$work/runtime" || fail CLOUD_PHP_RUNTIME_HASH_REJECTED
    mv -T -- "$work/runtime" "$runtime" || fail CLOUD_PHP_RUNTIME_INSTALL_FAILED
    mv -T -- "$work/php-wrapper" "$wrapper" || fail CLOUD_PHP_WRAPPER_FAILED
fi

# This runs only authenticated bytes, with PHP ini loading disabled. It does
# not load WordPress, any site configuration, or customer/supplier information.
"$wrapper" -r 'exit(PHP_VERSION === "8.4.24" && function_exists("gzopen") && function_exists("token_get_all") ? 0 : 1);' \
    >/dev/null 2>&1 || fail CLOUD_PHP_RUNTIME_UNAVAILABLE
printf '%s\n' CLOUD_PHP_READY
