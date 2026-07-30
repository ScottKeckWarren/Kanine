---
description: "Task list for Supervisor and Pup Workflow"
---

# Tasks: Supervisor and Pup Workflow

**Input**: Design documents from `specs/001-supervisor-pup-workflow/`

**Prerequisites**: plan.md ✅, spec.md ✅, data-model.md ✅, contracts/pup-api.md ✅,
research.md ✅, quickstart.md ✅

**TDD**: All implementation tasks MUST be preceded by a failing test. Tasks are ordered
to enforce Red-Green-Refactor.

**Organization**: Tasks grouped by user story to enable independent implementation and testing.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story (US1–US4)
- Include exact file paths in all descriptions

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Verify existing project wiring; extend Configuration for new supervisor keys.

- [x] T001 Verify all plan.md dependencies present in composer.json: react/http, guzzlehttp/guzzle, symfony/process, knplabs/github-api
- [x] T002 [P] Add `supervisor.dispatch_interval_seconds` and `supervisor.pup_timeout_seconds` keys to `src/Config/Configuration.php`
- [x] T003 [P] Add TLS enforcement rule to `src/Config/ConfigLoader.php`: if `supervisor.host` ≠ `127.0.0.1` and `::1`, require `supervisor.tls = true` or exit with named error

**Checkpoint**: Config extended and validated — foundational work can begin.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Domain entities and stores that ALL user stories depend on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T004 [P] Write failing test: `Issue` has id, repo, title, body, labels, column, pinned, assignedPupId, fetchedAt in `tests/Unit/Domain/IssueTest.php`
- [x] T005 [P] Create `src/Domain/Issue.php` with fields per data-model.md; `pinned` defaults false; `assignedPupId` nullable string
- [x] T006 [P] Write failing test: `Question` stores body, postedAt, answer, answeredAt in `tests/Unit/Domain/QuestionTest.php`
- [x] T007 [P] Create `src/Domain/Question.php` with id (UUID), taskId, pupId, body, postedAt, answer (nullable), answeredAt (nullable)
- [x] T008 [P] Write failing test: `Column` holds name, label, position in `tests/Unit/Domain/ColumnTest.php`
- [x] T009 [P] Create `src/Domain/Column.php` with name, label, position fields
- [x] T010 Write failing test: `LabelMapper` returns correct column name for matching label; falls back to position-0 column in `tests/Unit/GitHub/LabelMapperTest.php`
- [x] T011 Create `src/GitHub/LabelMapper.php`: given issue labels and Column config array, return first matching column name; fallback to position-0 column name
- [x] T012 Write failing test: `IssueStore` stores issues, pin/unpin, assign/unassign, filters eligible issues in `tests/Unit/Supervisor/IssueStoreTest.php`
- [x] T013 Create `src/Supervisor/IssueStore.php`: in-memory store for Issue objects; methods: add, getAll, getEligible (unpinned + unassigned), pin, unpin, assign, unassign

**Checkpoint**: Domain + stores ready — all user story phases can begin in parallel.

---

## Phase 3: User Story 1 — Board Renders and Auto-Refreshes (Priority: P1) 🎯 MVP

**Goal**: `bin/kanine` launches, renders Kanban board with issues in correct columns,
auto-refreshes, handles keyboard navigation, exits cleanly.

**Independent Test**: Run `bin/kanine` with config pointing to a test repo; see populated board;
press `q`; terminal fully restored. See quickstart.md § Validate: US1.

### Tests for User Story 1 ⚠️ Write FIRST — confirm red before implementing

- [x] T014 [P] [US1] Write failing test: `BoardRenderer` renders correct number of columns from config in `tests/Unit/Board/BoardRendererTest.php`
- [x] T015 [P] [US1] Write failing snapshot test: board layout matches expected fixture in `tests/Snapshot/Board/BoardSnapshotTest.php`
- [x] T016 [P] [US1] Write failing test: `ServeCommand` exits and restores terminal on `q` keypress in `tests/Unit/Console/Command/ServeCommandTest.php` *(file exists — add specific terminal-restore test case)*

### Implementation for User Story 1

