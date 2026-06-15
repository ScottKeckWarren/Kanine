<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

interface HttpServerInterface
{
    public function boundAddress(): string;

    public function start(): void;

    public function stop(): void;
}
