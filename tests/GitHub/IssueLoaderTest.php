<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\GitHub;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;
use ScottKeckWarren\Kanine\GitHub\GitHubClientInterface;
use ScottKeckWarren\Kanine\GitHub\IssueLoader;

final class IssueLoaderTest extends TestCase
{
    public function testItReturnsEmptyArrayWhenNoRepositoriesConfigured(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->expects($this->never())->method('fetchOpenIssues');

        $loader = new IssueLoader(
            client: $client,
            repositories: [],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertSame([], $tasks);
    }

    public function testItReturnsEmptyArrayWhenRepoHasNoIssues(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->with('owner/empty-repo')
            ->willReturn([]);

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/empty-repo'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertSame([], $tasks);
    }

    public function testItReturnsTasksForIssuesWithMatchingLabel(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->with('owner/repo')
            ->willReturn([
                [
                    'number' => 42,
                    'title'  => 'Fix the thing',
                    'body'   => 'Details here',
                    'labels' => [['name' => 'kanine: ready'], ['name' => 'bug']],
                ],
            ]);

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertCount(1, $tasks);
        $this->assertInstanceOf(Task::class, $tasks[0]);
        $this->assertSame(42, $tasks[0]->issueNumber);
        $this->assertSame('owner/repo', $tasks[0]->repo);
        $this->assertSame('Fix the thing', $tasks[0]->title);
        $this->assertSame('Details here', $tasks[0]->body);
        $this->assertSame(TaskState::Queued, $tasks[0]->state);
    }

    public function testItFiltersOutIssuesWithoutTheReadyLabel(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->with('owner/repo')
            ->willReturn([
                [
                    'number' => 7,
                    'title'  => 'Not ready issue',
                    'body'   => 'Body',
                    'labels' => [['name' => 'bug']],
                ],
                [
                    'number' => 8,
                    'title'  => 'Ready issue',
                    'body'   => 'Body',
                    'labels' => [['name' => 'kanine: ready']],
                ],
            ]);

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertCount(1, $tasks);
        $this->assertSame(8, $tasks[0]->issueNumber);
    }

    public function testItFiltersOutIssuesWithNoLabels(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->with('owner/repo')
            ->willReturn([
                [
                    'number' => 1,
                    'title'  => 'Unlabelled issue',
                    'body'   => 'Body',
                    'labels' => [],
                ],
            ]);

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertSame([], $tasks);
    }

    public function testItCollectsTasksFromMultipleRepositories(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->willReturnMap([
                [
                    'owner/repo-a',
                    [
                        [
                            'number' => 10,
                            'title'  => 'Task A',
                            'body'   => 'Body A',
                            'labels' => [['name' => 'kanine: ready']],
                        ],
                    ],
                ],
                [
                    'owner/repo-b',
                    [
                        [
                            'number' => 20,
                            'title'  => 'Task B',
                            'body'   => 'Body B',
                            'labels' => [['name' => 'kanine: ready']],
                        ],
                    ],
                ],
            ]);

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/repo-a', 'owner/repo-b'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertCount(2, $tasks);
        $this->assertSame('owner/repo-a', $tasks[0]->repo);
        $this->assertSame(10, $tasks[0]->issueNumber);
        $this->assertSame('owner/repo-b', $tasks[1]->repo);
        $this->assertSame(20, $tasks[1]->issueNumber);
    }

    public function testItGeneratesAUniqueIdForEachTask(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->willReturn([
                [
                    'number' => 5,
                    'title'  => 'Issue A',
                    'body'   => 'Body',
                    'labels' => [['name' => 'kanine: ready']],
                ],
                [
                    'number' => 6,
                    'title'  => 'Issue B',
                    'body'   => 'Body',
                    'labels' => [['name' => 'kanine: ready']],
                ],
            ]);

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertCount(2, $tasks);
        $this->assertNotSame($tasks[0]->id, $tasks[1]->id);
    }

    public function testItUsesRepoAndIssueNumberToFormTaskId(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->willReturn([
                [
                    'number' => 99,
                    'title'  => 'Some issue',
                    'body'   => 'Body',
                    'labels' => [['name' => 'kanine: ready']],
                ],
            ]);

        $loader = new IssueLoader(
            client: $client,
            repositories: ['myorg/myrepo'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertStringContainsString('myorg/myrepo', $tasks[0]->id);
        $this->assertStringContainsString('99', $tasks[0]->id);
    }

    // -------------------------------------------------------------------------
    // Error handling: GitHub API exceptions
    // -------------------------------------------------------------------------

    public function testItReturnsEmptyArrayWhenGitHubClientThrows(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->willThrowException(new RuntimeException('Connection refused'));

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertSame([], $tasks);
    }

    public function testItLogsErrorWhenGitHubClientThrows(): void
    {
        $errorMessages = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')
            ->willReturnCallback(function (string $message) use (&$errorMessages): void {
                $errorMessages[] = $message;
            });

        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->willThrowException(new RuntimeException('API rate limit exceeded'));

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logger: $logger,
        );

        $loader->load();

        $this->assertNotEmpty($errorMessages);
        $matched = array_filter(
            $errorMessages,
            fn (string $m): bool =>
                str_contains($m, 'GitHub fetch failed') && str_contains($m, 'API rate limit exceeded'),
        );
        $this->assertNotEmpty($matched, 'Expected error log containing "GitHub fetch failed" and exception message');
    }

    public function testItContinuesToLoadFromRemainingReposWhenOneThrows(): void
    {
        $client = $this->createMock(GitHubClientInterface::class);
        $client->method('fetchOpenIssues')
            ->willReturnCallback(function (string $repo): array {
                if ($repo === 'owner/broken-repo') {
                    throw new RuntimeException('Timeout');
                }

                return [
                    [
                        'number' => 5,
                        'title'  => 'Good issue',
                        'body'   => 'Body',
                        'labels' => [['name' => 'kanine: ready']],
                    ],
                ];
            });

        $loader = new IssueLoader(
            client: $client,
            repositories: ['owner/broken-repo', 'owner/good-repo'],
            readyLabel: 'kanine: ready',
            logger: $this->createMock(LoggerInterface::class),
        );

        $tasks = $loader->load();

        $this->assertCount(1, $tasks);
        $this->assertSame('owner/good-repo', $tasks[0]->repo);
    }
}
