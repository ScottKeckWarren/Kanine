<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

interface GitHubLabelWriterInterface
{
    /**
     * @param array<int, array{add?: string, remove?: string}> $labelActions
     */
    public function applyActions(string $repo, int $issueNumber, array $labelActions): void;
}
