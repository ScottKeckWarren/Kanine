<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

interface PupClientInterface
{
    /**
     * Register this pup with the supervisor.
     *
     * @return array{token: string, poll_interval_ms: int}
     */
    public function register(string $pupId, string $hostname): array;

    /**
     * Poll the supervisor for a new task assignment.
     *
     * @return array{new_task: array<string, mixed>|null}
     */
    public function poll(string $pupId, string $token, string $status): array;
}
