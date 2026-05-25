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

Branch cleanup uses conservative eligibility and does not delete ambiguous branches automatically in non-interactive mode.
