# Pull Requests

Pull request commands work with GitHub pull requests and GitLab merge requests through the configured provider.

## Submit Work

```bash
stud submit
stud su --draft
stud submit --labels "AI-Generated,RFR"
stud submit --assign-to-author
```

`stud submit` pushes the current branch and opens or updates the review request. PR descriptions are derived from Jira where available.

Agent mode can commit, push, and submit in one step:

```bash
echo '{"labels":"AI-Generated,RFR","stageAll":true}' | stud submit --agent
```

## Comment

```bash
echo "Ready for review" | stud pr:comment
stud pc "Manual note"
```

Threaded replies use targets returned by `pr:comments --threaded`.

```bash
stud pr:comments --threaded
echo '{"threaded":true}' | stud pr:comments --agent
echo '{"message":"Fixed","replyTo":"github:review_thread:THREAD_ID","resolve":true}' | stud pc --agent
```
