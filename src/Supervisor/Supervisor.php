<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use Psr\Log\LoggerInterface;

final class Supervisor implements SupervisorInterface
{
    public function __construct(
        private readonly TaskQueue $taskQueue,
        private readonly PupRegistry $pupRegistry,
        private readonly HttpServerInterface $httpServer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function boot(): void
    {
        $this->logger->info('Supervisor booting on ' . $this->httpServer->boundAddress());

        $this->httpServer->start();
    }
}
