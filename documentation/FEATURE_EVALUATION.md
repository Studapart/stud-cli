<div align="center">
  <img src="../src/resources/logo-300.png" alt="stud-cli Logo" width="200">
</div>

# stud-cli Feature Evaluation

This document evaluates the current feature set of stud-cli (as of the latest documentation) and identifies potential gaps or improvements from a Developer Experience (DX) perspective. It is intended to inform product and engineering discussions, not as a commitment to implement any specific feature.

## Current Feature Inventory

### Configuration & Setup

| Feature | Command(s) | Notes |
|--------|------------|--------|
| First-time setup | `config:init` / `init` | Interactive wizard: language, Jira URL, email, API token, Git provider (GitHub/GitLab), shell completion offer |
| Shell completion | `completion bash` / `completion zsh` | Generated scripts for bash/zsh |
| Config migration | Automatic on `update` and on command run | Global and project migrations; validation and prompting for missing keys |

### Jira (Read-Oriented)

| Feature | Command(s) | Notes |
|--------|------------|--------|
| List projects | `projects:list` / `pj` | All visible Jira projects |
| List items | `items:list` / `ls` | Active items; options: `--all`, `--project`, `--sort` |
| Show item | `items:show` / `sh` | Detailed view for one issue |
| Search (JQL) | `items:search` / `search` | Arbitrary JQL |
| Transitions | `items:transition` / `tx` | Change issue status; optional key from branch |
| List filters | `filters:list` / `fl` | Saved filter names/descriptions |
| Show filter | `filters:show` / `fs` | Issues from a saved filter by name |

### Git Workflow

| Feature | Command(s) | Notes |
|--------|------------|--------|
| Start work | `items:start` / `start` | Branch from issue; optional auto-assign + transition to In Progress |
| Takeover | `items:takeover` / `to` | Assign to self, find/checkout branch or start fresh; branch status checks |
| Branch rename | `branch:rename` / `rn` | Rename branch; optional key/name; PR/MR update and comment |
| Branch list | `branches:list` / `bl` | Local branches with status (merged, stale, active-pr, active) |
| Branch clean | `branches:clean` / `bc` | Interactive (or `--quiet`) cleanup of merged/stale branches |
| Conventional commit | `commit` / `co` | Guided commit; fixup/new; `--message`, `--all` |
| Force push (safe) | `please` / `pl` | `git push --force-with-lease` |
| Flatten fixups | `flatten` / `ft` | Squash fixup!/squash! commits |
| Status dashboard | `status` / `ss` | Jira + Git status summary |
| Submit PR/MR | `submit` / `su` | Push and create PR (GitHub) or MR (GitLab); draft, labels; Jira HTML → Markdown |
| PR comment | `pr:comment` / `pc` | Comment on current branch’s PR/MR (stdin or argument) |

### Release & Maintenance

| Feature | Command(s) | Notes |
|--------|------------|--------|
| Release branch | `release` / `rl` | Create release branch, bump version (explicit or `--major`/`--minor`/`--patch`), optional `--publish` |
| Deploy | `deploy` / `mep` | Merge release into main, tag, update develop; optional `--clean` for branch cleanup |
| Self-update | `update` / `up` | Download latest release; `--info` for changelog preview |
| Cache clear | `cache:clear` / `cc` | Clear update-check cache |
| Help | `help [command]` | Command-specific help from docs |

### Integrations & Constraints

- **Jira:** Read-only by design (except assignment/transition in `items:start` / `items:takeover` / `items:transition`). No comments, no editing of issues from the CLI.
- **Git providers:** GitHub and GitLab (including self-hosted GitLab via `GITLAB_INSTANCE_URL`).
- **i18n:** Multiple languages for user-facing messages.

---

## Potential Gaps and DX Improvements

### Configuration & Transparency

- **Config inspection:** No dedicated `config:show` or `config:validate` command. Users must inspect `~/.config/stud/config.yml` and `.git/stud.config` manually. A read-only summary (e.g. which provider, which keys are set, no secrets) could improve debugging and onboarding.
- **Config path / env:** Documenting or supporting a custom config path (e.g. env var) could help power users and scripting.

### Jira Workflow

- **Time tracking:** No log-work or time-tracking from the CLI. Fits “read-only” philosophy but is a common Jira need; could be a future, optional write operation.
- **Comments:** No “add comment to Jira issue” from CLI. Aligns with current scope; if scope ever expands, this would be a natural addition.
- **Attachments / links:** Not in scope today; likely out of scope for a lean CLI but worth noting for feature requests.
- **Bulk operations:** No bulk transition or bulk assign. Could reduce repetitive work for leads; would need clear UX (e.g. from filter, confirm list).

### Git & Repository

- **Base branch configuration:** Base branch is configurable (e.g. in project config) but ensuring it’s discoverable (e.g. in `status` or `config:show`) improves predictability.
- **Diff / preview before submit:** No built-in “preview diff that will go into the PR” or “preview PR body” before `stud submit`. Power users can use git/gh separately; a lightweight preview could improve confidence.
- **More Git hosts:** Bitbucket (and possibly others) are not supported. Current design (GitProviderInterface) makes adding providers feasible if there is demand.

### Quality of Life

- **Alias consistency:** Most commands have short aliases; a quick reference in README or `stud help` (e.g. “Common aliases”) could speed adoption.
- **Offline / degraded behavior:** When Jira or Git provider is unreachable, behavior is documented in troubleshooting; explicit “offline mode” or clearer error guidance could help in constrained environments.
- **Scripting / CI:** Non-interactive behavior is documented (e.g. `--quiet`, `--message`, `--all`). A short “Scripting and CI” section in README could gather best practices (e.g. `stud commit -m "..."`, `stud submit --draft`).

### Documentation & Discoverability

- **ADR index:** README and CONVENTIONS reference ADRs; a one-line “Full ADR index: documentation/” or a simple list in CONTRIBUTING could help new contributors.
- **Videos / tutorials:** Out of scope for this evaluation; could be linked from README if the community creates them.

---

## Summary

stud-cli already covers the core “golden path”: configure once, list/view Jira work, start/takeover branches, conventional commits, flatten, submit PR/MR, comment, and release/deploy. The main intentional limits are Jira write operations (except assignment/transition) and support for GitHub + GitLab only.

The most impactful potential additions from a DX perspective are:

1. **Config visibility** – e.g. `config:show` / `config:validate` (read-only, no secrets).
2. **Bulk Jira operations** – e.g. bulk transition or assign from a filter (if product scope allows).
3. **Submit preview** – e.g. “what will be in the PR” or “PR body preview” before `stud submit`.
4. **Scripting/CI section** – centralize non-interactive usage in README.
5. **Optional time tracking** – only if the project decides to allow limited Jira writes.

None of these are mandatory; they are options to prioritize based on user feedback and product goals.

---

## Detailed feature evaluations

A structured **brainstorm and evaluation** of five candidate features (config:show, config:test, commit:undo, pr:comments, Scripting & CI in README) with feasibility, ROI, complexity/risk, and GO/NOGO recommendations is in **[FEATURE_BRAINSTORM_2026.md](FEATURE_BRAINSTORM_2026.md)**.
