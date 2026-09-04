#!/usr/bin/env bash
set -euo pipefail
# Isolated Roave BC check against v1.10.0 (PHP 8.5, pinned Roave 8.21.0).
# Not added to root require-dev: one release cannot satisfy both PHP 8.2 and 8.5 platforms.
BASE_REF="${1:-v1.10.0}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
composer require --working-dir="$TMP_DIR" --no-interaction --no-progress roave/backward-compatibility-check:8.21.0 > /dev/null
git worktree list | grep -q "$BASE_REF" || git fetch --tags origin "$BASE_REF:$BASE_REF" 2>/dev/null || true
"$TMP_DIR/vendor/bin/roave-backward-compatibility-check" --from="$BASE_REF" --to=HEAD
