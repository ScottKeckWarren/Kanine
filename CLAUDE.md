# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Status

PHP project with Composer configured. Stack: PHP 8.2+, Symfony Console, Symfony YAML, PHP TUI, PHPUnit 11.

## Commands

```bash
composer install       # install dependencies
composer test          # run test suite (PHPUnit)
composer lint          # run linter (phpcs)
./vendor/bin/phpunit --filter TestClassName   # run single test class
```

## Branching & PR Policy

Never commit directly to `main`. All changes go through a PR:

1. Create a feature branch off `main`
2. Make changes
3. Open PR via `.claude/support-scripts/gh/create-pr.sh`
4. Merge only after PR is open (no force-pushes to `main`)

## Development Philosophy

**Documentation-driven:** Write or update docs/specs before writing code. If the intent isn't documented, clarify it first.

**Test-driven:** Write failing tests before implementation. No feature ships without tests covering the intended behavior.

**Best judgement:** Apply best judgement when requirements are ambiguous, a path has tradeoffs, or instructions don't cover an edge case. Prefer the choice that is safer, simpler, and more reversible. Document the reasoning if the decision is non-obvious.

## Dangerous Action Policy

Before executing any action that could have irreversible or shared-state consequences (git commit, git push, gh CLI, CI/CD triggers, database migrations, deployments, etc.), create a support script at:

```
.claude/support-scripts/<command>/<action>.sh
```

Examples:
- `.claude/support-scripts/git/commit.sh`
- `.claude/support-scripts/git/push.sh`
- `.claude/support-scripts/gh/create-pr.sh`

### Script requirements

Each script must:
1. Validate preconditions (branch name, clean working tree, required env vars, etc.)
2. Echo what it will do before doing it
3. Exit non-zero with a clear message on any failed check
4. Perform the action deterministically via CLI tools — no ad-hoc shell one-liners

### Invoking scripts

Always invoke support scripts directly (e.g. `./.claude/support-scripts/gh/view-issue.sh 4`), never via `bash <script>`. Scripts must be executable (`chmod +x`).

### File paths

Always use relative paths when referencing files — in support scripts, workflow YAML, config files, and any other context. Never use absolute paths.

### Pre-tool hooks

When a support script is created, also add a corresponding `PreToolUse` hook in `.claude/settings.json` that fires when the raw command is about to run and redirects to the script instead. The hook should:

1. Detect the relevant tool/command pattern (e.g. `Bash` tool with `git commit` or `git push`)
2. Print a message pointing to the support script path
3. Block the raw command so the script is used instead

This ensures the safeguards are enforced automatically, not just by convention.

### Script reference

Full listing with usage: [`.ai/docs/support-scripts.md`](.ai/docs/support-scripts.md)

### Rationale

Deterministic scripts are auditable, repeatable, and catch edge cases that inline commands miss. Scripts live in the repo so they evolve alongside project requirements.

<!-- SPECKIT START -->
For additional context about technologies to be used, project structure,
shell commands, and other important information, read the current plan
at specs/001-supervisor-pup-workflow/plan.md
<!-- SPECKIT END -->
