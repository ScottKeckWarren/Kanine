# Kanine — Project Requirements Document

> A PHP terminal UI that turns GitHub Issues into a live Kanban board and hands work
> off to Claude Code agents running in git submodules.

- **Package:** `scottkeckwarren/kanine`
- **Namespace:** `ScottKeckWarren\Kanine`
- **Binary:** `./vendor/bin/kanine`
- **Status:** Draft v0.1
- **License:** MIT (proposed)

---

## 1. Summary

Kanine is an open-source, PHP-based terminal user interface (TUI) that renders the
issues of one or more GitHub repositories as a Kanban board. Issue **labels** drive
which **column** (state) a card sits in. From the board, a developer can dispatch an
issue to a **Claude Code** agent that runs headlessly inside a dedicated **git
submodule**, and the board shows, in near real time, what each agent is currently
doing.

The tool is installed with Composer and launched as `./vendor/bin/kanine`. The first
deliverable (Phase 0) is a running TUI that draws the columns and supports a
configurable, toggleable refresh rate — no GitHub or agent integration yet.

---

## 2. Problem & Motivation

Solo developers and small teams increasingly delegate well-scoped work to AI coding
agents, but the orchestration is ad hoc: issues live in GitHub, agents run in
scattered terminal tabs, and there is no single surface that answers "what is in
flight, what state is it in, and what is each agent doing right now?"

Kanine consolidates that into one keyboard-driven board that lives where developers
already work — the terminal — and treats GitHub Issues as the source of truth rather
than introducing a parallel task system.

---

## 3. Goals & Non-Goals

### 3.1 Goals

1. Render GitHub Issues as a Kanban board inside the terminal.
2. Use GitHub issue **labels** as the mechanism for column/state assignment.
3. Provide a configurable, toggleable refresh rate so the board can poll for changes.
4. Dispatch an issue to a Claude Code agent running in a dedicated git submodule.
5. Surface live, per-card agent status ("what each issue is doing").
6. Install via Composer; run via a single `./vendor/bin/kanine` entry point.
7. Be configuration-driven and approachable for OSS contributors.

### 3.2 Non-Goals (initial releases)

- Not a replacement for GitHub Projects, Jira, or a full PM suite.
- No web UI; terminal only.
- No write-back of arbitrary issue edits beyond label/state transitions.
- No multi-provider support at launch (GitHub only; GitLab/Gitea are future work).
- No hosted/daemon service; it runs as a foreground TUI process.

---

## 4. Target Users

- **Solo developers** orchestrating one or more AI agents across a backlog.
- **Small teams** that already track work as GitHub Issues and want a shared,
  terminal-native view of in-flight work.
- **Tinkerers / OSS contributors** comfortable in PHP and the terminal.

This mirrors the "solo devs and small teams orchestrating AI coding agents with
GitHub Issues" audience.

---

## 5. Core Concepts (Domain Model)

| Concept | Description |
|---|---|
| **Board** | The whole view; owns an ordered set of columns and the active config. |
| **Column** | A named lane representing a state (e.g. *Backlog, Todo, In Progress, Review, Done*). |
| **Card** | A single GitHub Issue rendered in a column. |
| **State** | The logical status of an issue, derived from its labels. |
| **Label mapping** | Configurable rules that map GitHub labels → columns/states. |
| **Agent** | A Claude Code process assigned to an issue. |
| **Submodule** | The git submodule (working copy) an agent operates in. |
| **Refresh cycle** | The periodic poll that re-pulls issues and agent status. |

A `State` is best modeled as a backed enum, with the label-to-state resolution
encapsulated in a small state-machine so transitions are explicit and testable.

```php
enum State: string
{
    case Backlog    = 'backlog';
    case Todo       = 'todo';
    case InProgress = 'in_progress';
    case Review     = 'review';
    case Done       = 'done';
}
```

---

## 6. Functional Requirements

### 6.1 Board rendering
- **FR-1** Draw an ordered set of columns spanning the terminal width.
- **FR-2** Render each issue as a card under the column its labels resolve to.
- **FR-3** Show a per-card summary line (issue number, title, assignee/agent, status).
- **FR-4** Reflow gracefully on terminal resize.
- **FR-5** Provide a visible footer/status bar (refresh state, last sync time, keybinds).

