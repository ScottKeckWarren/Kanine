<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;

final class TaskTest extends TestCase
{
    public function testLabelsFieldIsStored(): void
    {
        $task = new Task(
            id: 'task-000',
            issueNumber: 1,
            repo: 'org/repo',
            title: 'Title',
            body: 'Body',
            labels: ['bug', 'kanine: ready'],
            state: TaskState::Queued,
        );

        $this->assertSame(['bug', 'kanine: ready'], $task->labels);
    }

    public function testItExposesAllConstructorProperties(): void
    {
        $task = new Task(
            id: 'task-001',
            issueNumber: 42,
            repo: 'acme/app',
            title: 'Fix login bug',
            body: 'Steps to reproduce...',
            labels: [],
            state: TaskState::Queued,
        );

        $this->assertSame('task-001', $task->id);
        $this->assertSame(42, $task->issueNumber);
        $this->assertSame('acme/app', $task->repo);
        $this->assertSame('Fix login bug', $task->title);
        $this->assertSame('Steps to reproduce...', $task->body);
        $this->assertSame(TaskState::Queued, $task->state);
    }

    public function testItAcceptsAssignedState(): void
    {
        $task = new Task(
            id: 'task-002',
            issueNumber: 7,
            repo: 'org/repo',
            title: 'Add feature',
            body: '',
            labels: [],
            state: TaskState::Assigned,
        );

        $this->assertSame(TaskState::Assigned, $task->state);
    }

    public function testItIsImmutable(): void
    {
        $task = new Task(
            id: 'task-003',
            issueNumber: 1,
            repo: 'org/repo',
            title: 'Title',
            body: 'Body',
            labels: [],
            state: TaskState::Queued,
        );

        $reflection = new \ReflectionClass($task);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property \${$property->getName()} must be readonly"
            );
        }
    }
}
