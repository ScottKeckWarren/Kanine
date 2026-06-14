#!/usr/bin/env bash
set -euo pipefail

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)

if [[ "$CURRENT_BRANCH" == "main" || "$CURRENT_BRANCH" == "master" ]]; then
  echo "ERROR: amending commits on '$CURRENT_BRANCH' is not allowed" >&2
  exit 1
fi

if [[ -z $(git diff --cached --name-only) ]]; then
  echo "ERROR: no staged changes to amend into commit" >&2
  exit 1
fi

echo "Amending last commit on branch '$CURRENT_BRANCH'..."
git commit --amend --no-edit
echo "Done."
