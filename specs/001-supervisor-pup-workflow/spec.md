# Feature Specification: Supervisor and Pup Workflow

**Feature Branch**: `001-supervisor-pup-workflow`

**Created**: 2026-06-28

**Status**: Draft

**Input**: User description: "build a CLI/TUI application that has a supervisor (bin/kanine) that
keeps track of multiple agents (bin/kanine --pup) that perform tasks based on issues inside of
GitHub issues. The goal is to have the supervisor pass out issues to each pup and the pup will
create a worktree for the task in the correct repository, performs the work, commits the work,
and creates a pull request."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Operator Launches Supervisor and Views Issue Board (Priority: P1)

An operator runs the supervisor from their terminal. A Kanban board appears showing all open
GitHub issues from the configured repository, organized into columns by status label. The board
auto-refreshes so the operator always sees current state without manual intervention.

**Why this priority**: The board is the entry point to the entire system. No other story
functions without it.

**Independent Test**: Operator runs the supervisor command, sees a Kanban board populated with
GitHub issues, and the board updates when an issue's label changes on GitHub.

**Acceptance Scenarios**:

1. **Given** a configured repository with open issues, **When** the operator launches the
   supervisor, **Then** a board renders showing issues in columns matching their status labels.
2. **Given** the board is running, **When** an issue's label changes on GitHub, **Then** the
   board reflects the change within the configured refresh interval.
3. **Given** the board is running, **When** the operator presses `q`, **Then** the terminal
   is fully restored and the process exits cleanly with no visible artifacts.

---

### User Story 2 - Supervisor Autonomously Dispatches Issues to Idle Pups (Priority: P2)

The supervisor continuously monitors registered pups and the issue backlog. Whenever a pup is
idle and eligible issues exist in the backlog, the supervisor automatically assigns an issue to
that pup without any operator action. The board reflects assignments as they happen. The operator
can observe the flow and may manually stop a pup or pin an issue to prevent auto-dispatch.

**Why this priority**: Autonomous dispatch is the core value proposition — pups should pull work
continuously without the operator manually driving each assignment.

**Independent Test**: With registered pups and issues in the backlog, the supervisor assigns
issues without any operator input; the board shows assignments appearing automatically.

**Acceptance Scenarios**:

1. **Given** idle pups and unassigned issues in the backlog, **When** the supervisor's dispatch
   loop runs, **Then** each idle pup is assigned one issue and the board reflects the
   assignments without any operator action.
2. **Given** all pups are busy, **When** a new issue enters the backlog, **Then** the
   supervisor queues it and assigns it as soon as a pup becomes idle.
3. **Given** no pups are registered, **When** issues exist in the backlog, **Then** the board
   shows a visible indicator that no pups are available to process work.
4. **Given** an issue is marked as pinned (operator-excluded from auto-dispatch), **When** the
   dispatch loop runs, **Then** that issue is skipped and remains in the backlog.
5. **Given** a pup accepts an assignment, **When** the supervisor records the binding, **Then**
   the board immediately reflects the pup-to-issue mapping.

---

### User Story 3 - Pup Receives Assignment and Performs Work (Priority: P3)

A pup process registers with the supervisor, polls for assignments, and when given an issue it
creates an isolated worktree in the target repository, performs the implementation, commits the
changes, and opens a pull request. The pup reports status to the supervisor throughout.

**Why this priority**: The pup is the autonomous worker. Without it the system is only a display
board.

**Independent Test**: A pup process registers, receives an issue assignment, and a pull request
appears on GitHub with the work committed and the PR linked to the issue.

**Acceptance Scenarios**:

1. **Given** a pup is started with the supervisor address configured, **When** the pup starts,
   **Then** it registers with the supervisor and the board's active pup count increments.
2. **Given** a registered idle pup, **When** the supervisor assigns an issue, **Then** the pup
   picks it up within two poll cycles and begins work.
3. **Given** a pup working on an issue, **When** the work is complete, **Then** the pup has
   created a worktree, committed all changes, opened a pull request, and reported completion to
   the supervisor.
4. **Given** a pup encounters an ambiguous decision point, **When** it posts a question to the
   supervisor, **Then** the question appears in the board's detail view for the operator to
   answer.

---

### User Story 4 - Operator Answers Pup Questions (Priority: P4)

When a pup is blocked on an ambiguous decision it posts a question to the supervisor. The
operator sees the question in the board's issue detail view, types an answer, and the pup
retrieves it on its next poll to continue work.

**Why this priority**: Without the Q&A loop, pups stall on ambiguity and cannot complete
complex issues autonomously.

**Independent Test**: A question appears in the issue detail view; operator types and submits
an answer; the answer is retrievable by the pup on next poll.

**Acceptance Scenarios**:

1. **Given** a pup posted a question, **When** the operator opens the issue detail, **Then**
   the question is displayed with a text input field.
2. **Given** a question is displayed, **When** the operator types an answer and presses Enter,
   **Then** the supervisor stores the answer and the pup retrieves it on next poll.
3. **Given** multiple questions queued on one issue, **When** the operator opens the detail,
   **Then** all questions are shown in order and each can be answered independently.

