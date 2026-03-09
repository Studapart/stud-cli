## Story: Create Jira work items from stud-cli (items:create)

Issue Type: Story

Title: Story: Add items:create command to create Jira issues with project-scoped default and proactive custom-field handling

### User Story

As a: **product team member or developer** prototyping with Cursor/Claude  
I want to: **create real Jira work items from the CLI in a specific Jira project**  
So that I can: **track work in Jira without leaving the terminal, with an optional default project per repo.**

### Description & Implementation Logic

Add a new command `stud items:create` (alias `ic`) that creates a Jira issue via the Jira Cloud REST API (`POST /rest/api/3/issue`). The command uses options for project, issue type, summary, and description; missing values are prompted in interactive mode. Description supports STDIN-then-`-d` precedence (same as `stud pr:comment`). Default Jira project is read from **project config only** (`.git/stud.config`, e.g. `JIRA_DEFAULT_PROJECT`). Before creating, the implementation calls createmeta for the chosen (project, issue type); if createmeta reports **required fields beyond** project, issuetype, summary, and description, the command **falls back to interactive** (prompt or clear message) and does not create with CLI-only input. No `--quiet` option. Follows ADR-005 (Handler → Response → Responder) and CONVENTIONS.md.

**Implementation steps:**

1. **Jira API – createmeta and create issue**
   - In `JiraService`, add:
     - `getCreateMetaIssueTypes(string $projectIdOrKey): array` — `GET /rest/api/3/issue/createmeta/{projectIdOrKey}/issuetypes`.
     - `getCreateMetaFields(string $projectIdOrKey, string $issueTypeId): array` — `GET /rest/api/3/issue/createmeta/{projectIdOrKey}/issuetypes/{issueTypeId}`; parse response to identify which fields are **required**.
     - `createIssue(array $fields): array` — `POST /rest/api/3/issue` with `fields` (project.key, issuetype id or name, summary; description in ADF if provided). Return created issue key/link (or throw on 4xx with clear message).
   - Build minimal ADF for description when user provides plain text (e.g. single paragraph). Reuse or extend existing conversion utilities where applicable; do not duplicate HTML→Markdown logic.

2. **Required-field detection and interactive fallback**
   - After resolving project and issue type (from options or prompts), call `getCreateMetaFields(project, issueTypeId)`.
   - From the response, compute the set of **required** field IDs/names. If any required field is **not** one of: project, issuetype, summary, description (or standard fields the CLI always sets), treat as “extra required fields”.
   - When extra required fields exist: **do not** call `createIssue` with only CLI-provided values. Instead: in interactive mode, prompt for those fields (or show a single clear message: “This project/issue type has required custom fields; please provide them when prompted”) and then create; in non-interactive mode, fail with a message that the user must run the command interactively to supply custom required fields.
   - When only standard required fields exist, proceed with create using options/prompted values.

3. **Project config – default project (project scope only)**
   - Define a project-level config key, e.g. `JIRA_DEFAULT_PROJECT`, in `.git/stud.config` only. **No** global config key for default project.
   - When `--project` / `-p` is not provided, read project config via `GitRepository::readProjectConfig()`; if `JIRA_DEFAULT_PROJECT` is present and non-empty, use it as the project key; otherwise prompt (interactive) or fail with a clear message (non-interactive). Validate that the project exists and the user has access (e.g. via existing `getProjects()` or createmeta) before using it.
   - Document the key in README and in `stud help items:create`. Do **not** add a config migration for this new optional key.

4. **Command signature and handler**
   - In `castor.php`, register task `items:create` with alias `ic`. Options (and aliases): `--project`/`-p`, `--type`/`-t` (default `Story`), `--summary`/`-m`, `--description`/`-d`. All optional from CLI perspective; missing values trigger prompts in interactive mode.
   - Implement `ItemCreateHandler`: resolve project (option or config default or prompt), issue type (option or default `Story` or prompt), summary (option or prompt), description (STDIN then `-d`, then optional prompt). Call createmeta; if extra required fields, follow step 2; otherwise build payload and call `JiraService::createIssue`. Return a Response DTO (e.g. created issue key, link, success).
   - **Description input:** Same behaviour as `pr:comment`: read STDIN first (non-blocking, only when not TTY); if non-empty use as description; else use `--description`/`-d` if provided; else in interactive mode optionally prompt. Do not add `--quiet` to this command.

5. **Responder and output**
   - Add a Responder for the create response (ADR-005): render success with issue key and Jira link (e.g. `{JIRA_URL}/browse/{key}`). Use existing output conventions (e.g. `$io->success()`). Wire Handler → Responder in the task.

6. **Help and documentation**
   - Add `items:create` to `HelpService` (command list and help text). Document options, default type `Story`, default project from project config only, STDIN/`-d` for description (same as pr:comment), and that custom required fields force interactive mode.
   - README: new subsection under Jira or Git workflow describing `stud items:create` / `stud ic`, options, default project (project config only), and link to `stud help items:create`. Update “Jira scope” wording to mention that stud-cli can create issues in a configured project.
   - CHANGELOG: add entry under `[Unreleased]` (e.g. under `### Added`) for the new command.

### Assumptions and Constraints

- **Jira Cloud:** Implementation targets Jira Cloud REST API v3 (`/rest/api/3/...`). Same auth as today (existing Jira API token); user must have Create issues permission in the target project.
- **Default project:** Project scope only (`.git/stud.config`). No global default for this feature.
- **Description format:** Jira Cloud expects ADF for `description`; implementation converts plain text from STDIN/`-d` to minimal ADF (e.g. one paragraph).
- **No quiet mode:** `items:create` does not support `--quiet`. Behaviour is either full CLI args + STDIN or interactive prompting (or fallback to interactive when custom required fields exist).
- **Backward compatibility:** New config key is additive; no migration. Existing behaviour unchanged.

### Acceptance Criteria

- [ ] User can run `stud items:create` or `stud ic` with options `--project`/`-p`, `--type`/`-t` (default `Story`), `--summary`/`-m`, `--description`/`-d`; missing values are prompted in interactive mode.
- [ ] Description is taken from STDIN first, then from `--description`/`-d` (same precedence as `pr:comment`); description is optional.
- [ ] Default project is read from project config only (e.g. `JIRA_DEFAULT_PROJECT` in `.git/stud.config`) when `--project` is not set; no global default; behaviour when default is missing is documented and either prompts or fails with a clear message.
- [ ] Before create, createmeta is called for (project, issue type). If createmeta reports required fields beyond project, issuetype, summary, description, the command does not create with CLI-only input and falls back to interactive (prompt or clear message); in non-interactive runs it fails with a message to run interactively for custom required fields.
- [ ] New command follows `object:verb` naming (`items:create`), alias `ic`, and ADR-005 (Handler → Response → Responder). No `--quiet` option.
- [ ] README and help updated (options, default project, description input, custom required fields). Jira scope wording updated to mention create-issue capability.
- [ ] Add/update unit tests (using PHPUnit) for JiraService createmeta and createIssue (mocked HTTP), and for ItemCreateHandler (mocked services, STDIN/options/precedence, createmeta fallback logic). No real Jira calls in unit tests.
- [ ] Code complies with CONVENTIONS.md (visibility, no final on injectables, test intent not text, mocks for services).
- [ ] CHANGELOG.md updated under `[Unreleased]` for the new command.