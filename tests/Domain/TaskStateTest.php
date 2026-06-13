<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\TaskState;

final class TaskStateTest extends TestCase
{
    public function testItIsAStringBackedEnum(): void
    {
        $this->assertSame('Queued', TaskState::Queued->value);
        $this->assertSame('Assigned', TaskState::Assigned->value);
    }

    public function testItCanBeCreatedFromAStringValue(): void
    {
        $this->assertSame(TaskState::Queued, TaskState::from('Queued'));
        $this->assertSame(TaskState::Assigned, TaskState::from('Assigned'));
    }

    public function testItHasExactlyTwoCases(): void
    {
        $this->assertCount(2, TaskState::cases());
    }
}
