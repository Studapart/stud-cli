# GitHub Integration

Use GitHub configuration when your repository remote is hosted on GitHub and you want `stud` to create pull requests, add comments, assign authors, manage labels, or inspect PR feedback.

## Token

Create a classic GitHub personal access token at [GitHub Settings > Developer settings > Personal access tokens](https://github.com/settings/tokens).

Required scope:

- `repo` for private repositories and pull request operations

Fine-grained tokens are not currently documented as supported for all `stud` operations.

## Configure

Run:

```bash
stud init
```

or configure project settings interactively:

```bash
stud config:project-init
```

For automation:

```bash
echo '{"gitProvider":"github"}' | stud config:project-init --agent
```

## What It Enables

- `stud submit` / `stud su`: create or update pull requests
- `stud pr:comment` / `stud pc`: comment on the active pull request
- `stud pr:comments` / `stud pcs`: fetch PR conversations
- `stud branches:list` and `stud branches:clean`: include PR state
- `stud branch:rename`: when the branch has an open PR, creates a new PR for the renamed branch and comments on it with the old and new names. GitHub's "Update a pull request" endpoint does not accept a new head branch (checked 2026-11-12; GitHub may change this later), so the existing PR is not renamed.

## GitHub Actions

For CI setup, secrets, fork safety, `config:validate --agent`, and the composite action, see [GitHub Actions with stud-cli](../github-actions.md).
