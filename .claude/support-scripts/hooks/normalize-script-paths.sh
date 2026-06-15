#!/usr/bin/env bash
# PreToolUse hook — rewrites absolute project paths to relative paths.
#
# Input:  JSON object on stdin  { "tool_input": { "command": "..." }, ... }
# Output: JSON object on stdout with command rewritten (or unchanged if no match)
# Exit 0 always — a non-zero exit blocks the tool call entirely.
#
# Prerequisite: jq must be available on PATH.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

input="$(cat)"

normalized=$(
  echo "$input" | jq --arg root "$PROJECT_ROOT" '
    .tool_input.command |= gsub($root + "/"; "./")
  '
)

echo "$normalized"
exit 0
