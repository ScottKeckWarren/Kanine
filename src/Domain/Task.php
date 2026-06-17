<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

final readonly class Task
{
    /**
     * @param string[] $labels
     */
    public function __construct(
        public string $id,
        public int $issueNumber,
        public string $repo,
        public string $title,
        public string $body,
        public array $labels,
        public TaskState $state,
    ) {
    }
}
