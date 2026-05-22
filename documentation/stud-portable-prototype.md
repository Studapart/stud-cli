# stud-portable Prototype

`scripts/build-portable` creates a portable `stud` directory for `linux-amd64` from an existing PHAR and a platform PHP runtime. `scripts/prototype-portable` remains as a compatibility wrapper for the original spike command shape.

The prototype follows the SCI-100 recommendation: keep `stud-<version>.phar` as the canonical application artifact and package it beside a runtime launcher. It does not rebuild application source.

## Usage

```bash
scripts/build-portable \
  --platform linux-amd64 \
  --phar .cursor/tmp/stud-3.16.1.phar \
  --runtime .cursor/tmp/static-php-linux-amd64/php \
  --output .cursor/tmp
```

Expected layout:

```text
.cursor/tmp/stud-portable-linux-amd64/
  stud
  runtime/php
  app/stud.phar
  README.md
```

The generated `stud` launcher executes `runtime/php app/stud.phar`, so the artifact does not use `php` from the user's `PATH`.

The script prints the generated artifact path on success. It consumes the PHAR and runtime that are passed to it; it does not run Composer, Castor, or Box.

## Smoke Checks

Run the repeatable smoke script against the generated binary:

```bash
scripts/smoke-portable --binary .cursor/tmp/stud-portable-linux-amd64/stud
```

The smoke script runs these safe commands:

```bash
./stud --version
echo '{}' | ./stud help --agent
echo '{"skipJira":true,"skipGit":true}' | ./stud config:validate --agent
```

## Prototype Boundaries

- Only `linux-amd64` is supported by this prototype.
- Runtime acquisition is intentionally outside this script; follow-up tasks should pin the selected `static-php-cli` runtime source.
- Agent-run temporary outputs must be written under `.cursor/tmp/` and must not be committed. CI can point `--output` at its artifact directory.
- The normal PHAR release remains unchanged.
- Portable self-update is not implemented here; users should replace the portable artifact manually during this spike.
