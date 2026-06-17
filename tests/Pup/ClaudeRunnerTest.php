<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Pup;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Pup\ClaudeRunner;
use ScottKeckWarren\Kanine\ValueObject\String\Prompt;
use Symfony\Component\Process\Process;

final class ClaudeRunnerTest extends TestCase
{
    public function testStartDelegatesToProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('start');

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );
        $runner->start();
    }

    public function testIsRunningDelegatesToProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );

        $this->assertTrue($runner->isRunning());
    }

    public function testIsRunningReturnsFalseWhenProcessIsNotRunning(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(false);

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );

        $this->assertFalse($runner->isRunning());
    }

    public function testStopDelegatesToProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('stop');

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );
        $runner->stop();
    }

    public function testGetExitCodeDelegatesToProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );

        $this->assertSame(0, $runner->getExitCode());
    }

    public function testGetExitCodeReturnsNullWhenProcessHasNotExited(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getExitCode')->willReturn(null);

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );

        $this->assertNull($runner->getExitCode());
    }

    public function testCommandIncludesPromptAndIssueBody(): void
    {
        $prompt = new Prompt('You are a senior PHP developer.');
        $title = 'Fix the login bug';
        $body  = 'Users cannot log in when 2FA is enabled';
        $issueNumber = 42;

        $runner = new ClaudeRunner(
            prompt: $prompt,
            issueNumber: $issueNumber,
            title: $title,
            body: $body,
        );

        $command = $runner->getCommand();

        $this->assertContains('claude', $command);
        $this->assertContains('--headless', $command);
        $this->assertContains('--print', $command);

        $promptArg = end($command);
        $expected = "{$prompt}\n\n## Issue #{$issueNumber}: {$title}\n\n{$body}";
        $this->assertSame($expected, $promptArg);
    }

    public function testGetLatestLineNullBeforeOutput(): void
    {
        $process = $this->createMock(Process::class);

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );

        $this->assertNull($runner->getLatestLine());
    }

    public function testGetLatestLineUpdatesFromCallback(): void
    {
        $capturedCallback = null;

        $process = $this->createMock(Process::class);
        $process->expects($this->once())
            ->method('start')
            ->willReturnCallback(function (callable $callback) use (&$capturedCallback): void {
                $capturedCallback = $callback;
            });

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );

        $runner->start();

        $capturedCallback(Process::OUT, "first line\n");
        $capturedCallback(Process::OUT, "second line\n");

        $this->assertSame('second line', $runner->getLatestLine());
    }

    public function testRingBufferMaxTenLines(): void
    {
        $capturedCallback = null;

        $process = $this->createMock(Process::class);
        $process->expects($this->once())
            ->method('start')
            ->willReturnCallback(function (callable $callback) use (&$capturedCallback): void {
                $capturedCallback = $callback;
            });

        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
            process: $process,
        );

        $runner->start();

        for ($i = 1; $i <= 15; $i++) {
            $capturedCallback(Process::OUT, "line {$i}\n");
        }

        $this->assertSame('line 15', $runner->getLatestLine());
        $this->assertCount(10, $runner->getLines());
        $this->assertSame('line 6', $runner->getLines()[0]);
        $this->assertSame('line 15', $runner->getLines()[9]);
    }

    public function testGetUsagePctReturnsNull(): void
    {
        $runner = new ClaudeRunner(
            prompt: $this->makePrompt(),
            issueNumber: 1,
            title: 'Fix bug',
            body: 'Some details',
        );

        $this->assertNull($runner->getUsagePct());
    }

    private function makePrompt(string $value = 'You are a helpful assistant.'): Prompt
    {
        return new Prompt($value);
    }
}
