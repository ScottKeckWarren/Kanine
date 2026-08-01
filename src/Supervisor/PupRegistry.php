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
        $now   = new \DateTimeImmutable();

        $this->pups[$pupId] = new Pup(
            id: $pupId,
            hostname: $hostname,
            token: $token,
            status: PupStatus::Idle,
            assignedTaskId: null,
            lastHeartbeatAt: $now,
            registeredAt: $now,
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
            lastHeartbeatAt: $existing->lastHeartbeatAt,
            registeredAt: $existing->registeredAt,
        );
    }

    public function findByAssignedTask(string $taskId): ?Pup
    {
        foreach ($this->pups as $pup) {
            if ($pup->assignedTaskId === $taskId) {
                return $pup;
            }
        }

        return null;
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
            lastHeartbeatAt: $existing->lastHeartbeatAt,
            registeredAt: $existing->registeredAt,
        );
    }

    public function unassign(string $pupId): void
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
            assignedTaskId: null,
            lastHeartbeatAt: $existing->lastHeartbeatAt,
            registeredAt: $existing->registeredAt,
        );
    }

    /**
     * @return list<Pup>
     */
    public function getIdlePups(): array
    {
        return array_values(
            array_filter(
                $this->pups,
                fn (Pup $pup): bool => $pup->status === PupStatus::Idle,
            )
        );
    }

    /**
     * @return list<Pup>
     */
    public function getAllActivePups(): array
    {
        return array_values(
            array_filter(
                $this->pups,
                fn (Pup $pup): bool => $pup->status !== PupStatus::Inactive,
            )
        );
    }

    public function activePupCount(): int
    {
        return count($this->getAllActivePups());
    }

    public function forceHeartbeatAt(string $pupId, \DateTimeImmutable $at): void
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
            assignedTaskId: $existing->assignedTaskId,
            lastHeartbeatAt: $at,
            registeredAt: $existing->registeredAt,
        );
    }

    public function updateHeartbeat(string $pupId): void
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
            assignedTaskId: $existing->assignedTaskId,
            lastHeartbeatAt: new \DateTimeImmutable(),
            registeredAt: $existing->registeredAt,
        );
    }

    public function markInactive(string $pupId): void
    {
        $existing = $this->find($pupId);

        if ($existing === null) {
            return;
        }

        $this->pups[$pupId] = new Pup(
            id: $existing->id,
            hostname: $existing->hostname,
            token: $existing->token,
            status: PupStatus::Inactive,
            assignedTaskId: null,
            lastHeartbeatAt: $existing->lastHeartbeatAt,
            registeredAt: $existing->registeredAt,
        );
    }

    public function remove(string $pupId): void
    {
        unset($this->pups[$pupId]);
    }
}
