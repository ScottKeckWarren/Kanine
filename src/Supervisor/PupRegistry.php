<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use ScottKeckWarren\Kanine\Domain\Pup;
use ScottKeckWarren\Kanine\Domain\PupStatus;

final class PupRegistry
{
    /** @var array<string, Pup> */
    private array $pups = [];

    public function register(string $pupId, string $hostname): string
    {
        $token = bin2hex(random_bytes(32));

        $this->pups[$pupId] = new Pup(
            id: $pupId,
            hostname: $hostname,
            token: $token,
            status: PupStatus::Idle,
            assignedTaskId: null,
        );

        return $token;
    }

    public function find(string $pupId): ?Pup
    {
        return $this->pups[$pupId] ?? null;
    }

    public function validate(string $pupId, string $token): bool
    {
        $pup = $this->find($pupId);

        if ($pup === null) {
            return false;
        }

        return hash_equals($pup->token, $token);
    }

    public function updateStatus(string $pupId, PupStatus $status): void
    {
        $existing = $this->find($pupId);

        if ($existing === null) {
            return;
        }

        $this->pups[$pupId] = new Pup(
            id: $existing->id,
            hostname: $existing->hostname,
            token: $existing->token,
            status: $status,
            assignedTaskId: $existing->assignedTaskId,
        );
    }

    public function assign(string $pupId, string $taskId): void
    {
        $existing = $this->find($pupId);

        if ($existing === null) {
            return;
        }

        $this->pups[$pupId] = new Pup(
            id: $existing->id,
            hostname: $existing->hostname,
            token: $existing->token,
            status: $existing->status,
            assignedTaskId: $taskId,
        );
    }
}
