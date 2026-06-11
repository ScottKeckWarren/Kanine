#!/usr/bin/env bash
set -euo pipefail

TITLE="${1:-}"
BASE_BRANCH="${2:-main}"
BODY_FILE="${3:-}"

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)

if [[ "$CURRENT_BRANCH" == "main" || "$CURRENT_BRANCH" == "master" ]]; then
  echo "ERROR: cannot open PR from '$CURRENT_BRANCH'" >&2
  exit 1
fi

if [[ -z "$TITLE" ]]; then
  echo "ERROR: PR title required" >&2
  echo "Usage: $0 <title> [base-branch] [body-file]" >&2
  exit 1
fi

if ! command -v gh &>/dev/null; then
  echo "ERROR: gh CLI not installed" >&2
  exit 1
fi

if ! gh auth status &>/dev/null; then
  echo "ERROR: gh not authenticated — run 'gh auth login'" >&2
  exit 1
fi

if ! git ls-remote --exit-code origin "$CURRENT_BRANCH" &>/dev/null; then
  echo "ERROR: branch '$CURRENT_BRANCH' not pushed to origin yet" >&2
  echo "Run .claude/support-scripts/git/push.sh first" >&2
  exit 1
fi

echo "Opening PR: '$TITLE' → $BASE_BRANCH"

if [[ -n "$BODY_FILE" && -f "$BODY_FILE" ]]; then
  gh pr create --title "$TITLE" --base "$BASE_BRANCH" --body-file "$BODY_FILE"
else
  gh pr create --title "$TITLE" --base "$BASE_BRANCH" --body ""
fi
