# macOS Setup

PHAR is the recommended install path for macOS until portable artifacts have broader validation.

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash
```

The installer uses `~/.local/bin/stud` and updates your shell configuration when `~/.local/bin` is not already on `PATH`.

## Requirements for PHAR

Install PHP with Homebrew:

```bash
brew install php
```

Homebrew PHP includes the required `xml`, `curl`, and `mbstring` extensions.

Verify:

```bash
php -v
php -m | grep -E '^(xml|curl|mbstring)$'
```

## Shell PATH

The installer detects common shell configuration files. On modern macOS, zsh is the default shell, so `~/.zshrc` is usually the right place for:

```bash
export PATH="$HOME/.local/bin:$PATH"
```

If you use bash, use `~/.bashrc` or `~/.profile` depending on your local setup.

## Portable on Apple Silicon

Apple Silicon macOS can use the `darwin-arm64` portable artifact:

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --portable
```

The portable artifact is currently unsigned and unnotarized. After checksum verification, local macOS policy may still require you to approve the binary in System Settings or remove quarantine metadata:

```bash
xattr -dr com.apple.quarantine "$HOME/.local/share/stud-portable/darwin-arm64/<version>"
```

Signing, notarization, Homebrew packaging, and managed installers are follow-up work. Versioned portable installs can update with `stud update`; rerun the latest portable installer first if your machine still uses the older non-versioned portable layout.
