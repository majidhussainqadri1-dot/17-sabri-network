#!/usr/bin/env bash
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="${1:-$repo_root/source-packages/17-sabri-network-1.1.0.zip}"
mkdir -p "$(dirname "$out")"
cat "$repo_root"/source-packages/base64/part-*.b64 | tr -d '\n\r' | base64 --decode > "$out"
echo 'ebddffd4b5b157d50b58680767a6525fec84477fd91afeb58d6f257c77571400  '"$out" | sha256sum --check -
unzip -t "$out"
echo "Reconstructed and verified: $out"
