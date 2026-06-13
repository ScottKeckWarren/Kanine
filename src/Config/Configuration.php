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
    ) {
    }
}
