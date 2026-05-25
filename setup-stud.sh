#!/bin/bash
# setup-stud.sh - Install or reinstall stud-cli from the latest GitHub release.
# Usage: curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash
#        curl -fsSL ... | bash -s -- --phar
#        curl -fsSL ... | bash -s -- --portable
#        curl -fsSL ... | bash -s -- --force
#        curl -fsSL ... | bash -s -- --skip-init

set -e

FORCE=false
SKIP_INIT=false
INSTALL_MODE="phar"
INSTALL_DIR="${HOME}/.local/bin"
PORTABLE_ROOT="${HOME}/.local/share/stud-portable"

die() {
    echo "$1" >&2
    exit 1
}

usage() {
    cat <<'USAGE'
Usage: setup-stud.sh [--phar|--portable] [--force] [--skip-init]

Install modes:
  --phar       Install the recommended PHAR artifact. This is the default.
  --portable   Install the portable artifact for Linux amd64 / WSL2 or macOS Apple Silicon.

Options:
  --force      Reinstall even when stud is already present.
  --skip-init  Skip interactive first-time configuration.
USAGE
}

for arg in "$@"; do
    case "$arg" in
        --phar) INSTALL_MODE="phar" ;;
        --portable) INSTALL_MODE="portable" ;;
        --force) FORCE=true ;;
        --skip-init) SKIP_INIT=true ;;
        -h|--help)
            usage
            exit 0
            ;;
        *) die "Unknown option: $arg" ;;
    esac
done

REPO_URL="https://github.com/Studapart/stud-cli"
API_LATEST="https://api.github.com/repos/Studapart/stud-cli/releases/latest"
STUD_BIN="${INSTALL_DIR}/stud"

# Strip carriage returns so URLs are valid when script has CRLF line endings (e.g. on macOS)
REPO_URL="${REPO_URL//$'\r'/}"
API_LATEST="${API_LATEST//$'\r'/}"

# Detect OS and architecture for general installer support.
detect_platform() {
    local os
    local arch
    case "$(uname -s)" in
        Linux)
            os="linux"
            if [ -n "${WSL_DISTRO_NAME:-}" ] || grep -qEi "microsoft|wsl" /proc/version 2>/dev/null; then
                : # WSL2 treated as Linux
            fi
            ;;
        Darwin) os="macos" ;;
        *) die "Unsupported OS: $(uname -s). This script supports Linux (including WSL2) and macOS." ;;
    esac
    case "$(uname -m)" in
        x86_64|amd64) arch="x86_64" ;;
        aarch64|arm64) arch="aarch64" ;;
        *) die "Unsupported architecture: $(uname -m)." ;;
    esac
    echo "${os}-${arch}"
}

detect_portable_platform() {
    case "$(detect_platform)" in
        linux-x86_64) echo "linux-amd64" ;;
        macos-aarch64) echo "darwin-arm64" ;;
        *)
            die "Portable install supports Linux amd64 / WSL2 and macOS Apple Silicon only. Use the recommended PHAR install with --phar on this platform."
            ;;
    esac
}

# Get latest release version from GitHub API (strip leading v and any carriage return)
get_latest_version() {
    local tag_name
    tag_name=$(curl -sSfL "$API_LATEST" | grep '"tag_name"' | sed 's/.*"v\?\([^"]*\)".*/\1/' | head -1 | tr -d '\r')
    if [ -z "$tag_name" ]; then
        die "Could not determine latest release version from GitHub API."
    fi
    echo "$tag_name"
}

# Get version from existing stud (stud --version may print "stud 3.8.1" or similar)
get_stud_version() {
    local out
    out=$("$1" --version 2>/dev/null || true)
    if [ -n "$out" ]; then
        echo "$out" | sed -n 's/.*[^0-9]\([0-9]\+\.[0-9]\+\.[0-9]\+\).*/\1/p' | head -1
    fi
}

# Check for existing stud and handle --force / version comparison for PHAR installs.
check_existing() {
    local latest="$1"
    local stud_cmd
    stud_cmd=$(command -v stud 2>/dev/null || true)
    if [ -z "$stud_cmd" ]; then
        return 0
    fi
    local current
    current=$(get_stud_version "$stud_cmd")
    if [ -z "$current" ]; then
        return 0
    fi
    if [ "$current" = "$latest" ] && [ "$FORCE" = false ]; then
        echo "Already up to date. To update in the future, use: stud update"
        exit 0
    fi
    if [ "$current" != "$latest" ] && [ "$FORCE" = false ]; then
        echo "Installed version: $current"
        echo "Latest version:    $latest"
        echo "To upgrade, run: stud update"
        exit 0
    fi
    # FORCE=true: continue to overwrite
    return 0
}

