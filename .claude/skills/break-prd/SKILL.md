---
name: break-prd
version: 1.0.0
description: |
  Take a PRD file and generate a complete set of GitHub issues from it, plus
  a tracking issue that maps all dependencies between tasks. Asks clarifying
  questions via an analyst subagent until 90% confident before generating
  issues. Each issue includes TDD-focused acceptance criteria and is scoped
  for a single developer working session.
license: MIT
compatibility: claude-code
allowed-tools:
  - Read
  - Write
  - Bash
  - Agent
  - AskUserQuestion
---

# Break PRD into GitHub Issues

Decompose a Product Requirements Document into a sequenced, dependency-mapped
set of GitHub issues — each ready for `/implement` — plus a tracking issue
that serves as the project board in text form.

## Invocation

```
/break-prd docs/V0.2-PRD.md        — break a specific PRD file into issues
/break-prd                          — prompt the user for a file path
```

## Steps

### 1. Resolve the PRD file

If a file path was provided as an argument, verify it exists:

```bash
test -f <path> && echo "found" || echo "missing"
```

If missing, stop and tell the user: "PRD file '<path>' not found."

If no argument was provided, ask the user for the path before proceeding:

```
Which PRD file should I break into issues?
```

### 2. Read the PRD

Read the full PRD file content. Note:
- Document title and version
- Top-level goals and non-goals
- Feature sections and subsections
- Any existing dependency or sequencing hints the author included
- Technical constraints, stack references, or out-of-scope statements

### 3. Spawn the PRD analyst subagent (first pass)

Use the Agent tool with a prompt that includes:

- The full PRD text verbatim
- Project context: PHP CLI tool, Composer, Symfony Console, Symfony YAML,
  PHP TUI, PHPUnit 11, PHP 8.2+, PSR-12, GitHub-based workflow
- Instruction: analyze the PRD and produce:
  1. **Confidence score** (0–100%): how confident the analyst is it can
     generate accurate, complete issues from the PRD as-is
  2. **Ambiguities**: a numbered list of anything that is unclear, missing,
     or would require a guess to resolve — each item phrased as a specific
     question the analyst needs answered
  3. **Proposed issue list** (draft): a rough enumeration of the issues the
     analyst would create — title only, one line each — so the user can see
     the decomposition direction even before questions are answered
  4. **Sequencing observations**: any obvious ordering constraints or
     dependency clusters the analyst spotted

Present the analyst's full response to the user.

### 4. Iterate until 90% confidence

After each analyst response, check the reported confidence score.

**If confidence < 90%:**

Present the analyst's questions to the user. Wait for the user's reply. Then:

- Send the user's answers to the SAME analyst agent via SendMessage (do NOT
  spawn a new agent — preserve conversation context so the analyst builds on
  prior answers)
- Include in the message: the user's answers verbatim, plus this instruction:
  "Update your confidence score. Ask any remaining questions. If confidence
  is now ≥ 90%, say so explicitly and stop asking questions."

Repeat until the analyst reports confidence ≥ 90% or the user says "proceed"
or "just go" (which overrides the threshold and accepts current confidence).

**If confidence ≥ 90%:**

Continue to step 5.

### 5. Generate the full issue specifications

Send a final message to the analyst agent with this instruction:

> Now that confidence is ≥ 90%, produce the complete issue specifications.
> For each issue output a block using this exact format:
>
> ---ISSUE---
> TITLE: <short imperative title, ≤ 72 chars>
> DEPENDS_ON: <comma-separated list of other issue titles, or "none">
> BODY:
> ## Summary
>
> <one paragraph — what this issue delivers and why>
>
> ## What this issue delivers
>
> <numbered list of concrete outputs: files created, classes added, commands
> registered, config keys read, etc.>
>
> ## Acceptance criteria
>
> - [ ] <testable criterion a junior dev can verify — written as behavior>
> - [ ] <each criterion maps to one or more PHPUnit tests>
> - [ ] <TDD note: write test first, confirm red, implement, confirm green>
>
> ## Out of scope
>
> <explicit list of related things this issue does NOT cover>
>
> ## TDD test plan
>
> For each acceptance criterion, name the test class and method(s) that will
> cover it:
>
> | Test class | Method | Criterion |
> |------------|--------|-----------|
> | `FooTest`  | `testBarReturnsBaz` | criterion text |
>
> ---END---
>
> After all issues, output the tracking issue in this exact format:
>
> ---TRACKING---
> TITLE: Tracking: <PRD title and version>
> BODY:
> ## Overview
>
> <2–3 sentence summary of what this PRD delivers>
>
> ## Issues
>
> Ordered by suggested implementation sequence:
>
> | # | Issue | Depends on | Status |
> |---|-------|-----------|--------|
> | 1 | #PLACEHOLDER — <title> | — | [ ] |
> | 2 | #PLACEHOLDER — <title> | #1 | [ ] |
>
> (Issue numbers will be filled in after creation.)
>
> ## Dependency graph
>
> ```
> <ASCII or plain-text graph showing which issues block which>
> ```
>
> ## Definition of done
>
> This PRD is complete when all issues above are closed and their PRs merged.
>
> ---END---

