<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\TaskState;

final class TaskStateTest extends TestCase
{
    public function test_it_is_a_string_backed_enum(): void
    {
        $this->assertSame('Queued', TaskState::Queued->value);
        $this->assertSame('Assigned', TaskState::Assigned->value);
    }

    public function test_it_can_be_created_from_a_string_value(): void
    {
        $this->assertSame(TaskState::Queued, TaskState::from('Queued'));
        $this->assertSame(TaskState::Assigned, TaskState::from('Assigned'));
    }

    public function test_it_has_exactly_two_cases(): void
    {
        $this->assertCount(2, TaskState::cases());
    }
}
