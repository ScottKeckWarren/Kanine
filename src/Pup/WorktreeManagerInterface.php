<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

interface WorktreeManagerInterface
{
    /**
     * Create a git worktree for the given issue ID.
     *
     * @return string The path of the new worktree
     * @throws \RuntimeException if git worktree add fails
     */
    public function create(int $issueId): string;

    /**
     * Remove an existing git worktree.
     *
     * @throws \RuntimeException if git worktree remove fails
     */
    public function remove(string $worktreePath): void;
}
