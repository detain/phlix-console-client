#!/usr/bin/env bash
#
# check-i18n-hardcoded.sh
#
# CI check that fails if a hardcoded user-facing string is added to one of
# the three converted i18n files: RecommendationsScreen.php, DetailScreen.php,
# or FilterBar.php.
#
# The check itself is token-based (see scripts/check-i18n-hardcoded.php): it
# inspects real PHP string literals via token_get_all() instead of regex over
# raw text, so it cannot mistake the code between two quotes for a string.
#
# Usage: ./scripts/check-i18n-hardcoded.sh
#
# Exits 0 if no hardcoded strings found (check passes),
# exits 1 if hardcoded strings are found (check fails).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

echo "Checking for hardcoded user-facing strings in converted i18n files..."

exec php scripts/check-i18n-hardcoded.php