### 6. Parse the analyst response

From the analyst's response, extract:

- Each `---ISSUE--- ... ---END---` block as a separate issue spec
- The `---TRACKING--- ... ---END---` block as the tracking issue spec
- For each issue: its TITLE, DEPENDS_ON list, and BODY

Assign a local sequence number to each issue in dependency order (issues with
no dependencies first, then issues that depend only on already-numbered issues,
etc.). If cycles are detected, flag them to the user and stop.

### 7. Show the plan to the user

Before creating anything in GitHub, present a summary table to the user:

```
PRD: <title>
Issues to create: <N>

Seq  Title                                          Depends on
---  ---------------------------------------------  ----------
1    <title>                                        —
2    <title>                                        #1
3    <title>                                        #1, #2
...

+ 1 tracking issue

Proceed? (yes / edit / cancel)
```

Wait for the user to confirm. If they say "edit", ask what to change and
re-present the table. If they say "cancel", stop.

### 8. Create each issue

For each issue in sequence order:

a. Write the body to `tmp/prd-issue-<seq>.md`

b. Create the issue via support script:
   ```bash
   .claude/support-scripts/gh/create-issue.sh "<TITLE>" "tmp/prd-issue-<seq>.md"
   ```

c. Capture the issue URL from the output and extract the issue number from
   the URL (last path segment).

d. Record the mapping: `seq -> GitHub issue number` for use in the tracking
   issue.

e. Log progress to the user: `Created #<number>: <title>`

### 9. Build the tracking issue body

Take the tracking issue body from the analyst and replace every `#PLACEHOLDER`
with the actual GitHub issue number from step 8:

- `#PLACEHOLDER — <title>` → `#<actual-number> — <title>`

Also update the dependency graph to use real issue numbers.

Write the resolved tracking body to `tmp/prd-tracking.md`.

### 10. Create the tracking issue

```bash
.claude/support-scripts/gh/create-issue.sh "<TRACKING TITLE>" "tmp/prd-tracking.md" "tracking"
```

Capture the tracking issue URL and number.

### 11. Comment on each issue with tracking reference

For each created issue, write a short comment body to
`tmp/prd-issue-<number>-tracking-ref.md`:

```markdown
Part of tracking issue #<tracking-number>. See there for full dependency map
and implementation sequence.
```

Post the comment:
```bash
.claude/support-scripts/gh/comment-issue.sh <number> tmp/prd-issue-<number>-tracking-ref.md --yes
```

### 12. Report to user

Tell the user:

- PRD file processed
- Number of issues created (with list of `#number — title`)
- Tracking issue URL
- Suggested next step: "Run `/architect-issue <number>` on each issue to
  refine it before implementation, or `/implement <number>` directly on
  issues with clear acceptance criteria."

## Rules

- Never create issues until the user confirms the plan in step 7
- Never spawn more than one analyst agent per invocation — use SendMessage
  to continue the same agent across all clarification rounds
- Never proceed past step 4 while confidence is < 90% unless the user
  explicitly overrides ("just go", "proceed", "skip questions")
- Never write issues with vague or untestable acceptance criteria — if a
  criterion cannot be expressed as a PHPUnit test, it must be rewritten
- Never create an issue that covers more than one logical feature boundary;
  if the analyst generates a large issue, flag it and ask the user whether
  to split it
- Never use raw `gh issue create` — always use `create-issue.sh`
- Never commit or push anything — this skill only creates GitHub issues
- If the PRD has a version number, include it in the tracking issue title
- Temp files go in `tmp/`, never `/tmp`
- If any `create-issue.sh` call fails, stop immediately and report the
  error. Do NOT continue creating remaining issues or the tracking issue —
  partial state is better than silent partial state.
- Clean up `tmp/prd-issue-*.md` and `tmp/prd-tracking.md` after the
  tracking issue is created (but only on success)
