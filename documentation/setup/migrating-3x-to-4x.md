# Migrating from stud-cli 3.x to 4.x

stud-cli **4.x** is the **PHP 8.4** line. stud-cli **5.x** (current `develop`) is the **PHP 8.5** line. When PHP 8.6 is released, that line will be stud-cli **6.x**. After 4.x GA, the **3.x** line is frozen: no further features or security patches.

## What changes for you

| Surface | 3.x | 4.x |
|---------|-----|-----|
| Minimum PHP (PHAR / source) | 8.2 | **PHP 8.4** |
| Portable runtime | StaticPHP 8.2 | StaticPHP **8.4.23** |
| Install channels | PHAR, portable, Composer | Same channels |
| ADF library | `damienharper/adf-tools` | `studapart/adf-tools` (same `DH\Adf` API) |
| GitHub Action default PHP | 8.2 | **8.4** |

## Stay on 3.x

If you cannot move to PHP 8.4 yet:

1. Pin the last **3.x** release tag (for example `v3.22.1`).
2. Keep using that PHAR / portable artifact or Composer constraint.
3. Accept that 3.x receives no further maintenance after 4.x GA.

## Stay on PHP 8.4

Use stud-cli **4.x** (latest tag `v4.3.0`). Do not install from `develop`; that branch is the 5.x line and requires PHP 8.5.

## Upgrade to 4.x

### PHAR

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/v4.3.0/setup-stud.sh | bash -s -- --force
```

### Portable

Rerun the 4.x installer with `--portable` on a supported platform (`linux-amd64`, `darwin-arm64`). The 4.x portable bundle ships PHP 8.4.23.

### Composer / source

On a PHP 8.4 host, install from the `v4.3.0` tag. `composer.json` on that tag pins `studapart/adf-tools` via a VCS repository until Packagist publish.

### GitHub Actions

```yaml
uses: Studapart/stud-cli/.github/actions/stud-cli-setup@v4.3.0
with:
  php-version: '8.4'
```

## What you keep

- Command names, aliases, and agent JSON contracts stay the same unless a follow-up release notes otherwise.
- Markdown → ADF conversion still uses the `DH\Adf` namespace.

## Further reading

- [Foundation upgrade TECH_SPEC](../development/foundation-upgrade-4x.md)
- [Setup overview](index.md)
- [SECURITY.md](../../SECURITY.md)
