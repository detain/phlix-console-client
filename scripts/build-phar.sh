#!/usr/bin/env bash
#
# Build script for phlix PHAR distribution.
#
# Usage: ./scripts/build-phar.sh
#
# Requires:
#   - PHP >= 8.3 with ext-gd, ext-phar, ext-zlib
#   - box (https://box-project.github.io/box4/)
#
# The PHAR will be built at build/phlix.phar

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$PROJECT_DIR/build"
PHAR_PATH="$BUILD_DIR/phlix.phar"
BOX_JSON="$PROJECT_DIR/box.json"

echo "=== Phlix PHAR Build Script ==="
echo ""

# Check PHP version
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION;')
echo "PHP version: $PHP_VERSION"

# Check required extensions
for ext in gd phar zlib; do
    if ! php -m | grep -q "^${ext}$"; then
        echo "ERROR: Required PHP extension '$ext' is not loaded."
        exit 1
    fi
done
echo "Required extensions: OK (gd, phar, zlib)"

# Ensure build directory exists
mkdir -p "$BUILD_DIR"

# Check for box
BOX_BIN=""
if command -v box &> /dev/null; then
    BOX_BIN="box"
elif [ -f "$PROJECT_DIR/vendor/bin/box" ]; then
    BOX_BIN="$PROJECT_DIR/vendor/bin/box"
else
    echo ""
    echo "box not found. Installing via composer..."
    cd "$PROJECT_DIR"
    composer require --dev humbug/box --no-interaction 2>&1 || {
        echo "Failed to install box via composer. Trying to download..."
        # Fallback: download box.phar directly
        BOX_BIN="/tmp/box.phar"
        curl -fsSL https://github.com/box-project/box/releases/latest/download/box.phar \
            -o "$BOX_BIN" 2>/dev/null || {
            echo "ERROR: Could not install box. Please install it manually:"
            echo "  composer require --dev humbug/box"
            echo "  or download from: https://box-project.github.io/box4/"
            exit 1
        }
        chmod +x "$BOX_BIN"
    }
    cd - > /dev/null
fi

BOX_BIN="${BOX_BIN:-$(command -v box)}"
if [ -z "$BOX_BIN" ] || [ ! -x "$BOX_BIN" ]; then
    if [ -f "$PROJECT_DIR/vendor/bin/box" ]; then
        BOX_BIN="$PROJECT_DIR/vendor/bin/box"
    else
        echo "ERROR: box executable not found"
        exit 1
    fi
fi

echo "Using box: $BOX_BIN"
BOX_VERSION=$("$BOX_BIN" --version 2>/dev/null || echo "unknown")
echo "box version: $BOX_VERSION"

# Check box.json exists
if [ ! -f "$BOX_JSON" ]; then
    echo "ERROR: box.json not found at $BOX_JSON"
    echo "Please create box.json with PHAR configuration"
    exit 1
fi

# Build the PHAR
echo ""
echo "Building PHAR..."
cd "$PROJECT_DIR"
"$BOX_BIN" compile --no-progress 2>&1 || {
    echo "ERROR: PHAR build failed"
    exit 1
}

# Verify the PHAR was created
if [ ! -f "$PHAR_PATH" ]; then
    echo "ERROR: PHAR not found at $PHAR_PATH after build"
    exit 1
fi

PHAR_SIZE=$(stat -c%s "$PHAR_PATH" 2>/dev/null || stat -f%z "$PHAR_PATH" 2>/dev/null)
echo ""
echo "=== Build Complete ==="
echo "PHAR: $PHAR_PATH"
echo "Size: $PHAR_SIZE bytes"

# Verify PHAR can run --version
echo ""
echo "Verifying PHAR..."
if php "$PHAR_PATH" --version; then
    echo "PHAR verification: OK"
else
    echo "ERROR: PHAR --version failed"
    exit 1
fi

echo ""
echo "Build successful: $PHAR_PATH"
