# Flow: `stud items:create` / `stud ic`

## 1. Entry (castor.php)

- **Task:** `items:create` (alias `ic`).
- **Options:** `--project`/`-p`, `--type`/`-t`, `--summary`/`-m`, `--description`/`-d`.
- **Interactive flag:** `$interactive = function_exists('posix_isatty') && @posix_isatty(STDIN)`  
  → `true` when stdin is a TTY (e.g. running in a terminal), `false` when piped or non-TTY.
- Handler is called with: `handle(io(), $interactive, $project, $type, $summary, $description)`.

## 2. Handler flow (ItemCreateHandler::handle)

| Step | What happens | When prompts run |
|------|----------------|------------------|
| 1 | **Resolve project** (`resolveProjectKey`) | If no `-p` and no `JIRA_DEFAULT_PROJECT` in `.git/stud.config`: in **interactive** mode only, prompts for project. Otherwise fails with “no project”. |
| 2 | **Ensure project exists** (`ensureProjectExists`) | Calls Jira `getProject(key)`. If project not found: in **interactive** mode only, prompts once for a new key and retries. Otherwise fails with “project not found”. |
| 3 | **Resolve type** | Uses `-t`/`--type` if non-empty, else default `"Story"`. **No prompt.** |
| 4 | **Resolve summary** (`resolveSummary`) | If `-m`/`--summary` provided (non-empty), use it. Else: in **interactive** mode only, prompts for summary. Otherwise fails with “no summary”. **No prompt in non-interactive.** |
| 5 | **Resolve description** (`getDescription`) | 1) STDIN (if not TTY and has content), 2) else `-d`/`--description`, 3) else `null`. **There is no interactive prompt for description** when it’s missing; description stays optional. |
| 6 | **Issue type id** (`resolveIssueTypeId`) | Createmeta for project → find issue type by name. Fails with “Issue type X not found” if missing. No prompt. |
| 7 | **Required fields** (from createmeta) | Createmeta for (project, issue type) → list required field ids. Fails with “Could not fetch field metadata” on error. No prompt. |
| 8 | **Extra required fields** | If any required field is not in `project` / `issuetype` / `summary` / `description`: **only in interactive mode**, `promptForExtraRequiredFields` runs (prompts for each). In non-interactive, fails with “run interactively for extra required fields”. |
| 9 | **Create issue** | `JiraService::createIssue($fields)`. On non-201, throws `ApiException` with message “Could not create issue.” and technical details (response body). |
| 10 | **Response** | Success → ItemCreateResponder (key + browse URL). Failure → ErrorResponder shows `item.create.error_create` with `%error%` = exception message. |

## 3. When you see “Could not create issue: Could not create issue.”

- Jira’s create-issue API returned a status other than 201 (e.g. 400).
- The handler only shows the exception **message** (`getMessage()`), which is the generic “Could not create issue.” The **real reason** (e.g. required field missing, invalid value) is in `ApiException::getTechnicalDetails()` and is not shown today, so the message looks duplicated and unhelpful.

## 4. Why no interactive prompt for description?

- By design, **description is optional** and is only taken from:
  1. STDIN (when not a TTY and there is input), or  
  2. `-d` / `--description`.
- There is **no** `$io->ask(...)` for description in the handler when it’s missing. So even in interactive mode, if you don’t pass `-d` and don’t pipe stdin, description stays `null` and the issue is created without a description; no prompt is triggered.

## 5. Summary for your run

- Command: `stud ic --project "SDA" --type "Task" -m "Update AI Prompts..."` (no `-d`, no stdin).
- **Interactive:** Depends on TTY. If you ran this in a normal terminal, `$interactive === true`.
- **Project/summary:** Resolved from options; no prompt needed.
- **Description:** Not provided → `null`; **no description prompt** exists, so none was shown.
- **Error:** Create-issue API failed (non-201). You only see “Could not create issue: Could not create issue.” because the handler does not surface `getTechnicalDetails()`.

Next step: surface Jira’s error (e.g. append or use `getTechnicalDetails()` for ApiException) so the message shows why the create failed.
