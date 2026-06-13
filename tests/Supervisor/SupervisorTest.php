<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;
use ScottKeckWarren\Kanine\GitHub\IssueLoaderInterface;
use ScottKeckWarren\Kanine\Supervisor\HttpServerInterface;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;
use ScottKeckWarren\Kanine\Supervisor\Supervisor;
use ScottKeckWarren\Kanine\Supervisor\TaskQueue;

final class SupervisorTest extends TestCase
{
    public function testSupervisorIsInstantiable(): void
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
        );

        $this->assertInstanceOf(Supervisor::class, $supervisor);
    }

    public function testBootCallsStartOnHttpServer(): void
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);

        $server->expects($this->once())->method('start');

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
        );

        $supervisor->boot();
    }

    public function testBootLogsStartingMessage(): void
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);
        $server->method('start');

        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('Supervisor booting'));

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
        );

        $supervisor->boot();
    }

    public function testBootCallsIssueLoaderLoadWhenLoaderIsProvided(): void
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);
        $loader   = $this->createMock(IssueLoaderInterface::class);

        $loader->expects($this->once())->method('load')->willReturn([]);

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
            issueLoader: $loader,
        );

        $supervisor->boot();
    }

    public function testBootEnqueuesTasksReturnedByIssueLoader(): void
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);
        $loader   = $this->createMock(IssueLoaderInterface::class);

        $task = new Task(
            id: 'owner/repo#42',
            issueNumber: 42,
            repo: 'owner/repo',
            title: 'Fix the thing',
            body: 'Details',
            state: TaskState::Queued,
        );

        $loader->method('load')->willReturn([$task]);

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
            issueLoader: $loader,
        );

        $supervisor->boot();

        $this->assertSame($task, $queue->peek());
    }

    public function testBootLogsInfoForEachEnqueuedTask(): void
    {
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);
        $loader   = $this->createMock(IssueLoaderInterface::class);

        $task = new Task(
            id: 'owner/repo#7',
            issueNumber: 7,
            repo: 'owner/repo',
            title: 'My issue',
            body: 'Body',
            state: TaskState::Queued,
        );

        $loader->method('load')->willReturn([$task]);

        $loggedMessages = [];
        $logger         = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message) use (&$loggedMessages): void {
            $loggedMessages[] = $message;
        });

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
            issueLoader: $loader,
        );

        $supervisor->boot();

        $matched = array_filter(
            $loggedMessages,
            static fn(string $m) => preg_match('/Queued #7.*My issue.*owner\/repo/', $m) === 1,
        );

        $this->assertNotEmpty($matched, 'Expected a log entry matching "Queued #7: My issue (owner/repo)"');
    }

    public function testBootDoesNotCrashWhenIssueLoaderThrows(): void
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);
        $loader   = $this->createMock(IssueLoaderInterface::class);

        $loader->method('load')->willThrowException(new \RuntimeException('API failure'));

        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('API failure'));

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
            issueLoader: $loader,
        );

        $supervisor->boot();

        $this->assertNull($queue->peek());
    }

    public function testBootProceedsToStartHttpServerEvenWhenIssueLoaderThrows(): void
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);
        $loader   = $this->createMock(IssueLoaderInterface::class);

        $loader->method('load')->willThrowException(new \RuntimeException('API failure'));

        $server->expects($this->once())->method('start');

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
            issueLoader: $loader,
        );

        $supervisor->boot();
    }

    public function testBootWorksWithoutIssueLoader(): void
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $queue    = new TaskQueue();
        $registry = new PupRegistry();
        $server   = $this->createMock(HttpServerInterface::class);

        $server->expects($this->once())->method('start');

        $supervisor = new Supervisor(
            taskQueue: $queue,
            pupRegistry: $registry,
            httpServer: $server,
            logger: $logger,
        );

        $supervisor->boot();

        $this->assertNull($queue->peek());
    }
}
