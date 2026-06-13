#!/usr/bin/env bash
set -euo pipefail

# Run PHPUnit with a --filter argument.
# Usage: ./.claude/support-scripts/test/phpunit-filter.sh <filter>
# Example: ./.claude/support-scripts/test/phpunit-filter.sh LoggerFactoryTest

FILTER="${1:-}"

if [[ -z "$FILTER" ]]; then
    echo "Usage: $0 <filter>"
    echo "  filter — PHPUnit --filter value (class name, method, or regex)"
    exit 1
fi

if [[ ! -f "vendor/bin/phpunit" ]]; then
    echo "ERROR: vendor/bin/phpunit not found — run 'composer install' first"
    exit 1
fi

echo "Running: vendor/bin/phpunit --filter '$FILTER'"
echo ""

./vendor/bin/phpunit --filter "$FILTER"
