<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\GitHub;

use ScottKeckWarren\Kanine\Domain\Task;

interface IssueLoaderInterface
{
    /**
     * @return list<Task>
     */
    public function load(): array;
}
