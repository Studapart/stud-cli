# Linux / WSL Setup

PHAR is the recommended install path for Linux and WSL2.

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash
```

The installer downloads the latest PHAR release, installs it to `~/.local/bin/stud`, ensures `~/.local/bin` is available in future shells, and offers to run `stud init`.

## Requirements for PHAR

Install PHP 8.2+ and required extensions.

Ubuntu 24.04+ / Debian 12+:

```bash
sudo apt update && sudo apt install php8.2-cli php8.2-xml php8.2-curl php8.2-mbstring
```

Ubuntu 22.04 may need the Ondrej PPA first:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update && sudo apt install php8.2-cli php8.2-xml php8.2-curl php8.2-mbstring
```

Fedora / RHEL:

```bash
sudo dnf install php-cli php-xml php-curl php-mbstring
```

Verify:

```bash
php -v
php -m | grep -E '^(xml|curl|mbstring)$'
```

## WSL2

Use the Linux instructions inside your WSL2 terminal. Keep `stud` and its configuration in the WSL filesystem rather than on a mounted Windows drive.

## Portable on Linux / WSL2

Linux amd64 and WSL2 can use the portable artifact:

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --portable
```

The installer verifies the portable archive against `checksums.txt` before installing it under `~/.local/share/stud-portable/linux-amd64` and linking `~/.local/bin/stud`.

Portable does not require local PHP, but Git, credentials, network access, and `stud` configuration are still required.
