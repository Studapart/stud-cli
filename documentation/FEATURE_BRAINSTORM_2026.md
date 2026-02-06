# Feature Brainstorm & Evaluation (2026)

This document evaluates five candidate features against **feasibility**, **return on investment (ROI)**, **complexity/risk**, and recommends **GO** or **NOGO** for each. It is intended to support prioritisation and roadmap decisions.

---

## 1. `stud config:show` (secrets redacted)

**Description:** Display current configuration (global and, when in a repo, project) with all secrets redacted so users can verify setup or share output for support without exposing credentials.

### A. Feasibility

| Aspect | Assessment |
|--------|------------|
| **Data source** | Config already loaded from `~/.config/stud/config.yml` and `.git/stud.config`; same paths used by existing config pass and migrations. |
| **Redaction** | Straightforward: allowlist “safe” keys (e.g. `JIRA_URL`, `LANGUAGE`, `GIT_PROVIDER`, `baseBranch`) and redact any key that might contain secrets (`JIRA_API_TOKEN`, `JIRA_EMAIL` if desired, `GIT_TOKEN`, `GITHUB_TOKEN`, `GITLAB_TOKEN`, `GITLAB_INSTANCE_URL` if it contains credentials). Show redacted value as `*** REDACTED ***` or `****`. |
| **Scope** | Read-only; no new external APIs. Optional: show “key present” vs “key missing” for known keys so users see what’s set. |

**Verdict: High feasibility.** Fits existing config loading; no new integrations.

### B. Return on Investment

| Dimension | Assessment |
|----------|------------|
| **Acquisition** | Unlikely to directly attract new users; mainly improves experience for existing users and reduces “why isn’t this working?” friction. |
| **Productivity** | Saves time when debugging (“did my config save?”), onboarding (“what’s in my project config?”), and support (users can paste safe output). |
| **Differentiation** | Common pattern in CLI tools (e.g. `kubectl config view`); aligns stud-cli with expectations. |

**Verdict: Medium ROI.** Strong support/debug value; modest impact on acquisition.

### C. Complexity and Risk

| Dimension | Assessment |
|----------|------------|
| **Implementation** | New command, one handler, read config and output a formatted view (e.g. definition list or table). Define a single list of “secret” keys; redact everything else or allowlist safe keys only. |
| **Risk** | **Leak risk:** If a new secret key is added later and not added to the redaction list, it could be exposed. Mitigation: centralise secret key names (e.g. in a constant or config), and default to “redact if key name contains TOKEN, PASSWORD, SECRET”. |
| **Edge cases** | Jira URL with query params containing tokens: redact query string or entire value if URL is not in “safe” list. Prefer safe default (redact). |

**Verdict: Low complexity, low risk with a clear redaction policy.**

### D. GO / NOGO

**GO.** Low effort, clear value for support and debugging, aligns with [FEATURE_EVALUATION](FEATURE_EVALUATION.md) recommendation. Implement with a centralised list of secret/safe keys and conservative redaction.

---

## 2. `stud config:test` (validate config and connectivity)

**Description:** Validate that configuration is present and that Jira and the Git provider are reachable and accept the configured credentials (e.g. one read call per backend). Output a simple “Jira: OK / Fail”, “Git provider: OK / Fail” (and optionally “Git repo: OK” for remotes).

### A. Feasibility

| Aspect | Assessment |
|--------|------------|
| **Jira** | `JiraService` already has methods that perform API calls (e.g. projects, current user, or a simple “myself” endpoint). One cheap read (e.g. get current user or list projects with maxResults=1) is enough to verify token and connectivity. |
| **Git provider** | `GithubProvider` / `GitLabProvider` can use an existing method (e.g. get repo, or a “test” endpoint). GitHub: e.g. `GET /user` or `GET /repos/:owner/:repo`; GitLab: `GET /user` or project. Both are already used elsewhere. |
| **Definition of “test”** | Agreed contract: one lightweight read per backend; no writes. Timeout and error handling reuse existing HTTP client behaviour. |

**Verdict: High feasibility.** Reuses existing services and one read call per system.

### B. Return on Investment

