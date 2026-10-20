#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
: "${SOURCE_SHA:?Missing SOURCE_SHA}"
: "${DEPLOY_MODE:?Missing DEPLOY_MODE}"
[[ "$SOURCE_SHA" =~ ^[a-f0-9]{40}$ ]]
[[ "$DEPLOY_MODE" =~ ^(check|apply)$ ]]
source scripts/hostinger-ssh-common.sh
petish_prepare_ssh
remote_work=''
cleanup() {
  if [[ "$remote_work" =~ ^/home/u839205597/\.petish-staging-deploy\.[A-Za-z0-9]+$ ]]; then
    "${petish_ssh[@]}" "rm -rf -- '$remote_work'" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
remote_work="$("${petish_ssh[@]}" 'umask 077; mktemp -d "$HOME/.petish-staging-deploy.XXXXXXXX"')"
if [[ ! "$remote_work" =~ ^/home/u839205597/\.petish-staging-deploy\.[A-Za-z0-9]+$ ]]; then
  printf '%s\n' 'A private deployment directory could not be verified.' >&2
  exit 1
fi
"${petish_ssh[@]}" "cat > '$remote_work/deploy.sh'" < scripts/deploy-staging-remote.sh
"${petish_ssh[@]}" "cat > '$remote_work/source.tar.gz'" < "$RUNNER_TEMP/petish-staging-source.tar.gz"
"${petish_ssh[@]}" "bash '$remote_work/deploy.sh' '$remote_work/source.tar.gz' '$DEPLOY_MODE' '$SOURCE_SHA'"
