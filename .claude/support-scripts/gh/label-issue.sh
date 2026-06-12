#!/usr/bin/env bash
set -euo pipefail

ISSUE_NUMBER="${1:-}"
YES="${2:-}"

if [[ -z "$ISSUE_NUMBER" ]]; then
  echo "ERROR: issue number required" >&2
  echo "Usage: $0 <issue-number> [--yes] -- <label> [<label> ...]" >&2
  exit 1
fi

shift 1
if [[ "${1:-}" == "--yes" ]]; then
  YES="--yes"
  shift 1
fi

if [[ "${1:-}" == "--" ]]; then
  shift 1
fi

LABELS=("$@")

if [[ ${#LABELS[@]} -eq 0 ]]; then
  echo "ERROR: at least one label required" >&2
  echo "Usage: $0 <issue-number> [--yes] -- <label> [<label> ...]" >&2
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

echo "Adding labels to issue #$ISSUE_NUMBER: '$ISSUE_TITLE'"
for LABEL in "${LABELS[@]}"; do
  echo "  + $LABEL"
done

if [[ "$YES" != "--yes" ]]; then
  echo "Proceed? [y/N]"
  read -r CONFIRM
  if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
    echo "Aborted." >&2
    exit 1
  fi
fi

for LABEL in "${LABELS[@]}"; do
  gh issue edit "$ISSUE_NUMBER" --add-label "$LABEL"
done

echo "Labels added to issue #$ISSUE_NUMBER."
