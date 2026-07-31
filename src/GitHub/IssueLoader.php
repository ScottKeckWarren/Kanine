<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\GitHub;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;
use Throwable;

final class IssueLoader implements IssueLoaderInterface
{
    /**
     * @param list<string> $repositories
     */
    public function __construct(
        private readonly GitHubClientInterface $client,
        private readonly array $repositories,
        private readonly string $readyLabel,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?IssueStore $issueStore = null,
        private readonly ?LabelMapper $labelMapper = null,
    ) {
    }

    /**
     * @return list<Task>
     */
    public function load(): array
    {
        $tasks = [];

        foreach ($this->repositories as $repository) {
            $this->logger->info("Fetching issues from {$repository}");

            try {
                $issues = $this->client->fetchOpenIssues($repository);
            } catch (Throwable $e) {
                $this->logger->error("GitHub fetch failed: {$e->getMessage()}");
                continue;
            }

            foreach ($issues as $issue) {
                $this->logger->info("Found #{$issue['number']}: {$issue['title']} ({$repository})");

                $labelNames = array_map(
                    static fn (array $label): string => $label['name'],
                    $issue['labels'],
                );

                if ($this->issueStore !== null && $this->labelMapper !== null) {
                    $this->issueStore->add(new Issue(
                        id: $issue['number'],
                        repo: $repository,
                        title: $issue['title'],
                        body: $issue['body'] ?? '',
                        labels: $labelNames,
                        column: $this->labelMapper->resolveColumn($labelNames),
                        fetchedAt: new \DateTimeImmutable(),
                    ));
                }

                if (!$this->hasReadyLabel($issue['labels'])) {
                    continue;
                }

                $tasks[] = new Task(
                    id: $repository . '#' . $issue['number'],
                    issueNumber: $issue['number'],
                    repo: $repository,
                    title: $issue['title'],
                    body: $issue['body'] ?? '',
                    labels: $labelNames,
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
