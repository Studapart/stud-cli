# Setup Overview

`stud-cli` has one public installer entry point: `setup-stud.sh`. The installer resolves the latest GitHub release and installs one of two modes.

## Recommended Default: PHAR

Use PHAR unless you specifically need a bundled PHP runtime.

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash
```

The PHAR path requires PHP 8.2+ with `xml`, `curl`, and `mbstring`. It supports `stud update`, so upgrades are managed by the tool.

## Opt-In: Portable

Portable artifacts bundle PHP and are available for:

- Linux amd64, including WSL2
- macOS Apple Silicon (`darwin-arm64`)

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --portable
```

Portable is useful when installing local PHP is not desirable. It is not yet the default recommendation, and portable self-update is not implemented. Upgrade portable installs by rerunning the installer or replacing the installed artifact.

## Installer Options

```bash
--phar       Install the recommended PHAR artifact. This is the default.
--portable   Install the supported portable artifact for this platform.
--force      Reinstall the selected mode even when stud is already present.
--skip-init  Skip the interactive configuration prompt.
```

## Next Steps

- [Linux / WSL setup](linux-wsl.md)
- [macOS setup](macos.md)
- [PHAR details](phar.md)
- [Portable details](portable.md)
- [Configuration](configuration.md)