### 6.2 GitHub integration
- **FR-6** Fetch open issues for one or more configured repositories.
- **FR-7** Resolve each issue's column from its labels via the configured mapping.
- **FR-8** Authenticate via a token from config, `GITHUB_TOKEN`, or `gh auth token`.
- **FR-9** Handle/report rate limiting and network errors without crashing the TUI.

### 6.3 Refresh
- **FR-10** Poll GitHub (and agent status) on a configurable interval.
- **FR-11** Allow the refresh to be toggled on/off at runtime via a keybind.
- **FR-12** Allow the interval to be changed at runtime (cycle/increase/decrease).
- **FR-13** Support manual refresh on demand (e.g. `r`) regardless of auto-refresh.

### 6.4 Agent delegation (later phase)
- **FR-14** Dispatch a selected issue to a Claude Code agent in a git submodule.
- **FR-15** Run the agent headlessly (non-interactive) and capture status/output.
- **FR-16** Display live agent status on the card ("what each issue is doing").
- **FR-17** Move the card's column/labels as the agent progresses or completes.
- **FR-18** Allow stopping/cleaning up an agent and its submodule.

### 6.5 Navigation
- **FR-19** Keyboard navigation across columns and cards.
- **FR-20** Open the selected issue's detail (body, labels, agent log).
- **FR-21** Quit cleanly (`q`), restoring the terminal to a sane state.

---

## 7. Non-Functional Requirements

- **NFR-1 (Platform)** PHP **8.2+** minimum. Use enums and readonly properties;
  treat PHP 8.4 property hooks as optional niceties, not a hard dependency.
- **NFR-2 (Terminal)** Work in common terminals (xterm-256color, modern macOS/Linux
  terminals). Windows support is best-effort/secondary at launch.
- **NFR-3 (Performance)** A refresh cycle must never block input handling; rendering
  stays responsive during network/agent I/O.
- **NFR-4 (Resilience)** Network, auth, and agent failures degrade gracefully and are
  surfaced in the status bar — never an uncaught exception that corrupts the terminal.
- **NFR-5 (Testability)** Core logic (config, label→state mapping, board model) is
  unit-testable without a terminal or network, supporting a TDD workflow.
- **NFR-6 (Footprint)** Keep dependencies lean; prefer well-maintained, focused libs.

---

## 8. Technical Architecture

### 8.1 Proposed stack

| Concern | Choice | Notes |
|---|---|---|
| CLI entry / commands | `symfony/console` | Standard, well-understood command layer. |
| TUI rendering | `php-tui/php-tui` | Mature PHP port of Rust's Ratatui; rich widgets/layout. |
| Config parsing | `symfony/yaml` | Friendly, OSS-conventional config files. |
| GitHub API | `knplabs/github-api` | Established PHP GitHub client. |
| Agent runtime | Claude Code (headless) | Invoked as a subprocess per submodule. |
| Process control | `symfony/process` | Manage agent subprocesses safely. |

> **Watch item:** Symfony recently published an experimental `symfony/tui` component.
> It is promising but experimental (no BC promise yet); `php-tui/php-tui` is the safer
> default for now. Keep the rendering layer behind an interface so a future swap is cheap.

### 8.2 Suggested package layout

```
kanine/
├── bin/
│   └── kanine                 # executable stub -> boots the Console application
├── config/
│   └── kanine.dist.yaml       # shipped default config (template)
├── src/
│   ├── Console/
│   │   ├── Application.php
│   │   └── Command/
│   │       └── BoardCommand.php
│   ├── Config/
│   │   ├── Configuration.php   # typed config object
│   │   └── ConfigLoader.php    # finds + parses + validates yaml
│   ├── Board/
│   │   ├── Board.php
│   │   ├── Column.php
│   │   ├── Card.php
│   │   └── State.php           # backed enum
│   ├── Rendering/
│   │   ├── BoardRenderer.php   # php-tui widget tree
│   │   └── RendererInterface.php
│   └── Runtime/
│       └── RefreshLoop.php     # tick + input handling
├── tests/
├── composer.json
└── docs/
    └── PRD.md
```

### 8.3 Composer wiring

```jsonc
{
  "name": "scottkeckwarren/kanine",
  "description": "A PHP TUI Kanban board backed by GitHub Issues with Claude Code agents.",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=8.2",
    "symfony/console": "^7.0",
    "symfony/yaml": "^7.0",
    "php-tui/php-tui": "^0.1"
  },
  "autoload": {
    "psr-4": { "ScottKeckWarren\\Kanine\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "ScottKeckWarren\\Kanine\\Tests\\": "tests/" }
  },
  "bin": ["bin/kanine"]
}
```

