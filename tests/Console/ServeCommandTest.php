<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Config\ConfigInitializerInterface;
use ScottKeckWarren\Kanine\Config\ConfigLoaderInterface;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\Console\Command\ServeCommand;
use ScottKeckWarren\Kanine\Supervisor\SupervisorInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ServeCommandTest extends TestCase
{
    public function testServeCommandNameIsServe(): void
    {
        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertSame('serve', $command->getName());
    }

    public function testServeCommandCallsSupervisorBoot(): void
    {
        $supervisor = $this->createMock(SupervisorInterface::class);
        $supervisor->expects($this->once())->method('boot');

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $supervisor,
            logger: $this->createMock(LoggerInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
    }

    public function testServeCommandExitsZero(): void
    {
        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testServeCommandLogsStartup(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('serve'));

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $logger,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
    }

    public function testServeRunsWizardWhenConfigAbsent(): void
    {
        $initializer = $this->makeInitializer(configExists: false);
        $initializer->expects($this->once())->method('run');

        $command = new ServeCommand(
            configInitializer: $initializer,
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
    }

    public function testServeDoesNotRunWizardWhenConfigPresent(): void
    {
        $initializer = $this->makeInitializer(configExists: true);
        $initializer->expects($this->never())->method('run');

        $command = new ServeCommand(
            configInitializer: $initializer,
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
    }

    // -------------------------------------------------------------------------
    // Graceful shutdown — signal registration
    // -------------------------------------------------------------------------

    public function testServeRegistersSigintAndSigtermHandlers(): void
    {
        $registeredSignals = [];

        $signalInstaller = function (int $signal, callable $handler) use (&$registeredSignals): void {
            $registeredSignals[] = $signal;
        };

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            signalInstaller: $signalInstaller,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertContains(SIGINT, $registeredSignals);
        $this->assertContains(SIGTERM, $registeredSignals);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeInitializer(bool $configExists): ConfigInitializerInterface
    {
        $initializer = $this->createMock(ConfigInitializerInterface::class);
        $initializer->method('configExists')->willReturn($configExists);

        return $initializer;
    }

    private function makeConfigLoader(): ConfigLoaderInterface
    {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'gh-token',
            repositories: [],
            readyLabel: 'kanine-ready',
            logFile: null,
        );

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn($config);

        return $loader;
    }
}