# Check PHP >= 8.2 and required extensions (xml, curl, mbstring)
check_php() {
    local php_cmd
    php_cmd=$(command -v php 2>/dev/null || true)
    if [ -z "$php_cmd" ]; then
        show_php_install
        return 1
    fi
    local version
    version=$("$php_cmd" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
    if [ -z "$version" ]; then
        show_php_install
        return 1
    fi
    local major minor
    major=$(echo "$version" | cut -d. -f1)
    minor=$(echo "$version" | cut -d. -f2)
    if [ "$major" -lt 8 ] || { [ "$major" -eq 8 ] && [ "$minor" -lt 2 ]; }; then
        echo "PHP $version found. PHP 8.2 or higher is required."
        show_php_install
        return 1
    fi
    for ext in xml curl mbstring; do
        if ! "$php_cmd" -m 2>/dev/null | grep -q "^${ext}$"; then
            echo "Required PHP extension '${ext}' is missing."
            show_php_install
            return 1
        fi
    done
    return 0
}

show_php_install() {
    echo ""
    if [ -f /etc/debian_version ] || command -v apt-get >/dev/null 2>&1; then
        echo "Ubuntu/Debian:"
        echo "  sudo apt update && sudo apt install php8.2-cli php8.2-xml php8.2-curl php8.2-mbstring"
        echo "  (On Ubuntu 22.04 you may need: sudo add-apt-repository ppa:ondrej/php first)"
    elif [ "$(uname -s)" = "Darwin" ]; then
        echo "macOS (Homebrew):"
        echo "  brew install php"
    elif command -v dnf >/dev/null 2>&1; then
        echo "Fedora/RHEL:"
        echo "  sudo dnf install php-cli php-xml php-curl php-mbstring"
    else
        echo "Please install PHP 8.2+ with extensions: xml, curl, mbstring."
    fi
    echo ""
    printf "Would you like to run these commands now? [y/N] "
    read -r answer </dev/tty || true
    case "$answer" in
        [yY]|[yY][eE][sS])
            if [ -f /etc/debian_version ] || command -v apt-get >/dev/null 2>&1; then
                sudo apt update && sudo apt install -y php8.2-cli php8.2-xml php8.2-curl php8.2-mbstring 2>/dev/null || {
                    sudo add-apt-repository -y ppa:ondrej/php
                    sudo apt update && sudo apt install -y php8.2-cli php8.2-xml php8.2-curl php8.2-mbstring
                }
            elif [ "$(uname -s)" = "Darwin" ]; then
                brew install php
            elif command -v dnf >/dev/null 2>&1; then
                sudo dnf install -y php-cli php-xml php-curl php-mbstring
            else
                echo "Please install PHP 8.2+ manually and re-run this script."
                exit 1
            fi
            ;;
        *)
            echo "Please install PHP 8.2+ manually and re-run this script."
            exit 1
            ;;
    esac
}

download_release_asset() {
    local url="$1"
    local target="$2"
    url="${url//$'\r'/}"
    if ! curl -sSfL -o "$target" "$url"; then
        die "Failed to download ${url}"
    fi
}

verify_portable_checksum() {
    local checksums_file="$1"
    local artifact_name="$2"
    local checksum_entry
    checksum_entry=$(grep "  ${artifact_name}$" "$checksums_file" || true)
    if [ -z "$checksum_entry" ]; then
        die "Checksum entry for ${artifact_name} was not found in checksums.txt."
    fi
    if command -v sha256sum >/dev/null 2>&1; then
        printf '%s\n' "$checksum_entry" | sha256sum -c -
    elif command -v shasum >/dev/null 2>&1; then
        printf '%s\n' "$checksum_entry" | shasum -a 256 -c -
    else
        die "sha256sum or shasum is required to verify portable artifacts."
    fi
}

install_phar() {
    local latest_version="$1"
    local download_url
    mkdir -p "$INSTALL_DIR"
    latest_version="${latest_version//$'\r'/}"
    download_url="${REPO_URL}/releases/download/v${latest_version}/stud-${latest_version}.phar"
    download_release_asset "$download_url" "${INSTALL_DIR}/stud"
    chmod +x "${INSTALL_DIR}/stud"
}

