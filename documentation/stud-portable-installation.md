# stud-portable Installation And Support

`stud-portable` is the company-first distribution path for users who should not need to install PHP locally. It packages the canonical `stud-<version>.phar` with a platform runtime and publishes checksums alongside the release assets.

The existing PHAR installation remains supported. Use this guide when you install a portable artifact from a GitHub release.

## Supported Platforms

| Platform | Artifact | Status |
| --- | --- | --- |
| Linux amd64 | `stud-portable-<version>-linux-amd64.tar.gz` | Supported first rollout |
| Windows | `stud-portable-<version>-linux-amd64.tar.gz` inside WSL2 | Supported through WSL2 only |
| macOS Apple Silicon | `stud-portable-<version>-darwin-arm64.tar.gz` | Planned; use only after the asset exists on a release |

Deferred targets:

- Native Windows without WSL2
- Linux ARM64
- Windows ARM64
- Public distribution polish beyond company needs

## Install On Linux amd64

1. Download the portable archive and checksums from the release page:

```bash
VERSION=3.16.1
curl -fL -o "stud-portable-${VERSION}-linux-amd64.tar.gz" \
  "https://github.com/Studapart/stud-cli/releases/download/v${VERSION}/stud-portable-${VERSION}-linux-amd64.tar.gz"
curl -fL -o checksums.txt \
  "https://github.com/Studapart/stud-cli/releases/download/v${VERSION}/checksums.txt"
```

2. Verify the checksum before extracting:

```bash
sha256sum -c --ignore-missing checksums.txt
```

3. Install the extracted directory under a user-owned path and expose the launcher on `PATH`:

```bash
tar -xzf "stud-portable-${VERSION}-linux-amd64.tar.gz"
mkdir -p "$HOME/.local/opt/stud-portable" "$HOME/.local/bin"
rm -rf "$HOME/.local/opt/stud-portable/linux-amd64"
mv "stud-portable-${VERSION}-linux-amd64" "$HOME/.local/opt/stud-portable/linux-amd64"
ln -sf "$HOME/.local/opt/stud-portable/linux-amd64/stud" "$HOME/.local/bin/stud"
```

4. Verify the install:

```bash
stud --version
echo '{}' | stud help --agent
echo '{"skipJira":true,"skipGit":true}' | stud config:validate --agent
```

Ensure `~/.local/bin` is on `PATH`. Add this to your shell config if needed:

```bash
export PATH="$HOME/.local/bin:$PATH"
```

## Windows Support

Windows support currently means WSL2 plus the Linux amd64 portable artifact.

1. Install WSL2 with an amd64 Linux distribution, for example Ubuntu.
2. Open the WSL2 terminal.
3. Follow the Linux amd64 installation steps inside WSL2.
4. Store the portable directory in the WSL filesystem, such as `~/.local/opt/stud-portable`, not on a mounted Windows drive.

Native Windows executables are not part of the first rollout.

## Remaining Prerequisites

Portable artifacts remove the need for a user-installed PHP runtime. They do not replace the tools and credentials that `stud` needs to do work:

- Git must be installed and configured.
- SSH keys or HTTPS credentials must work for the Git provider.
- Jira, GitHub, and GitLab tokens are still required when the corresponding commands use those services.
- Network and certificate trust must allow access to company services.
- Existing `stud` configuration still lives under `~/.config/stud/config.yml` and project configuration still uses `.git/stud.config`.

## Updating

Portable self-update is not implemented in the first rollout. To update:

1. Download the new release archive and `checksums.txt`.
2. Verify the checksum.
3. Replace the platform directory under `~/.local/opt/stud-portable`.
4. Keep the `~/.local/bin/stud` symlink pointing to the platform launcher.

The `stud update` command remains intended for PHAR installs unless release notes say otherwise.

## Troubleshooting

If `stud` is not found, confirm `~/.local/bin` is on `PATH` and that the symlink points to an existing portable launcher.

If the launcher is not executable, run:

```bash
chmod +x "$HOME/.local/opt/stud-portable/linux-amd64/stud"
chmod +x "$HOME/.local/opt/stud-portable/linux-amd64/runtime/php"
```

If checksum verification fails, delete the downloaded archive and download it again from the release page. Do not install an artifact whose checksum does not match.

If a command fails because of credentials, run the safe validation command first:

```bash
echo '{"skipJira":true,"skipGit":true}' | stud config:validate --agent
```

When reporting an issue, include:

- The artifact filename and release version.
- The platform, for example Linux amd64 or WSL2 Ubuntu.
- Output from `stud --version`.
- The failing command and its output.
