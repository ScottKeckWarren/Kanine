#!/usr/bin/env bash
set -euo pipefail

# Lists issues (open + closed) with number and title, paginated.
# Usage: list-issues.sh [--limit N]

LIMIT="${2:-1000}"

if ! command -v gh &>/dev/null; then
  echo "ERROR: gh CLI not installed" >&2
  exit 1
fi

if ! gh auth status &>/dev/null; then
  echo "ERROR: gh not authenticated — run 'gh auth login'" >&2
  exit 1
fi

echo "Listing issues (state: all, limit: $LIMIT)..." >&2

gh issue list --state all --limit "$LIMIT" --json number,title