---

### Edge Cases

- What happens when a pup crashes mid-task? The supervisor detects missed heartbeats beyond a
  configurable threshold and marks the issue as unassigned so it can be re-dispatched.
- What happens when the GitHub API rate-limits the supervisor? The board shows a warning in the
  status footer and continues displaying cached state until the rate-limit window resets.
- What happens when two pups try to claim the same issue? The supervisor enforces
  single-assignment — first acceptance wins; the second pup receives a rejection and polls for
  a different issue.
- What happens when a repository is not accessible at boot? Config validation surfaces the
  error before any board rendering begins.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The supervisor MUST display all open GitHub issues as cards on a Kanban board,
  organized into columns by status label.
- **FR-002**: The supervisor MUST auto-refresh the board at a configurable interval.
- **FR-003**: The supervisor MUST continuously and autonomously assign unassigned backlog issues
  to idle pups without requiring operator action.
- **FR-003a**: The operator MUST be able to pin an issue to exclude it from autonomous dispatch,
  and unpin it to make it eligible again.
- **FR-004**: The supervisor MUST expose an HTTP API that pups use to register, poll for
  assignments, post questions, and report completion.
- **FR-005**: Pups MUST register with the supervisor at startup and appear in the board's
  active pup count.
- **FR-006**: Pups MUST poll the supervisor for assignments at a configurable interval.
- **FR-007**: When assigned an issue, a pup MUST create an isolated worktree within a local
  clone of the target repository.
- **FR-008**: A pup MUST perform implementation work within the worktree, commit all changes,
  and open a pull request against the repository's default branch.
- **FR-009**: A pup MUST report status updates (registered, working, blocked, complete) to the
  supervisor throughout the task lifecycle.
- **FR-010**: When a pup posts a question, the supervisor MUST display it in the board's issue
  detail view and store the operator's answer for retrieval by the pup.
- **FR-011**: The supervisor MUST enforce single-assignment: each issue MUST be assigned to at
  most one pup at a time.
- **FR-012**: The supervisor MUST detect pup inactivity beyond a configurable timeout and
  release the assigned issue for re-dispatch.
- **FR-013**: Repository access tokens MUST be supplied via a `--token` CLI flag,
  `github.token` in `.kanine/kanine.local.yaml` (a gitignored, `0600`-permission local
  file), an inline `github.token` in the tracked config, or the environment variable named
  by `github.token_env` (default `GITHUB_TOKEN`) — checked in that priority order — and
  MUST NOT appear in any log or error output.
- **FR-013a**: On startup, if no token is available from any source, the supervisor MUST
  print instructions covering `--token`, `.kanine/kanine.local.yaml`, and the env var,
  then exit before any network I/O begins.
- **FR-014**: The terminal MUST be fully restored to its pre-launch state when the supervisor
  exits for any reason (normal, error, or signal).
- **FR-015**: Configuration MUST be validated at startup; any missing required key MUST produce
  a named error and the process MUST exit before any network I/O begins.

### Key Entities

- **Issue**: A GitHub issue representing a unit of work; carries title, body, labels, and
  current assignment state within the supervisor.
- **Pup**: An agent process with a unique identifier; has registration state, heartbeat
  timestamp, and current assignment.
- **Assignment**: The binding between one issue and one pup; tracks status, timestamps, and
  the history of status transitions.
- **Question**: A message posted by a pup on an active assignment; has a body, posted
  timestamp, and an optional operator answer.
- **Column**: A named board lane that maps one or more GitHub label values to a display
  position.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Within one dispatch cycle of a pup registering, the supervisor automatically
  assigns the highest-priority eligible issue to that pup without operator action.
- **SC-002**: The board reflects GitHub label changes within the configured refresh interval
  (default 30 seconds).
- **SC-003**: A pup picks up a dispatched issue within two poll cycles of it being assigned.
- **SC-004**: A pup completes a well-scoped issue end-to-end — worktree creation, commit, pull
  request — without operator intervention beyond the initial dispatch.
- **SC-005**: The supervisor handles 10 concurrently active pups without visible lag or dropped
  keypresses on the board.
- **SC-006**: After 1 hour of continuous operation with 10 active pups, the supervisor process
  remains stable with no observable memory growth trend.
- **SC-007**: A question posted by a pup appears in the operator's board within one board
  refresh cycle.

## Assumptions

- Repository clones are already present on the host running the pup; pups are not responsible
  for the initial clone operation.
- Each pup runs as a separate process reachable by the supervisor over HTTP; no shared memory
  or in-process communication is assumed.
- The supervisor is the single source of truth for issue assignment state; GitHub labels are
  the source of truth for column placement.
- Initial implementation targets one repository; the data model MUST be designed to support
  multiple repositories without a breaking schema change.
- Pups are stateless between task assignments; all task context is derived from the issue body
  and operator answers stored by the supervisor.
- The operator is a developer comfortable with a terminal-based keyboard-driven interface.
- Pull requests are opened against the default branch of the repository unless the issue
  specifies otherwise.
