<!--
Sync Impact Report
Version change: (none) → 1.0.0
Added sections: Core Principles (I–V), Additional Constraints, Development Workflow, Governance
Modified principles: N/A — initial ratification
Removed sections: N/A
Templates:
  - .specify/templates/plan-template.md ✅ Constitution Check gates populated
  - .specify/templates/spec-template.md ✅ No changes required — structure aligned
  - .specify/templates/tasks-template.md ✅ TDD ordering already aligned
Deferred: none
-->

# Kanine Constitution

## Core Principles

### I. Code Quality

All PHP source code MUST comply with PSR-12 as enforced by `phpcs`. Every file MUST declare
`strict_types=1`. Cyclomatic complexity per method MUST NOT exceed 10. Public APIs MUST carry
a single-line docblock stating intent. Dead code MUST be removed before merge; `// TODO: remove`
survivors are not acceptable.

**Rationale**: Kanine is a long-running terminal process managing external agent state across
multiple repositories. Inconsistent code quality amplifies debugging cost and raises the risk of
silent failures during autonomous pup operation.

### II. Test-Driven Development (NON-NEGOTIABLE)

Tests MUST be written and confirmed failing before any implementation begins. The
Red-Green-Refactor cycle is mandatory and non-negotiable. No feature MUST ship without passing
PHPUnit 11 tests covering the intended behavior. Writing tests after the fact or in parallel
with implementation does not satisfy this principle.

**Rationale**: Claude Code pups depend on Kanine's correctness. A bug in poll handling or label
logic causes silent wrong behavior across all repos. The TDD gate prevents regressions that only
surface at runtime deep inside the agent loop.

### III. Testing Standards

Every public domain class MUST have a unit test. GitHub API interactions, pup-protocol endpoints,
and config resolution MUST each have integration tests against real or contract-stubbed
dependencies. Snapshot or fixture tests MUST cover TUI rendering for all board views and card
states. Minimum line coverage per module is 80%; coverage MUST NOT decrease across a PR.

**Rationale**: Three distinct subsystems — TUI, supervisor HTTP, GitHub sync — interact
asynchronously. Test compartmentalization catches regressions in each layer independently and
prevents cross-subsystem assumptions from propagating silently.

### IV. User Experience Consistency

Keyboard shortcuts MUST remain stable across all views; no key MUST perform different actions
depending on focus context without explicit on-screen indication. The terminal MUST be fully
restored on exit — normal exit, error, or signal. The status footer MUST always display: current
view name, last-sync timestamp, and active pup count. Error messages MUST state what failed and
what the user can do next; generic messages such as "something went wrong" are forbidden.

**Rationale**: Kanine runs in developers' primary terminal for extended sessions. Inconsistent
shortcuts or a dirty terminal state after exit erodes trust and disrupts flow more than any
missing feature.

### V. Performance Requirements

Board re-render MUST complete within 100ms of receiving updated data. GitHub API polling MUST be
non-blocking and MUST NOT freeze the TUI event loop. Pup poll responses MUST return within
500ms at p95. The process MUST NOT exceed 64MB RSS memory after 1 hour of continuous operation
with 10 active pups and 5 repositories configured.

**Rationale**: Kanine is always visible in the developer's terminal. Any perceptible lag or
memory growth during a long session creates a perception of unreliability in the toolchain and
undermines confidence in dispatched pups.

## Additional Constraints

- **Token/Credential Handling**: GitHub tokens MUST be read from environment variables only.
  They MUST NOT appear in config files, logs, or error output under any circumstances.
- **TLS for Remote Pups**: The supervisor MUST enforce TLS when `supervisor.host` is not
  `127.0.0.1` or `::1`.
- **Config Validation at Boot**: Any missing required config key MUST produce a clear error
  naming the key and exit before attempting network I/O.
- **No Silent Data Loss**: Label mutations on GitHub MUST be confirmed via API response.
  Failed mutations MUST be surfaced as board-level warnings, not silently dropped.

## Development Workflow

- All changes MUST land via PR; direct commits to `main` are forbidden.
- Specs and docs MUST be drafted or updated before implementation begins.
- Dangerous actions (git commit, git push, gh CLI, CI triggers) MUST use support scripts under
  `.claude/support-scripts/`.
- PRs MUST pass `composer test` and `composer lint` before merge.
- Each PR MUST include a Constitution Check confirming no principles are violated, or documenting
  a justified exception in the Complexity Tracking table.

## Governance

This constitution supersedes all other stated practices. Amendments require: (1) a PR updating
this file, (2) a version bump per the rules below, (3) an updated Sync Impact Report in the
HTML comment above, and (4) propagated changes to affected templates.

**Versioning policy**:
- MAJOR: principle removed, redefined, or governance structure changed incompatibly.
- MINOR: new principle or section added, or materially expanded guidance.
- PATCH: clarification, wording refinement, or typo fix.

All PRs and code reviews MUST verify constitution compliance. Complexity exceptions MUST be
documented in the plan's Complexity Tracking table. Compliance review is expected at PR review
time; automated linting enforces Code Quality (Principle I) only.

**Version**: 1.0.0 | **Ratified**: 2026-06-28 | **Last Amended**: 2026-06-28
