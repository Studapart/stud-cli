# Migrating from stud-cli 3.x to 4.x

stud-cli **4.x** requires **PHP ≥ 8.4.1**, **Symfony 8.1.x**, and **Castor 1.8.1**. After 4.x GA, the **3.x** line is frozen: no further features or security patches.

## What changes for you

| Surface | 3.x | 4.x |
|---------|-----|-----|
| Minimum PHP (PHAR / source) | 8.2 | **8.4.1** |
| Portable runtime | StaticPHP 8.2 | StaticPHP **8.4** |
| Install channels | PHAR, portable, Composer | Same channels |
| ADF library | `damienharper/adf-tools` | `studapart/adf-tools` (same `DH\Adf` API) |
| GitHub Action default PHP | 8.2 | **8.4** |

## Stay on 3.x

If you cannot move to PHP 8.4.1 yet:

1. Pin the last **3.x** release tag (for example `v3.22.1`).
2. Keep using that PHAR / portable artifact or Composer constraint.
3. Accept that 3.x receives no further maintenance after 4.x GA.

## Upgrade to 4.x

### PHAR

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --force
```

Or, once you already have a 3.x PHAR on PATH with PHP 8.4.1+:

```bash
stud update
```

### Portable

Rerun the installer with `--portable` on a supported platform (`linux-amd64`, `darwin-arm64`). The 4.x portable bundle ships a PHP 8.4 runtime.

### Composer / source

```bash
# Require PHP 8.4.1+ on the host
composer install
```

`composer.json` pins `studapart/adf-tools` via a VCS repository until Packagist publish.

### GitHub Actions

```yaml
uses: Studapart/stud-cli/.github/actions/stud-cli-setup@v4.0.0
# optional override; default is already 8.4
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
