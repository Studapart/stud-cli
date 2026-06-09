# PHAR Install

PHAR is the recommended `stud-cli` installation mode.

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --phar
```

Omitting `--phar` does the same thing.

## What the Installer Does

1. Resolves the latest GitHub release.
2. Checks for PHP 8.2+ and required extensions.
3. Downloads the latest `stud-<version>.phar` asset.
4. Installs it as `~/.local/bin/stud`.
5. Ensures `~/.local/bin` is available in future shells.
6. Verifies `stud --version`.
7. Offers to run `stud init`.

## Updating

PHAR installs keep the existing single-binary update behavior:

```bash
stud update
```

Preview release notes before updating:

```bash
stud update --info
stud up -i
```

If you installed globally with `sudo`, updates may require elevated privileges. Prefer the user-owned `~/.local/bin` install path.

Portable installs also support `stud update`, but they update by installing a complete portable bundle and switching the managed symlink instead of replacing a PHAR in place.

## CI and Non-Interactive Install

Use `--skip-init` when the installer runs in CI or a script:

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --phar --skip-init
```
