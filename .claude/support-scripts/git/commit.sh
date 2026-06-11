#!/usr/bin/env bash
set -euo pipefail

MSG_FILE="${1:-}"

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)

if [[ "$CURRENT_BRANCH" == "main" || "$CURRENT_BRANCH" == "master" ]]; then
  echo "ERROR: direct commits to '$CURRENT_BRANCH' are not allowed" >&2
  echo "Create a feature branch first via .claude/support-scripts/git/create-branch.sh" >&2
  exit 1
fi

if [[ -z $(git diff --cached --name-only) ]]; then
  echo "ERROR: no staged changes to commit" >&2
  exit 1
fi

if [[ -z "$MSG_FILE" ]]; then
  echo "ERROR: commit message file required" >&2
  echo "Usage: $0 <message-file>" >&2
  exit 1
fi

if [[ ! -f "$MSG_FILE" ]]; then
  echo "ERROR: message file '$MSG_FILE' not found" >&2
  exit 1
fi

echo "Committing on branch '$CURRENT_BRANCH'..."
git commit -F "$MSG_FILE"
echo "Done."
