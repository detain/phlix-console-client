#!/usr/bin/env bash
#
# check-i18n-hardcoded.sh
#
# CI check that fails if a hardcoded user-facing string is added to one of
# the three converted i18n files: RecommendationsScreen.php, DetailScreen.php,
# or FilterBar.php.
#
# Usage: ./scripts/check-i18n-hardcoded.sh
#
# Exits 0 if no hardcoded strings found (check passes),
# exits 1 if hardcoded strings are found (check fails).

set -euo pipefail

# Files to check (the three converted screens)
FILES=(
    'src/Screen/RecommendationsScreen.php'
    'src/Screen/DetailScreen.php'
    'src/Ui/FilterBar.php'
)

# Change to project root (where this script lives)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

# Color codes (only if stdout is a terminal)
if [[ -t 1 ]]; then
    RED='\033[0;31m'
    NC='\033[0m' # No Color
else
    RED=''
    NC=''
fi

echo "Checking for hardcoded user-facing strings in converted i18n files..."

# Check that all files exist
for file in "${FILES[@]}"; do
    if [[ ! -f "$file" ]]; then
        echo -e "${RED}Warning: File not found: $file${NC}" >&2
    fi
done

# Create a Python script to do the checking
python3 - << 'PYEOF'
import re
import os
import sys

# Known technical values that are not user-facing
KNOWN_TECHNICAL = {
    "'name'", "'year'", "'rating'", "'date_added'", "'runtime'",
    "'asc'", "'desc'",
    "'p'", "'P'", "'s'", "'S'", "'C'", "'r'", "'R'", "'f'", "'F'",
    "'w'", "'W'", "'d'", "'D'", "'q'", "'Q'", "'k'", "'j'",
    "'en'", "'utf'", "'UTF'", "'json'",
    "'true'", "'false'", "'null'",
    "'view'", "'init'", "'update'", "'boot'", "'render'", "'create'",
}

# Translation key pattern (e.g., 'recommendations.title')
KEY_PATTERN = re.compile(r"^[a-z][a-z0-9_]+\.[a-z_]+$")

FILES = [
    'src/Screen/RecommendationsScreen.php',
    'src/Screen/DetailScreen.php',
    'src/Ui/FilterBar.php',
]

hardcoded_strings = []

# Regex to find single-quoted strings
single_quote_pattern = re.compile(r"'([^']+)'")

for filepath in FILES:
    if not os.path.isfile(filepath):
        continue

    with open(filepath, 'r') as f:
        content = f.read()

    # Find all single-quoted strings
    for match in single_quote_pattern.finditer(content):
        s = match.group(1)
        # Skip short strings (likely technical)
        if len(s) < 3:
            continue
        # Skip strings that are known technical values
        if f"'{s}'" in KNOWN_TECHNICAL:
            continue
        # Skip strings that look like translation keys
        if KEY_PATTERN.match(s):
            continue
        # Skip strings inside Lang::t() or Lang::_() calls
        start = match.start()
        context_start = max(0, start - 50)
        context_before = content[context_start:start]
        if 'Lang::t' in context_before or 'Lang::_' in context_before:
            continue
        # This looks like a hardcoded string
        if re.search(r'[a-zA-Z]{2}', s):  # Has visible letters
            hardcoded_strings.append((filepath, f"'{s}'"))

if hardcoded_strings:
    print("ERROR: Found potential hardcoded user-facing string(s):")
    for filepath, s in hardcoded_strings:
        print(f"  - {filepath}: {s}")
    print("")
    print("To fix: wrap the string in Lang::t() or Lang::_() using a key from resources/lang/en.php")
    print("See docs/i18n.md for conversion guide.")
    sys.exit(1)
else:
    print("No hardcoded strings found. Check passed.")
    sys.exit(0)
PYEOF
