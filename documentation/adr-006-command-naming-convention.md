# [ADR-006] Command Naming Convention: object:verb Pattern

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Castor Framework, Symfony Console

## 1. Context and Problem Statement

**The Pain Point:** CLI tools often use inconsistent command naming conventions, making it difficult for users to discover and remember commands. Common issues:
- **Verb-first patterns** (`list-items`, `show-item`) are less intuitive
- **Noun-first patterns** (`items-list`, `item-show`) are more natural but inconsistent
- **No clear grouping** of related commands
- **Hard to discover** related functionality

**The Goal:** Establish a consistent, intuitive command naming convention that:
- Groups related commands logically
- Makes command discovery easier
- Follows natural language patterns
- Supports aliases for common operations

## 2. Decision Drivers & Constraints

* **User Experience:** Commands should be intuitive and easy to remember
* **Discoverability:** Related commands should be grouped together
* **Consistency:** All commands should follow the same pattern
* **Natural Language:** Commands should read naturally
* **Castor Framework:** Must work with Castor's task naming system
* **Alias Support:** Short aliases needed for frequently used commands

## 3. Considered Options

* **Option 1:** Verb-first pattern (`list-items`, `show-item`, `create-branch`)
  * Pros: Action-oriented, common in some CLI tools
  * Cons: Less intuitive, harder to group related commands

* **Option 2:** Noun-first pattern (`items-list`, `item-show`, `branch-create`)
  * Pros: Groups related commands, more natural
  * Cons: Less action-oriented

* **Option 3:** object:verb pattern (`items:list`, `items:show`, `branch:rename`) (Chosen)
  * Pros: Groups by object, clear action, supports namespaces, intuitive
  * Cons: Requires colon separator (standard in Symfony Console)

* **Option 4:** Flat naming with prefixes (`stud-items-list`, `stud-items-show`)
  * Pros: No separator needed
  * Cons: Verbose, harder to parse, less elegant

## 4. Decision Outcome

**Chosen Option:** `Option 3 - object:verb Pattern`

**Justification:**
We chose the `object:verb` pattern because:
1. **Natural Grouping:** Commands are grouped by the object they operate on (`items:`, `branch:`, `project:`)
2. **Intuitive:** Reads naturally: "items list", "items show", "branch rename"
3. **Discoverability:** Users can type `stud items:` and see all item-related commands
4. **Symfony Standard:** Uses colon separator, standard in Symfony Console
5. **Namespace Support:** Allows logical namespacing without conflicts
6. **Alias Friendly:** Short aliases (`ls`, `sh`, `rn`) work well with this pattern

**Command Categories:**
- **Configuration:** `config:init`, `completion`
- **Jira Information:** `projects:list`, `items:list`, `items:show`, `items:search`, `items:transition`, `filters:list`, `filters:show`
- **Git Workflow:** `items:start`, `items:takeover`, `branch:rename`, `branches:list`, `branches:clean`, `commit`, `please`, `flatten`, `status`, `submit`, `pr:comment`
- **Release:** `release`, `deploy`
- **Utility:** `update`, `cache:clear`, `help`

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **User Experience** | *(+) Intuitive, easy to discover related commands* |
| **Consistency** | *(+) All commands follow same pattern* |
| **Discoverability** | *(+) Tab completion groups commands by object* |
| **Learning Curve** | *(+) Natural language pattern, easy to remember* |
| **Alias Support** | *(+) Short aliases complement full command names* |
| **Verbosity** | *(-) Full command names are longer than single words* |
| **Namespace Conflicts** | *(Neutral) Colon separator prevents conflicts* |

## 6. Implementation Plan

* [x] Establish `object:verb` naming convention
* [x] Group related commands by object (`items:`, `branch:`, `project:`, etc.)
* [x] Use verbs that clearly describe the action (`list`, `show`, `start`, `rename`)
* [x] Create short aliases for frequently used commands
* [x] Document naming convention in README
* [x] Ensure all new commands follow the pattern
* [x] Update help system to reflect grouping

---

### Implementation Details

**Naming Rules:**

1. **Object First:** The noun/object comes first (`items`, `branch`, `project`)
2. **Verb Second:** The action comes after the colon (`list`, `show`, `start`)
3. **Plural Objects:** Use plural for collections (`items:list`, `branches:list`)
4. **Singular Objects:** Use singular for single operations (`item:show` → `items:show` for consistency)
5. **Clear Verbs:** Use unambiguous verbs (`list`, `show`, `start`, `rename`, `clean`)

**Alias Strategy:**
- Short, memorable aliases for frequently used commands
- Aliases are 2-3 characters when possible
- Examples: `ls` (items:list), `sh` (items:show), `rn` (branch:rename), `co` (commit)

**Command Examples:**

```bash
# Jira Information (object: verb)
stud items:list          # List work items
stud items:show PROJ-123  # Show specific item
stud items:search "..."   # Search items
stud items:start PROJ-123 # Start work on item

# Git Workflow (object: verb or verb)
stud branch:rename       # Rename branch
stud branches:list       # List branches
stud branches:clean      # Clean branches
stud commit              # Make commit (no object needed)

# Configuration (object: verb)
stud config:init         # Initialize config
stud completion bash     # Generate completion
```

**Castor Implementation:**

```php
#[AsTask(name: 'items:list', aliases: ['ls'], description: 'Lists active work items')]
function items_list(...): void {
    // ...
}

#[AsTask(name: 'branch:rename', aliases: ['rn'], description: 'Renames a branch')]
function branch_rename(...): void {
    // ...
}
```

---

### Pro-Tip for Symfony 7.4 ADRs

This decision follows **Convention over Configuration** principles. By establishing a clear naming convention, we reduce cognitive load for developers and users. The `object:verb` pattern is self-documenting and aligns with Symfony Console's namespace support, making it a natural fit for Symfony 7.4 applications.
