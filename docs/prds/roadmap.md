# Kanine — Release Roadmap

## V0.1 — End-to-End Dispatch (complete)

Supervisor queues GitHub Issues tagged `kanine: ready`, pups poll and run `claude --headless`.
No TUI, no worktrees, no completion signal.

## V0.2 — Close the Loop (complete)

Label-based prompt resolution, live status reporting, task completion signal, GitHub label write-back, usage throttling.
No TUI, no worktrees, no persistence.

## V0.3 — Git Worktrees

Isolate each pup in a dedicated git worktree so multiple pups can safely work the same repo in parallel.
Foundational for milestone-aware dispatch — "actionable" gating depends on knowing which pup owns which worktree.

## V0.4 — Persistence

SQLite sidecar persists task queue and pup registry across supervisor restarts.
Builds on V0.3: worktree-to-pup mappings are worth persisting once worktrees exist.

## V0.5 — Milestone Priorities

Priority-ordered dispatch across GitHub milestones, with a designated cleanup milestone as fallback.

- Config defines an ordered list of milestone tiers: `[milestone-a, milestone-b, ...]`
- Supervisor dequeues from tier 1 first; moves to tier 2 only when tier 1 has no actionable issues
- A designated `cleanup` milestone is pulled from only when all configured tiers are empty or blocked
- "Actionable" = issue is `queued` and no pup already holds a worktree for that repo/branch
- Replaces pure FIFO with priority-aware dequeue
- Tier state survives supervisor restarts (requires V0.4 persistence)

## V0.6 — Question / Answer Flow

Protocol already specced (`POST /tasks/{id}/questions`).
Pup posts a blocking question; supervisor surfaces it; human answers; pup resumes.
Enables blocked-task second-assignment: pup picks up another task while waiting on a question.

## V0.7 — TUI

Live terminal board using `php-tui` (already a dependency).
Pup status, active tasks, queue depth, milestone tier swim lanes (from V0.5), usage throttle meter.

---

## Nice to Have Features

Features not tied to a specific version. Candidates for any release where they fit naturally.

### Operation Window (high value, low complexity)

Configure a time window during which pups accept new tasks. Outside the window, pups finish any in-flight task then stop polling until the window reopens. Useful for overnight runs or off-hours automation.

```yaml
schedule:
  timezone: America/Chicago
  window:
    start: "21:00"   # 9 PM
    end:   "06:00"   # 6 AM
```

Supervisor broadcasts window state on each poll response. Pups outside the window log "outside operation window — sleeping" and back off to a long poll interval (e.g. 5 min) rather than exit, so they resume automatically when the window opens.

### Pup Stale Detection / Auto-Deregistration

Supervisor tracks last-seen timestamp per pup. Pup that misses N consecutive polls (configurable) is marked stale, its assigned task returned to the queue, and its worktree cleaned up. Prevents stuck tasks from blocking the queue indefinitely.

### Task Retry

Failed tasks can be automatically re-queued up to a configurable max retry count. Retry count and failure reason stored in persistence layer (requires V0.4). Useful when failures are transient (network blip, rate limit).

### Task Dependencies

Issue B won't be dispatched until issue A is `Complete`. Expressed via a config file or a GitHub issue body convention (e.g. `depends-on: #42`). Enables sequenced work within a milestone.

### Notifications

Push a message to Slack, Discord, or email when a task completes, fails, or a pup posts a question. Configurable per-event. Lets you walk away and get notified when human input is needed.

### Remote Pups / TLS

Pups on remote machines (CI runners, VMs, other dev boxes). Requires TLS and token-based auth hardening. Currently localhost-only by design.

### Dry Run Mode

`kanine serve --dry-run` fetches and queues issues, logs what would be dispatched, but never assigns tasks to pups. Useful for validating milestone priority config without burning API tokens.

### Cost / Token Tracking

Aggregate usage percentages across all pups over time. Log estimated session cost per task. Surface in TUI (V0.7). Helps identify expensive tasks and plan token budget across a work window.
