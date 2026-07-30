# GitHub Actions with stud-cli

This document is the single source of truth for using **stud-cli** in GitHub Actions: required secrets, optional variables, `permissions`, fork safety, and how to call **agent mode** (`--agent`) with JSON on stdin.

## Composite action: `stud-cli-setup`

Path in this repository: **`.github/actions/stud-cli-setup`**.

### What it does

1. Sets up **PHP 8.2+** with extensions **xml**, **curl**, and **mbstring** (`shivammathur/setup-php`).
2. Downloads `setup-stud.sh` from **`Studapart/stud-cli`** at the Git ref you choose (`stud-install-ref`, default `develop`) and runs it with **`--force --skip-init`** so CI never blocks on interactive `stud init`.
3. Writes **`~/.config/stud/config.yml`** (mode `600`) from action inputs. Optional Git tokens are only written when non-empty.
4. Optionally writes **`.git/stud.config`** from the `project-stud-config` input (you must **`actions/checkout`** before this action when using project config).
5. Runs **`stud config:validate --agent`** when `run-config-validate` is `true` (default). Validate flags are derived from configured Jira / Linear inputs (see **Provider-conditional validate** below).

Global and project paths match the CLI: **`~/.config/stud/config.yml`** and **`.git/stud.config`** (see [Configuration](setup/configuration.md)).

### Pinning the install script

- **Supply chain:** Prefer pinning `stud-install-ref` to a **release tag** (semver) in production workflows.
- **Script ref vs PHAR version:** `stud-install-ref` selects which **`setup-stud.sh`** text is downloaded from GitHub. Only a **semver tag** (for example `v3.20.0`) also pins the installed stud binary. A **commit SHA** or branch name still runs that ref’s installer script, but the installer falls through to the **latest GitHub release** PHAR. Dogfooding unreleased CLI features needs a release or a future install-from-checkout path — a SHA alone does not run tip code.
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
| `jira-url` | conditional | Jira base URL (no trailing slash). Required when validating Jira. |
| `jira-email` | conditional | Atlassian email. Required with `jira-url` and `jira-api-token` for Jira. |
| `jira-api-token` | conditional | Jira API token (from a secret). Required when validating Jira. |
| `linear-api-key` | no | Linear API key → `LINEAR_API_KEY` in global config when non-empty. |
| `work-item-providers` | no | Explicit intent: `jira`, `linear`, or `both`. When empty, derived from non-empty secret inputs. |
| `language` | no | Default `en`. |
| `github-token` | no | Adds `GITHUB_TOKEN` to global config when set. |
| `gitlab-token` | no | Adds `GITLAB_TOKEN` when set. |
| `gitlab-instance-url` | no | Adds `GITLAB_INSTANCE_URL` when set. |
| `jira-transition-enabled` | no | `true` / `false` (string), default `false`. |
| `stud-install-ref` | no | Git ref for `setup-stud.sh`, default `develop`. |
| `run-config-validate` | no | Default `true`. |
| `validate-skip-git` | no | Default **`true`** → skips Git provider connectivity check. Set `false` when you need GitHub/GitLab token validation. |
| `project-stud-config` | no | Multiline content for `.git/stud.config`. |
| `php-version` | no | Default `8.2`. |

### Provider-conditional validate

The validate step sets `skipJira`, `skipLinear`, and `skipGit` from your inputs:

| Setup | `config:validate` agent JSON |
|-------|------------------------------|
| Jira only (three Jira inputs set) | `skipJira: false`, `skipLinear: true`, `skipGit` per `validate-skip-git` |
| Linear only (`linear-api-key` set, no Jira trio) | `skipJira: true`, `skipLinear: false`, `skipGit` per `validate-skip-git` |
| Both providers | `skipJira: false`, `skipLinear: false`, `skipGit` per `validate-skip-git` |

Use **`work-item-providers`** when CI intent should differ from what secrets happen to be present (for example `both` while only one secret set should fail at config write time).

**Linear-only validate payload (equivalent manual call):**

```bash
echo '{"skipJira":true,"skipGit":true}' | stud config:validate --agent
```

**Jira-only (unchanged):**

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
- The Jira and Linear label sync workflows in this repo gate on **`github.event.pull_request.head.repo.fork == false`** so secrets are only used for same-repo PRs.
- **`pull_request_target`** runs in the base repo context and can access secrets; it also increases risk if the workflow checks out or runs untrusted code from the head branch. Prefer **`pull_request`** + fork guards + explicit variables for maintenance workflows unless you fully understand **`pull_request_target`** hardening. Avoid copying untrusted scripts into the job without review.

