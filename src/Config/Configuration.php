<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Config;

final readonly class Configuration
{
    /**
     * @param list<string> $repositories
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
    ) {
    }
}
