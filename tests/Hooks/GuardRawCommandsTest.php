<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Hooks;

use PHPUnit\Framework\TestCase;

final class GuardRawCommandsTest extends TestCase
{
    private string $hookPath;

    // --- allowed commands (exit 0) ---

    public function testAllowsComposerCommand(): void
    {
        $result = $this->runHook('composer test');
        $this->assertSame(0, $result['exit']);
    }

    public function testAllowsUnrelatedShellCommand(): void
    {
        $result = $this->runHook('ls -la');
        $this->assertSame(0, $result['exit']);
    }

    public function testAllowsSupportScriptCall(): void
    {
        $result = $this->runHook('.claude/support-scripts/git/commit.sh');
        $this->assertSame(0, $result['exit']);
    }

    public function testAllowsPhpunitWithoutFilter(): void
    {
        $result = $this->runHook('phpunit');
        $this->assertSame(0, $result['exit']);
    }

    // --- blocked commands (exit 1) ---

    public function testBlocksRawGitCommit(): void
    {
        $result = $this->runHook('git commit -m "foo"');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('BLOCKED:', $result['output']);
        $this->assertStringContainsString('git commit', $result['output']);
    }

    public function testBlocksRawGitPush(): void
    {
        $result = $this->runHook('git push origin main');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('BLOCKED:', $result['output']);
    }

    public function testBlocksRawGitBranchShowCurrent(): void
    {
        // Regression: original bug allowed this through due to wrong JSON key
        $result = $this->runHook('git branch --show-current');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('BLOCKED:', $result['output']);
    }

    public function testBlocksRawGhIssueView(): void
    {
        $result = $this->runHook('gh issue view 33');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('BLOCKED:', $result['output']);
        $this->assertStringContainsString('gh issue', $result['output']);
    }

    public function testBlocksRawGhPrCreate(): void
    {
        $result = $this->runHook('gh pr create');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('BLOCKED:', $result['output']);
    }

    public function testBlocksPhpunitWithFilter(): void
    {
        $result = $this->runHook('phpunit --filter FooTest');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('BLOCKED:', $result['output']);
    }

    public function testBlocksVendorPhpunitWithFilter(): void
    {
        $result = $this->runHook('./vendor/bin/phpunit --filter FooTest');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('BLOCKED:', $result['output']);
    }

    // --- JSON format coverage ---

    public function testClaudeCodeToolInputFormatBlocked(): void
    {
        // Claude Code sends {"tool_name":"Bash","tool_input":{"command":"..."}}
        $result = $this->runHook('git push', 'tool_input');
        $this->assertSame(1, $result['exit']);
    }

    public function testLegacyTopLevelCommandFormatBlocked(): void
    {
        // Legacy format: {"command":"..."}
        $result = $this->runHook('git push', 'legacy');
        $this->assertSame(1, $result['exit']);
    }

    public function testEmptyJsonPayloadAllowed(): void
    {
        $result = $this->runHookRaw('{}');
        $this->assertSame(0, $result['exit']);
    }

    public function testMalformedJsonAllowed(): void
    {
        // Should not crash — script uses || true fallback
        $result = $this->runHookRaw('not-valid-json');
        $this->assertSame(0, $result['exit']);
    }

    // --- PHPUnit lifecycle ---

    protected function setUp(): void
    {
        $this->hookPath = dirname(__DIR__, 2) . '/.claude/support-scripts/hooks/guard-raw-commands.sh';
        $this->assertFileExists($this->hookPath);
        $this->assertTrue(is_executable($this->hookPath), 'Hook script must be executable');
    }

    // --- helpers ---

    /** @return array{exit: int, output: string} */
    private function runHook(string $command, string $format = 'tool_input'): array
    {
        $json = match ($format) {
            'tool_input' => json_encode(['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]),
            'legacy'     => json_encode(['command' => $command]),
            default      => throw new \InvalidArgumentException("Unknown format: $format"),
        };

        return $this->runHookRaw((string) $json);
    }

    /** @return array{exit: int, output: string} */
    private function runHookRaw(string $stdinPayload): array
    {
        $proc = proc_open(
            [$this->hookPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($proc);

        fwrite($pipes[0], $stdinPayload);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($proc);

        return ['exit' => $exit, 'output' => (string) $output];
    }
}
