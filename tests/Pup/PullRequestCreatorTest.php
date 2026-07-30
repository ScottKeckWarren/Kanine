<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Pup;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ScottKeckWarren\Kanine\Pup\PullRequestCreator;
use Symfony\Component\Process\Process;

final class PullRequestCreatorTest extends TestCase
{
    public function testPushRunsGitPushOriginBranchFromWorktreePath(): void
    {
        $capturedCommand = null;
        $capturedCwd     = null;

        $process = $this->createMock(Process::class);
        $process->method('run');
        $process->method('isSuccessful')->willReturn(true);

        $factory = function (array $cmd, string $cwd) use (&$capturedCommand, &$capturedCwd, $process): Process {
            $capturedCommand = $cmd;
            $capturedCwd     = $cwd;
            return $process;
        };

        $creator = new PullRequestCreator(
            prCreator: fn () => 'https://github.com/owner/repo/pull/1',
            processFactory: $factory,
        );

        $creator->push('/worktrees/issue-42', 'issue-42');

        $this->assertSame(['git', 'push', 'origin', 'issue-42'], $capturedCommand);
        $this->assertSame('/worktrees/issue-42', $capturedCwd);
    }

    public function testPushThrowsRuntimeExceptionOnNonZeroExit(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run');
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn('fatal: remote rejected');

        $factory = fn (array $cmd, string $cwd): Process => $process;

        $creator = new PullRequestCreator(
            prCreator: fn () => 'https://github.com/owner/repo/pull/1',
            processFactory: $factory,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/git push failed/');

        $creator->push('/worktrees/issue-42', 'issue-42');
    }

    public function testCreatePRCallsPrCreatorWithCorrectArgs(): void
    {
        $capturedArgs = null;

        $prCreator = function (
            string $owner,
            string $repo,
            string $branch,
            string $title,
            string $body,
        ) use (&$capturedArgs): string {
            $capturedArgs = compact('owner', 'repo', 'branch', 'title', 'body');
            return 'https://github.com/owner/repo/pull/1';
        };

        $process = $this->createMock(Process::class);
        $factory = fn (array $cmd, string $cwd): Process => $process;

        $creator = new PullRequestCreator(
            prCreator: $prCreator,
            processFactory: $factory,
        );

        $creator->createPR('owner', 'repo', 'issue-42', 'Fix bug', 'The body');

        $this->assertSame('owner', $capturedArgs['owner']);
        $this->assertSame('repo', $capturedArgs['repo']);
        $this->assertSame('issue-42', $capturedArgs['branch']);
        $this->assertSame('Fix bug', $capturedArgs['title']);
        $this->assertSame('The body', $capturedArgs['body']);
    }

    public function testCreatePRReturnsPrUrl(): void
    {
        $prCreator = fn (
            string $owner,
            string $repo,
            string $branch,
            string $title,
            string $body,
        ): string => 'https://github.com/owner/repo/pull/99';

        $process = $this->createMock(Process::class);
        $factory = fn (array $cmd, string $cwd): Process => $process;

        $creator = new PullRequestCreator(
            prCreator: $prCreator,
            processFactory: $factory,
        );

        $url = $creator->createPR('owner', 'repo', 'issue-42', 'Fix bug', 'The body');

        $this->assertSame('https://github.com/owner/repo/pull/99', $url);
    }
}
