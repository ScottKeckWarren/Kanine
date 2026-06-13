<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\PupStatus;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;

final class PupRegistryAssignTest extends TestCase
{
    private PupRegistry $registry;

    public function testAssignStoresTaskIdOnPup(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->registry->assign(pupId: 'pup-001', taskId: 'task-abc');

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame('task-abc', $pup->assignedTaskId);
    }

    public function testAssignPreservesOtherPupFields(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->registry->assign(pupId: 'pup-001', taskId: 'task-abc');

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame('pup-001', $pup->id);
        $this->assertSame('worker-01.local', $pup->hostname);
        $this->assertSame($token, $pup->token);
        $this->assertSame(PupStatus::Idle, $pup->status);
    }

    public function testAssignDoesNothingForUnknownPup(): void
    {
        $this->registry->assign(pupId: 'unknown-pup', taskId: 'task-abc');

        $this->assertNull($this->registry->find('unknown-pup'));
    }

    public function testAssignReplacesPreviouslyAssignedTask(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');
        $this->registry->assign(pupId: 'pup-001', taskId: 'task-first');

        $this->registry->assign(pupId: 'pup-001', taskId: 'task-second');

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame('task-second', $pup->assignedTaskId);
    }

    protected function setUp(): void
    {
        $this->registry = new PupRegistry();
    }
}
