<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\GitHub;

use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;

final class IssueLoader implements IssueLoaderInterface
{
    /**
     * @param list<string> $repositories
     */
    public function __construct(
        private readonly GitHubClientInterface $client,
        private readonly array $repositories,
        private readonly string $readyLabel,
    ) {
    }

    /**
     * @return list<Task>
     */
    public function load(): array
    {
        $tasks = [];

        foreach ($this->repositories as $repository) {
            $issues = $this->client->fetchOpenIssues($repository);

            foreach ($issues as $issue) {
                if (!$this->hasReadyLabel($issue['labels'])) {
                    continue;
                }

                $tasks[] = new Task(
                    id: $repository . '#' . $issue['number'],
                    issueNumber: $issue['number'],
                    repo: $repository,
                    title: $issue['title'],
                    body: $issue['body'],
                    state: TaskState::Queued,
                );
            }
        }

        return $tasks;
    }

    /**
     * @param list<array{name: string}> $labels
     */
    private function hasReadyLabel(array $labels): bool
    {
        foreach ($labels as $label) {
            if ($label['name'] === $this->readyLabel) {
                return true;
            }
        }

        return false;
    }
}
