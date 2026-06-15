#!/usr/bin/env bash
set -euo pipefail

# Reads Claude Code PreToolUse hook input from stdin (JSON with a "command" field).
# Blocks raw `git`, `gh`, and `phpunit --filter` commands; directs to support scripts.

input=$(cat)
command_str=$(echo "$input" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d.get('tool_input', {}).get('command', '') or d.get('command', ''))" 2>/dev/null || true)
leading_token=$(echo "$command_str" | awk '{print $1}')

# Block raw phpunit --filter calls
if [[ "$leading_token" == *"phpunit"* ]] && echo "$command_str" | grep -q -- '--filter'; then
    echo ""
    echo "BLOCKED: raw \`$command_str\`"
    echo ""
    echo "Use the support script:"
    echo "  .claude/support-scripts/test/phpunit-filter.sh <filter>"
    echo ""
    exit 1
fi

if [[ "$leading_token" != "git" && "$leading_token" != "gh" ]]; then
    exit 0
fi

echo ""
echo "BLOCKED: raw \`$command_str\`"
echo ""
echo "Use a support script from .claude/support-scripts/$leading_token/:"
echo ""
ls -1 "$(dirname "$0")/../$leading_token/" 2>/dev/null | sed 's/^/  /'
echo ""
echo "If no script exists for this action, create one per the Dangerous Action Policy in CLAUDE.md."
echo ""

exit 1
