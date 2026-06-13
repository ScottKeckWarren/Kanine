#!/usr/bin/env bash
set -euo pipefail

# Run the full PHPUnit test suite.
# Usage: ./.claude/support-scripts/test/phpunit-full.sh

if [[ ! -f "vendor/bin/phpunit" ]]; then
    echo "ERROR: vendor/bin/phpunit not found — run 'composer install' first"
    exit 1
fi

echo "Running: vendor/bin/phpunit"
echo ""

./vendor/bin/phpunit
