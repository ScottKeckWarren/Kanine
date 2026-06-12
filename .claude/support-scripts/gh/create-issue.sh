#!/usr/bin/env bash
set -euo pipefail

TITLE="${1:-}"
BODY_FILE="${2:-}"
LABEL="${3:-}"

if [[ -z "$TITLE" ]]; then
  echo "ERROR: issue title required" >&2
  echo "Usage: $0 <title> [body-file] [label]" >&2
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

echo "Creating issue: '$TITLE'..."

ARGS=(--title "$TITLE")

if [[ -n "$BODY_FILE" ]]; then
  if [[ ! -f "$BODY_FILE" ]]; then
    echo "ERROR: body file '$BODY_FILE' not found" >&2
    exit 1
  fi
  ARGS+=(--body-file "$BODY_FILE")
else
  ARGS+=(--body "")
fi

if [[ -n "$LABEL" ]]; then
  ARGS+=(--label "$LABEL")
fi

gh issue create "${ARGS[@]}"
