<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Pup;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ScottKeckWarren\Kanine\Pup\WorktreeManager;
use Symfony\Component\Process\Process;

final class WorktreeManagerTest extends TestCase
{
    public function testCreateRunsGitWorktreeAddWithCorrectArgs(): void
    {
        $capturedCommand = null;
        $capturedCwd     = null;

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('run');

        $factory = function (array $cmd, string $cwd) use (&$capturedCommand, &$capturedCwd, $process): Process {
            $capturedCommand = $cmd;
            $capturedCwd     = $cwd;
            return $process;
        };

        $manager = new WorktreeManager(
            repoPath: '/repo',
            worktreeBase: '/worktrees',
            processFactory: $factory,
        );

        $manager->create(42);

        $this->assertSame(['git', 'worktree', 'add', '/worktrees/issue-42', '-b', 'issue-42'], $capturedCommand);
        $this->assertSame('/repo', $capturedCwd);
    }

    public function testCreateReturnsExpectedWorktreePath(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('run');

        $factory = fn (array $cmd, string $cwd): Process => $process;

        $manager = new WorktreeManager(
            repoPath: '/repo',
            worktreeBase: '/worktrees',
            processFactory: $factory,
        );

        $path = $manager->create(42);

        $this->assertSame('/worktrees/issue-42', $path);
    }

    public function testCreateThrowsRuntimeExceptionOnNonZeroExit(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run');
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn('fatal: branch already exists');

        $factory = fn (array $cmd, string $cwd): Process => $process;

        $manager = new WorktreeManager(
            repoPath: '/repo',
            worktreeBase: '/worktrees',
            processFactory: $factory,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/git worktree add failed/');

        $manager->create(42);
    }

    public function testRemoveRunsGitWorktreeRemoveWithCorrectArgs(): void
    {
        $capturedCommand = null;

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('run');

        $factory = function (array $cmd, string $cwd) use (&$capturedCommand, $process): Process {
            $capturedCommand = $cmd;
            return $process;
        };

        $manager = new WorktreeManager(
            repoPath: '/repo',
            worktreeBase: '/worktrees',
            processFactory: $factory,
        );

        $manager->remove('/worktrees/issue-42');

        $this->assertSame(['git', 'worktree', 'remove', '--force', '/worktrees/issue-42'], $capturedCommand);
    }

    public function testRemoveThrowsRuntimeExceptionOnNonZeroExit(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run');
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn('error: not a worktree');

        $factory = fn (array $cmd, string $cwd): Process => $process;

        $manager = new WorktreeManager(
            repoPath: '/repo',
            worktreeBase: '/worktrees',
            processFactory: $factory,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/git worktree remove failed/');

        $manager->remove('/worktrees/issue-42');
    }
}
