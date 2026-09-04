#!/usr/bin/env bash
set -euo pipefail
# Isolated Roave BC check against v1.10.0 (PHP 8.5, pinned Roave 8.21.0).
# Not added to root require-dev: one release cannot satisfy both PHP 8.2 and 8.5 platforms.
# BetterReflection cannot compile PHP's `new` parameter default. Normalize that
# initializer identically in disposable snapshots so every other API is checked.
BASE_REF="${1:-v1.10.0}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
composer require --working-dir="$TMP_DIR" --no-interaction --no-progress roave/backward-compatibility-check:8.21.0 > /dev/null
git worktree list | grep -q "$BASE_REF" || git fetch --tags origin "$BASE_REF:$BASE_REF" 2>/dev/null || true

BASE_COMMIT="$(git rev-parse "${BASE_REF}^{commit}")"
TARGET_COMMIT="$(git rev-parse HEAD)"
COMPARE_REPO="$TMP_DIR/repository"
git clone --quiet --no-hardlinks . "$COMPARE_REPO"

normalize_snapshot() {
    local source_ref="$1"
    git -C "$COMPARE_REPO" checkout --quiet --detach "$source_ref"
    php -r '
        $path = $argv[1];
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new RuntimeException("Unable to read JobRegistry snapshot");
        }
        $normalized = str_replace(
            "public readonly JobMiddlewareRegistry \$middleware = new JobMiddlewareRegistry()",
            "public readonly ?JobMiddlewareRegistry \$middleware = null",
            $source,
            $replacements
        );
        if ($replacements !== 1 || file_put_contents($path, $normalized) === false) {
            throw new RuntimeException("Unable to normalize JobRegistry snapshot");
        }
    ' "$COMPARE_REPO/src/JobRegistry.php"
    git -C "$COMPARE_REPO" add src/JobRegistry.php
    git -C "$COMPARE_REPO" \
        -c user.name='SimpleQueue CI' \
        -c user.email='ci@localhost' \
        commit --quiet --no-gpg-sign -m 'Normalize unsupported reflected initializer'
    git -C "$COMPARE_REPO" rev-parse HEAD
}

NORMALIZED_BASE="$(normalize_snapshot "$BASE_COMMIT")"
NORMALIZED_TARGET="$(normalize_snapshot "$TARGET_COMMIT")"
git -C "$COMPARE_REPO" checkout --quiet --detach "$NORMALIZED_TARGET"
(
    cd "$COMPARE_REPO"
    "$TMP_DIR/vendor/bin/roave-backward-compatibility-check" \
        --from="$NORMALIZED_BASE" \
        --to="$NORMALIZED_TARGET"
)
