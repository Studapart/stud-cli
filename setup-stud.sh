#!/bin/bash
# setup-stud.sh — Install or reinstall stud-cli from the latest GitHub release.
# Usage: curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash
#        curl -fsSL ... | bash -s -- --force

set -e

FORCE=false
for arg in "$@"; do
    case "$arg" in
        --force) FORCE=true ;;
    esac
done

REPO_URL="https://github.com/Studapart/stud-cli"
API_LATEST="https://api.github.com/repos/Studapart/stud-cli/releases/latest"
INSTALL_DIR="${HOME}/.local/bin"
STUD_BIN="${INSTALL_DIR}/stud"

die() {
    echo "$1" >&2
    exit 1
}

# Detect OS and architecture
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

# Get latest release version from GitHub API (strip leading v)
get_latest_version() {
    local tag_name
    tag_name=$(curl -sSfL "$API_LATEST" | grep '"tag_name"' | sed 's/.*"v\?\([^"]*\)".*/\1/' | head -1)
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

# Check for existing stud and handle --force / version comparison
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

# Ensure curl is available
if ! command -v curl >/dev/null 2>&1; then
    die "curl is required but not installed. Please install curl and re-run this script."
fi

# Detect platform (exits if unsupported)
detect_platform >/dev/null

LATEST_VERSION=$(get_latest_version)
check_existing "$LATEST_VERSION"

if ! check_php; then
    if ! check_php; then
        die "PHP 8.2+ with extensions (xml, curl, mbstring) is still not available."
    fi
fi

# Download and install
mkdir -p "$INSTALL_DIR"
DOWNLOAD_URL="${REPO_URL}/releases/download/v${LATEST_VERSION}/stud-${LATEST_VERSION}.phar"
if ! curl -sSfL -o "${INSTALL_DIR}/stud" "$DOWNLOAD_URL"; then
    die "Failed to download stud from ${DOWNLOAD_URL}"
fi
chmod +x "${INSTALL_DIR}/stud"

# Ensure PATH
add_path() {
    local path_export="export PATH=\"\$HOME/.local/bin:\$PATH\""
    local config_file
    if [ -n "${ZSH_VERSION:-}" ] && [ -f "${HOME}/.zshrc" ]; then
        config_file="${HOME}/.zshrc"
    elif [ -f "${HOME}/.bashrc" ]; then
        config_file="${HOME}/.bashrc"
    elif [ -f "${HOME}/.profile" ]; then
        config_file="${HOME}/.profile"
    else
        config_file="${HOME}/.profile"
        touch "$config_file"
    fi
    if ! grep -q '.local/bin' "$config_file" 2>/dev/null; then
        echo "$path_export" >> "$config_file"
        echo "Added ~/.local/bin to PATH in $config_file. Run 'source $config_file' or restart your terminal."
    fi
}

case ":$PATH:" in
    *":${INSTALL_DIR}:"*) ;;
    *) add_path ;;
esac

# Verify installation
if [ -x "$STUD_BIN" ]; then
    echo "Installed version:"
    "$STUD_BIN" --version || true
fi

# Offer first-time setup (skip if --force)
RUN_INIT=true
if [ "$FORCE" = true ]; then
    RUN_INIT=false
fi
if [ "$RUN_INIT" = true ]; then
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

echo ""
echo "stud is installed! Quick start:"
echo "  stud help   # See all commands"
echo "  stud init   # First-time configuration"
echo "  stud ls     # List your Jira items"
