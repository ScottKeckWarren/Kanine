<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Domain;

enum PupStatus: string
{
    case Idle      = 'Idle';
    case Working   = 'Working';
    case Completed = 'completed';
    case Failed    = 'failed';
}
