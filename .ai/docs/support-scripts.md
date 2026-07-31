# Support Scripts

All support scripts live under `.claude/support-scripts/`. Invoke directly — never via `bash <script>`.

A `PreToolUse` hook (`hooks/guard-raw-commands.sh`) blocks raw `git`, `gh`, and `phpunit --filter` commands and redirects to these scripts.

---

## git/

### `create-branch.sh`

Creates feature branch off a base branch.

```
./.claude/support-scripts/git/create-branch.sh <branch-name> [base-branch]
```

- `base-branch` defaults to `main`
- Checks: branch name not `main`/`master`, branch doesn't already exist, base branch exists

### `commit.sh`

Commits staged changes using a message file.

```
./.claude/support-scripts/git/commit.sh <message-file>
```

- Checks: not on `main`/`master`, staged changes exist, message file exists

### `amend.sh`

Amends last commit with currently staged changes (no message change).

```
./.claude/support-scripts/git/amend.sh
```

- Checks: not on `main`/`master`, staged changes exist
- Use instead of a second commit on a feature branch

### `push.sh`

Pushes current branch to `origin`.

```
./.claude/support-scripts/git/push.sh [--force]
```

- Checks: not on `main`/`master`, remote `origin` exists
- `--force` uses `--force-with-lease`, not `--force`

### `staged-stat.sh`

Prints `git diff --cached --stat`. Exits non-zero if nothing staged.

```
./.claude/support-scripts/git/staged-stat.sh
```

---

## gh/

### `list-issues.sh`

Lists issues (open + closed) as JSON `[{number, title}]`.

```
./.claude/support-scripts/gh/list-issues.sh [--limit N]
```

- `--limit` defaults to 1000
- Checks: `gh` installed and authenticated

### `view-issue.sh`

Fetches issue by number and prints JSON.

```
./.claude/support-scripts/gh/view-issue.sh <issue-number> [--json fields | --web]
```

- Default format: `--json number,title,body,labels`
- Checks: numeric issue number, `gh` installed and authenticated, issue exists

### `create-issue.sh`

Creates new GitHub issue.

```
./.claude/support-scripts/gh/create-issue.sh <title> [body-file] [label]
```

- `body-file` optional; issue created with empty body if omitted
- `label` optional

### `update-issue.sh`

Replaces issue body from a file.

```
./.claude/support-scripts/gh/update-issue.sh <issue-number> <body-file> [--yes]
```

- Prompts for confirmation unless `--yes` passed
- Checks: issue exists, body file exists

### `comment-issue.sh`

Adds comment to issue from a file.

```
./.claude/support-scripts/gh/comment-issue.sh <issue-number> <body-file> [--yes]
```

- Prompts for confirmation unless `--yes` passed
- Prints comment body before posting

### `label-issue.sh`

Adds one or more labels to an issue.

```
./.claude/support-scripts/gh/label-issue.sh <issue-number> [--yes] -- <label> [<label> ...]
```

- `--` separator required before labels
- Prompts for confirmation unless `--yes` passed

### `create-pr.sh`

Opens a pull request from current branch.

```
./.claude/support-scripts/gh/create-pr.sh <title> [base-branch] [body-file]
```

- `base-branch` defaults to `main`
- Checks: not on `main`/`master`, branch pushed to origin, `gh` authenticated
- Run `push.sh` first if branch not yet pushed

---

## test/

### `phpunit-full.sh`

Runs full PHPUnit suite.

```
./.claude/support-scripts/test/phpunit-full.sh
```

- Checks: `vendor/bin/phpunit` exists (run `composer install` if not)

### `phpunit-filter.sh`

Runs PHPUnit with `--filter` (class name, method, or regex).

```
./.claude/support-scripts/test/phpunit-filter.sh <filter>
```

- Checks: filter arg provided, `vendor/bin/phpunit` exists

### `phpunit-single-file.sh` *(root-level)*

Runs PHPUnit against one test file by path.

```
./.claude/support-scripts/phpunit-single-file.sh <path/to/TestFile.php>
```

- Checks: file exists, `vendor/bin/phpunit` exists

---

## hooks/

### `guard-raw-commands.sh`

`PreToolUse` hook. Reads JSON from stdin, blocks raw `git`, `gh`, and `phpunit --filter` invocations, and prints the relevant support script directory. Not invoked directly — wired into `.claude/settings.json`.