| Dimension | Assessment |
|----------|------------|
| **Acquisition** | Reduces fear of “did I set it up wrong?” and gives immediate feedback after `config:init`. Can help adoption. |
| **Productivity** | Fast way to answer “is the problem my token or the network?”; reduces back-and-forth in support and in CI/setup verification. |
| **CI / scripting** | Enables “stud config:test” as a health check after deploying/configuring agents or runners. |

**Verdict: Medium–High ROI.** Strong DX and support; useful for scripting and CI.

### C. Complexity and Risk

| Dimension | Assessment |
|----------|------------|
| **Implementation** | New command; handler that (1) loads config (existing path), (2) calls Jira (one method), (3) resolves Git provider and calls one method, (4) formats result (e.g. table or section per component). Optional: `--skip-jira` / `--skip-git` for partial checks. |
| **Risk** | Extra API calls when users run it repeatedly (minimal impact; read-only). Rate limits: one call per backend per run is acceptable. Misleading “OK”: e.g. token has read but not write scope—we only test “can we read?” which matches stud-cli’s Jira read-only design. |

**Verdict: Low–medium complexity, low risk.**

### D. GO / NOGO

**GO.** Clear benefit for onboarding, support, and CI; implementation is bounded and reuses existing code. Consider adding `--skip-jira` / `--skip-git` in a follow-up if needed.

---

## 3. `stud commit:undo` (alias `stud undo`) – remove last commit, keep changes

**Description:** Remove the last commit from the current branch and leave the changes in the working tree (unstaged), so the user can re-edit and recommit without losing work. Equivalent to `git reset HEAD~1` (mixed).

### A. Feasibility

| Aspect | Assessment |
|--------|------------|
| **Git** | `git reset HEAD~1` (mixed) is the standard way to “undo last commit, keep changes”. No Jira or Git provider calls. |
| **Integration** | `GitRepository` already runs git commands; add a method (e.g. `undoLastCommit(): void` or use existing `run()`) that executes the reset. Check: at least one commit exists; optionally that we’re not on a protected branch. |

**Verdict: High feasibility.** Single git command, no new APIs.

### B. Return on Investment

| Dimension | Assessment |
|----------|------------|
| **Acquisition** | Unlikely to be a primary driver; “undo last commit” is a convenience. |
| **Productivity** | Helps users who don’t know or remember `git reset HEAD~1`; keeps workflow inside stud-cli and reinforces “golden path” (commit → undo → recommit). |
| **Safety** | We can add a guard: if the last commit is already pushed, warn or refuse (avoid accidental history rewrite for shared branches). |

**Verdict: Medium ROI.** Nice quality-of-life improvement; strengthens narrative that stud-cli covers the full commit workflow.

### C. Complexity and Risk

| Dimension | Assessment |
|----------|------------|
| **Implementation** | New command + handler; check “in repo”, “has at least one commit”, optionally “HEAD not pushed or warn”. Then `git reset HEAD~1`. |
| **Risk** | **Pushed commit:** If user has already pushed the commit they undo, next push would require force-push. Mitigation: detect “is HEAD pushed?” (e.g. compare with remote); if yes, warn “Last commit is already on remote. Undo will require force-push to update remote. Continue? [y/N]” or refuse. Refusing is safer; warning is more flexible. |

**Verdict: Low complexity; risk is manageable with a simple “already pushed?” check.**

### D. GO / NOGO

**GO.** Simple to implement and fits the Git workflow story. Recommend implementing the “already pushed?” check (warn or refuse) to avoid surprising force-push scenarios.

---

## 4. `stud pr:comments` – fetch and display PR/MR comments (optionally review comments)

**Description:** For the current branch’s PR/MR, fetch comments and display them as a list of sections (author, date, body). Optionally include review (inline) comments.

### A. Feasibility

| Aspect | Assessment |
|--------|------------|
| **GitHub** | Issue comments: `GET /repos/:owner/:repo/issues/:issue_number/comments`. Review comments: `GET /repos/:owner/:repo/pulls/:pull_number/comments`. Both are standard; we already resolve PR by branch in `PrCommentHandler`, `SubmitHandler`, etc. |
| **GitLab** | MR notes: `GET /projects/:id/merge_requests/:mr_iid/notes`. Discussion/threads can be flattened for display. |
| **Abstraction** | `GitProviderInterface` currently has `createComment` but no “list comments”. Add e.g. `getPullRequestComments(int $issueNumber): array` and, if desired, `getPullRequestReviewComments(int $pullNumber): array`. Return a consistent DTO (author, date, body, optionally path/line for review comments). |

