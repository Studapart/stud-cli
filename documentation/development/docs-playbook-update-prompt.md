# Documentation Playbook Update Prompt

Use this prompt when command behavior, command metadata, workflow expectations, or agent JSON input/output changes.

```text
You are updating stud-cli workflow documentation after a command or workflow change.

Inputs:
- The latest generated command reference at documentation/reference/commands.md
- The changed command implementation and tests
- The curated workflow playbook at documentation/features/workflow-playbook.md
- The related feature overview pages under documentation/features/

Tasks:
1. Run stud docs:generate to refresh documentation/reference/commands.md.
2. Run stud docs:check to confirm the generated reference is current.
3. Compare the changed commands against documentation/features/workflow-playbook.md.
4. Update the Mermaid workflow only when the command changes the idea-to-PR path, adds a new decision point, changes where stud please belongs, or changes how Jira, Confluence, Git, or PR commands connect.
5. Keep the Mermaid diagram visual-only. Do not use Mermaid click directives; put command links in nearby Markdown recipe text where GitHub renders them reliably.
6. Update recipe sections when a human would now choose a different command, flag, or sequence.
7. Keep curated pages concise. Put exhaustive options, agent JSON fields, and output shapes in the generated reference instead of duplicating them.
8. Preserve stud please as a history-rewrite follow-up command after stud flatten, git rebase, or another intentional rewrite. Do not present it as the default push path.
9. If a command is added, renamed, or removed, update any README or documentation/features/index.md links that help users discover it.
10. Verify the changed docs render as Markdown and that relative links resolve from the edited file.

Expected output:
- Updated generated reference if command metadata changed
- Updated playbook/schema only when workflow guidance changed
- Updated feature index or README links when discovery changed
- A short summary of what changed and which checks were run
```
