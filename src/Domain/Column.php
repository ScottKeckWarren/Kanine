<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

final readonly class Column
{
    public function __construct(
        public string $name,
        public string $label,
        public int $position,
    ) {
    }
}
