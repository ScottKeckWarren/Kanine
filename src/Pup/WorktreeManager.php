<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use Symfony\Component\Process\Process;

final class WorktreeManager implements WorktreeManagerInterface
{
    /** @var callable(array<string>, string): Process */
    private readonly mixed $processFactory;

    public function __construct(
        private readonly string $repoPath,
        private readonly string $worktreeBase,
        mixed $processFactory = null,
    ) {
        $this->processFactory = $processFactory
            ?? static fn (array $cmd, string $cwd): Process => new Process($cmd, $cwd);
    }

    /**
     * Create a git worktree for the given issue ID.
     *
     * @return string The path of the new worktree
     * @throws \RuntimeException if git worktree add fails
     */
    public function create(int $issueId): string
    {
        $path    = $this->worktreeBase . '/issue-' . $issueId;
        $branch  = 'issue-' . $issueId;
        $process = ($this->processFactory)(
            ['git', 'worktree', 'add', $path, '-b', $branch],
            $this->repoPath,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'git worktree add failed: ' . $process->getErrorOutput(),
            );
        }

        return $path;
    }

    /**
     * Remove an existing git worktree.
     *
     * @throws \RuntimeException if git worktree remove fails
     */
    public function remove(string $worktreePath): void
    {
        $process = ($this->processFactory)(
            ['git', 'worktree', 'remove', '--force', $worktreePath],
            $this->repoPath,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'git worktree remove failed: ' . $process->getErrorOutput(),
            );
        }
    }
}
