# Development Guide

This page is for contributors running `stud-cli` from source or changing release and packaging internals.

## Requirements

- PHP 8.4.1+
- Composer
- Required PHP extensions: `xml`, `curl`, `mbstring`

## Setup

```bash
composer install
./stud help
./stud config:init
```

## Tests

```bash
vendor/bin/phpunit
vendor/bin/phpunit tests/Architecture/
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

`tests/Architecture/` guards presentation boundaries: no direct Logger, console `$io->` output, or ad-hoc translation in handlers/services; frozen allow lists track legacy `WorkflowOutput` usage pending responder migration.

For coverage:

```bash
php -dpcov.enabled=1 -dpcov.directory=. -dpcov.exclude="~vendor~" ./vendor/bin/phpunit --coverage-text
```

## Documentation Maintenance

When command signatures, options, aliases, agent JSON fields, or command output shapes change, refresh the generated command reference:

```bash
stud docs:generate
stud docs:check
```

Use the [documentation playbook update prompt](docs-playbook-update-prompt.md) when a command change also affects the curated workflow playbook, Mermaid schema, feature pages, or README discovery links.

## Build PHAR

```bash
scripts/build-phar --version 1.0.0 --output stud-1.0.0.phar
```

The script reads the locked `jolicode/castor` version from `composer.lock`, downloads the matching Castor host PHAR from the GitHub **release asset** URL (`…/releases/download/vX.Y.Z/castor.linux-amd64.phar`, not the REST API), and passes it to `castor repack --castor-phar`. That avoids unauthenticated GitHub API rate limits during local builds. Override with `CASTOR_PHAR=/path/to/castor.phar` when you already have the host PHAR. Use `--dry-run` to print the resolved tag, URL, and repack args without building.
## Portable Packaging

Portable packaging consumes the canonical PHAR and a platform runtime. See [stud-portable packaging](../stud-portable-prototype.md).

## Foundation upgrade (4.x)

stud-cli **4.x** runs on PHP ≥ 8.4.1, Symfony 8.1.x, and Castor 1.8.1. Spike inventory and migration notes: [foundation upgrade 4.x](foundation-upgrade-4x.md). Consumer migration: [3.x → 4.x](../setup/migrating-3x-to-4x.md). Packaged binaries always disable Castor AI-agent environment guessing; see [packaging and Castor agent detection](packaging-and-castor-agent-detection.md).

## AI and agent workflows

- [AI development protocol](../../AI.md)
- [Automation and agent mode](../features/automation.md)
- [Documentation index](../index.md)

## Architecture

- [Engineering conventions](../../CONVENTIONS.md)
- [Responder pattern ADR](../adr-005-responder-pattern-architecture.md)
- [Agent mode ADR](../adr-012-agent-mode-json-io.md)
- [Dual output ADR](../adr-013-responder-based-dual-output.md)
- [Documentation playbook update prompt](docs-playbook-update-prompt.md)
