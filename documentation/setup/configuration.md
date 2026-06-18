# Configuration

Run the first-time wizard after installing:

```bash
stud init
```

The wizard creates or updates `~/.config/stud/config.yml`.

## Jira

You need:

- Jira URL
- Jira email address
- Atlassian API token

Create an Atlassian token at [Atlassian Account Settings > Security > API tokens](https://id.atlassian.com/manage-profile/security/api-tokens).

Jira access enables reading issues, projects, filters, attachments, and Confluence content on the same Atlassian site when those commands are used.

## Project Configuration

Repository-specific values live in `.git/stud.config`.

```bash
stud config:project-init
stud cpi
```

Agent mode is available for automation:

```bash
echo '{"projectKey":"SCI","baseBranch":"develop"}' | stud config:project-init --agent
```

## Validate Setup

```bash
stud config:validate
stud config:validate --skip-jira
stud config:validate --skip-git
```

## Inspect Configuration Safely

```bash
stud config:show
stud config:show -k baseBranch
stud config:show -k JIRA_URL -q
```

Secrets are redacted in shared output.

## Provider Tokens

Git provider setup is split by provider:

- [GitHub integration](../integrations/github.md)
- [GitLab integration](../integrations/gitlab.md)
