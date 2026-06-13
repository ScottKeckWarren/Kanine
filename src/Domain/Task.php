<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

final readonly class Task
{
    public function __construct(
        public string $id,
        public int $issueNumber,
        public string $repo,
        public string $title,
        public string $body,
        public TaskState $state,
    ) {
    }
}
