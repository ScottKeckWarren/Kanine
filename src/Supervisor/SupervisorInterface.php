<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use ScottKeckWarren\Kanine\GitHub\IssueLoaderInterface;

interface SupervisorInterface
{
    public function boot(): void;

    public function stop(): void;

    public function setIssueLoader(IssueLoaderInterface $loader): void;
}
