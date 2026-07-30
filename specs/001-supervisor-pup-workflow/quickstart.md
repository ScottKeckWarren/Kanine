# Quickstart Validation Guide: Supervisor and Pup Workflow

**Date**: 2026-06-28
**Feature**: specs/001-supervisor-pup-workflow/spec.md
**API contract**: [contracts/pup-api.md](contracts/pup-api.md)
**Data model**: [data-model.md](data-model.md)

This guide covers how to validate each user story end-to-end. It assumes the project is
installed and dependencies are present (`composer install`).

---

## Prerequisites

1. PHP 8.2+ available on `$PATH`
2. `composer install` completed
3. A GitHub token with `repo` scope in `GITHUB_TOKEN` env var
4. A test repository with at least 3 open issues carrying status labels
   (e.g. `status: backlog`, `status: todo`)
5. A local clone of the test repository present on the same machine

---

## Setup: Configuration File

Create `.kanine/kanine.yaml` (or run `bin/kanine init` to use the wizard):

```yaml
github:
  repositories:
    - owner/your-test-repo
  token_env: GITHUB_TOKEN

board:
  columns:
    - { name: "Backlog",     label: "status: backlog" }
    - { name: "Todo",        label: "status: todo" }
    - { name: "In Progress", label: "status: in progress" }
    - { name: "Review",      label: "status: review" }
    - { name: "Done",        label: "status: done" }

refresh:
  interval_seconds: 10

supervisor:
  host: 127.0.0.1
  port: 7777
  tls: false
  dispatch_interval_seconds: 2
  pup_timeout_seconds: 15
```

---

## Validate: US1 — Board Renders and Auto-Refreshes

```bash
bin/kanine
```

**Expected**:
- Kanban board appears with columns matching config
- Issues appear in the correct column based on their GitHub labels
- Status footer shows: view name, last-sync timestamp, pup count (0)
- Pressing `q` exits cleanly and restores the terminal

**Verify auto-refresh**: Change a label on a GitHub issue via the GitHub UI. Within 10 seconds
the board should move the card to the correct column without any keypress.

---

## Validate: US2 — Autonomous Dispatch

Start the supervisor in one terminal:

```bash
bin/kanine
```

In a second terminal, start one or more pups:

```bash
KANINE_SUPERVISOR_URL=http://127.0.0.1:7777 \
KANINE_PUP_ID=$(uuidgen) \
bin/kanine --pup
```

**Expected within 2 dispatch cycles (~4 seconds)**:
- The board's active pup count increments
- A backlog issue moves to "In Progress" automatically — no keypress required
- The card shows the pup's identifier

**Verify no-pup indicator**: Stop all pups and verify the board shows an indicator that no
pups are available to process work.

**Verify pin**: Select an issue with `↑`/`↓` and press `p` to pin it. Confirm pinned issue
remains in backlog while other issues are dispatched.

---

## Validate: US3 — Pup Performs Work End-to-End

**Prerequisites**: Test repository clone present; `claude` CLI available in `$PATH`.

Start supervisor, then start a pup with the repo path configured:

```bash
KANINE_SUPERVISOR_URL=http://127.0.0.1:7777 \
KANINE_PUP_ID=$(uuidgen) \
KANINE_REPO_PATH=/path/to/local/clone \
KANINE_WORKTREE_DIR=/tmp/kanine-worktrees \
bin/kanine --pup
```

**Expected sequence**:
1. Pup registers — board pup count increments
2. Supervisor dispatches an issue — card moves to In Progress with pup ID
3. Pup creates worktree at `KANINE_WORKTREE_DIR/issue-{N}`
4. Pup runs Claude Code on the issue
5. Pup commits changes and pushes branch
6. Pup creates PR on GitHub linking to the issue
7. Pup reports complete — supervisor releases assignment; card moves to Done/Review
8. Worktree is removed

**Verify on GitHub**: A PR should exist against the default branch with commits referencing
the issue number.

---

## Validate: US4 — Operator Answers Pup Questions

While a pup is working, use the pup API directly to simulate a question (or let Claude Code
generate one via its KANINE_SUPERVISOR_URL integration):

```bash
curl -s -X POST http://127.0.0.1:7777/pups/{pupId}/questions \
  -H 'Content-Type: application/json' \
  -d '{"questionId":"test-q-1","body":"Should token expiry be 15m or 24h?"}'
```

**Expected**:
1. Board shows a `?` indicator on the card for the active issue
2. Press `Enter` on the card to open the detail view
3. Question is displayed with a text input field
4. Type an answer and press `Enter`
5. `GET /pups/{pupId}/poll` response includes the answer in `pendingAnswers`

**Verify via API**:
```bash
curl -s http://127.0.0.1:7777/pups/{pupId}/poll
# pendingAnswers should contain the answer to test-q-1
```

---

## Validate: Inactivity Detection

Start a pup, let it receive an assignment, then kill the pup process (`Ctrl-C`).

**Expected**: Within `pup_timeout_seconds` (15s default), the board shows the issue
returning to the backlog and the pup count decrements.

---

## Validate: Config Errors

Remove `github.repositories` from config and restart the supervisor:

```bash
bin/kanine
```

**Expected**: Process exits immediately with an error message naming `github.repositories`
as the missing key. No network I/O attempted.

---

## Key Keyboard Shortcuts Reference

| Key       | Action                                              |
|-----------|-----------------------------------------------------|
| `q`       | Quit; restores terminal                             |
| `r`       | Force board refresh now                             |
| `a`       | Toggle auto-refresh on/off                          |
| `↑` / `↓` | Move between cards in the current column            |
| `←` / `→` | Move between columns                                |
| `Enter`   | Open card detail (issue body, pup status, questions) |
| `p`       | Pin/unpin selected issue (excludes from dispatch)   |
| `s`       | Stop pup on selected in-progress issue              |