## Dual-PM label sync (Jira + Linear)

**Decision:** Keep **two thin workflows** — **`.github/workflows/jira-label-sync.yml`** and **`.github/workflows/linear-label-sync.yml`** — that both call the shared script **`.github/scripts/sync-pr-labels.sh`**. Do not merge them into one matrix job: secrets and setup differ per tracker, and independent job status makes soft-skip vs real sync clearer for required checks.

Both run on **`pull_request`** events **`labeled`** / **`unlabeled`** when the PR head is **not** from a fork.

Shared script env: `PROVIDER` (`jira`|`linear`), `LABEL_MAP_JSON`, `LABELS_JSON`, `HEAD_REF`, `MAP_VAR_NAME`. Merge logic lives in **`tests/Fixtures/label-sync/merge-managed-labels.jq`** (covered by `LabelSyncMergeAlgorithmTest`).

### Soft-skip vs hard-fail

| Outcome | Conditions |
|---------|------------|
| **Soft-skip (exit 0)** | Required secrets for that tracker are missing; or `items:show --agent` exits non-zero / returns `success=false` for the pinned provider (wrong tracker for the branch key, or issue not found). Soft-skip logs a clear reason and dumps agent JSON when present. **Soft-skip is not a sync.** |
| **Hard-fail (exit 1)** | Label-map repository variable missing or not valid JSON; no issue key (`PROJ-123`) in the branch name; or `items:update` fails after a successful show. |

`items:show` is invoked with `set +e` so a non-zero stud exit still allows logging before soft-skip. Both show and update agent payloads always include explicit **`provider`**.

### Jira label sync (`jira-label-sync.yml`)

**Secrets (required for real sync):** `STUD_JIRA_URL`, `STUD_JIRA_EMAIL`, `STUD_JIRA_API_TOKEN`. If any are unset, the job soft-skips before `stud-cli-setup`.

**Repository variable — label map:** Set **`STUD_JIRA_LABEL_MAP`** to a JSON object whose keys are **GitHub PR label names** and values are **Jira label names**, for example:

```json
{"bug":"Bug","enhancement":"Story"}
```

You can change this variable in the GitHub UI without committing code. Variables are not secret; do not put credentials in this JSON. Missing or invalid map **hard-fails**.

**Agent calls** (via the shared script):

```bash
jq -n --arg key "${KEY}" '{key: $key, provider: "jira"}' | stud items:show --agent
jq -n --arg key "${KEY}" --arg fields "labels=${FIELDS_VALUE}" \
  '{key: $key, fields: $fields, provider: "jira"}' | stud items:update --agent
```

**Merge semantics (does not wipe unrelated Jira labels):** Jira’s `labels` field is replaced in full on each **`items:update`**. The script calls **`items:show`** first and reads **`data.issue.labels`**, then:

- Keep every label already on the issue whose name is **not** a **value** in `STUD_JIRA_LABEL_MAP` (unmanaged labels are never removed).
- For each **distinct Jira name** that appears as a map value (a “managed” target): add that label if **any** GitHub label that maps to it is on the PR; remove **only** that Jira name if **none** of those GitHub labels are on the PR. Multiple GitHub labels mapping to the same Jira label behave as an OR for “present on the PR”.
- If the merged list equals the current list (order-insensitive), the script skips **`items:update`**.

**Edge case:** An empty PR label list still runs the merge so managed labels are removed when no mapped GitHub labels remain.

**Dual-PM:** On a Linear-only branch key (for example `SCIL-*`), the Jira job soft-skips when show fails for `provider: jira` instead of failing the required check.

### Linear label sync (`linear-label-sync.yml`)

**Secret (required for real sync):** `STUD_LINEAR_API_KEY`. If unset, the job soft-skips before `stud-cli-setup` (so `config:validate` does not hard-fail). Soft-skip ≠ sync — you need **`STUD_LINEAR_API_KEY`** and **`STUD_LINEAR_LABEL_MAP`** for actual Linear sync.

**Repository variable — label map:** Set **`STUD_LINEAR_LABEL_MAP`** to a JSON object whose keys are **GitHub PR label names** and values are **Linear label names**, for example:

