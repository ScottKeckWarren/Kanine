<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;
use ScottKeckWarren\Kanine\Supervisor\TaskQueue;

final class TaskQueueTest extends TestCase
{
    public function testDequeueReturnsNullWhenQueueIsEmpty(): void
    {
        $queue = new TaskQueue();

        $result = $queue->dequeue();

        $this->assertNull($result);
    }

    public function testDequeueReturnsTaskAfterEnqueue(): void
    {
        $queue = new TaskQueue();
        $task = $this->makeTask('task-1');

        $queue->enqueue($task);
        $result = $queue->dequeue();

        $this->assertNotNull($result);
        $this->assertSame('task-1', $result->id);
    }

    public function testDequeuePreservesFifoOrder(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));
        $queue->enqueue($this->makeTask('task-2'));
        $queue->enqueue($this->makeTask('task-3'));

        $first  = $queue->dequeue();
        $second = $queue->dequeue();
        $third  = $queue->dequeue();

        $this->assertSame('task-1', $first?->id);
        $this->assertSame('task-2', $second?->id);
        $this->assertSame('task-3', $third?->id);
    }

    public function testDequeueRemovesTaskFromQueue(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));

        $queue->dequeue();
        $result = $queue->dequeue();

        $this->assertNull($result);
    }

    public function testDequeueSkipsAssignedTasks(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));
        $queue->enqueue($this->makeTask('task-2'));

        $queue->assign('task-1');
        $result = $queue->dequeue();

        $this->assertSame('task-2', $result?->id);
    }

    public function testDequeueReturnsNullWhenAllTasksAreAssigned(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));

        $queue->assign('task-1');
        $result = $queue->dequeue();

        $this->assertNull($result);
    }

    public function testPeekReturnsNullWhenQueueIsEmpty(): void
    {
        $queue = new TaskQueue();

        $result = $queue->peek();

        $this->assertNull($result);
    }

    public function testPeekReturnsNextQueuedTaskWithoutConsumingIt(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));

        $first  = $queue->peek();
        $second = $queue->peek();

        $this->assertSame('task-1', $first?->id);
        $this->assertSame('task-1', $second?->id);
    }

    public function testPeekDoesNotConsumeTheTask(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));

        $queue->peek();
        $result = $queue->dequeue();

        $this->assertSame('task-1', $result?->id);
    }

    public function testPeekSkipsAssignedTasks(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));
        $queue->enqueue($this->makeTask('task-2'));

        $queue->assign('task-1');
        $result = $queue->peek();

        $this->assertSame('task-2', $result?->id);
    }

    public function testAssignTransitionsTaskToAssignedState(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));

        $queue->assign('task-1');

        $task = $queue->find('task-1');
        $this->assertSame(TaskState::Assigned, $task?->state);
    }

    public function testAssignThrowsWhenTaskIdNotFound(): void
    {
        $queue = new TaskQueue();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/task-missing/');

        $queue->assign('task-missing');
    }

    public function testUnassignReturnsTaskToQueuedState(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));
        $queue->assign('task-1');

        $queue->unassign('task-1');

        $task = $queue->find('task-1');
        $this->assertSame(TaskState::Queued, $task?->state);
    }

    public function testUnassignMakesTaskAvailableViaDequeue(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));
        $queue->assign('task-1');

        $queue->unassign('task-1');
        $result = $queue->dequeue();

        $this->assertSame('task-1', $result?->id);
    }

    public function testUnassignThrowsWhenTaskIdNotFound(): void
    {
        $queue = new TaskQueue();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/task-missing/');

        $queue->unassign('task-missing');
    }

    public function testDequeuedTaskHasQueuedState(): void
    {
        $queue = new TaskQueue();
        $queue->enqueue($this->makeTask('task-1'));

        $result = $queue->dequeue();

        $this->assertSame(TaskState::Queued, $result?->state);
    }

    public function testEnqueuingAnAlreadyAssignedTaskPreservesItsState(): void
    {
        $queue = new TaskQueue();
        $assignedTask = $this->makeTask('task-1', TaskState::Assigned);

        $queue->enqueue($assignedTask);
        $result = $queue->dequeue();

        $this->assertNull($result);
    }

    public function testAssignPreservesLabels(): void
    {
        $queue = new TaskQueue();
        $task  = new Task(
            id: 'task-1',
            issueNumber: 1,
            repo: 'org/repo',
            title: 'Task 1',
            body: '',
            labels: ['bug', 'kanine: ready'],
            state: TaskState::Queued,
        );
        $queue->enqueue($task);

        $queue->assign('task-1');

        $found = $queue->find('task-1');
        $this->assertSame(['bug', 'kanine: ready'], $found?->labels);
    }

    private function makeTask(string $id, TaskState $state = TaskState::Queued): Task
    {
        return new Task(
            id: $id,
            issueNumber: 1,
            repo: 'org/repo',
            title: 'Task ' . $id,
            body: '',
            labels: [],
            state: $state,
        );
    }
}
