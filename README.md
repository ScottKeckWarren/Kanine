# Kanine

A PHP terminal UI that turns GitHub Issues into a live Kanban board and hands work off to Claude Code agents — across as many repositories as you want, all in one view.

```
┌─ Backlog ──────┐ ┌─ Todo ─────────┐ ┌─ In Progress ──┐ ┌─ Review ───────┐ ┌─ Done ─────────┐
│ #12 Add OAuth  │ │ #18 Fix N+1    │ │ #23 JWT auth   │ │ #9  Rate limit │ │ #4  CI setup   │
│ #15 Dark mode  │ │ #20 CSV export │ │     [pup-1] ●  │ │    [pup-2] ●  │ │                │
│ #17 Webhooks   │ │                │ │ Generating...  │ │ Awaiting ans.. │ │                │
└────────────────┘ └────────────────┘ └────────────────┘ └────────────────┘ └────────────────┘
 a: auto-refresh [on]  interval: 30s  last sync: 12:04:01   q: quit  r: refresh
```

---

## What it does

Kanine watches one or more GitHub repositories and renders their Issues as a keyboard-driven Kanban board in your terminal. Issue labels determine which column a card sits in.

From the board you can dispatch any Issue to a **pup** — a headless Claude Code agent running in an isolated git worktree. Each pup works the Issue autonomously: reads the code, writes tests, implements the feature, opens a PR. The board shows live status for every pup. When a pup hits something ambiguous — missing config, a design decision, unclear requirements — it posts a question to Kanine. You answer it from the board; the pup gets the answer on its next poll and keeps going.

You stay in the loop without being in the way.

---

## Multiple projects, one board

Kanine is built for multi-repo workflows. Configure as many repositories as you like — they all appear on the same board, their cards intermixed by state. A small repo badge on each card tells you which project it belongs to.

```yaml
github:
  repositories:
    - acme/api
    - acme/frontend
    - acme/infra
    - personal/side-project
```

All issues from all configured repos flow into the same column layout. Pups are repo-aware — each one clones into its own worktree scoped to the correct repository.

---

## Installation

Requires PHP 8.2+ and Composer.

```bash
composer require scottkeckwarren/kanine
```

Launch the board:

```bash
./vendor/bin/kanine
```

Or with a custom config file:

```bash
./vendor/bin/kanine --config=./my-kanine.yaml
```

---

## Configuration

Kanine resolves config in this order (first found wins):

1. `--config=<path>` CLI option
2. `./kanine.yaml` in the current directory
3. `~/.config/kanine/kanine.yaml`
4. Built-in defaults

Minimal config:

```yaml
github:
  repositories:
    - owner/repo
  token_env: GITHUB_TOKEN   # or set GITHUB_TOKEN in your shell

board:
  columns:
    - { name: "Backlog",     label: "status: backlog" }
    - { name: "Todo",        label: "status: todo" }
    - { name: "In Progress", label: "status: in progress" }
    - { name: "Review",      label: "status: review" }
    - { name: "Done",        label: "status: done" }

refresh:
  enabled: true
  interval_seconds: 30
```

Full config reference — `config/kanine.dist.yaml`.

---

## Keyboard shortcuts

| Key | Action |
|---|---|
| `q` / `Ctrl-C` | Quit (restores terminal) |
| `r` | Refresh now |
| `a` | Toggle auto-refresh on/off |
| `+` / `-` | Cycle refresh interval up/down |
| `←` / `→` | Move between columns |
| `↑` / `↓` | Move between cards |
| `Enter` | Open card detail (issue body, agent log, questions) |
| `d` | Dispatch selected issue to a pup |
| `s` | Stop pup on selected issue |

---

## Pups (Claude Code agents)

A **pup** is a long-running Claude Code process assigned to one Issue at a time. It:

1. Registers with Kanine on startup
2. Polls Kanine for task assignments every few seconds
3. Works the assigned Issue (reads code, writes tests, implements, opens PR)
4. Posts questions to Kanine when it needs user input
5. Picks up another Issue while waiting for answers
6. Receives answers on its next poll and resumes

Pups can run anywhere — same machine, a separate server, or a Docker container. They connect to Kanine's embedded HTTP server over HTTPS/JSON.

### Starting a pup

```bash
KANINE_SUPERVISOR_URL=http://localhost:7777 \
KANINE_PUP_ID=$(uuidgen) \
claude --headless --pup
```

Or let Kanine spawn pups directly from the board (`d` on a card).

### How questions work

When Claude hits an ambiguous decision point, it generates a question and posts it to Kanine. You see it in the card's detail view:

```
─ #23 JWT auth ────────────────────────────────────────────────────
  Status:   working (pup-1)
  Question: Should tokens expire after 15 minutes or 24 hours?
            The issue mentions "secure" but no session length.
  > _
```

Type your answer and press `Enter`. Kanine stores it; pup picks it up within one poll interval and continues. If multiple questions are queued across different issues or repos, they all appear as a list you work through at your own pace.

### Pup configuration (supervisor)

```yaml
supervisor:
  host: 127.0.0.1   # 0.0.0.0 to accept remote pups
  port: 7777
  tls: false        # set true + provide cert/key for remote pups
```

See `docs/architecture/pup-protocol.md` for the full HTTPS/JSON API that governs supervisor-pup communication.

---

## How columns work

A card's column is determined by its GitHub labels. You define the mapping in config:

```yaml
board:
  columns:
    - { name: "In Progress", label: "status: in progress" }
```

Any issue with label `status: in progress` appears in that column. When a pup completes an issue, Kanine applies the appropriate label change on GitHub — the card moves columns automatically on the next refresh.

Issues with no matching label land in **Backlog** by default.

---

## Roadmap

| Phase | Status | What ships |
|---|---|---|
| 0 | In progress | TUI skeleton, columns, refresh config |
| 1 | Planned | Live GitHub issues rendered as cards |
| 2 | Planned | Label→state mapping, manual card moves |
| 3 | Planned | Pup dispatch, headless Claude Code integration |
| 4 | Planned | Live pup status on cards, auto column advance |
| 5 | Planned | Detail view, question answering UI, multi-repo polish |

---

## Stack

- **PHP 8.2+**
- `symfony/console` — CLI entry point
- `php-tui/php-tui` — terminal rendering
- `symfony/yaml` — config parsing
- `knplabs/github-api` — GitHub integration
- `symfony/process` — pup subprocess management
- `PHPUnit 11` — test suite

---

## Development

```bash
composer install
composer test      # run PHPUnit
composer lint      # run phpcs
```

See `CLAUDE.md` for contribution policies and the dangerous-action script requirements that apply to automated tooling in this repo.

---

## License

MIT — see `LICENSE`.