- [x] T017 [P] [US1] Create `src/Board/CardRenderer.php`: php-tui widget for an issue card showing issue number, title, repo badge, assigned pup ID (if any)
- [x] T018 [P] [US1] Create `src/Board/FooterRenderer.php`: php-tui status bar showing view name, last-sync timestamp, active pup count, optional warning message
- [x] T019 [US1] Create `src/Board/BoardRenderer.php`: php-tui Kanban layout composing Column headers + CardRenderer cards; constructed from Column array + Issue array
- [x] T020 [US1] Extend `src/GitHub/IssueLoader.php` to map fetched GitHub issues through `LabelMapper` and populate `IssueStore` with `Issue` domain objects
- [x] T021 [US1] Wire `BoardRenderer` + `IssueStore` into `src/Console/Command/ServeCommand.php` using ReactPHP loop periodic timer at `refresh.interval_seconds`
- [x] T022 [US1] Implement keyboard handling in `ServeCommand`: `q` (quit + terminal restore), `r` (force refresh), `a` (toggle auto-refresh), `←`/`→` (column nav), `↑`/`↓` (card nav)

**Checkpoint**: US1 complete — board visible, navigable, refreshes, exits clean. Validate independently.

---

## Phase 4: User Story 2 — Autonomous Dispatch (Priority: P2)

**Goal**: Supervisor dispatch loop assigns backlog issues to idle pups without operator action.
Pup registers, polls, receives assignment. Board reflects assignments automatically.

**Independent Test**: Start supervisor + one pup; backlog issue moves to In Progress automatically
within 2 dispatch cycles. Board pup count shows 1. See quickstart.md § Validate: US2.

### Tests for User Story 2 ⚠️ Write FIRST — confirm red before implementing

- [x] T023 [P] [US2] Write failing test: `Dispatcher` assigns oldest eligible issue to first idle pup; skips pinned issues in `tests/Unit/Supervisor/DispatcherTest.php`
- [x] T024 [P] [US2] Write failing test: `PupRegistry` returns idle pups and updates heartbeat on poll in `tests/Unit/Supervisor/PupRegistryTest.php` *(file exists — add test cases for new getIdlePups/updateHeartbeat methods)*
- [x] T025 [P] [US2] Write failing integration test: register → poll cycle returns assignment in `tests/Integration/Supervisor/HttpServerTest.php` *(file exists — add register→poll test case)*

### Implementation for User Story 2

- [x] T026 [US2] Extend `src/Supervisor/PupRegistry.php` with `getIdlePups()`, `updateHeartbeat(string $pupId)`, and `markInactive(string $pupId)` methods *(7 methods exist; add these 3)*
- [x] T027 [US2] Create `src/Supervisor/Dispatcher.php`: FIFO dispatch — fetch oldest eligible issue from IssueStore; assign to first idle pup from PupRegistry; update IssueStore assignment
- [x] T028 [US2] Add `POST /pups/register` endpoint to `src/Supervisor/HttpServer.php`: validate pupId UUID, register in PupRegistry, return `{ok, pollIntervalSeconds}`
- [x] T029 [US2] Update `POST /pups/{pupId}/poll` → `GET /pups/{pupId}/poll` in `src/Supervisor/HttpServer.php`: change method, update heartbeat, return `{assignment, pendingAnswers}`; assignment only when pup is idle *(endpoint exists as POST — change method + add pendingAnswers + heartbeat update)*
- [x] T030 [US2] Add `DELETE /pups/{pupId}` endpoint to `src/Supervisor/HttpServer.php`: deregister pup; release assignment back to IssueStore
- [x] T031 [US2] Wire `Dispatcher` into `ServeCommand` ReactPHP loop periodic timer at `supervisor.dispatch_interval_seconds`
- [x] T032 [P] [US2] Extend `src/Pup/PupClient.php` with `register(string $pupId): array` and `poll(string $pupId): array` per contracts/pup-api.md
- [x] T033 [US2] Extend `src/Console/Command/PupCommand.php`: call `PupClient::register()` at startup; start poll loop via ReactPHP loop timer; store received assignment locally *(PupCommand exists — add registration + poll loop wiring)*
- [ ] T034 [US2] Update `FooterRenderer` to display live pup count; show "No pups available" warning when pup count = 0 and eligible issues exist
- [ ] T035 [US2] Add `p` key handler in `ServeCommand` to pin/unpin selected issue via `IssueStore`; update `CardRenderer` to show pinned indicator on card

**Checkpoint**: US1 + US2 both independently functional. Board auto-dispatches without operator input.

---

## Phase 5: User Story 3 — Pup Performs Work End-to-End (Priority: P3)

**Goal**: Pup receives assignment, creates worktree, runs Claude Code, commits, opens PR,
reports complete. Supervisor releases assignment; card moves column.

**Independent Test**: Pup receives issue, creates worktree at `KANINE_WORKTREE_DIR/issue-N`,
PR appears on GitHub. See quickstart.md § Validate: US3.

### Tests for User Story 3 ⚠️ Write FIRST — confirm red before implementing

