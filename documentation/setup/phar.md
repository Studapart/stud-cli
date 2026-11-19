# PHAR Install

PHAR is the recommended `stud-cli` installation mode.

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --phar
```

Omitting `--phar` does the same thing.

## What the Installer Does

1. Resolves the latest GitHub release.
2. Checks for PHP 8.5 (8.5.12 or newer) and required extensions.
3. Downloads the latest `stud-<version>.phar` asset.
4. Installs it as `~/.local/bin/stud`.
5. Ensures `~/.local/bin` is available in future shells.
6. Verifies `stud --version`.
7. Offers to run `stud init`.

## Updating

PHAR installs store each version as its own file under `~/.local/share/stud-portable/phar/<version>-stud.phar`, and `~/.local/bin/stud` is a symlink to the active one:

```bash
stud update
```

An update installs the new version as its own file, then switches the `~/.local/bin/stud` symlink to it. The running copy is never overwritten. A plain PHAR already at `~/.local/bin/stud` is kept as the versioned file for its own version, and the link then points at the new one. A PHAR that runs from another location (for example `/usr/local/bin/stud`) is replaced in place, as before, and no older copy is kept. If `~/.local/bin/stud` links to that PHAR, the update stops instead, and `setup-stud.sh --phar --force` switches to the managed layout.

When you answer yes to the cleanup prompt, the update only records that consent. A later `stud` command that already runs the new version removes the older copies. If that removal fails, the command shows a warning and retries on the next run. To cancel a cleanup that keeps failing, delete `~/.local/share/stud-portable/.cleanup-pending`. If `~/.local/bin/stud` is a symlink to a file outside the managed install, the update stops and keeps the current version. Run `setup-stud.sh --phar --force` to switch it to the managed layout.

Preview release notes before updating:

```bash
stud update --info
stud up -i
```

If you installed globally with `sudo`, updates may require elevated privileges. Prefer the user-owned `~/.local/bin` install path.

Packaged PHAR always disables Castor’s AI-agent environment guessing. `stud --agent` remains the only switch for JSON agent mode. See [packaging and Castor agent detection](../development/packaging-and-castor-agent-detection.md).

Portable installs use the same symlink switch. Each update installs a complete portable bundle, and older bundles are removed only under the cleanup rules above.

## CI and Non-Interactive Install

Use `--skip-init` when the installer runs in CI or a script:

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --phar --skip-init
```