> `"bin": ["bin/kanine"]` is what makes Composer symlink the script into
> `./vendor/bin/kanine` in consuming projects. Verify the exact `php-tui/php-tui`
> version constraint against Packagist when you wire this up.

---

## 9. Configuration

Kanine reads a YAML config, resolved in this order (first found wins):

1. `--config=<path>` CLI option
2. `./kanine.yaml` (project-local)
3. `~/.config/kanine/kanine.yaml` (user-global)
4. Built-in defaults (`config/kanine.dist.yaml`)

```yaml
# kanine.yaml
refresh:
  enabled: true          # auto-refresh on/off (toggleable at runtime)
  interval_seconds: 30   # poll cadence
  steps: [5, 15, 30, 60, 120]  # values the runtime "cycle rate" keybind walks through

board:
  columns:               # order = left-to-right on screen
    - { name: "Backlog",     state: backlog }
    - { name: "Todo",        state: todo }
    - { name: "In Progress", state: in_progress }
    - { name: "Review",      state: review }
    - { name: "Done",        state: done }

# (later phases)
github:
  repositories: ["owner/repo"]
  label_map:
    "status: in progress": in_progress
    "status: review": review
  token_env: GITHUB_TOKEN
```

For **Phase 0** only the `refresh` and `board.columns` sections are consumed.

---

## 10. CLI & Installation

```bash
composer require scottkeckwarren/kanine
./vendor/bin/kanine            # launches the board (default command)
./vendor/bin/kanine --config=./kanine.yaml
```

Default keybindings (initial set):

| Key | Action |
|---|---|
| `q` / `Ctrl-C` | Quit (restore terminal) |
| `r` | Manual refresh now |
| `a` | Toggle auto-refresh on/off |
| `+` / `-` | Cycle refresh interval up/down through `refresh.steps` |
| `←` / `→` | Move between columns *(later)* |
| `↑` / `↓` | Move between cards *(later)* |

---

## 11. Roadmap (Phased)

| Phase | Theme | Outcome |
|---|---|---|
| **0** | **Skeleton + columns + refresh config** | Installable tool that draws columns and has a toggleable, configurable refresh tick. **(detailed in §12)** |
| 1 | Live GitHub data | Fetch open issues, render as cards, resolve columns from labels. |
| 2 | State machine | Formal label→state mapping, transitions, manual moves write labels back. |
| 3 | Agent dispatch | Run Claude Code headlessly in a submodule for a selected issue. |
| 4 | Live agent status | Stream/parse agent status onto cards; auto-advance columns. |
| 5 | Polish | Detail view, error surfacing, themes, Windows pass, docs site. |

---

## 12. Phase 0 — First Steps (MVP)

**Objective:** `./vendor/bin/kanine` launches a full-screen TUI that draws the
configured columns and runs a refresh loop whose rate can be toggled and changed —
**no GitHub, no agents yet.** Cards are placeholders.

### 12.1 Scope

In scope:
- Composer scaffold (autoload + `bin`), bootable Console application.
- A `BoardCommand` that enters the TUI.
- `ConfigLoader` that reads `refresh` and `board.columns` (with defaults).
- A `php-tui` renderer that lays out N columns across the terminal width with headers
  and a footer/status bar.
- A refresh loop that re-renders on each tick at the configured interval.
- Keybinds: quit (`q`), toggle auto-refresh (`a`), change interval (`+`/`-`),
  manual tick (`r`).
- A visible "tick counter" / last-refresh timestamp so the refresh behavior is
  observable even with no data.

Out of scope for Phase 0: any GitHub call, any Claude Code call, card navigation,
issue detail, label writing.

### 12.2 Build order

1. **Scaffold the package.** Create `composer.json` (per §8.3), `src/`, `bin/kanine`,
   and `config/kanine.dist.yaml`. Confirm `composer install` produces
   `./vendor/bin/kanine`.

2. **Bootable entry point.** `bin/kanine` requires the autoloader and runs
   `ScottKeckWarren\Kanine\Console\Application`, registering `BoardCommand` as the
   default command so a bare `./vendor/bin/kanine` opens the board.

3. **Config layer.** Implement `Configuration` (typed: `refreshEnabled`,
   `refreshIntervalSeconds`, `refreshSteps`, `columns`) and `ConfigLoader` (resolve
   path → parse YAML → validate → fall back to defaults). Unit-test this in isolation.

