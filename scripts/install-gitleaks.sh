#!/usr/bin/env bash
set -euo pipefail
: "${RUNNER_TEMP:?Missing RUNNER_TEMP}"
tool_dir="$RUNNER_TEMP/petish-gitleaks"
mkdir -p "$tool_dir"
curl --fail --location --silent --show-error --retry 2 \
  https://github.com/gitleaks/gitleaks/releases/download/v8.24.3/gitleaks_8.24.3_linux_x64.tar.gz \
  --output "$tool_dir/gitleaks.tar.gz"
# Release archive SHA256 checked against the publisher's v8.24.3 checksums.
printf '%s  %s\n' '9991e0b2903da4c8f6122b5c3186448b927a5da4deef1fe45271c3793f4ee29c' \
  "$tool_dir/gitleaks.tar.gz" | sha256sum --check --status
tar -xzf "$tool_dir/gitleaks.tar.gz" -C "$tool_dir" gitleaks
chmod 700 "$tool_dir/gitleaks"