- [x] T036 [P] [US3] Write failing test: `WorktreeManager::create()` runs `git worktree add` at correct path in `tests/Unit/Pup/WorktreeManagerTest.php`
- [x] T037 [P] [US3] Write failing test: `WorktreeManager::remove()` runs `git worktree remove` in `tests/Unit/Pup/WorktreeManagerTest.php`
- [x] T038 [P] [US3] Write failing test: `PullRequestCreator` calls GitHub API with correct repo, branch, title in `tests/Unit/Pup/PullRequestCreatorTest.php`
- [x] T039 [P] [US3] Write failing integration test: status `POST /pups/{pupId}/status` transitions pup state correctly in `tests/Integration/Supervisor/HttpServerTest.php` *(file exists — add status transition test case)*

### Implementation for User Story 3

- [x] T040 [US3] Create `src/Pup/WorktreeManager.php`: `create(int $issueId, string $repoPath, string $worktreeBase): string` — runs `git worktree add` via symfony/process, returns worktree path; `remove(string $worktreePath)` runs `git worktree remove`
- [x] T041 [US3] Create `src/Pup/PullRequestCreator.php`: push branch via `git push` (symfony/process); create PR via `knplabs/github-api` `PullRequest::create()` against default branch; return PR URL
- [x] T042 [US3] Add `POST /pups/{pupId}/status` endpoint to `src/Supervisor/HttpServer.php`: validate status transition, update PupRegistry and IssueStore; on `complete`/`failed` release assignment *(`/tasks/{taskId}/status` exists — add new pup-scoped route)*
- [x] T043 [P] [US3] Extend `src/Pup/PupClient.php` with `reportStatus(string $pupId, string $status, string $message = ''): void` per contracts/pup-api.md *(postStatus() + postComplete() exist — verify signature matches contract)*
- [x] T044 [US3] Extend `src/Pup/ClaudeRunner.php` to accept a working directory parameter and run Claude Code within the worktree path *(exists — add working directory support)*
- [x] T045 [US3] Extend `src/Console/Command/PupCommand.php` to orchestrate full task lifecycle on assignment receipt: create worktree → run ClaudeRunner → commit → PullRequestCreator → reportStatus complete; on failure: cleanup worktree → reportStatus failed *(exists — wire full lifecycle)*
- [x] T046 [US3] Update `ServeCommand` to handle status transitions from HttpServer: when pup reports complete/failed, re-fetch issue labels and update IssueStore column placement

**Checkpoint**: US1 + US2 + US3 all independently functional. Pups work issues end-to-end.

---

## Phase 6: User Story 4 — Operator Answers Pup Questions (Priority: P4)

**Goal**: Pup posts question; supervisor displays it in card detail view; operator types answer;
pup retrieves answer on next poll and resumes.

**Independent Test**: POST `/pups/{pupId}/questions`; open card detail; answer question;
poll returns answer in `pendingAnswers`. See quickstart.md § Validate: US4.

### Tests for User Story 4 ⚠️ Write FIRST — confirm red before implementing

- [x] T047 [P] [US4] Write failing test: `QuestionStore` stores question, returns answer in poll, clears after return in `tests/Unit/Supervisor/QuestionStoreTest.php`
- [ ] T048 [P] [US4] Write failing snapshot test: detail view renders question list and text input in `tests/Snapshot/Board/DetailViewSnapshotTest.php` *(deferred — requires live TUI)*
- [x] T049 [P] [US4] Write failing integration test: post question → answer via board → poll returns answer in `tests/Integration/Supervisor/HttpServerTest.php`

### Implementation for User Story 4

- [x] T050 [US4] Create `src/Supervisor/QuestionStore.php`: in-memory store keyed by pupId + questionId; `add(Question)`, `getPending(string $pupId): array`, `answer(string $questionId, string $body)`, `popAnswered(string $pupId): array` (clears on retrieval)
- [x] T051 [US4] Add `POST /pups/{pupId}/questions` endpoint to `src/Supervisor/HttpServer.php`: validate fields, store in QuestionStore, return `{ok}`
- [x] T052 [US4] Update `GET /pups/{pupId}/poll` in `src/Supervisor/HttpServer.php` to include `pendingAnswers` from `QuestionStore::popAnswered()`; answers cleared after response
- [x] T053 [P] [US4] Extend `src/Pup/PupClient.php` with `postQuestion(string $pupId, string $questionId, string $body): void` per contracts/pup-api.md
- [x] T054 [US4] Extend `PupCommand` to post questions via `PupClient::postQuestion()` when ClaudeRunner signals ambiguity; retrieve answers from poll response and feed back to ClaudeRunner
- [x] T055 [US4] Update `CardRenderer` to show `?` indicator on cards that have open unanswered questions
- [ ] T056 [US4] Add detail view to `ServeCommand` (Enter key): php-tui panel showing issue body, pup status message, ordered question list, text input field for typing answers; submit answer with Enter *(deferred — requires live TUI)*
- [ ] T057 [US4] Wire answer submission in detail view to `QuestionStore::answer()` in supervisor (direct in-process call from ServeCommand) *(deferred — requires live TUI)*

