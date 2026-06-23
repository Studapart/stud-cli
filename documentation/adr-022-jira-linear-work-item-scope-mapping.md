# ADR-022: Jira and Linear work-item scope mapping

* **Status:** Accepted
* **Date:** 2026-06-16
* **Authors:** stud-cli maintainers
* **Technical Context:** SCI-143 multi-provider epic, Linear GraphQL API, `.git/stud.config`, SCI-182 dual-provider resolution

## 1. Context and Problem Statement

stud-cli is git-repository-centric: branch names, `.git/stud.config`, and issue-key prefixes assume a **single primary scope** per repo. With Linear support alongside Jira, we must decide which Linear entity corresponds to Jira **Project** (today’s `projectKey`) and which Linear concepts are **out of scope** for repo config and `items:*` commands.

Linear’s product model differs from Jira’s:

| Linear | Role (product) |
|--------|----------------|
| **Initiative** | Manually curated list of projects aligned with organizational goals; leadership progress tracking |
| **Team** | Owns issues, workflow states, labels, cycles; issues belong to exactly one team |
| **Project** | Outcome-bound unit of work (launch, feature) comprising issues and optional documents; many-to-many with teams |

Jira often maps **one project ≈ one repository**. Linear teams can span many repositories; Initiatives and Projects span many teams. That asymmetry is acceptable for stud-cli’s developer workflow but must be documented so config and commands stay predictable.

## 2. Decision Drivers & Constraints

* Issue identifiers drive branch regex, `items:start`, and dual-provider `auto` resolution (`TEAM-123` / `SCI-123`).
* Linear `issueCreate` requires a **team** id; workflow states and label groups are queried at **team** level.
* stud-cli targets engineers working in a repo, not portfolio or steering workflows.
* Dual Jira + Linear during migration must remain explicit (`projectKey`, `linearTeamKey`, `workItemProvider`) — no silent aliasing across hierarchy levels.

## 3. Considered Options

* **Option A — Linear Team ≈ Jira Project**  
  Repo config anchors on `linearTeamKey`; `projects:list` lists teams; discovery commands use `team(id: $key)`.

* **Option B — Linear Project ≈ Jira Project**  
  Anchor on Linear Project id/key. Rejected: no issue-key prefix; projects are M2M with teams; create/list APIs are team-scoped.

* **Option C — Linear Initiative ≈ Jira Project**  
  Anchor on Initiative. Rejected: no issue keys, no create scope, wrong audience for git-centric CLI.

* **Option D — Unify Jira and Linear metadata into one schema**  
  Single config shape for workflow/labels across providers. Rejected: different APIs (transitions vs states + label groups); YAGNI for migration period.

## 4. Decision Outcome

**Chosen Option:** Option A — **Linear Team is the stud-cli scope equivalent of Jira Project.**

### Entity mapping (conceptual, not 1:1 product parity)

| Jira | Linear (stud-cli) | Notes |
|------|-------------------|--------|
| Project (`projectKey`) | **Team** (`linearTeamKey`) | Primary repo scope; issue prefix |
| Epic (issue type / container) | **Project** (Linear entity) | Similar *planning* role only; **not** configured in stud-cli |
| Goals / Advanced Roadmaps | **Initiative** | Leadership / OKR-style tracking; **out of scope** |

Linear **Project** is closer to a bounded release or program (issues + docs, optional completion date) than to a Jira Project. Linear **Initiative** aligns with organizational goals (curated project lists), not with day-to-day `stud start` / branch workflows.

### In scope

* `projectKey` (Jira) and `linearTeamKey` (Linear) in `.git/stud.config`
* `workItemProvider`: `jira` \| `linear` \| `auto` (SCI-182)
* `projects:list` for Linear → **teams** query (team key + name)
* `projects:workflow`, `projects:labels` → team-level metadata
* Provider inference from issue-key prefix when `auto` is set

### Out of scope (unless a future ADR says otherwise)

* Linear **Initiatives** — no repo config key, no commands
* Linear **Projects** as primary scope — no `linearProjectKey`; optional future filters on `items:list` only
* Portfolio / cross-team reporting for non-developer Linear users

### Sub-teams

Linear sub-teams are **full teams** in the API with a **parent team** hierarchy (org structure). They are **not** the same as issue `parentId` (sub-issues).

* Each team (including sub-teams) has its own **team key** and issue identifier prefix.
* Issues belong to **exactly one team** — the team they were created on, not the parent team abstractly.
* Sub-teams may **inherit** cycles, statuses, and labels from the parent; inheritance does not merge issue namespaces.

**stud-cli does not resolve parent team keys on behalf of sub-teams.** Configure `linearTeamKey` (and use `--project` on discovery commands) with the **team that actually owns the issues** — typically the leaf sub-team when work lives there. Parent team key will not transparently address child-team issues.

### Git repository limitation (accepted)

| Model | Typical repo fit |
|-------|------------------|
| Jira | One project key per repo — strong 1:1 |
| Linear | One **primary team** per repo in config; one team may own many repos; Initiatives/Projects cut across teams |

This is a **documented limitation** of the development context, not a bug. Users who only use Linear for steering or initiative tracking are unlikely to use stud-cli; engineers keep a single owning team (or sub-team) per repository.

### Dual-provider migration

When both providers are configured:

```yaml
workItemProvider: auto   # jira | linear | auto
projectKey: SCI          # Jira
linearTeamKey: ENG       # Linear — may differ from projectKey
```

Resolution order and ambiguous-prefix errors are defined in SCI-182. Distinct prefixes are required for reliable `auto` mode.

## 5. Consequences

| Aspect | Result |
|--------|--------|
| Jira parity | (+) Existing `projectKey` behavior unchanged |
| Linear onboarding | (+) Clear rule: set team key, not Initiative or Linear Project |
| Migration | (+) Dual keys + `auto` support trial and gradual cutover |
| Multi-repo teams | (−) Linear team spanning repos needs consistent `linearTeamKey` per repo or explicit `--provider` / keys |
| Sub-teams | (−) Must configure actual owning team key; no parent-key transparency |
| Linear Projects / Initiatives | (−) Not represented in config; M2M team relationships unused by stud-cli |
| Future work | (○) Optional `items:list` filter by Linear Project id; no change to scope anchor |

## 6. References

* Linear product docs (paraphrased): Initiatives = goal-aligned project lists; Teams own issues; Projects = outcome-bound work M2M with teams.
* Epic: `.cursor/specs/SCI-143/TECH_SPEC.md`
* Dual provider: `.cursor/specs/SCI-182/TECH_SPEC.md`
* Linear list/discovery: `.cursor/specs/SCI-164/TECH_SPEC.md`, SCI-155, SCI-156
