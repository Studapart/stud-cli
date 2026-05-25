# Development Guide

This page is for contributors running `stud-cli` from source or changing release and packaging internals.

## Requirements

- PHP 8.2+
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
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

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

## Portable Packaging

Portable packaging consumes the canonical PHAR and a platform runtime. See [stud-portable packaging](../stud-portable-prototype.md).

## Architecture

- [Engineering conventions](../../CONVENTIONS.md)
- [Responder pattern ADR](../adr-005-responder-pattern-architecture.md)
- [Agent mode ADR](../adr-012-agent-mode-json-io.md)
- [Dual output ADR](../adr-013-responder-based-dual-output.md)
- [Documentation playbook update prompt](docs-playbook-update-prompt.md)
