<?php

declare(strict_types=1);

/**
 * Coverage enforcement script.
 *
 * Usage: php bin/check-coverage.php <path-to-coverage.xml>
 *
 * Parses a Clover-format coverage.xml, aggregates statement counts across all
 * <metrics> elements, computes line coverage percentage, and exits non-zero if
 * coverage is below 90%.
 *
 * This script is invoked by .github/workflows/ci.yml and is tested by
 * tests/Workflow/CoverageEnforcementTest.php.
 */

$xmlPath = $argv[1] ?? 'coverage.xml';

$xml = simplexml_load_file($xmlPath);

if ($xml === false) {
    echo "ERROR: Could not parse coverage file: {$xmlPath}\n";
    exit(1);
}

$metrics    = $xml->xpath('//metrics');
$statements = 0;
$covered    = 0;

foreach ($metrics as $m) {
    $statements += (int) $m['statements'];
    $covered    += (int) $m['coveredstatements'];
}

if ($statements === 0) {
    echo "No statements found in coverage.xml — check PHPUnit configuration.\n";
    exit(1);
}

$pct = ($covered / $statements) * 100;

printf("Line coverage: %.2f%%\n", $pct);

if ($pct < 90.0) {
    printf("FAIL: coverage %.2f%% is below the 90%% threshold.\n", $pct);
    exit(1);
}

echo "PASS\n";
