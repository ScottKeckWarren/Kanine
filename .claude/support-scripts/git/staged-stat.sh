#!/usr/bin/env bash
set -euo pipefail

STAT=$(git diff --cached --stat)

if [[ -z "$STAT" ]]; then
  echo "ERROR: No staged changes found. Stage files first:"
  echo ""
  echo "  git add <file>..."
  echo ""
  echo "Then run /create-pull-request again."
  exit 1
fi

echo "$STAT"
