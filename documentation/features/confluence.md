# Confluence

Confluence commands use the same Atlassian credentials as Jira when Confluence is on the same Atlassian site.

## Push Markdown

```bash
stud confluence:push --space DEV --title "Sprint Notes" --file notes.md
stud cpu -s DEV -t "Doc" < doc.md
```

Use `--page` to update an existing page.

```bash
stud cpu --page 12345 --file updated.md
```

## Show a Page

```bash
stud confluence:show --page 12345
stud csh --url "https://company.atlassian.net/wiki/spaces/DEV/pages/12345/Page"
```

Agent mode returns JSON with page metadata and markdown body:

```bash
echo '{"url":"https://company.atlassian.net/wiki/spaces/DEV/pages/12345/Page"}' | stud csh --agent
```

## Labels

```bash
stud confluence:page-labels --page 12345 --labels research,DX
```
