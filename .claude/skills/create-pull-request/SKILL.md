---
name: create-pull-request
version: 1.0.0
description: |
  Create a pull request from staged changes. Analyzes staged files, creates a
  new branch off main (or a specified base), writes a structured commit message
  file, commits, pushes, and opens a PR via gh. Use when the user wants to
  package staged changes into a PR with a clear, structured description.
license: MIT
compatibility: claude-code
allowed-tools:
  - Read
  - Write
  - Edit
  - Bash
  - AskUserQuestion
---

# Create Pull Request

You help the user turn staged git changes into a pull request with a structured, human-readable description.

## Steps

### 1. Validate staged changes exist

Run `git diff --cached --stat`. If nothing is staged, stop and tell the user: "No staged changes found. Stage files with `git add` first."

### 2. Analyze staged changes

Run these in parallel:
- `git diff --cached` — full diff for content analysis
- `git diff --cached --name-only` — file list
- `git log --oneline -10` — recent commit history for context and naming conventions
- `git branch -a` — list branches (to detect if a dependency branch exists)
- `git remote get-url origin` — confirm remote exists

### 3. Determine base branch

Default base branch: `main`.

Check if any staged files or recent commits suggest this work depends on an in-progress branch (e.g., a feature branch that isn't merged to main yet). If so, ask the user:

> "These changes look like they may build on branch `[branch-name]`. Use that as the base instead of `main`?"

Otherwise proceed with `main`.

### 4. Generate PR content

From the diff analysis, synthesize:

**Branch name**: `[type]/[short-kebab-description]` — type is one of: `feat`, `fix`, `refactor`, `chore`, `docs`, `test`. Max 50 chars total. No ticket numbers unless the user mentions one.

**Header** (commit subject): Conventional Commits format. `type(scope): short imperative description`. 50 chars max. No period.

**What Changed**: Bullet list of concrete changes. What files/functions/components were modified and how. Specific, not vague. No "various improvements."

**Why We Changed It**: The motivation. Business reason, bug being fixed, technical debt being addressed. If not inferrable from the diff, ask the user one focused question: "What's the motivation for this change?"

**How to Test**: Concrete steps a reviewer can follow to verify the change works. If the change is purely mechanical (rename, reformat), say so explicitly.

If the motivation is unclear from the diff, ask before writing the PR body — don't invent a reason.

### 5. Confirm with user

Show the user:
- Proposed branch name
- Base branch
- Full commit message (header + all three sections)

Ask: "Proceed with this branch name and commit message?"

If they say no or request changes, revise and confirm again before continuing.

### 6. Create branch and commit

Run sequentially — each step must succeed before the next:

```bash
git checkout -b [branch-name] [base-branch]
```

Write the commit message to `.git/COMMIT_EDITMSG_PR` (temporary file in git dir, not tracked):

```
[header]

What Changed
------------
[bullets]

Why We Changed It
-----------------
[explanation]

How to Test
-----------
[steps]
```

Then commit:
```bash
git commit -F .git/COMMIT_EDITMSG_PR
```

If the commit fails (pre-commit hook, lint error, etc.), report the exact error output and stop. Do NOT use `--no-verify`.

### 7. Push to origin

```bash
git push -u origin [branch-name]
```

If push fails, report exact error. Do not force push.

### 8. Create PR via gh

```bash
gh pr create \
  --title "[header]" \
  --base [base-branch] \
  --body "$(cat <<'EOF'
What Changed
------------
[bullets]

Why We Changed It
-----------------
[explanation]

How to Test
-----------
[steps]
EOF
)"
```

Output the PR URL to the user.

## Edge Cases

- **Dirty working tree (unstaged changes)**: Proceed — only staged changes are committed. Mention to the user that unstaged changes remain.
- **Branch already exists**: Append `-2` (then `-3`, etc.) to branch name and inform user.
- **No remote configured**: Stop after branch + commit. Tell user to add a remote and push manually.
- **gh not installed or not authenticated**: Stop after push. Print the push URL and tell user to open a PR manually.
- **Merge conflicts on checkout**: Report and stop. Do not attempt to resolve automatically.

## What NOT to do

- Do not `git add` additional files — only commit what is already staged.
- Do not amend existing commits.
- Do not skip hooks (`--no-verify`).
- Do not force push.
- Do not invent motivations — ask if unclear.
