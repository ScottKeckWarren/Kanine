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
     * @return array{new_task: array<string, mixed>|null, throttled: bool}
     */
    public function poll(string $pupId, string $token, string $status, ?float $usagePct = null): array;

    /**
     * Post a status update for a running task.
     *
     * @throws \RuntimeException on non-204 response
     */
    public function postStatus(string $pupId, string $token, string $taskId, string $message): void;

    /**
     * Mark a task as complete.
     *
     * @return array<string, mixed>
     */
    public function postComplete(
        string $pupId,
        string $token,
        string $taskId,
        string $outcome,
        string $summary,
        ?float $usagePct,
    ): array;
}
