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
     * @return array{
     *     assignment: array<string, mixed>|null,
     *     new_task: array<string, mixed>|null,
     *     pendingAnswers: array<mixed>
     * }
     */
    public function poll(string $pupId, string $token, string $status, ?float $usagePct = null): array;

    /**
     * Report pup status to the supervisor via the new /pups/{pupId}/status endpoint.
     *
     * @throws \RuntimeException on non-2xx response
     */
    public function reportStatus(string $pupId, string $status, string $message = ''): void;

    /**
     * Post a status update for a running task (legacy endpoint).
     *
     * @throws \RuntimeException on non-204 response
     */
    public function postStatus(string $pupId, string $token, string $taskId, string $message): void;

    /**
     * Post a question from the pup to the supervisor.
     *
     * @throws \RuntimeException on non-200 response
     */
    public function postQuestion(string $pupId, string $questionId, string $body): void;

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
