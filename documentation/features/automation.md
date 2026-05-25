# Automation

Use non-interactive flags and agent JSON for scripts, CI, and AI-driven workflows.

## Agent Mode

Commands that support `--agent` accept JSON on stdin and return structured JSON.

```bash
echo '{}' | stud help --agent
echo '{"commandName":"config:validate"}' | stud help --agent
echo '{"skipGit":true}' | stud config:validate --agent
```

Use the generated schema from `stud help --agent` as the source of truth for JSON properties.

## Quiet Mode

Where available, `--quiet` / `-q` means non-interactive: use documented defaults and do not prompt.

Examples:

```bash
stud commit --all --quiet
stud submit --labels "AI-Generated,RFR" --quiet
stud branches:clean --quiet
stud update --quiet
```

## CI Setup

For GitHub Actions, see [GitHub Actions with stud-cli](../github-actions.md).

In any CI system:

1. Install `stud` with `--skip-init`.
2. Write global and project configuration from secrets.
3. Run `stud config:validate --agent`.
4. Prefer `--agent` for machine-readable command output.
