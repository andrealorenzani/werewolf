---
description: Implement a new feature end to end (architect → implementer → reviewer → documenter)
argument-hint: <feature description>
---

Implement this feature: $ARGUMENTS

1. Read `docs/architecture.md` and `docs/code.md` to understand the constraints and where code lives.
2. If the request is ambiguous, ask the user to clarify (AskUserQuestion) before proceeding.
3. Spawn the `architect` agent with the feature description → plan. If the plan says `ARCHITECTURE CHANGE: yes`, show it to the user and get confirmation before continuing.
4. Spawn the `implementer` agent with the plan → code + tests. `php tests/run.php` must pass.
5. Spawn the `reviewer` agent on the result. Send blocking issues back to the `implementer` (max 2 loops), then report any remaining issues to the user.
6. Spawn the `documenter` agent with a summary of what changed and the reviewer's "docs drift" list → docs updated. Never skip this step.
7. Run `php tests/run.php` yourself one last time and report to the user: what was built, files changed, test results, doc updates, open issues.

You are the orchestrator: do not write code or docs yourself.
