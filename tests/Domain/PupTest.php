<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Pup;
use ScottKeckWarren\Kanine\Domain\PupStatus;

final class PupTest extends TestCase
{
    public function test_it_exposes_all_constructor_properties_when_idle(): void
    {
        $pup = new Pup(
            id: 'pup-001',
            hostname: 'worker-01.local',
            token: 'secret-token',
            status: PupStatus::Idle,
            assignedTaskId: null,
        );

        $this->assertSame('pup-001', $pup->id);
        $this->assertSame('worker-01.local', $pup->hostname);
        $this->assertSame('secret-token', $pup->token);
        $this->assertSame(PupStatus::Idle, $pup->status);
        $this->assertNull($pup->assignedTaskId);
    }

    public function test_it_exposes_assigned_task_id_when_working(): void
    {
        $pup = new Pup(
            id: 'pup-002',
            hostname: 'worker-02.local',
            token: 'another-token',
            status: PupStatus::Working,
            assignedTaskId: 'task-099',
        );

        $this->assertSame(PupStatus::Working, $pup->status);
        $this->assertSame('task-099', $pup->assignedTaskId);
    }

    public function test_it_is_immutable(): void
    {
        $pup = new Pup(
            id: 'pup-003',
            hostname: 'host',
            token: 'tok',
            status: PupStatus::Idle,
            assignedTaskId: null,
        );

        $reflection = new \ReflectionClass($pup);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property \${$property->getName()} must be readonly"
            );
        }
    }
}
