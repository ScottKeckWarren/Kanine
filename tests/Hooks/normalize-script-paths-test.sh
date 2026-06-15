#!/usr/bin/env bash
# Unit tests for .claude/support-scripts/hooks/normalize-script-paths.sh
#
# Pipes synthetic JSON payloads into the hook script and asserts stdout
# matches the expected normalized form.
#
# Usage: ./tests/hooks/normalize-script-paths-test.sh
# Exit 0 = all tests passed; non-zero = at least one failure.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
HOOK="$PROJECT_ROOT/.claude/support-scripts/hooks/normalize-script-paths.sh"

pass=0
fail=0

assert_equals() {
    local test_name="$1"
    local expected="$2"
    local actual="$3"

    if [[ "$expected" == "$actual" ]]; then
        echo "  PASS: $test_name"
        (( pass++ )) || true
    else
        echo "  FAIL: $test_name"
        echo "        expected: $expected"
        echo "        actual:   $actual"
        (( fail++ )) || true
    fi
}

run_hook() {
    local input="$1"
    echo "$input" | "$HOOK" | jq -c .
}

echo ""
echo "Running normalize-script-paths hook tests..."
echo ""

# ---------------------------------------------------------------------------
# Prerequisite: hook exists and is executable
# ---------------------------------------------------------------------------

if [[ ! -f "$HOOK" ]]; then
    echo "FATAL: hook script not found at $HOOK"
    exit 1
fi

if [[ ! -x "$HOOK" ]]; then
    echo "FATAL: hook script is not executable: $HOOK"
    exit 1
fi

# ---------------------------------------------------------------------------
# Test 1: absolute path pointing into project root is rewritten to relative
# ---------------------------------------------------------------------------

ABSOLUTE_CMD="$PROJECT_ROOT/.claude/support-scripts/gh/view-issue.sh 19"
INPUT_1=$(jq -n --arg cmd "$ABSOLUTE_CMD" '{"tool_name":"Bash","tool_input":{"command":$cmd}}')
EXPECTED_CMD_1="./.claude/support-scripts/gh/view-issue.sh 19"
EXPECTED_1=$(jq -cn --arg cmd "$EXPECTED_CMD_1" '{"tool_name":"Bash","tool_input":{"command":$cmd}}')

ACTUAL_1=$(run_hook "$INPUT_1")
assert_equals "absolute path is rewritten to relative" "$EXPECTED_1" "$ACTUAL_1"

# ---------------------------------------------------------------------------
# Test 2: command with no absolute project path passes through unchanged
# ---------------------------------------------------------------------------

INPUT_2=$(jq -cn '{"tool_name":"Bash","tool_input":{"command":"ls -la"}}')
ACTUAL_2=$(run_hook "$INPUT_2")
assert_equals "command with no project path is unchanged" "$INPUT_2" "$ACTUAL_2"

# ---------------------------------------------------------------------------
# Test 3: already-relative support script path passes through unchanged
# ---------------------------------------------------------------------------

INPUT_3=$(jq -cn '{"tool_name":"Bash","tool_input":{"command":"./.claude/support-scripts/git/commit.sh"}}')
ACTUAL_3=$(run_hook "$INPUT_3")
assert_equals "relative path passes through unchanged" "$INPUT_3" "$ACTUAL_3"

# ---------------------------------------------------------------------------
# Test 4: hook always exits 0 (even on no-op passthrough)
# ---------------------------------------------------------------------------

echo '{"tool_name":"Bash","tool_input":{"command":"echo hello"}}' | "$HOOK" > /dev/null
EXIT_CODE=$?
if [[ "$EXIT_CODE" -eq 0 ]]; then
    echo "  PASS: hook exits 0 for passthrough command"
    (( pass++ )) || true
else
    echo "  FAIL: hook should exit 0 but exited $EXIT_CODE"
    (( fail++ )) || true
fi

# ---------------------------------------------------------------------------
# Test 5: absolute path in a compound command is rewritten
# ---------------------------------------------------------------------------

ABSOLUTE_CMD_5="$PROJECT_ROOT/.claude/support-scripts/git/push.sh && echo done"
INPUT_5=$(jq -n --arg cmd "$ABSOLUTE_CMD_5" '{"tool_name":"Bash","tool_input":{"command":$cmd}}')
EXPECTED_CMD_5="./.claude/support-scripts/git/push.sh && echo done"
EXPECTED_5=$(jq -cn --arg cmd "$EXPECTED_CMD_5" '{"tool_name":"Bash","tool_input":{"command":$cmd}}')

ACTUAL_5=$(run_hook "$INPUT_5")
assert_equals "absolute path in compound command is rewritten" "$EXPECTED_5" "$ACTUAL_5"

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

echo ""
echo "Results: $pass passed, $fail failed"
echo ""

if [[ "$fail" -gt 0 ]]; then
    exit 1
fi

exit 0
