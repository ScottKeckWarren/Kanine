<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

final readonly class Question
{
    public function __construct(
        public string $id,
        public int $taskId,
        public string $pupId,
        public string $body,
        public \DateTimeImmutable $postedAt,
        public ?string $answer = null,
        public ?\DateTimeImmutable $answeredAt = null,
    ) {
    }
}
