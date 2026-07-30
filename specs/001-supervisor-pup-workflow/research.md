# Research: Supervisor and Pup Workflow

**Date**: 2026-06-28
**Feature**: specs/001-supervisor-pup-workflow/spec.md

## Decision 1: Non-Blocking HTTP Server + TUI Event Loop Integration

**Decision**: Use ReactPHP (`react/http`) — already in `composer.json` — as the supervisor's
embedded HTTP server. Run the ReactPHP event loop as the main loop; integrate php-tui re-renders
as periodic tasks registered on the loop timer.

**Rationale**: The supervisor must handle concurrent pup poll requests while keeping the TUI
responsive. Blocking I/O would freeze board rendering. ReactPHP's event loop supports both HTTP
listener and periodic timers in a single thread without threads or forking, which fits PHP's
execution model and keeps the process footprint small.

**Alternatives considered**:
- Raw socket server: More complex, reinvents what ReactPHP provides.
- Symfony HttpKernel with separate PHP-FPM: Requires a separate process, adds operational
  complexity, and makes in-memory state sharing between HTTP handler and TUI impossible.
- Polling in a `while(true)` busy-loop with `pcntl_*` for signal handling: Works but burns CPU
  and is harder to compose with timed board refreshes.

## Decision 2: Pup–Supervisor Communication Protocol

**Decision**: Simple JSON-over-HTTP REST API served by the supervisor. Pups are HTTP clients
(via GuzzleHTTP, already in stack). Pups poll; supervisor never pushes.

**Rationale**: Pull-based polling keeps pups stateless and crash-tolerant. Supervisor doesn't
need to track pup network addresses. GuzzleHTTP is already a dependency. The poll interval
(default 5s) is short enough for acceptable latency.

**Alternatives considered**:
- WebSockets / SSE (server-sent events): More complex to implement; pups would need to maintain
  persistent connections, adding reconnect logic. No benefit over short-poll given the 5s window.
- Shared database (Redis, SQLite): Adds an external dependency; in-memory state within the
  supervisor process is sufficient for a single-machine deployment.

## Decision 3: Worktree Lifecycle Management

**Decision**: Use `git worktree add` via `symfony/process` (already in stack). Each pup creates
a worktree at a configurable base path (`KANINE_WORKTREE_DIR`) named after the issue number.
On task completion or failure, the pup removes the worktree via `git worktree remove`.

**Rationale**: Git worktrees allow parallel branches to be checked out simultaneously without
interfering with the repository's main working tree or other pups. `symfony/process` provides
clean subprocess management with stdout/stderr capture.

**Alternatives considered**:
- Separate clones per pup: Much slower (full clone each time); disk-intensive.
- Single shared working tree with stash/branch switching: Unsafe with concurrent pups.

## Decision 4: Pull Request Creation

**Decision**: Use `knplabs/github-api` (already in stack) `PullRequest::create()` to open PRs.
The pup pushes the branch via `git push` (through `symfony/process`) and then calls the API.

**Rationale**: `knplabs/github-api` is already a declared dependency. It wraps the GitHub REST
API cleanly and handles authentication via the token from `GITHUB_TOKEN`.

**Alternatives considered**:
- `gh` CLI tool: Not guaranteed to be present in all environments; introduces a runtime
  dependency not under Composer's control.
- Raw curl to GitHub API: Verbose, no type safety, no retry handling.

## Decision 5: Autonomous Dispatch Algorithm

**Decision**: FIFO by issue creation date (oldest first), round-robin across idle pups when
multiple pups are idle simultaneously. Dispatch runs on a configurable tick (default 2s) inside
the ReactPHP event loop. Pinned issues are excluded from the eligible set.

**Rationale**: FIFO prevents starvation of older issues. Round-robin distributes load evenly
when pups free up simultaneously. A 2s dispatch tick is fast enough to feel immediate without
burning CPU.

**Alternatives considered**:
- Priority by issue label weight: More powerful but requires additional config and label
  conventions; deferred to future enhancement.
- Pup-initiated task claim (pups request work): Requires supervisor to expose a "claim" endpoint
  and handle contention. Supervisor-push (current design) keeps the assignment logic centralized
  and simpler to reason about.

## Decision 6: Pup Heartbeat and Inactivity Detection

**Decision**: Each `GET /pups/{pupId}/poll` request updates the pup's `lastHeartbeatAt`
timestamp in PupRegistry. If a pup misses 3 consecutive poll intervals (configurable), the
supervisor marks it inactive and releases its assigned issue back to the backlog.

**Rationale**: Reusing the poll endpoint as an implicit heartbeat eliminates a separate
`/heartbeat` endpoint. 3-miss threshold (default: 15s at 5s poll interval) tolerates transient
network hiccups without prematurely releasing assignments.

**Alternatives considered**:
- Separate `POST /pups/{pupId}/heartbeat` endpoint: More explicit but requires pups to maintain
  two timers (poll + heartbeat). Complicates pup logic unnecessarily.
- No inactivity detection: Risk of stuck assignments if a pup crashes silently.

## Decision 7: In-Memory State vs. Persistence

**Decision**: All supervisor state (PupRegistry, TaskQueue, IssueStore, QuestionStore) is
in-memory. On supervisor restart, pups must re-register, GitHub issues are re-fetched, and
in-flight questions are lost.

**Rationale**: In-memory state is the simplest correct design for v1. The system is designed
for operator-attended sessions; a supervisor restart is an operator action. Pup re-registration
is fast (one poll cycle). Questions can be re-asked.

**Alternatives considered**:
- SQLite persistence: Enables crash recovery but adds a dependency and complicates state
  transitions. Deferred to a future version if operator demand warrants it.
