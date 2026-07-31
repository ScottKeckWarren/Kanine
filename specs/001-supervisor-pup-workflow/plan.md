# Implementation Plan: Supervisor and Pup Workflow

**Branch**: `001-supervisor-pup-workflow` | **Date**: 2026-06-28 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-supervisor-pup-workflow/spec.md`

## Summary

Build the complete supervisor + pup workflow: a TUI Kanban board (`bin/kanine`) that autonomously
dispatches GitHub issues to registered pup agents (`bin/kanine --pup`), which create git
worktrees, perform implementation via Claude Code, commit changes, and open pull requests.
Supervisor communicates with pups over a ReactPHP-backed JSON HTTP API. State is in-memory;
GitHub labels are the source of truth for column placement.

## Technical Context

**Language/Version**: PHP 8.2+

**Primary Dependencies**: symfony/console, php-tui/php-tui, react/http, symfony/process,
knplabs/github-api, guzzlehttp/guzzle, monolog/monolog, symfony/yaml

**Storage**: In-memory (PupRegistry, TaskQueue, QuestionStore within supervisor process);
YAML for configuration; no persistent database in v1

**Testing**: PHPUnit 11 (`composer test`); phpcs (`composer lint`)

**Target Platform**: macOS / Linux terminal (developer workstation)

**Project Type**: CLI/TUI application (supervisor) + CLI agent process (pup)

**Performance Goals**: Board re-render ≤100ms; pup poll response p95 ≤500ms;
supervisor ≤64MB RSS after 1h with 10 pups + 5 repos

**Constraints**: Terminal fully restored on exit; GitHub token resolved via `--token` CLI
flag, `github.token` in gitignored `.kanine/kanine.local.yaml` (0600), inline
`github.token`/`github.token_env` in tracked config, or env var, in that priority order;
never in logs or errors; missing token on first run prompts with all options and exits
before network I/O; TLS required when supervisor not bound to localhost; config validated
at boot before network I/O

**Scale/Scope**: 10 concurrent pups, up to 5 repositories, hours-long continuous operation

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Code Quality (I)**: All PHP files declare `strict_types=1`; no method exceeds cyclomatic
  complexity 10; PSR-12 enforced by phpcs in CI
- [x] **TDD (II)**: Failing tests written and confirmed red before any implementation task begins;
  enforced by task ordering in tasks.md
- [x] **Testing Standards (III)**: Unit tests for all domain classes; integration tests for
  HttpServer API, GitHub API interactions, and config resolution; snapshot tests for TUI board
  and card rendering; coverage target ≥80% per module
- [x] **UX Consistency (IV)**: Keyboard shortcuts documented in quickstart.md and stable across
  all views; terminal restoration via php-tui teardown on exit/signal; all error messages name
  the failing component and next action
- [x] **Performance (V)**: ReactPHP event loop keeps HTTP server non-blocking; board renders
  from in-memory state (no I/O on render path); pup poll endpoint returns immediately from
  in-memory store
- [x] **Additional Constraints**: GitHub token resolved from `--token` CLI flag or
  `github.token` in gitignored, 0600 `.kanine/kanine.local.yaml`; missing token on startup
  prints both-option instructions and exits before I/O; TLS gate enforced by ConfigValidator
  when host ≠ localhost; config validated in boot sequence before any GitHub or HTTP I/O
- [x] **Exceptions**: None

## Project Structure

### Documentation (this feature)

```text
specs/001-supervisor-pup-workflow/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   └── pup-api.md
└── tasks.md             # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root)

```text
src/
├── Config/
│   ├── ConfigInitializer.php          # exists
│   ├── ConfigInitializerInterface.php # exists
│   ├── ConfigLoader.php               # exists
│   ├── ConfigLoaderInterface.php      # exists
│   └── Configuration.php             # exists
├── Console/
│   └── Command/
│       ├── InitCommand.php            # exists
│       ├── ServeCommand.php           # exists — extend with board + dispatch loop
│       └── PupCommand.php             # exists — extend with worktree + PR creation
├── Domain/
│   ├── Pup.php                        # exists
│   ├── PupStatus.php                  # exists
│   ├── Task.php                       # exists
│   ├── TaskState.php                  # exists
│   ├── Issue.php                      # NEW
│   ├── Question.php                   # NEW
│   └── Column.php                     # NEW
├── GitHub/
│   ├── GitHubClient.php               # exists
│   ├── GitHubClientInterface.php      # exists
│   ├── IssueLoader.php                # exists
│   ├── IssueLoaderInterface.php       # exists
│   └── LabelMapper.php                # NEW — maps GitHub labels to Column names
├── Pup/
│   ├── ClaudeRunner.php               # exists
│   ├── GitHubLabelWriter.php          # exists
│   ├── GitHubLabelWriterInterface.php # exists
│   ├── PromptResolver.php             # exists
│   ├── PromptResolverInterface.php    # exists
│   ├── PupClient.php                  # exists — extend with question/answer polling
│   ├── PupClientInterface.php         # exists
│   ├── WorktreeManager.php            # NEW
│   └── PullRequestCreator.php         # NEW
├── Supervisor/
│   ├── HttpServer.php                 # exists — extend with question + answer endpoints
│   ├── HttpServerInterface.php        # exists
│   ├── PupRegistry.php                # exists
│   ├── Supervisor.php                 # exists — extend with autonomous dispatch loop
│   ├── SupervisorInterface.php        # exists
│   ├── TaskQueue.php                  # exists
│   ├── UsageTracker.php               # exists
│   ├── Dispatcher.php                 # NEW — autonomous dispatch loop logic
│   ├── IssueStore.php                 # NEW — in-memory issue + pin state
│   └── QuestionStore.php              # NEW — in-memory question/answer store
├── Board/
│   ├── BoardRenderer.php              # NEW — php-tui board layout
│   ├── CardRenderer.php               # NEW — issue card widget
│   └── FooterRenderer.php             # NEW — status footer widget
├── Logger/
│   └── LoggerFactory.php              # exists
└── ValueObject/
    └── String/
        └── Prompt.php                 # exists

tests/
├── Unit/
│   ├── Domain/
│   ├── Supervisor/
│   ├── Pup/
│   ├── GitHub/
│   └── Board/
├── Integration/
│   ├── Supervisor/        # HttpServer API contract tests
│   ├── GitHub/            # IssueLoader, LabelMapper against stub
│   └── Pup/               # WorktreeManager, PullRequestCreator
└── Snapshot/
    └── Board/             # php-tui snapshot tests for board views
```

**Structure Decision**: Single-project layout extending existing `src/` and `tests/`
namespaces. New `Board/` namespace isolates TUI rendering. New `Supervisor/Dispatcher.php`
encapsulates autonomous dispatch logic separate from the HTTP server and state stores.

## Complexity Tracking

> No constitution violations — table left empty intentionally.
