<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

enum TaskState: string
{
    case Queued   = 'Queued';
    case Assigned = 'Assigned';
}
