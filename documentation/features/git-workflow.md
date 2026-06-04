# Git Workflow

Git workflow commands connect Jira keys, branch naming, conventional commits, and branch maintenance.

## Start Work

```bash
stud start SCI-123
stud items:takeover SCI-123
```

`stud start` creates or switches to a branch for the Jira item. `items:takeover` helps continue work from an existing local or remote branch.

## Commit and Push

```bash
stud commit
stud co --all
stud push
stud ps --no-please
```

`stud commit` creates conventional commit messages from branch and Jira context. `stud push` commits and pushes without opening a pull request.

## Sync and Branch Maintenance

```bash
stud sync
stud branch:rename
stud branches:list
stud branches:clean
```

Branch cleanup uses conservative eligibility and does not delete ambiguous branches automatically in non-interactive mode. `branches:clean` first scans local branches, remote heads on `origin`, and PR/MR state, then executes one cleanup decision per local branch:

- Protected branches, the current branch, and branches with open PRs are skipped.
- Locally merged branches are deleted with safe local deletion.
- Provider-merged branches, such as squash-merged PR branches, are force-deleted locally only when no open PR risk remains.
- Existing remote branches are deleted only after an interactive confirmation; quiet and agent mode keep them.
- Branches whose remote no longer exists are treated as local-only cleanup and skip remote deletion.
- Branches whose safety cannot be determined are reported for manual action.
