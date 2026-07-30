<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

interface PullRequestCreatorInterface
{
    /**
     * Push branch to origin from the given worktree path.
     *
     * @throws \RuntimeException if git push fails
     */
    public function push(string $worktreePath, string $branch): void;

    /**
     * Create a pull request and return its URL.
     */
    public function createPR(
        string $owner,
        string $repo,
        string $branch,
        string $title,
        string $body,
    ): string;
}
