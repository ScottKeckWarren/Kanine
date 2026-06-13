<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Pup;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Pup\ClaudeRunner;
use Symfony\Component\Process\Process;

final class ClaudeRunnerTest extends TestCase
{
    public function testStartDelegatesToProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('start');

        $runner = new ClaudeRunner(title: 'Fix bug', body: 'Some details', process: $process);
        $runner->start();
    }

    public function testIsRunningDelegatesToProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);

        $runner = new ClaudeRunner(title: 'Fix bug', body: 'Some details', process: $process);

        $this->assertTrue($runner->isRunning());
    }

    public function testIsRunningReturnsFalseWhenProcessIsNotRunning(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(false);

        $runner = new ClaudeRunner(title: 'Fix bug', body: 'Some details', process: $process);

        $this->assertFalse($runner->isRunning());
    }

    public function testStopDelegatesToProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('stop');

        $runner = new ClaudeRunner(title: 'Fix bug', body: 'Some details', process: $process);
        $runner->stop();
    }

    public function testGetExitCodeDelegatesToProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $runner = new ClaudeRunner(title: 'Fix bug', body: 'Some details', process: $process);

        $this->assertSame(0, $runner->getExitCode());
    }

    public function testGetExitCodeReturnsNullWhenProcessHasNotExited(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getExitCode')->willReturn(null);

        $runner = new ClaudeRunner(title: 'Fix bug', body: 'Some details', process: $process);

        $this->assertNull($runner->getExitCode());
    }

    public function testDefaultProcessCommandIncludesTitleAndBody(): void
    {
        $title = 'Fix the login bug';
        $body  = 'Users cannot log in when 2FA is enabled';

        $runner = new ClaudeRunner(title: $title, body: $body);

        $command = $runner->getCommand();

        $this->assertContains('claude', $command);
        $this->assertContains('--headless', $command);
        $this->assertContains('--print', $command);

        $promptArg = end($command);
        $this->assertStringContainsString($title, $promptArg);
        $this->assertStringContainsString($body, $promptArg);
    }
}