**Verdict: Medium–high feasibility.** Both providers support listing comments; interface extension and display logic are straightforward.

### B. Return on Investment

| Dimension | Assessment |
|----------|------------|
| **Acquisition** | Minor; mainly for users who already use PRs and want to stay in the terminal. |
| **Productivity** | High for terminal-centric workflow: see feedback without opening the browser; useful in CI or scripts (“show me comments on this PR”). Review comments are especially valuable (inline feedback). |
| **Consistency** | Complements `pr:comment` (write) with read; completes the “PR conversation” story. |

**Verdict: Medium ROI.** Strong for productivity and workflow consistency; limited impact on acquisition.

### C. Complexity and Risk

| Dimension | Assessment |
|----------|------------|
| **Implementation** | New command; handler that finds PR (reuse existing logic), then calls new provider method(s). Responder: sections per comment (e.g. “— Author @x, Date — Body”). Pagination: if APIs support it, fetch all pages or cap (e.g. last 50). |
| **Review comments** | Yes, include them. Display as a second block (e.g. “Review comments”) with optional file/line so users can locate them. Slightly more work (two API shapes, GitHub vs GitLab) but high value. |
| **Risk** | Rate limits (one request per comment type per run); pagination/cap to avoid huge output. |

**Verdict: Medium complexity (two comment types, two providers); low risk.**

### D. GO / NOGO

**GO.** Worth the moderate effort. **Recommendation:** Implement both issue comments and review comments from the start (or issue comments first, then review comments in the same command) so the command is the single place to “read PR feedback”. Document behaviour (e.g. “Shows issue comments and review comments”) in README and help.

---

## 5. Scripting & CI best practices section in README

**Description:** Add a dedicated subsection to the README that documents non-interactive usage: flags to avoid prompts, exit codes (if we document them), example snippets (e.g. `stud commit -m "..."`, `stud submit --draft`), and guidance for CI (config must be complete; prefer `--quiet` where applicable).

### A. Feasibility

| Aspect | Assessment |
|--------|------------|
| **Content** | Pure documentation; no code changes. Gather existing flags (e.g. `--quiet`, `-m`, `--all`, `--draft`, `--labels`) and any exit-code guarantees; add short examples and a “Scripting & CI” subsection under Usage or a new top-level section. |

**Verdict: High feasibility.** Documentation only.

### B. Return on Investment

| Dimension | Assessment |
|----------|------------|
| **Acquisition** | Helps teams that automate (CI, bots, scripts); can be a deciding factor for “can we use stud in our pipeline?”. |
| **Productivity** | Reduces trial-and-error and support questions (“why does it hang in CI?” → “use non-interactive flags and ensure config is set”). |
| **Cost** | Zero implementation cost; only writing and maintenance. |

**Verdict: Medium ROI with zero implementation cost.**

### C. Complexity and Risk

| Dimension | Assessment |
|----------|------------|
| **Effort** | One README subsection; keep it concise (bullet list + 2–3 examples). |
| **Risk** | None. Slight maintenance if we add new flags (document them in the same place). |

**Verdict: No complexity or risk.**

### D. GO / NOGO

**GO.** No downside; quick win. Improves clarity for automation and CI users and supports the “scripting” gap identified in [FEATURE_EVALUATION](FEATURE_EVALUATION.md).

---

## Summary Table

| Feature | Feasibility | ROI | Complexity/Risk | Recommendation |
|---------|-------------|-----|------------------|----------------|
| **1. config:show** | High | Medium | Low | **GO** |
| **2. config:test** | High | Medium–High | Low–Medium | **GO** |
| **3. commit:undo** | High | Medium | Low | **GO** (with “already pushed?” guard) |
| **4. pr:comments** | Medium–High | Medium | Medium | **GO** (include review comments) |
| **5. Scripting & CI (README)** | High | Medium | None | **GO** |

**No NOGOs.** All five are feasible and provide net value; prioritisation can be driven by effort (5 cheapest, then 1 and 3, then 2, then 4) and product focus (config visibility vs. Git workflow vs. PR read experience).

---

*Document created for roadmap and prioritisation. Implementation decisions remain with the maintainers.*
