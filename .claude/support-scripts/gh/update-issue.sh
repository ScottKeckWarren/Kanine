#!/usr/bin/env bash
set -euo pipefail

ISSUE_NUMBER="${1:-}"
BODY_FILE="${2:-}"
YES="${3:-}"

if [[ -z "$ISSUE_NUMBER" ]]; then
  echo "ERROR: issue number required" >&2
  echo "Usage: $0 <issue-number> <body-file> [--yes]" >&2
  exit 1
fi

if [[ -z "$BODY_FILE" ]]; then
  echo "ERROR: body file required" >&2
  echo "Usage: $0 <issue-number> <body-file> [--yes]" >&2
  exit 1
fi

if [[ ! -f "$BODY_FILE" ]]; then
  echo "ERROR: body file '$BODY_FILE' not found" >&2
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

if ! gh issue view "$ISSUE_NUMBER" &>/dev/null; then
  echo "ERROR: issue #$ISSUE_NUMBER not found" >&2
  exit 1
fi

ISSUE_TITLE=$(gh issue view "$ISSUE_NUMBER" --json title --jq '.title')

echo "Updating issue #$ISSUE_NUMBER: '$ISSUE_TITLE'"
echo "Body source: $BODY_FILE"
echo "---"
cat "$BODY_FILE"
echo "---"
if [[ "$YES" != "--yes" ]]; then
  echo "Proceed? [y/N]"
  read -r CONFIRM
  if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
    echo "Aborted." >&2
    exit 1
  fi
fi

gh issue edit "$ISSUE_NUMBER" --body-file "$BODY_FILE"
echo "Issue #$ISSUE_NUMBER updated."
