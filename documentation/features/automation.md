# Automation

Use non-interactive flags and agent JSON for scripts, CI, and AI-driven workflows.

## Agent Mode

Commands that support `--agent` accept JSON on stdin and return structured JSON.

```bash
echo '{}' | stud help --agent
echo '{"essential":false}' | stud help --agent
echo '{"command":"config:validate"}' | stud help --agent
echo '{"skipGit":true}' | stud config:validate --agent
```

Use the generated schema from `stud help --agent` as the source of truth for JSON properties.
Empty input returns the essential commands used in common agent workflows. Pass
`{"essential":false}` to return every command schema, or
`{"command":"<name-or-alias>"}` to inspect one command regardless of whether it is
essential.

Agent mode uses compact success output by default to reduce tokens. The `compact`
flag (default `true`) omits `data` only for **completion-only** commands. Commands
that return structured `data` (for example `items:show`, `config:show`) always
include `data` regardless of `compact`.

For completion-only commands, compact output omits `data`:

```json
{"success":true}
```

Commands that return follow-up values keep the smallest useful `data` value, and
errors always include an explicit `error` string. Send `{"compact":false}` when
you need the full success payload. Use `compact` for this mode; `zip` is not
supported.

## Quiet Mode

Where available, `--quiet` / `-q` means non-interactive: use documented defaults and do not prompt.

Examples:

```bash
stud commit --all --quiet
stud submit --labels "AI-Generated,RFR" --quiet
stud branches:clean --quiet
stud update --quiet
```

## AI development protocol

Agents implementing features in this repository must follow [AI.md](../../AI.md)
(`--agent` first, four-phase protocol, `CONVENTIONS.md` compliance).

## CI Setup

For GitHub Actions, see [GitHub Actions with stud-cli](../github-actions.md).

In any CI system:

1. Install `stud` with `--skip-init`.
2. Write global and project configuration from secrets.
3. Run `stud config:validate --agent`.
4. Prefer `--agent` for machine-readable command output.
