<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Logger;

interface LogTailProvider
{
    /**
     * @return list<string> the most recent log lines, oldest first, capped at $lines
     */
    public function tail(int $lines): array;
}
