# GitHub Actions with stud-cli

This document is the single source of truth for using **stud-cli** in GitHub Actions: required secrets, optional variables, `permissions`, fork safety, and how to call **agent mode** (`--agent`) with JSON on stdin.

## Composite action: `stud-cli-setup`

Path in this repository: **`.github/actions/stud-cli-setup`**.

### What it does

1. Sets up **PHP 8.2+** with extensions **xml**, **curl**, and **mbstring** (`shivammathur/setup-php`).
2. Downloads `setup-stud.sh` from **`Studapart/stud-cli`** at the Git ref you choose (`stud-install-ref`, default `develop`) and runs it with **`--force --skip-init`** so CI never blocks on interactive `stud init`.
3. Writes **`~/.config/stud/config.yml`** (mode `600`) from action inputs. Optional Git tokens are only written when non-empty.
4. Optionally writes **`.git/stud.config`** from the `project-stud-config` input (you must **`actions/checkout`** before this action when using project config).
5. Runs **`echo '{"skipJira":false,"skipGit":…}' | stud config:validate --agent`** when `run-config-validate` is `true` (default).

Global and project paths match the CLI: **`~/.config/stud/config.yml`** and **`.git/stud.config`** (see README [Configuration](../README.md#configuration)).

### Pinning the install script

- **Supply chain:** Pin `stud-install-ref` to a **release tag** or **commit SHA** rather than a moving branch in production workflows.
- **`--skip-init`:** The bundled install command expects a `setup-stud.sh` that supports **`--skip-init`**. If you point `stud-install-ref` at an older ref, install may still succeed but the post-install prompt can block the job; use a recent ref or tag.

### Versioning when consuming this repo

Callers outside `Studapart/stud-cli` should reference a **tag** or **SHA** so the action definition does not change unexpectedly:

```yaml
uses: Studapart/stud-cli/.github/actions/stud-cli-setup@v3.12.1
```

Adjust the version to the tag you trust. Path-style actions are versioned with the repository ref.

### Inputs summary

| Input | Required | Purpose |
|-------|----------|---------|
| `jira-url` | yes | Jira base URL (no trailing slash). |
| `jira-email` | yes | Atlassian email. |
| `jira-api-token` | yes | Jira API token (from a secret). |
| `language` | no | Default `en`. |
| `github-token` | no | Adds `GITHUB_TOKEN` to global config when set. |
| `gitlab-token` | no | Adds `GITLAB_TOKEN` when set. |
| `gitlab-instance-url` | no | Adds `GITLAB_INSTANCE_URL` when set. |
| `jira-transition-enabled` | no | `true` / `false` (string), default `false`. |
| `stud-install-ref` | no | Git ref for `setup-stud.sh`, default `develop`. |
| `run-config-validate` | no | Default `true`. |
| `validate-skip-git` | no | Default **`true`** → Jira-only validation (no GitHub/GitLab token required). Set `false` when you need Git connectivity checks. |
| `project-stud-config` | no | Multiline content for `.git/stud.config`. |
| `php-version` | no | Default `8.2`. |

### `skipGit`: when to use `true` vs `false`

- **`validate-skip-git: true`** — Passes `skipGit: true` to **`stud config:validate --agent`**. Use when the job only needs Jira (e.g. label sync). GitHub **GITHUB_TOKEN** / GitLab tokens are **not** required for validation.
- **`validate-skip-git: false`** — Validates both Jira and the configured Git provider. Provide **`github-token`** and/or **`gitlab-token`** (+ instance URL if needed) in config so validation can succeed.

Example (Jira-only):

```bash
echo '{"skipJira":false,"skipGit":true}' | stud config:validate --agent
```

### Secrets and logging

- Map Jira settings to **GitHub Actions secrets** (e.g. `STUD_JIRA_URL`, `STUD_JIRA_EMAIL`, `STUD_JIRA_API_TOKEN`). GitHub masks secret values in logs.
- Do **not** `cat` **`~/.config/stud/config.yml`** in workflows.
- When building JSON for **`stud items:update --agent`**, include only issue key and field strings (e.g. `labels=…`). Do not place tokens in payload files.

### Agent mode reference

Run:

```bash
echo '{}' | stud help --agent
```

For updates, stdin is one JSON object, e.g. **`{"key":"SCI-123","fields":"labels=foo,bar"}`**. Field names and value shapes must match Jira edit metadata for your project.

## Public repositories, forks, and `pull_request_target`

- Workflows triggered by **`pull_request`** from **forks** do not receive **repository secrets** by default. Do not assume Jira or Git tokens exist on those runs.
- The Jira label sync workflow in this repo gates on **`github.event.pull_request.head.repo.fork == false`** so secrets are only used for same-repo PRs.
- **`pull_request_target`** runs in the base repo context and can access secrets; it also increases risk if the workflow checks out or runs untrusted code from the head branch. Prefer **`pull_request`** + fork guards + explicit variables for maintenance workflows unless you fully understand **`pull_request_target`** hardening. Avoid copying untrusted scripts into the job without review.

## Jira label sync workflow (`jira-label-sync.yml`)

Workflow **`.github/workflows/jira-label-sync.yml`** runs on **`pull_request`** events **`labeled`** / **`unlabeled`** when the PR head is **not** from a fork (so repository secrets are available).

**Secrets:** `STUD_JIRA_URL`, `STUD_JIRA_EMAIL`, `STUD_JIRA_API_TOKEN` (same as the composite action).

**Repository variable — label map:** Set **`STUD_JIRA_LABEL_MAP`** to a JSON object whose keys are **GitHub PR label names** and values are **Jira label names** (as accepted by your project’s Jira `labels` field), for example:

```json
{"bug":"Bug","enhancement":"Story"}
```

You can change this variable in the GitHub UI without committing code. The workflow validates that the value is JSON before running `jq`. Variables are not secret; do not put credentials in this JSON.

**Merge semantics (does not wipe unrelated Jira labels):** Jira’s `labels` field is replaced in full on each **`items:update`**. The workflow therefore calls **`stud items:show --agent`** first (JSON input `{"key":"PROJ-123"}`) and reads **`data.issue.labels`**. The same payload may include **`data.issue.attachments`** (filename, size, content URL, MIME type) for any automation that needs attachment discovery without downloading files. It builds the next label set as:

- Keep every label already on the issue whose name is **not** a **value** in `STUD_JIRA_LABEL_MAP` (unmanaged labels are never removed by this workflow).
- For each **distinct Jira name** that appears as a map value (a “managed” target): add that label if **any** GitHub label that maps to it is on the PR; remove **only** that Jira name if **none** of those GitHub labels are on the PR. Multiple GitHub labels mapping to the same Jira label behave as an OR for “present on the PR”.
- If the merged list equals the current list (order-insensitive), the workflow skips **`items:update`** to avoid noise.

**Edge case:** An empty PR label list still runs the merge so managed Jira labels are removed when no mapped GitHub labels remain; it does not short-circuit in a way that would skip those removals.

**Install ref:** The workflow uses **`stud-install-ref: ${{ github.event.pull_request.head.sha }}`** so `setup-stud.sh` matches the PR branch tip. In another repository, pin **`stud-install-ref`** to a **release tag** instead.

## Example: call composite then `items:update`

```yaml
jobs:
  jira:
    runs-on: ubuntu-latest
    permissions:
      contents: read
    steps:
      - uses: actions/checkout@v5
      - uses: ./.github/actions/stud-cli-setup
        with:
          jira-url: ${{ secrets.STUD_JIRA_URL }}
          jira-email: ${{ secrets.STUD_JIRA_EMAIL }}
          jira-api-token: ${{ secrets.STUD_JIRA_API_TOKEN }}
          stud-install-ref: develop
          validate-skip-git: true
      - run: |
          printf '%s' '{"key":"SCI-123","fields":"labels=DX"}' | stud items:update --agent
```

## Optional: action metadata checks

This repository does not run a dedicated GitHub Action metadata linter in CI. Maintainers can verify workflows locally with **[actionlint](https://github.com/rhysd/actionlint)** (e.g. install via package manager or run the official container) to catch `uses:` pin issues, invalid `if:` expressions, and similar problems.

## `setup-stud.sh` and CI

For non-Action installs, you can skip the interactive configuration prompt with **`--skip-init`** (without relying on **`--force`** only for that behavior):

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --skip-init
```

Use **`--force --skip-init`** when you want a non-interactive reinstall/update as well.
