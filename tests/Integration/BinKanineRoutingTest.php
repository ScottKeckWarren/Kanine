<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises bin/kanine's real argv routing through the Symfony Console Application,
 * as a subprocess. No existing test covers this — CommandTester-based tests wrap
 * individual Command objects directly and never go through Application::run().
 *
 * Each subprocess runs with a fresh $HOME/cwd containing a minimal .kanine/kanine.yaml
 * (repositories: []) so ConfigInitializer's interactive wizard never triggers, and
 * ConfigLoader::validate() throws "No repositories configured" quickly and safely,
 * without ever starting the supervisor's event loop.
 */
final class BinKanineRoutingTest extends TestCase
{
    private string $tempDir;

    public function testServeSubcommandWithTokenDoesNotFailArgumentParsing(): void
    {
        $process = $this->runKanine(['serve', '--token', 'sometoken']);
        $output  = $process->getOutput() . $process->getErrorOutput();

        $this->assertStringNotContainsString('No arguments expected', $output);
        $this->assertStringContainsString('No repositories configured', $output);
    }

    public function testBareServeWithoutTokenAlsoRoutesCorrectly(): void
    {
        $process = $this->runKanine(['serve'], ['GITHUB_TOKEN' => 'sometoken']);
        $output  = $process->getOutput() . $process->getErrorOutput();

        $this->assertStringNotContainsString('No arguments expected', $output);
        $this->assertStringContainsString('No repositories configured', $output);
    }

    public function testNoArgsStillDefaultsToServe(): void
    {
        $process = $this->runKanine([], ['GITHUB_TOKEN' => 'sometoken']);
        $output  = $process->getOutput() . $process->getErrorOutput();

        $this->assertStringNotContainsString('No arguments expected', $output);
        $this->assertStringContainsString('No repositories configured', $output);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kanine-routing-test-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        $dotKanineDir = $this->tempDir . '/.kanine';
        mkdir($dotKanineDir, 0777, true);
        file_put_contents($dotKanineDir . '/kanine.yaml', <<<YAML
            github:
              repositories: []
              token_env: GITHUB_TOKEN
            supervisor:
              host: 127.0.0.1
              port: 3737
            YAML);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $extraEnv
     */
    private function runKanine(array $args, array $extraEnv = []): Process
    {
        $binKanine = dirname(__DIR__, 2) . '/bin/kanine';

        $process = new Process(
            command: array_merge(['php', $binKanine], $args),
            cwd: $this->tempDir,
            env: array_merge(['HOME' => $this->tempDir], $extraEnv),
        );
        $process->setTimeout(10);
        $process->run();

        return $process;
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
