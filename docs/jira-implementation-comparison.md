# Jira implementation comparison: stud-cli vs lesstif/php-JiraCloud-RESTAPI

Comparison of our in-house Jira integration with [lesstif/php-JiraCloud-RESTAPI](https://github.com/lesstif/php-JiraCloud-RESTAPI) (PHP ^8.1). We cannot use that package as-is (PHP 8.2+ and no new dependency on their stack); this document lists **differences** and **opportunities** to improve our implementation, then a **checklist plan**.

---

## 1. Differences and opportunities (bullet list)

### Architecture & dependencies

- **They**: Full Jira Cloud REST client library; PHP 8.1+, JsonMapper, phpdotenv, [adf-tools](https://github.com/DamienHarper/adf-tools) for rich ADF. **We**: Minimal integration inside stud-cli; PHP 8.2, Symfony HttpClient, no Jira-specific libs.
- **Opportunity**: Keep our “no extra Jira lib” approach but adopt patterns (e.g. fluent issue payload builder, or small ADF builder) where they simplify our code without adding a dependency.

### Authentication & configuration

- **They**: Dotenv or array config; optional proxy (server, port, user, password); optional session cookie auth with cookie file. **We**: Config from `.git/stud.config` (or global); Basic auth (email + API token) only; no proxy, no cookie auth.
- **Decision**: Current auth and config work well; no change for now.

### Issue create

- **They**: Fluent `IssueField` API: `setProjectKey`, `setSummary`, `setAssigneeNameAsString`, `setPriorityNameAsString`, `setIssueTypeAsString`, `setDescription(ADF)`, `addVersionAsString`, `addComponentsAsArray`, `setSecurityId`, `setDueDateAsString`, `addCustomField(id, value)`. **We**: Build `fields` array from createmeta; we set project, issuetype, summary, description, reporter (and custom fields via createmeta); we do not set assignee, priority, components, versions, due date, or security at create time.
- **Opportunity**: Extend `stud ic` with optional fields similar to theirs (priority, component, fix-version, etc.); **assignee = current user** (same as reporter) when the field is required or requested, no separate assignee option. Optionally introduce a small internal “issue payload builder” for clarity.

### ADF (description / comments)

- **They**: Rich ADF via adf-tools: headings, paragraph, strong, em, underline, codeblock. **We**: `JiraAdfHelper::plainTextToAdf()` only – paragraphs and plain text.
- **Opportunity**: (1) Optional Markdown → ADF conversion (e.g. headings, bold, code blocks) for description and future comment support, either in-house or via a lightweight library compatible with PHP 8.2. (2) Keep plain-text→ADF as default for STDIN/paste.

### Create issue – custom fields

- **They**: `addCustomField('customfield_10100', value)` with examples for string, single-select `['value' => 'x']`, multi-select `[['value' => 'a'], ['value' => 'b']]`. **We**: Createmeta-driven; we only send fields we know (standard by name + extra required from prompt); we don’t yet support optional custom fields from CLI.
- **Opportunity**: Allow optional `--custom-field id=value` (or similar) on `stud ic` and merge into the create payload; document supported value shapes (string, single/multi select) as we add them.

### Transitions

- **They**: Transition by **status name** (e.g. “In Progress”) via `setTransitionName('In Progress')` (and untranslated name for localized Jira). **We**: Transition by **transition ID** only; we fetch transitions and let user pick (or use cached ID).
- **Opportunity**: Add “transition by status name” in `JiraService`: resolve transition ID from `getTransitions()` by matching `to.name` (and optionally `to.statusCategory`), then call existing `transitionIssue(key, id)`. This improves scripting and matches how many users think (“move to In Progress”).

### Sub-tasks and bulk create

- **They**: Create sub-task with `setIssueTypeAsString('Sub-task')` and `setParentKeyOrId($issueKey)`; bulk create with `createMultiple([...])`. **We**: No sub-task or bulk create.
- **Decision**: Add sub-task create with `--parent ISSUE-KEY` (and issue type Sub-task where required). **No bulk creation** — not needed.

### Attachments, worklog, watchers, notify

- **They**: Add attachments, add/edit/get worklog, add/remove watchers, send notification. **We**: None of these.
- **Opportunity**: Low priority for a CLI focused on branch/workflow; add only if product asks (e.g. `stud ic --attach file` or worklog for time tracking).

### Update issue

- **They**: Update issue (assignee, priority, labels, fix versions, description, etc.); dedicated helpers for `updateLabels`, `updateFixVersions`. **We**: No generic issue update; we have assign (assignee) only.
- **Opportunity**: Optional `stud item update <key>` (or similar) for assignee, labels, fix versions, or description when needed for workflow automation.

### Comments

- **They**: Add/get/delete/update comment with ADF body. **We**: No Jira comment API.
- **Opportunity**: `stud item comment <key> [text]` or pipe from STDIN (reusing our ADF and description logic) for quick comments from the CLI.

### Search / JQL

- **They**: JQL search with pagination; optional JqlQuery builder. **We**: JQL search (POST `/rest/api/3/search/jql`) with fixed field list; no pagination exposed.
- **Opportunity**: Add pagination (startAt, maxResults) to `searchIssues()` and expose in `stud search` when result sets are large.

### Other APIs (project, field, user, version, board, epic)

- **They**: Create/update/delete project; get/create custom fields; user (create, get, find, assignable); group; priority; version CRUD; components; board; epic. **We**: Only get project(s), get filters, get createmeta (issuetypes + fields).
- **Opportunity**: For stud-cli, most of these are out of scope; we could add “get field list” (e.g. for debugging or future custom-field UX) if useful.

### Error handling and logging

- **They**: Optional request/response logging (file, level). **We**: No Jira request logging; we expose API error details in user-facing messages via `ApiException` and `extractTechnicalDetails`.
- **Opportunity**: Optional debug mode (e.g. env or flag) that logs Jira request URL and response status/body to stderr or a log file, without adding a logging dependency.

---

## 2. Dependency decisions: adf-tools and JsonMapper

### adf-tools (DamienHarper/adf-tools)

- **What it is**: A PHP library to **build** ADF documents with a fluent API (e.g. `(new Document())->paragraph()->text('x')->end()`; headings, bold, code blocks, bullet lists, etc.). It does **not** parse HTML or Markdown; it only produces ADF JSON from method calls.
- **Compatibility**: `php: ">=7.4"`, `ext-json` only in `require`. No Symfony in production (only `symfony/var-dumper` in `require-dev`). **Compatible with PHP 8.2 and our stack.**
- **Our use case**: We currently build ADF only from plain text (paragraphs) in `JiraAdfHelper`. Our “parsing” is Jira **HTML** → plain text (already handled by `JiraHtmlConverter`). adf-tools would help when we add **Markdown → ADF**: we would parse Markdown (e.g. with league/commonmark or a small parser), then drive adf-tools’ `Document()` to build rich ADF (headings, **bold**, `code`, lists) instead of hand-rolling every node type.
- **Recommendation**: **Consider adding** `damienharper/adf-tools` when we implement Markdown → ADF for description (and later comments). It keeps ADF structure correct and reduces maintenance. Plain-text → ADF can stay as-is (our minimal helper) for the default no-Markdown path.

### JsonMapper (netresearch/jsonmapper)

- **What it is**: Maps JSON arrays onto PHP class properties using docblocks/attributes. Used by lesstif to map Jira API responses to DTOs.
- **Compatibility**: PHP >=7.1; **compatible with PHP 8.2**. No Symfony dependency.
- **Our use case**: We map Jira responses manually in `JiraService` (`mapToWorkItem`, `mapToProject`, `mapToFilter`). Our DTOs are few and simple.
- **Recommendation**: **Optional.** Our manual mapping is clear and we have few response types. JsonMapper would reduce boilerplate if we add many more Jira endpoints; for current scope, skipping it is fine.

---

## 3. Checklist plan to improve our implementation

Use this as a prioritized backlog; each item can be a separate task/ADR.

### High value (workflow and UX)

- [ ] **Transitions by status name**  
  In `JiraService`, add a method that accepts issue key and target status name (e.g. "In Progress"), fetches transitions, finds the transition whose `to.name` matches, and calls `transitionIssue(key, transitionId)`. Use it from `ItemTransitionHandler` / `ItemStartHandler` where “move to In Progress” is the default, and keep transition-ID path for backward compatibility.

- [ ] **Optional create-time fields**  
  Extend `stud ic` with optional flags: `--assignee`, `--priority`, `--component`, `--fix-version` (and possibly `--due-date`). Resolve IDs/keys from createmeta or existing Jira APIs and merge into the create payload. Document in README.

- [ ] **Optional custom fields on create**  
  Add `--custom-field` (or similar) to `stud ic` (e.g. `customfield_10001=value`) and merge into `fields`; support at least string and single-select value shape. Document and add tests.

- [ ] **JQL search pagination**  
  Add `startAt`/`maxResults` to `JiraService::searchIssues()` and expose in `stud search` (e.g. `--max N`, `--page`) so large result sets are usable.

### Medium value (quality and maintainability)

- [ ] **Markdown → ADF for description**  
  Optionally support Markdown input for description (headings, **bold**, `code`) and convert to ADF. Consider adding `damienharper/adf-tools` to build the ADF tree from parsed Markdown (e.g. league/commonmark); keep plain-text→ADF as default. Document when Markdown is used (e.g. `--description-format=markdown` or auto-detect).

- [ ] **Internal issue payload builder**  
  If create logic grows (assignee, priority, components, versions, custom fields), refactor to a small internal builder that produces the `fields` array from options + createmeta, and keep `JiraService::createIssue($fields)` as a thin wrapper over POST.

- [ ] **Optional debug logging for Jira**  
  When a debug flag or env is set, log Jira request method/URL and response status/body (truncated) to stderr or a log file. No new dependency; helps support and debugging.

### Lower priority (only if product needs them)

- [ ] **Sub-task create**  
  `stud ic --parent ISSUE-KEY`: when `--parent` is provided, create a sub-task (issue type Sub-task where required; set parent key in payload). No bulk create.

- [ ] **Issue comment command**  
  `stud item comment <key>` with optional text or STDIN, reusing ADF and description logic; POST to issue comment API.

- [ ] **Issue update command**  
  `stud item update <key>` for assignee, labels, fix versions, or description when needed for automation.

- [ ] **Proxy support**  
  If users need it, allow proxy configuration (e.g. in stud config) and pass through Symfony HttpClient options.

---

## 4. Out of scope (for now)

- Using lesstif/php-JiraCloud-RESTAPI as a dependency (PHP and scope mismatch). adf-tools may be added for Markdown→ADF (see §2).
- Project/admin APIs (create/update/delete project, create custom field, user/group management).
- Attachments, worklog, watchers, notify, remote issue links, time tracking, boards/epics (unless product requests them).
- Cookie-based session auth (we stay with Basic + API token).

---

*References: [lesstif/php-JiraCloud-RESTAPI](https://github.com/lesstif/php-JiraCloud-RESTAPI), [Jira Cloud REST API v3](https://developer.atlassian.com/cloud/jira/platform/rest/v3/), stud-cli `src/Service/JiraService.php`, `src/Service/JiraAdfHelper.php`, `src/Handler/ItemCreateHandler.php`.*
