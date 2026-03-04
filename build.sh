#!/bin/bash
set -e

# ============================================================================
# CC Switch — Build standalone binary via static-php-cli (spc) + box
#
# Uses spc to build micro.sfx with swoole + pdo_sqlite, then combines
# with .phar to create a single standalone binary.
#
# Prerequisites:
#   - PHP 8.1+ (for running box and composer)
#   - Build tools: re2c, flex, cmake, make, gcc/g++
#     Install: sudo apt-get install -y re2c flex cmake build-essential
#
# Steps:
#   1. Detect platform (OS + arch)
#   2. Build micro.sfx with swoole via spc (or reuse cached)
#   3. Install production dependencies
#   4. Compile .phar via box
#   5. Concatenate micro.sfx + .phar into standalone binary
#
# Environment variables:
#   SPC_PHP_VERSION — PHP version to build (default: 8.3)
#   SPC_EXTENSIONS  — Comma-separated extensions (has default)
#   SKIP_SPC_BUILD  — Set to 1 to skip spc build (use existing micro.sfx)
# ============================================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="${SCRIPT_DIR}/build"
MICRO_SFX="${BUILD_DIR}/micro.sfx"
PHAR_FILE="${BUILD_DIR}/cc-switch.phar"
OUTPUT_BIN="${BUILD_DIR}/cc-switch"

SPC_PHP_VERSION="${SPC_PHP_VERSION:-8.3}"
SPC_EXTENSIONS="${SPC_EXTENSIONS:-swoole,pdo_sqlite,phar,curl,openssl,mbstring,tokenizer,filter,zlib,sockets,pcntl,posix,ctype,fileinfo,session}"

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

# ---- 2. Build micro.sfx via spc ----

build_micro_sfx() {
    mkdir -p "${BUILD_DIR}"

    if [ -f "${MICRO_SFX}" ] && [ "${SKIP_SPC_BUILD:-0}" = "1" ]; then
        echo "==> micro.sfx already exists, skipping build."
        return
    fi

    local spc_bin="${BUILD_DIR}/spc"
    local spc_work="${BUILD_DIR}/spc-work"

    # Download spc if not present
    if [ ! -f "${spc_bin}" ]; then
        echo "==> Downloading spc (static-php-cli) ..."
        local spc_os spc_arch
        case "${PLATFORM}" in
            linux-x86_64)   spc_os="linux"; spc_arch="x86_64" ;;
            linux-aarch64)  spc_os="linux"; spc_arch="aarch64" ;;
            macos-x86_64)   spc_os="macos"; spc_arch="x86_64" ;;
            macos-aarch64)  spc_os="macos"; spc_arch="aarch64" ;;
        esac
        curl -fsSL -o "${BUILD_DIR}/spc.tgz" \
            "https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-${spc_os}-${spc_arch}.tar.gz"
        tar -xzf "${BUILD_DIR}/spc.tgz" -C "${BUILD_DIR}"
        rm -f "${BUILD_DIR}/spc.tgz"
        chmod +x "${spc_bin}"
    fi

    # Download sources
    echo "==> Downloading PHP sources and extensions ..."
    cd "${BUILD_DIR}"

    local custom_url_flag=""
    # Use GitHub mirror if php.net is unreachable
    if ! curl -sfSL --max-time 5 "https://www.php.net/releases/index.php?json&version=${SPC_PHP_VERSION}" > /dev/null 2>&1; then
        echo "==> php.net unreachable, using GitHub mirror for PHP source ..."
        # Get latest patch version from GitHub
        local php_tag
        php_tag=$(curl -sfSL "https://api.github.com/repos/php/php-src/tags?per_page=20" \
            | grep -o '"name": "php-'"${SPC_PHP_VERSION}"'[^"]*"' \
            | head -1 | grep -o 'php-[0-9.]*')
        if [ -z "${php_tag}" ]; then
            php_tag="php-${SPC_PHP_VERSION}.30"
        fi
        custom_url_flag="--custom-url=php-src:https://github.com/php/php-src/archive/refs/tags/${php_tag}.tar.gz"
    fi

    "${spc_bin}" download \
        --with-php="${SPC_PHP_VERSION}" \
        --for-extensions="${SPC_EXTENSIONS}" \
        --prefer-pre-built \
        ${custom_url_flag}

    # Build micro.sfx
    echo "==> Building micro.sfx (this may take a few minutes) ..."
    "${spc_bin}" build "${SPC_EXTENSIONS}" --build-micro

    # Copy micro.sfx to build dir
    local built_sfx="${BUILD_DIR}/buildroot/bin/micro.sfx"
    if [ ! -f "${built_sfx}" ]; then
        built_sfx="$(find "${BUILD_DIR}" -name 'micro.sfx' -path '*/buildroot/*' -type f 2>/dev/null | head -1)"
    fi

    if [ -f "${built_sfx}" ]; then
        cp "${built_sfx}" "${MICRO_SFX}"
        echo "==> micro.sfx ready."
    else
        echo "Error: micro.sfx build failed."
        exit 1
    fi
}

build_micro_sfx

# ---- 3. Install production dependencies ----

echo "==> Installing production dependencies ..."
cd "${SCRIPT_DIR}"
composer install --no-dev --optimize-autoloader --no-interaction --quiet --ignore-platform-reqs

# ---- 4. Compile .phar ----

echo "==> Compiling .phar ..."

if [ -f "${SCRIPT_DIR}/vendor/bin/box" ]; then
    php "${SCRIPT_DIR}/vendor/bin/box" compile
elif command -v box &>/dev/null; then
    box compile
elif [ -f "${SCRIPT_DIR}/box.phar" ]; then
    php "${SCRIPT_DIR}/box.phar" compile
else
    echo "==> box not found, downloading box.phar ..."
    curl -fSL -o "${SCRIPT_DIR}/box.phar" \
        "https://github.com/box-project/box/releases/download/4.6.6/box.phar"
    chmod +x "${SCRIPT_DIR}/box.phar"
    php "${SCRIPT_DIR}/box.phar" compile
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