install_portable() {
    local latest_version="$1"
    local platform="$2"
    local artifact_name="stud-portable-${latest_version}-${platform}.tar.gz"
    local artifact_dir="stud-portable-${latest_version}-${platform}"
    local release_url="${REPO_URL}/releases/download/v${latest_version}"
    local tmp_dir

    tmp_dir=$(mktemp -d "${TMPDIR:-/tmp}/stud-portable.XXXXXX")
    cleanup_portable_tmp() {
        rm -rf "$tmp_dir"
    }
    trap cleanup_portable_tmp EXIT

    download_release_asset "${release_url}/${artifact_name}" "${tmp_dir}/${artifact_name}"
    download_release_asset "${release_url}/checksums.txt" "${tmp_dir}/checksums.txt"

    (
        cd "$tmp_dir" || exit 1
        verify_portable_checksum "checksums.txt" "$artifact_name"
        tar -xzf "$artifact_name"
    )

    if [ ! -x "${tmp_dir}/${artifact_dir}/stud" ]; then
        die "Portable artifact ${artifact_name} did not contain an executable stud launcher."
    fi

    mkdir -p "$PORTABLE_ROOT" "$INSTALL_DIR"
    rm -rf "${PORTABLE_ROOT:?}/${platform}"
    mv "${tmp_dir}/${artifact_dir}" "${PORTABLE_ROOT}/${platform}"
    ln -sf "${PORTABLE_ROOT}/${platform}/stud" "$STUD_BIN"
}

detect_shell_config_file() {
    local shell_name
    shell_name=$(basename "${SHELL:-}")

    if [ -n "${ZSH_VERSION:-}" ] || [ "$shell_name" = "zsh" ]; then
        echo "${HOME}/.zshrc"
    elif [ -n "${BASH_VERSION:-}" ] || [ "$shell_name" = "bash" ]; then
        if [ -f "${HOME}/.bashrc" ]; then
            echo "${HOME}/.bashrc"
        else
            echo "${HOME}/.profile"
        fi
    elif [ -f "${HOME}/.profile" ]; then
        echo "${HOME}/.profile"
    else
        echo "${HOME}/.profile"
    fi
}

add_path() {
    local path_export="export PATH=\"\$HOME/.local/bin:\$PATH\""
    local config_file
    config_file=$(detect_shell_config_file)
    if [ ! -f "$config_file" ]; then
        touch "$config_file"
    fi
    if ! grep -q '.local/bin' "$config_file" 2>/dev/null; then
        echo "$path_export" >> "$config_file"
        echo "Added ~/.local/bin to PATH in $config_file. Run 'source $config_file' or restart your terminal."
    fi
}

ensure_user_bin_on_path() {
    case ":$PATH:" in
        *":${INSTALL_DIR}:"*) ;;
        *) add_path ;;
    esac
}

offer_first_time_setup() {
    local run_init=true
    if [ "$FORCE" = true ] || [ "$SKIP_INIT" = true ]; then
        run_init=false
    fi
    if [ "$run_init" = true ]; then
        printf "Would you like to configure stud now? [Y/n] "
        read -r answer </dev/tty || true
        case "$answer" in
            [nN]|[nN][oO])
                echo "Run 'stud init' when you're ready to configure."
                ;;
            *)
                "$STUD_BIN" init || true
                ;;
        esac
    fi
}

# Ensure required installer tools are available.
command -v curl >/dev/null 2>&1 || die "curl is required but not installed. Please install curl and re-run this script."
command -v tar >/dev/null 2>&1 || die "tar is required but not installed. Please install tar and re-run this script."

# Detect platform early so unsupported OS/architecture fails before any install work.
detect_platform >/dev/null

LATEST_VERSION=$(get_latest_version)

case "$INSTALL_MODE" in
    phar)
        check_existing "$LATEST_VERSION"
        if ! check_php; then
            if ! check_php; then
                die "PHP 8.2+ with extensions (xml, curl, mbstring) is still not available."
            fi
        fi
        install_phar "$LATEST_VERSION"
        ;;
    portable)
        PORTABLE_PLATFORM=$(detect_portable_platform)
        echo "Installing portable stud for ${PORTABLE_PLATFORM}."
        install_portable "$LATEST_VERSION" "$PORTABLE_PLATFORM"
        ;;
    *)
        die "Unsupported install mode: ${INSTALL_MODE}"
        ;;
esac

ensure_user_bin_on_path

# Verify installation
if [ -x "$STUD_BIN" ]; then
    echo "Installed version:"
    "$STUD_BIN" --version || true
fi

offer_first_time_setup

echo ""
echo "stud is installed! Quick start:"
echo "  stud help   # See all commands"
echo "  stud init   # First-time configuration"
echo "  stud ls     # List your Jira items"
