#!/bin/bash
set -e

# ============================================================================
# CC Switch — Build standalone binary via static-php-cli + box
#
# Steps:
#   1. Detect platform (OS + arch)
#   2. Download static-php-cli micro.sfx if missing
#   3. Install production dependencies
#   4. Compile .phar via box
#   5. Concatenate micro.sfx + .phar into standalone binary
# ============================================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="${SCRIPT_DIR}/build"
MICRO_SFX="${BUILD_DIR}/micro.sfx"
PHAR_FILE="${BUILD_DIR}/cc-switch.phar"
OUTPUT_BIN="${BUILD_DIR}/cc-switch"

# ---- 1. Detect platform ----

detect_platform() {
    local os arch

    case "$(uname -s)" in
        Linux*)  os="linux" ;;
        Darwin*) os="macos" ;;
        *)
            echo "Error: Unsupported OS '$(uname -s)'. Only Linux and macOS are supported."
            exit 1
            ;;
    esac

    case "$(uname -m)" in
        x86_64|amd64)  arch="x86_64" ;;
        aarch64|arm64) arch="aarch64" ;;
        *)
            echo "Error: Unsupported architecture '$(uname -m)'. Only x86_64 and aarch64 are supported."
            exit 1
            ;;
    esac

    echo "${os}-${arch}"
}

PLATFORM="$(detect_platform)"
echo "==> Platform: ${PLATFORM}"

# ---- 2. Download micro.sfx if missing ----

download_micro_sfx() {
    mkdir -p "${BUILD_DIR}"

    if [ -f "${MICRO_SFX}" ]; then
        echo "==> micro.sfx already exists, skipping download."
        return
    fi

    # static-php-cli download URL pattern
    # Includes: swoole, pdo_sqlite, curl, zlib, openssl, mbstring, tokenizer, filter
    local base_url="https://dl.static-php.dev/static-php-cli/common"
    local filename

    case "${PLATFORM}" in
        linux-x86_64)   filename="php-8.3-micro-linux-x86_64.tar.gz" ;;
        linux-aarch64)  filename="php-8.3-micro-linux-aarch64.tar.gz" ;;
        macos-x86_64)   filename="php-8.3-micro-macos-x86_64.tar.gz" ;;
        macos-aarch64)  filename="php-8.3-micro-macos-aarch64.tar.gz" ;;
    esac

    local url="${base_url}/${filename}"
    local tarball="${BUILD_DIR}/${filename}"

    echo "==> Downloading micro.sfx from ${url} ..."

    if command -v curl &>/dev/null; then
        curl -fSL -o "${tarball}" "${url}"
    elif command -v wget &>/dev/null; then
        wget -q -O "${tarball}" "${url}"
    else
        echo "Error: Neither curl nor wget found. Please install one."
        exit 1
    fi

    echo "==> Extracting micro.sfx ..."
    tar -xzf "${tarball}" -C "${BUILD_DIR}"

    # The archive typically contains micro.sfx at root level
    if [ ! -f "${MICRO_SFX}" ]; then
        # Search for it inside extracted files
        local found
        found="$(find "${BUILD_DIR}" -name 'micro.sfx' -type f 2>/dev/null | head -1)"
        if [ -n "${found}" ] && [ "${found}" != "${MICRO_SFX}" ]; then
            mv "${found}" "${MICRO_SFX}"
        else
            echo "Error: micro.sfx not found after extraction."
            exit 1
        fi
    fi

    rm -f "${tarball}"
    echo "==> micro.sfx ready."
}

download_micro_sfx

# ---- 3. Install production dependencies ----

echo "==> Installing production dependencies ..."
cd "${SCRIPT_DIR}"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

# ---- 4. Compile .phar ----

echo "==> Compiling .phar ..."

if [ -f "${SCRIPT_DIR}/vendor/bin/box" ]; then
    php "${SCRIPT_DIR}/vendor/bin/box" compile
elif command -v box &>/dev/null; then
    box compile
elif [ -f "${SCRIPT_DIR}/box.phar" ]; then
    php "${SCRIPT_DIR}/box.phar" compile
else
    echo "Error: box not found. Install via: composer require --dev humbug/box"
    echo "  Or download: https://github.com/box-project/box/releases"
    exit 1
fi

if [ ! -f "${PHAR_FILE}" ]; then
    echo "Error: .phar compilation failed — ${PHAR_FILE} not found."
    exit 1
fi

# ---- 5. Build standalone binary ----

echo "==> Building standalone binary ..."
cat "${MICRO_SFX}" "${PHAR_FILE}" > "${OUTPUT_BIN}"
chmod +x "${OUTPUT_BIN}"

# ---- Done ----

BINARY_SIZE=$(du -h "${OUTPUT_BIN}" | cut -f1)
echo ""
echo "==> Build complete!"
echo "    Binary: ${OUTPUT_BIN}"
echo "    Size:   ${BINARY_SIZE}"
echo ""
echo "    Run:  ${OUTPUT_BIN} --help"
