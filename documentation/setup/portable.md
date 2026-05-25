# Portable Install

Portable artifacts bundle `stud-cli`, the canonical PHAR, and a platform PHP runtime. Use this path when installing PHP locally is not desirable.

PHAR remains the recommended default until portable has broader validation.

## Supported Platforms

| Platform | Portable Target | Notes |
| --- | --- | --- |
| Linux amd64 | `linux-amd64` | Includes WSL2 on amd64 Linux distributions. |
| macOS Apple Silicon | `darwin-arm64` | Unsigned and unnotarized for now. |
| Windows | `linux-amd64` through WSL2 | Native Windows is not supported. |

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --portable
```

The installer:

1. Resolves the latest GitHub release.
2. Detects `linux-amd64` or `darwin-arm64`.
3. Downloads the matching portable archive and `checksums.txt`.
4. Verifies the archive checksum before extraction.
5. Installs the bundle under `~/.local/share/stud-portable/<platform>`.
6. Links only the launcher to `~/.local/bin/stud`.
7. Verifies `stud --version`.

The launcher resolves its real portable directory before executing, so `stud` works from `~/.local/bin/stud` even though that command is a symlink. The bundled runtime and `app/stud.phar` must stay beside the launcher inside the portable directory.

## Updating Portable Installs

Portable self-update is not implemented. Upgrade by rerunning the installer or by replacing the platform directory with a newer verified artifact.

## macOS Caveat

The `darwin-arm64` artifact is unsigned and unnotarized. After checksum verification, macOS may still require approval in System Settings or quarantine removal:

```bash
xattr -dr com.apple.quarantine "$HOME/.local/share/stud-portable/darwin-arm64"
```

## What Portable Does Not Include

Portable removes the need for a user-installed PHP runtime. It does not include:

- Git installation or Git credentials
- Jira, GitHub, or GitLab tokens
- Network access to company services
- `~/.config/stud/config.yml` or repository `.git/stud.config`
