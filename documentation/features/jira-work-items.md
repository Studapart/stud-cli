# Jira Work Items

Jira commands help you find and inspect work without leaving the terminal.

## Browse

```bash
stud items:list
stud ls -a
stud ls --project SCI
stud items:show SCI-123
stud sh SCI-123
```

`items:show --agent` includes attachment metadata so automation can decide whether to download files.

## Create and Update

```bash
stud items:create -p SCI -m "Add installer mode"
stud items:update SCI-123 --summary "New title"
stud iu SCI-123 --fields "labels=Bug,DX"
```

`items:create` and `items:update` support `--description-format markdown` when Markdown should be converted to Jira ADF.

## Attachments

```bash
stud items:download SCI-123 --path .cursor/tmp/SCI-123
stud items:upload SCI-123 -f report.md
```

Downloads use Jira authentication from `stud` and should be stored in task-specific temporary folders when used by automation.

## Transitions

```bash
stud items:transition SCI-123
stud tx
```

When no key is provided, `stud` tries to detect the Jira key from the current branch.
