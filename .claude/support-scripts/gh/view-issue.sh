#!/usr/bin/env bash
set -euo pipefail

ISSUE_NUMBER="${1:-}"
FORMAT="${2:---json number,title,body,labels}"

if [[ -z "$ISSUE_NUMBER" ]]; then
  echo "ERROR: issue number required" >&2
  echo "Usage: $0 <issue-number> [--json fields | --web]" >&2
  exit 1
fi

if ! [[ "$ISSUE_NUMBER" =~ ^[0-9]+$ ]]; then
  echo "ERROR: issue number must be numeric, got '$ISSUE_NUMBER'" >&2
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

echo "Fetching issue #${ISSUE_NUMBER}..."
# shellcheck disable=SC2086
gh issue view "$ISSUE_NUMBER" $FORMAT
