#!/usr/bin/env bash
set -euo pipefail

BRANCH_NAME="${1:-}"
BASE_BRANCH="${2:-main}"

if [[ -z "$BRANCH_NAME" ]]; then
  echo "ERROR: branch name required" >&2
  echo "Usage: $0 <branch-name> [base-branch]" >&2
  exit 1
fi

if git show-ref --verify --quiet "refs/heads/$BRANCH_NAME"; then
  echo "ERROR: branch '$BRANCH_NAME' already exists" >&2
  exit 1
fi

if [[ "$BRANCH_NAME" == "main" || "$BRANCH_NAME" == "master" ]]; then
  echo "ERROR: cannot create branch named '$BRANCH_NAME'" >&2
  exit 1
fi

if ! git show-ref --verify --quiet "refs/heads/$BASE_BRANCH" && \
   ! git show-ref --verify --quiet "refs/remotes/origin/$BASE_BRANCH"; then
  echo "ERROR: base branch '$BASE_BRANCH' does not exist" >&2
  exit 1
fi

echo "Creating branch '$BRANCH_NAME' off '$BASE_BRANCH'..."
git checkout -b "$BRANCH_NAME" "$BASE_BRANCH"
echo "Done."
