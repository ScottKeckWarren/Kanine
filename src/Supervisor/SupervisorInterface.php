<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

interface SupervisorInterface
{
    public function boot(): void;
}
