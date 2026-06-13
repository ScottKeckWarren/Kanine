<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\GitHub\IssueLoaderInterface;

final class Supervisor implements SupervisorInterface
{
    public function __construct(
        private readonly TaskQueue $taskQueue,
        private readonly PupRegistry $pupRegistry,
        private readonly HttpServerInterface $httpServer,
        private readonly LoggerInterface $logger,
        private readonly ?IssueLoaderInterface $issueLoader = null,
    ) {
    }

    public function boot(): void
    {
        $this->logger->info('Supervisor booting on ' . $this->httpServer->boundAddress());

        $this->loadIssues();

        $this->httpServer->start();
    }

    private function loadIssues(): void
    {
        if ($this->issueLoader === null) {
            return;
        }

        try {
            $tasks = $this->issueLoader->load();
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            return;
        }

        foreach ($tasks as $task) {
            $this->taskQueue->enqueue($task);
            $this->logger->info(sprintf(
                'Queued #%d: %s (%s)',
                $task->issueNumber,
                $task->title,
                $task->repo,
            ));
        }
    }
}
