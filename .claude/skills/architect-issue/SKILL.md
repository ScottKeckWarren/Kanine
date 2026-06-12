---
name: architect-issue
version: 1.0.0
description: |
  Take a GitHub issue and refine it through a conversation with a PHP architect
  subagent. Asks clarifying questions, proposes an approach, iterates with the
  user until the design is settled, then updates the issue body and applies
  "ready for development" and "human review needed" labels via support scripts.
license: MIT
compatibility: claude-code
allowed-tools:
  - Read
  - Write
  - Bash
  - Agent
---

# Architect Issue

Refine a raw GitHub issue into a fully scoped, junior-developer-ready spec
through an iterative conversation with a PHP architect subagent.

## Invocation

The user invokes this skill with an optional issue number:

- `/architect-issue 11` — refine issue #11
- `/architect-issue` — fetch all issues that lack a "ready for development"
  label and use the first one

## Steps

### 1. Fetch the issue

If an issue number was provided, fetch it:
```bash
gh issue view <number> --json number,title,body,labels
```

If no number was provided, fetch all issues without the "ready for development"
label and take the first result:
```bash
gh issue list --json number,title,labels,body --limit 50
```
Filter out any issue that already has a label named `ready for development`.
Take the first remaining issue. If none found, tell the user and stop.

### 2. Brief the user

Tell the user which issue you are working on (number + title) before spawning
any subagent.

### 3. Spawn the PHP architect subagent (first pass)

Use the Agent tool with a prompt that includes:
- Full project context (PHP CLI tool, Composer, PHPUnit, PHPCS PSR-12 +
  Slevomat, PHP 8.0+, GitHub issue manager)
- The issue number, title, and body verbatim
- All other open issues as brief context (titles only) so the architect
  understands the broader scope
- Instruction: identify the key architectural questions that must be answered
  before a junior developer can implement this issue, then propose concrete
  answers based on PHP CLI best practices (cite comparable tools like Composer,
  WP-CLI, Laravel Artisan where relevant), and end with an invitation for the
  user to push back or add context

Present the architect's full response to the user.

### 4. Iterate with the user

After each architect response, wait for the user's reply. For each reply:

- Send the user's answer to the SAME architect agent via SendMessage (do not
  spawn a new agent — preserve the conversation context)
- Include in the message: the user's answer verbatim, plus an instruction to
  update their proposed approach, ask any remaining questions, and flag what is
  now resolved vs still open
- Present the architect's updated response to the user

Continue this loop until one of:
- The user signals they are satisfied ("looks good", "yes", "that's it", etc.)
- All open questions are resolved and the architect proposes a final scope

### 5. Generate the final issue body

Once the design is settled, send a final message to the architect agent with
the instruction: produce the complete GitHub issue body in markdown. It must
include:
- Summary (what the issue delivers — doc, scaffold, implementation, etc.)
- What this issue delivers (numbered list)
- Any directory structures, schemas, or data models decided during the
  conversation (with annotated code blocks)
- Docs/outline to write (if the issue is documentation-focused)
- Out of scope section (explicitly list deferred decisions)
- Acceptance criteria (checkbox list a junior dev can verify)

### 6. Write body to tmp file

Write the body to `tmp/issue-<number>-body.md`.

### 7. Update the issue

Run the update support script:
```bash
.claude/support-scripts/gh/update-issue.sh <number> tmp/issue-<number>-body.md --yes
```

### 8. Apply labels

Run the label support script:
```bash
.claude/support-scripts/gh/label-issue.sh <number> --yes -- "ready for development" "human review needed"
```

### 9. Report to user

Tell the user:
- Issue number and title
- Link to the issue on GitHub (from the gh output)
- Brief summary of what was decided (2–3 sentences max)

## Rules

- Never spawn more than one architect agent per skill invocation — use
  SendMessage to continue the same agent across all conversation turns
- Never call `gh issue edit` or `gh issue edit` directly — always use the
  support scripts
- Never add labels directly via `gh` — always use `label-issue.sh`
- Do not finalize the issue body until the user signals they are done iterating
- If the issue already has a "ready for development" label, stop and tell the
  user it is already refined
