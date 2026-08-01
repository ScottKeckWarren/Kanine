<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Config;

use ScottKeckWarren\Kanine\Domain\Column;

final readonly class Configuration
{
    /**
     * @param list<string> $repositories
     * @param list<Column> $columns
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $githubToken,
        public array $repositories,
        public string $readyLabel,
        public ?string $logFile,
        public int $statusIntervalMs = 10000,
        public float $usageThrottlePct = 90.0,
        public int $maxThrottlePollMs = 60000,
        public string $doneLabel = 'kanine: done',
        public string $failedLabel = 'kanine: failed',
        public string $architectLabel = 'architect',
        public string $humanFeedbackLabel = 'human feedback needed',
        public int $dispatchIntervalSeconds = 2,
        public int $pupTimeoutSeconds = 15,
        public bool $tls = false,
        public int $refreshIntervalSeconds = 10,
        public array $columns = [],
    ) {
    }
}
