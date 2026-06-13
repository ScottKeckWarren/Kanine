<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\Console\Command\ServeCommand;
use ScottKeckWarren\Kanine\Supervisor\SupervisorInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ServeCommandTest extends TestCase
{
    public function testServeCommandNameIsServe(): void
    {
        $logger     = $this->createMock(LoggerInterface::class);
        $supervisor = $this->createMock(SupervisorInterface::class);

        $command = new ServeCommand(
            config: $this->makeConfig(),
            supervisor: $supervisor,
            logger: $logger,
        );

        $this->assertSame('serve', $command->getName());
    }

    public function testServeCommandCallsSupervisorBoot(): void
    {
        $logger     = $this->createMock(LoggerInterface::class);
        $supervisor = $this->createMock(SupervisorInterface::class);

        $supervisor->expects($this->once())->method('boot');

        $command = new ServeCommand(
            config: $this->makeConfig(),
            supervisor: $supervisor,
            logger: $logger,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
    }

    public function testServeCommandExitsZero(): void
    {
        $logger     = $this->createMock(LoggerInterface::class);
        $supervisor = $this->createMock(SupervisorInterface::class);

        $command = new ServeCommand(
            config: $this->makeConfig(),
            supervisor: $supervisor,
            logger: $logger,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testServeCommandLogsStartup(): void
    {
        $logger     = $this->createMock(LoggerInterface::class);
        $supervisor = $this->createMock(SupervisorInterface::class);

        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('serve'));

        $command = new ServeCommand(
            config: $this->makeConfig(),
            supervisor: $supervisor,
            logger: $logger,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
    }

    private function makeConfig(): Configuration
    {
        return new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'gh-token',
            repositories: [],
            readyLabel: 'kanine-ready',
            logFile: null,
        );
    }
}
