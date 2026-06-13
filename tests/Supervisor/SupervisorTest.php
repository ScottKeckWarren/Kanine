<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
}
