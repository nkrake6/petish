#!/usr/bin/env bash
# Staging browser processes only; never inherit the host SSH/GitHub environment.
set +x
set -euo pipefail
umask 077
[[ ${HOMEPAGE_MODE:-} == staging ]]
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
[[ $(pwd -P) == "$root" && -d "$root/.git" && ! -L "$root/.git" ]]
repository=mcr.microsoft.com/playwright
image_pattern='^mcr\.microsoft\.com/playwright@sha256:[a-f0-9]{64}$'

browser_container() {
    local image=$1 work=$2 script=$3 network=$4
    shift 4
    [[ $image =~ $image_pattern && -d $work && ! -L $work && $(realpath -- "$work") == "$work" ]]
    [[ $root != *','* && $work != *','* && $root != *$'\n'* && $work != *$'\n'* ]]
    [[ $(stat -c %a -- "$work") == 700 && $(stat -c %u -- "$work") == "$(id -u)" ]]
    local mask=$work/browser-git-mask
    if [[ ! -e $mask && ! -L $mask ]]; then mkdir -m 700 -- "$mask"; fi
    [[ -d $mask && ! -L $mask && $(realpath -- "$mask") == "$mask" && -z $(ls -A -- "$mask") ]]
    local -a network_options=()
    [[ $network != none ]] || network_options=(--network none)
    # /tmp holds browser profiles/caches. Shared memory is private to this
    # container. The same smoke check exercises WebKit with these exact flags.
    docker run --rm --init --pull never --user "$(id -u):$(id -g)" --read-only \
        --cap-drop ALL --security-opt no-new-privileges --shm-size 1g \
        --tmpfs /tmp:rw,nosuid,nodev,mode=1777,size=1g \
        --mount "type=bind,source=$root,target=$root,readonly" \
        --mount "type=bind,source=$work,target=$work" \
        --mount "type=bind,source=$mask,target=$root/.git,readonly" \
        --workdir "$root" "${network_options[@]}" \
        --env PLAYWRIGHT_BROWSERS_PATH=/ms-playwright --env HOME=/tmp --env TMPDIR=/tmp \
        "$image" node "$script" "$@"
}

case ${1:-} in
    prepare)
        [[ $# == 1 ]]
        version=$(node -p "require('./tools/staging-rehearsal/package-lock.json').packages['node_modules/playwright'].version")
        [[ $version == 1.62.1 ]]
        tag=$repository:v$version-noble
        timeout --kill-after=5s 240s docker pull "$tag" >&2
        digests=$(docker image inspect --format '{{json .RepoDigests}}' "$tag")
        image=$(python3 - "$digests" <<'PY'
import json,re,sys
values=json.loads(sys.argv[1])
if not isinstance(values,list) or not all(isinstance(value,str) for value in values):raise SystemExit(1)
exact={value for value in values if re.fullmatch(r'mcr\.microsoft\.com/playwright@sha256:[a-f0-9]{64}',value)}
if len(exact)!=1:raise SystemExit(1)
print(exact.pop())
PY
)
        [[ $image =~ $image_pattern ]]
        : "${RUNNER_TEMP:?}"
        work=$(mktemp -d "$RUNNER_TEMP/petish-homepage-container.XXXXXXXX")
        trap 'rm -rf -- "$work"' EXIT
        # Validate executable browser revisions, rendering and WebKit's sandbox
        # compatibility before any remote fixture is created. No networking.
        timeout --kill-after=5s 90s bash "$0" smoke "$image" "$work" >&2
        printf '%s\n' "$image"
        ;;
    smoke)
        [[ $# == 3 ]]
        browser_container "$2" "$3" tools/homepage/container-smoke.mjs none
        ;;
    run)
        [[ $# == 5 && ${PETISH_HOMEPAGE_BROWSER_IMAGE:-} =~ $image_pattern ]]
        work=$2 script=$3 input=$4 output=$5
        [[ $script == tools/homepage/browser.mjs || $script == tools/homepage/cart-badge-browser.mjs ]]
        [[ $(dirname -- "$input") == "$work" && $(dirname -- "$output") == "$work" ]]
        [[ -f $input && ! -L $input && ! -e $output && ! -L $output ]]
        browser_container "$PETISH_HOMEPAGE_BROWSER_IMAGE" "$work" "$script" staging "$input" "$output"
        ;;
    *) exit 1 ;;
esac
