#!/usr/bin/env bash
set -euo pipefail

# Run PHPUnit against a single test file.
# Usage: ./.claude/support-scripts/phpunit-single-file.sh <path/to/TestFile.php>
# Example: ./.claude/support-scripts/phpunit-single-file.sh tests/Supervisor/PupRegistryTest.php

FILE="${1:-}"

if [[ -z "$FILE" ]]; then
    echo "Usage: $0 <path/to/TestFile.php>"
    echo "  path — relative path to the test file"
    exit 1
fi

if [[ ! -f "$FILE" ]]; then
    echo "ERROR: test file '$FILE' not found"
    exit 1
fi

if [[ ! -f "vendor/bin/phpunit" ]]; then
    echo "ERROR: vendor/bin/phpunit not found — run 'composer install' first"
    exit 1
fi

echo "Running: vendor/bin/phpunit '$FILE'"
echo ""

./vendor/bin/phpunit "$FILE"
