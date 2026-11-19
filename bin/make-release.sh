#!/usr/bin/env bash
# Build tldr-block.zip: plugin code plus llm-json-php under lib/, so the
# plugin runs without Composer. Usage: bin/make-release.sh [output-dir]
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/build}"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$out" "$stage/tldr-block"
git -C "$root" archive --format=tar HEAD | tar -x -C "$stage/tldr-block"

(cd "$stage/tldr-block" && composer install --no-dev --no-interaction --no-progress --prefer-dist --quiet)
mkdir -p "$stage/tldr-block/lib"
cp -R "$stage/tldr-block/vendor/indianopinion/llm-json-php" "$stage/tldr-block/lib/llm-json-php"
rm -rf "$stage/tldr-block/vendor" "$stage/tldr-block/composer.lock"

(cd "$stage" && zip -qr "$out/tldr-block.zip" tldr-block)
echo "Wrote $out/tldr-block.zip"
