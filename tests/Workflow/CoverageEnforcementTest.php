<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Workflow;

use PHPUnit\Framework\TestCase;

/**
 * Tests the inline PHP coverage-enforcement snippet used in .github/workflows/ci.yml.
 *
 * The snippet is extracted to a standalone script so we can execute it in a
 * subprocess and assert on its exit code and stdout without duplicating the
 * logic inside this test class.
 */
final class CoverageEnforcementTest extends TestCase
{
    private string $scriptPath;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir    = sys_get_temp_dir() . '/kanine-coverage-test-' . uniqid('', true);
        $this->scriptPath = dirname(__DIR__, 2) . '/bin/check-coverage.php';

        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_it_passes_when_coverage_is_exactly_90_percent(): void
    {
        $coverageXml = $this->buildCoverageXml(statements: 100, covered: 90);
        $xmlPath     = $this->writeTempXml($coverageXml);

        [$exitCode, $output] = $this->runScript($xmlPath);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Line coverage: 90.00%', $output);
        $this->assertStringContainsString('PASS', $output);
    }

    public function test_it_passes_when_coverage_is_above_90_percent(): void
    {
        $coverageXml = $this->buildCoverageXml(statements: 100, covered: 95);
        $xmlPath     = $this->writeTempXml($coverageXml);

        [$exitCode, $output] = $this->runScript($xmlPath);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Line coverage: 95.00%', $output);
        $this->assertStringContainsString('PASS', $output);
    }

    public function test_it_fails_when_coverage_is_below_90_percent(): void
    {
        $coverageXml = $this->buildCoverageXml(statements: 100, covered: 89);
        $xmlPath     = $this->writeTempXml($coverageXml);

        [$exitCode, $output] = $this->runScript($xmlPath);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Line coverage: 89.00%', $output);
        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringContainsString('89.00%', $output);
        $this->assertStringContainsString('90%', $output);
    }

    public function test_it_fails_when_there_are_zero_statements(): void
    {
        $coverageXml = $this->buildCoverageXml(statements: 0, covered: 0);
        $xmlPath     = $this->writeTempXml($coverageXml);

        [$exitCode, $output] = $this->runScript($xmlPath);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No statements found', $output);
    }

    public function test_it_aggregates_multiple_metrics_elements(): void
    {
        $coverageXml = $this->buildCoverageXmlMultipleMetrics([
            ['statements' => 50, 'covered' => 45],
            ['statements' => 50, 'covered' => 46],
        ]);
        $xmlPath = $this->writeTempXml($coverageXml);

        [$exitCode, $output] = $this->runScript($xmlPath);

        // 91 / 100 = 91%
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Line coverage: 91.00%', $output);
        $this->assertStringContainsString('PASS', $output);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCoverageXml(int $statements, int $covered): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage generated="1">
              <project timestamp="1">
                <metrics statements="{$statements}"
                         coveredstatements="{$covered}"
                         conditionals="0"
                         coveredconditionals="0"
                         methods="0"
                         coveredmethods="0"/>
              </project>
            </coverage>
            XML;
    }

    /**
     * @param array<int, array{statements: int, covered: int}> $metricSets
     */
    private function buildCoverageXmlMultipleMetrics(array $metricSets): string
    {
        $metricsXml = '';
        foreach ($metricSets as $set) {
            $metricsXml .= <<<XML
                        <metrics statements="{$set['statements']}"
                                 coveredstatements="{$set['covered']}"
                                 conditionals="0"
                                 coveredconditionals="0"
                                 methods="0"
                                 coveredmethods="0"/>

                XML;
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage generated="1">
              <project timestamp="1">
            {$metricsXml}
              </project>
            </coverage>
            XML;
    }

    private function writeTempXml(string $content): string
    {
        $path = $this->tempDir . '/coverage.xml';
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array{int, string}
     */
    private function runScript(string $xmlPath): array
    {
        $command     = sprintf('php %s %s 2>&1', escapeshellarg($this->scriptPath), escapeshellarg($xmlPath));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if ($process === false) {
            $this->fail('Failed to start coverage check process.');
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, (string) $output];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