**Checkpoint**: All four user stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Inactivity detection, error message audit, comprehensive test coverage.

- [ ] T058 [P] Implement inactivity detection in `src/Supervisor/PupRegistry.php`: each Dispatcher tick checks `lastHeartbeatAt` against `pup_timeout_seconds`; calls `markInactive()` and releases assignment via IssueStore
- [ ] T059 [P] Audit error messages in `src/Supervisor/HttpServer.php`, `src/Console/Command/ServeCommand.php`, `src/Console/Command/PupCommand.php`: each must name what failed + what to do next (no generic messages)
- [ ] T060 [P] Write integration test: full dispatch → work → complete cycle in `tests/Integration/Supervisor/FullCycleTest.php`
- [ ] T061 [P] Write snapshot tests for remaining board states: empty board, board with pinned issue, board with active + idle pups in `tests/Snapshot/Board/`
- [ ] T062 Manual validation: run supervisor with 10 simulated pups per quickstart.md memory profile scenario; confirm RSS ≤ 64MB after 60 minutes

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 — BLOCKS all user stories
- **US1 (Phase 3)**: Depends on Foundational completion
- **US2 (Phase 4)**: Depends on US1 completion (board must exist to reflect dispatch)
- **US3 (Phase 5)**: Depends on US2 completion (assignment must exist to work)
- **US4 (Phase 6)**: Depends on US3 completion (status transitions must work before Q&A)
- **Polish (Phase 7)**: Depends on all user stories complete

### User Story Dependencies

- **US1**: Independent after Foundational
- **US2**: Depends on US1 (FooterRenderer for pup count; CardRenderer for pup ID)
- **US3**: Depends on US2 (assignment comes from US2 dispatch)
- **US4**: Depends on US3 (question flow requires status transitions from US3)

### Within Each User Story

1. Write tests FIRST — confirm they FAIL (red)
2. Implement until tests pass (green)
3. Refactor if needed
4. Models/stores before services
5. Services before HTTP endpoints
6. HTTP endpoints before command wiring
7. Command wiring before board display

### Parallel Opportunities

- T004–T009: All domain entity tests + files run in parallel
- T014–T016: All US1 test stubs run in parallel
- T017–T018: CardRenderer + FooterRenderer creation runs in parallel
- T023–T025: US2 test stubs run in parallel
- T028–T030: HttpServer endpoint additions run in parallel (different routes)
- T036–T039: US3 test stubs run in parallel
- T047–T049: US4 test stubs run in parallel
- T058–T061: All polish tasks run in parallel

---

## Parallel Example: User Story 2

```bash
# Write all tests for US2 together first:
Task: T023 — DispatcherTest.php
Task: T024 — PupRegistryTest.php
Task: T025 — HttpServerTest.php (register/poll)

# Then implement in order:
Task: T026 — PupRegistry methods
Task: T027 — Dispatcher
# Then parallel:
Task: T028 — HttpServer POST /pups/register
Task: T029 — HttpServer GET /pups/{pupId}/poll
Task: T030 — HttpServer DELETE /pups/{pupId}
Task: T032 — PupClient register() + poll()
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL — blocks everything)
3. Complete Phase 3: US1 — Board renders with issues
4. **STOP AND VALIDATE**: Run `bin/kanine`, see board, press `q`, terminal restored
5. Demonstrate independently before proceeding

### Incremental Delivery

1. Setup + Foundational → ready for stories
2. US1 → board renders → validate → demo
3. US2 → autonomous dispatch → validate (pup registers + receives assignment)
4. US3 → pup works issue end-to-end → validate (PR appears on GitHub)
5. US4 → Q&A loop → validate (question answered, pup resumes)
6. Polish → inactivity detection, error audits, full test coverage

---

## Notes

- `[P]` = different files, no incomplete task dependencies — safe to run in parallel
- `[US#]` label maps task to its user story for independent traceability
- Tests marked ⚠️ MUST be written first and confirmed red before implementation begins
- Each user story phase must be independently demonstrable before moving to the next
- Existing source files (PupRegistry, TaskQueue, HttpServer, PupClient, ClaudeRunner, etc.)
  are extended in-place — do not create duplicates
