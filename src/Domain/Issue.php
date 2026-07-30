<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

final readonly class Issue
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public int $id,
        public string $repo,
        public string $title,
        public string $body,
        public array $labels,
        public string $column,
        public bool $pinned = false,
        public ?string $assignedPupId = null,
        public ?\DateTimeImmutable $fetchedAt = null,
    ) {
    }
}
