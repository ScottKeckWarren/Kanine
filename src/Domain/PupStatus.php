<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

enum PupStatus: string
{
    case Idle      = 'idle';
    case Working   = 'working';
    case Blocked   = 'blocked';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Inactive  = 'inactive';
}
