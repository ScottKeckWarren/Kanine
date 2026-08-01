<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Board;

final readonly class BoardState
{
    public function __construct(
        public int $columnIndex,
        public int $cardIndex,
        public bool $autoRefresh,
    ) {
    }

    public static function initial(): self
    {
        return new self(columnIndex: 0, cardIndex: 0, autoRefresh: true);
    }

    public function withColumnIndex(int $columnIndex): self
    {
        return new self($columnIndex, $this->cardIndex, $this->autoRefresh);
    }

    public function withCardIndex(int $cardIndex): self
    {
        return new self($this->columnIndex, $cardIndex, $this->autoRefresh);
    }

    public function withAutoRefresh(bool $autoRefresh): self
    {
        return new self($this->columnIndex, $this->cardIndex, $autoRefresh);
    }
}
