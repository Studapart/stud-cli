# GitLab Integration

Use GitLab configuration when your repository remote is hosted on GitLab and you want `stud` to create merge requests, add comments, manage labels, or inspect MR feedback.

## Token

Create a GitLab personal access token from your GitLab user settings.

Required scope:

- `api` for merge request, comment, and label operations

For self-hosted GitLab, configure the instance URL in project or global configuration.

## Configure

Run:

```bash
stud init
```

or configure project settings:

```bash
stud config:project-init --git-provider gitlab --gitlab-instance-url https://gitlab.example.com
```

For automation:

```bash
echo '{"gitProvider":"gitlab","gitlabInstanceUrl":"https://gitlab.example.com"}' | stud config:project-init --agent
```

## What It Enables

- `stud submit` / `stud su`: create merge requests
- `stud pr:comment` / `stud pc`: comment on the active merge request
- `stud pr:comments` / `stud pcs`: fetch MR conversations
- `stud branches:list` and `stud branches:clean`: include MR state
- `stud branch:rename`: keep branch workflow context aligned where supported
