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
5. Installs the bundle under `~/.local/share/stud-portable/<platform>/<version>`.
6. Links only the active version launcher to `~/.local/bin/stud`.
7. Verifies `stud --version`.

The launcher resolves its real portable directory before executing, so `stud` works from `~/.local/bin/stud` even though that command is a symlink. The bundled runtime and `app/stud.phar` must stay beside the launcher inside the versioned portable directory.

## Updating Portable Installs

Versioned portable installs support:

```bash
stud update
```

Portable update downloads the matching portable archive for the current platform, verifies it against `checksums.txt`, extracts it as a complete bundle, runs a launcher smoke check, and then switches the managed `~/.local/bin/stud` symlink to the new version. Previous portable versions are kept by default so rollback remains possible by repointing the symlink.

Preview release notes without changing the install:

```bash
stud update --info
stud up -i
```

Quiet updates keep old versions and do not prompt for cleanup:

```bash
stud update --quiet
```

Legacy portable installs used the older `~/.local/share/stud-portable/<platform>` layout. Re-run the latest portable installer to move to the update-compatible versioned layout. If `~/.local/bin/stud` points to a managed portable launcher, the installer repoints it to the new versioned launcher. If it points to an unmanaged binary or unexpected symlink target, the installer refuses to overwrite it unless you rerun with `--force`.

## macOS Caveat

The `darwin-arm64` artifact is unsigned and unnotarized. After checksum verification, macOS may still require approval in System Settings or quarantine removal:

```bash
xattr -dr com.apple.quarantine "$HOME/.local/share/stud-portable/darwin-arm64/<version>"
```

## What Portable Does Not Include

Portable removes the need for a user-installed PHP runtime. It does not include:

- Git installation or Git credentials
- Jira, GitHub, or GitLab tokens
- Network access to company services
- `~/.config/stud/config.yml` or repository `.git/stud.config`
