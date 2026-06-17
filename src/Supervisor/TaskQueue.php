<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;

final class TaskQueue
{
    /** @var array<string, Task> Keyed by task id, insertion order preserved */
    private array $tasks = [];

    public function enqueue(Task $task): void
    {
        $this->tasks[$task->id] = $task;
    }

    public function dequeue(): ?Task
    {
        foreach ($this->tasks as $id => $task) {
            if ($task->state === TaskState::Queued) {
                unset($this->tasks[$id]);
                return $task;
            }
        }

        return null;
    }

    public function peek(): ?Task
    {
        foreach ($this->tasks as $task) {
            if ($task->state === TaskState::Queued) {
                return $task;
            }
        }

        return null;
    }

    public function assign(string $taskId): void
    {
        if (!isset($this->tasks[$taskId])) {
            throw new \InvalidArgumentException(
                "Task not found in queue: {$taskId}"
            );
        }

        $this->tasks[$taskId] = $this->withState($this->tasks[$taskId], TaskState::Assigned);
    }

    public function unassign(string $taskId): void
    {
        if (!isset($this->tasks[$taskId])) {
            throw new \InvalidArgumentException(
                "Task not found in queue: {$taskId}"
            );
        }

        $this->tasks[$taskId] = $this->withState($this->tasks[$taskId], TaskState::Queued);
    }

    public function find(string $taskId): ?Task
    {
        return $this->tasks[$taskId] ?? null;
    }

    private function withState(Task $task, TaskState $state): Task
    {
        return new Task(
            id: $task->id,
            issueNumber: $task->issueNumber,
            repo: $task->repo,
            title: $task->title,
            body: $task->body,
            labels: $task->labels,
            state: $state,
        );
    }
}