4. **Board model.** `Column { name, State }`, `Board { Column[] }`. Trivial now but it
   sets up Phase 1 cleanly.

5. **Renderer.** `BoardRenderer` builds a `php-tui` layout: a horizontal split into one
   block per column (each with a titled border), plus a bottom status bar showing
   auto-refresh on/off, current interval, and last tick time.

6. **Refresh loop.** `RefreshLoop` owns the tick. Each iteration: poll terminal input
   (non-blocking), apply keybinds, and re-render. When auto-refresh is on, advance a
   tick counter every `interval_seconds`; toggling/`+`/`-` mutate the in-memory config
   live so the change is immediately visible in the status bar.

7. **Clean teardown.** Ensure `q`/`Ctrl-C` disables raw mode and the alternate screen
   so the terminal is left usable.

### 12.3 Illustrative refresh-loop shape

```php
final class RefreshLoop
{
    public function run(Configuration $config, BoardRenderer $renderer): void
    {
        $enabled  = $config->refreshEnabled;
        $interval = $config->refreshIntervalSeconds;
        $lastTick = 0.0;

        // enter raw mode + alternate screen via php-tui here
        while (true) {
            foreach ($this->readInput() as $key) {
                match ($key) {
                    'q'     => $this->quit(),                       // restore + exit
                    'a'     => $enabled = !$enabled,                // FR-11
                    '+'     => $interval = $this->stepUp($config, $interval),   // FR-12
                    '-'     => $interval = $this->stepDown($config, $interval), // FR-12
                    'r'     => $lastTick = 0.0,                     // force a tick (FR-13)
                    default => null,
                };
            }

            $now = microtime(true);
            if ($enabled && ($now - $lastTick) >= $interval) {
                $lastTick = $now;
                // Phase 1+: refetch issues here. Phase 0: just bump a counter.
            }

            $renderer->render($enabled, $interval, $lastTick);
            usleep(50_000); // ~20fps input/render cadence, independent of refresh rate
        }
    }
}
```

> Note the two distinct cadences: a fast input/render loop (so the UI stays
> responsive) and the slower, configurable **refresh** interval that controls when
> data is re-pulled. Phase 0 exercises the second cadence with a counter instead of a
> network call.

### 12.4 Acceptance criteria

- **AC-1** `composer install` yields an executable `./vendor/bin/kanine`.
- **AC-2** Running it clears the screen and draws all columns from config, each with a
  header, spanning the terminal width.
- **AC-3** A status bar shows: auto-refresh on/off, current interval, last tick time.
- **AC-4** `a` toggles auto-refresh; the status bar reflects it immediately.
- **AC-5** `+`/`-` change the interval through the configured `steps`; the status bar
  updates and the tick cadence visibly changes.
- **AC-6** `r` forces an immediate tick even when auto-refresh is off.
- **AC-7** Resizing the terminal reflows the columns without corruption.
- **AC-8** `q` exits and leaves the terminal fully usable (no stuck raw mode).
- **AC-9** `ConfigLoader` and the label/column model have passing unit tests that run
  without a terminal.

---

## 13. Risks & Open Questions

- **Submodules vs. worktrees.** Per-agent isolation is the requirement; git
  *worktrees* may be a lighter fit than *submodules* for transient agent working
  copies. Decide before Phase 3. (Spec says submodules; worth a deliberate call.)
- **Headless Claude Code contract.** Exact invocation, status/output format, and
  concurrency limits need to be pinned down before Phase 3/4.
- **Rendering library bet.** `php-tui/php-tui` is at an early version; keep rendering
  behind `RendererInterface` so `symfony/tui` (or another) can be swapped later.
- **GitHub rate limits.** Aggressive refresh intervals against large repos will hit
  limits; consider conditional requests/ETags and a sane minimum interval.
- **Windows terminals.** Raw-mode handling differs; treat as secondary at launch.
- **Concurrency model.** PHP's single-threaded loop must juggle input, render, polling,
  and (later) multiple agent subprocesses without blocking — validate the approach
  early.

---

## 14. Glossary

- **TUI** — Text/terminal user interface.
- **Card** — On-screen representation of a single GitHub Issue.
- **Column / Lane** — A board state (Backlog, Todo, In Progress, …).
- **Agent** — A Claude Code process working an issue.
- **Submodule** — The isolated git working copy an agent operates in.
- **Refresh cycle** — The configurable periodic poll for issue/agent updates.
