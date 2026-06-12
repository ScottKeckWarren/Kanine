---
name: implement
version: 1.0.0
description: |
  Take a GitHub issue number, fetch the issue, create a feature branch, spawn
  the PHP developer subagent to implement all acceptance criteria via TDD, then
  commit the changes. If the developer hits blockers it cannot resolve, post
  questions as a comment on the issue and apply the "human input required" label
  so a human can unblock it.
license: MIT
compatibility: claude-code
allowed-tools:
  - Read
  - Write
  - Edit
  - Bash
  - Agent
---

# Implement Issue

Drive a GitHub issue to completion using the PHP developer subagent. The skill
creates the branch, hands the full issue spec to the developer, monitors for
blockers, and commits the result.

## Invocation

```
/implement 11        — implement issue #11
/implement           — find the first issue labeled "ready for development"
                       that does not already have a PR and implement it
```

## Steps

### 1. Fetch the issue

If an issue number was provided:
```bash
.claude/support-scripts/gh/view-issue.sh <number>
```

If no number was provided, fetch all open issues and take the first one labeled
`ready for development` that has no associated PR:
```bash
gh issue list --json number,title,labels,body --limit 50
```
Filter to issues that have a label named `ready for development`. Take the
first. If none found, tell the user and stop.

### 2. Check preconditions

- If the issue has a `human input required` label, stop and tell the user:
  "Issue #N has open questions that need human input before it can be
  implemented. Resolve the questions on the issue, remove the
  'human input required' label, then invoke /implement again."
- If the current branch is not `main`, warn the user and stop unless they
  explicitly passed `--force`.

### 3. Brief the user

Tell the user: issue number, title, and a one-sentence summary of what will be
implemented.

### 4. Create a feature branch

Derive a branch name from the issue: `feat/issue-<number>-<slug>` where the
slug is the issue title lowercased, non-alphanumeric characters replaced with
hyphens, truncated to 40 chars total for the slug portion.

Create the branch via support script:
```bash
.claude/support-scripts/git/create-branch.sh <branch-name> main
```

### 5. Spawn the PHP developer subagent

Use the Agent tool with `agentType: "php-developer"` and a prompt that
includes:

- The issue number, title, and full body verbatim
- Instruction to implement ALL acceptance criteria in the issue body using
  strict TDD (red → green → refactor), following the PHP developer agent's
  standards
- Instruction: if any requirement is ambiguous or blocked on information
  the agent cannot determine from the codebase or issue body, do NOT guess —
  instead return a structured list of blockers in this exact format at the
  very end of the response:

  ```
  BLOCKERS:
  - <question or blocker description>
  - <question or blocker description>
  ```

  Return this section ONLY if there are genuine blockers. If everything can be
  implemented, omit the BLOCKERS section entirely.
- Instruction: when done implementing (no blockers), run the full test suite
  via `composer test` and confirm all tests pass before returning
- Instruction: do NOT commit — the skill handles committing

### 6. Check for blockers

After the developer agent returns, scan its response for a `BLOCKERS:` section.

**If blockers found:**

a. Write the comment body to `tmp/issue-<number>-blockers.md`:

```markdown
## Implementation Blocked — Questions for Human Review

The following questions arose during implementation of this issue and cannot
be resolved without human input:

<bulleted list of blockers from the agent>

Please answer these questions, update the issue body with any clarifications,
and remove the `human input required` label when ready. Then re-run
`/implement <number>`.
```

b. Post the comment:
```bash
.claude/support-scripts/gh/comment-issue.sh <number> tmp/issue-<number>-blockers.md --yes
```

c. Apply the label:
```bash
.claude/support-scripts/gh/label-issue.sh <number> --yes -- "human input required"
```

d. Tell the user: "Implementation blocked on N question(s). Posted to issue
#<number> and applied 'human input required' label. Fix the questions and
re-run `/implement <number>`."

e. Stop — do NOT commit partial work.

**If no blockers:**

Continue to step 7.

### 7. Stage all changes

```bash
git add -A
```

Confirm at least one file was staged. If nothing changed, tell the user
"Developer agent ran but made no file changes" and stop.

### 8. Run tests

```bash
composer test
```

If tests fail, report the exact failure output and stop. Do NOT commit broken
tests.

### 9. Commit

Write commit message to `tmp/COMMIT_EDITMSG_IMPLEMENT`:

```
feat: implement issue #<number> — <issue title>

Closes #<number>
```

Commit via support script:
```bash
.claude/support-scripts/git/commit.sh tmp/COMMIT_EDITMSG_IMPLEMENT
```

If the commit fails (hook, lint error, etc.), report the exact error output and
stop. Do NOT use `--no-verify`.

### 10. Report to user

Tell the user:
- Branch name
- Issue number and title
- Number of files changed
- Test count (from `composer test` output)
- Next step: "Run `/create-pull-request` to open a PR, or push manually."

## Rules

- Never commit directly to `main` — always create the feature branch first
- Never commit partial work when blockers are present — post questions first
- Never skip hooks (`--no-verify`)
- Never guess at ambiguous requirements — escalate via the blocker flow
- Use support scripts for all gh and git actions — no raw `git commit`, `git
  push`, or `gh issue` CLI calls
- If the issue lacks acceptance criteria (no checkbox list), proceed with best
  judgement based on the issue title and body, but note in the commit message
  that acceptance criteria were inferred
