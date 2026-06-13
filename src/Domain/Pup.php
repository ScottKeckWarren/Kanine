<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

final readonly class Pup
{
    public function __construct(
        public string $id,
        public string $hostname,
        public string $token,
        public PupStatus $status,
        public ?string $assignedTaskId,
    ) {
    }
}
