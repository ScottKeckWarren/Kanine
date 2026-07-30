<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\GitHub;

use ScottKeckWarren\Kanine\Domain\Column;

final class LabelMapper
{
    /**
     * @param list<Column> $columns
     */
    public function __construct(private readonly array $columns)
    {
    }

    /**
     * @param list<string> $issueLabels
     */
    public function resolveColumn(array $issueLabels): string
    {
        foreach ($this->columns as $column) {
            if (in_array($column->label, $issueLabels, true)) {
                return $column->name;
            }
        }

        $sorted = $this->columns;
        usort($sorted, fn (Column $a, Column $b): int => $a->position <=> $b->position);

        return $sorted[0]->name ?? 'Backlog';
    }
}