```json
{"bug":"Bug","enhancement":"Feature"}
```

**Agent calls** use explicit Linear provider selection (same shared script with `PROVIDER=linear`).

**Merge semantics** are identical to the Jira workflow. The workflow never updates Jira issues.

**Dual-PM:** On a Jira-only branch key (for example `SCI-*`), the Linear job soft-skips when the secret is unset or show fails for `provider: linear`.

### Install ref on label-sync workflows

Both workflows pass **`stud-install-ref: ${{ github.event.pull_request.head.sha }}`** so the **installer script text** matches the PR tip. That does **not** install a tip PHAR (see [Pinning the install script](#pinning-the-install-script)). In consumer repositories, prefer a **release tag** for both script and binary.

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

## Example: Linear-only CI job

Use when the repository uses Linear as the only issue tracker and no Jira secrets are configured:

```yaml
jobs:
  linear:
    runs-on: ubuntu-latest
    permissions:
      contents: read
    steps:
      - uses: actions/checkout@v5
      - uses: ./.github/actions/stud-cli-setup
        with:
          linear-api-key: ${{ secrets.STUD_LINEAR_API_KEY }}
          stud-install-ref: develop
          validate-skip-git: true
          project-stud-config: |
            issueTrackerProvider: linear
            projectKey: ENG
            migration_version: '999999999999999'
      - run: |
          printf '%s' '{"key":"ENG-42","provider":"linear"}' | stud items:show --agent
```

Store **`STUD_LINEAR_API_KEY`** as a repository secret. The composite action writes **`LINEAR_API_KEY`** and **`ISSUE_TRACKER_PROVIDERS: [linear]`** to global config, then runs **`config:validate`** with **`skipJira: true`**.

## PR analytics (Google Sheets)

Workflow **`.github/workflows/pr-analytics.yml`** syncs PR / review / label metrics for a date range and base branch into Google Sheets. It does **not** collect coverage (keep using **`tests.yml`**) and does **not** parse the changelog.

### Triggers

| Trigger | Behaviour |
|---------|-----------|
| `schedule` | Once per day in the **early morning Europe/Paris** — single UTC cron `0 2 * * *` (04:00 Paris under CEST, 03:00 under CET). GitHub cron ticks are best-effort, so the actual start can drift; the run **always** syncs and is never skipped on the local hour. Covers the **previous Paris calendar day** as `start_date=end_date=yesterday`, then applies existing `T00:00:00Z`–`T23:59:59Z` bounds on that date. Forces **`target_branch=develop`** and **`append=true`**. Runs from the workflow file on the repo **default branch `develop`**. |
| `workflow_dispatch` | Manual / backfill. Optional date range (default: current UTC month), target branch (default `develop`), sheet id, and append. **Append defaults to `true`**; set `false` only to clear+replace. |

| Sheet | Content |
|-------|---------|
| `PRs` | PR metadata and time-to-merge |
| `Reviews` | Reviewer submissions and time-to-review |
| `PRs Labels` | PR ↔ label rows |

### Secrets and variables

| Name | Required | Notes |
|------|----------|-------|
| `GOOGLE_SERVICE_ACCOUNT_KEY` | yes | Service account JSON with access to the spreadsheet |
| `GOOGLE_SHEET_ID` | yes (or workflow input on dispatch) | Spreadsheet ID from the URL; required for scheduled runs |
| `vars.APP_ID` + `secrets.APP_PRIVATE_KEY` | optional | Prefer GitHub App token for PR API rate limits; falls back to `GITHUB_TOKEN` |

**Local script tests:** `node --test .github/scripts/sync-pr-analytics-sheets.test.cjs .github/scripts/google-api-retry.test.cjs .github/scripts/pr-analytics-workflow-contract.test.cjs`

## Optional: action metadata checks

This repository does not run a dedicated GitHub Action metadata linter in CI. Maintainers can verify workflows locally with **[actionlint](https://github.com/rhysd/actionlint)** (e.g. install via package manager or run the official container) to catch `uses:` pin issues, invalid `if:` expressions, and similar problems.

## `setup-stud.sh` and CI

For non-Action installs, you can skip the interactive configuration prompt with **`--skip-init`** (without relying on **`--force`** only for that behavior):

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --skip-init
```

Use **`--force --skip-init`** when you want a non-interactive reinstall/update as well.
