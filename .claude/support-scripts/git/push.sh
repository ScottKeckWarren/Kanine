#!/usr/bin/env bash
set -euo pipefail

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)

if [[ "$CURRENT_BRANCH" == "main" || "$CURRENT_BRANCH" == "master" ]]; then
  echo "ERROR: direct push to '$CURRENT_BRANCH' is not allowed" >&2
  exit 1
fi

if ! git remote get-url origin &>/dev/null; then
  echo "ERROR: no remote 'origin' configured" >&2
  exit 1
fi

echo "Pushing '$CURRENT_BRANCH' to origin..."
git push -u origin "$CURRENT_BRANCH"
echo "Done."
