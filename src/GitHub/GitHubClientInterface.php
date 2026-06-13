<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\GitHub;

interface GitHubClientInterface
{
    /**
     * Fetch all open issues for a repository.
     *
     * @param string $repository In "owner/repo" format.
     * @return list<array{number: int, title: string, body: string, labels: list<array{name: string}>}>
     */
    public function fetchOpenIssues(string $repository): array;
}
