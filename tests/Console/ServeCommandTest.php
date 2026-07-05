<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Config\ConfigInitializerInterface;
use ScottKeckWarren\Kanine\Config\ConfigLoaderInterface;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\Console\Command\ServeCommand;
use ScottKeckWarren\Kanine\GitHub\IssueLoaderInterface;
use ScottKeckWarren\Kanine\Supervisor\SupervisorInterface;
use ScottKeckWarren\Kanine\Supervisor\UsageTracker;
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

    public function testSignalHandlerLogsShutdownMessage(): void
    {
        $capturedHandler = null;

        $signalInstaller = function (int $signal, callable $handler) use (&$capturedHandler): void {
            if ($capturedHandler === null) {
                $capturedHandler = $handler;
            }
        };

        $infoMessages = [];
        $logger       = $this->createMock(LoggerInterface::class);
        $logger->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $logger,
            signalInstaller: $signalInstaller,
        );

        (new CommandTester($command))->execute([]);

        $this->assertNotNull($capturedHandler);
        ($capturedHandler)(SIGINT, null);

        $found = array_filter($infoMessages, fn (string $m): bool => str_contains($m, 'Supervisor shutting down'));
        $this->assertNotEmpty($found, 'Expected log message containing "Supervisor shutting down"');
    }

    public function testSignalHandlerCallsSupervisorStop(): void
    {
        $capturedHandler = null;

        $signalInstaller = function (int $signal, callable $handler) use (&$capturedHandler): void {
            if ($capturedHandler === null) {
                $capturedHandler = $handler;
            }
        };

        $supervisor = $this->createMock(SupervisorInterface::class);
        $supervisor->expects($this->once())->method('stop');

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $supervisor,
            logger: $this->createMock(LoggerInterface::class),
            signalInstaller: $signalInstaller,
        );

        (new CommandTester($command))->execute([]);

        $this->assertNotNull($capturedHandler);
        ($capturedHandler)(SIGINT, null);
    }

    public function testServeCommandLogsAndOutputsErrorWhenConfigLoaderThrows(): void
    {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('load')->willThrowException(
            new \InvalidArgumentException('ERROR: GITHUB_TOKEN env var not set.')
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('GITHUB_TOKEN'));

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(true),
            configLoader: $loader,
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $logger,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('GITHUB_TOKEN', $tester->getDisplay());
    }

    public function testServeCommandInjectsIssueLoaderIntoSupervisorBeforeBoot(): void
    {
        $capturedLoader = null;

        $supervisor = $this->createMock(SupervisorInterface::class);
        $supervisor->expects($this->once())
            ->method('setIssueLoader')
            ->willReturnCallback(function (IssueLoaderInterface $loader) use (&$capturedLoader): void {
                $capturedLoader = $loader;
            });

        $issueLoader = $this->createMock(IssueLoaderInterface::class);
        $factory     = fn (Configuration $config, LoggerInterface $logger): IssueLoaderInterface => $issueLoader;

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $supervisor,
            logger: $this->createMock(LoggerInterface::class),
            issueLoaderFactory: $factory,
        );

        (new CommandTester($command))->execute([]);

        $this->assertSame($issueLoader, $capturedLoader);
    }

    // -------------------------------------------------------------------------
    // UsageTracker, doneLabel, failedLabel wiring
    // -------------------------------------------------------------------------

    public function testUsageTrackerPassedToHttpServer(): void
    {
        $capturedTracker = null;

        $httpServerFactory = function (
            UsageTracker $tracker,
            string $doneLabel,
            string $failedLabel,
        ) use (&$capturedTracker): void {
            $capturedTracker = $tracker;
        };

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            httpServerFactory: $httpServerFactory,
        );

        (new CommandTester($command))->execute([]);

        $this->assertInstanceOf(UsageTracker::class, $capturedTracker);
    }

    public function testDoneLabelPassedToHttpServer(): void
    {
        $capturedDoneLabel = null;

        $httpServerFactory = function (
            UsageTracker $tracker,
            string $doneLabel,
            string $failedLabel,
        ) use (&$capturedDoneLabel): void {
            $capturedDoneLabel = $doneLabel;
        };

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(doneLabel: 'kanine: done'),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            httpServerFactory: $httpServerFactory,
        );

        (new CommandTester($command))->execute([]);

        $this->assertSame('kanine: done', $capturedDoneLabel);
    }

    public function testUsageTrackerConstructedWithConfiguredThreshold(): void
    {
        $capturedTracker = null;

        $httpServerFactory = function (
            UsageTracker $tracker,
            string $doneLabel,
            string $failedLabel,
        ) use (&$capturedTracker): void {
            $capturedTracker = $tracker;
        };

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(usageThrottlePct: 75.0),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            httpServerFactory: $httpServerFactory,
        );

        (new CommandTester($command))->execute([]);

        $this->assertInstanceOf(UsageTracker::class, $capturedTracker);
        $capturedTracker->record(76.0);
        $this->assertTrue($capturedTracker->isThrottled());
    }

    public function testFailedLabelPassedToHttpServer(): void
    {
        $capturedFailedLabel = null;

        $httpServerFactory = function (
            UsageTracker $tracker,
            string $doneLabel,
            string $failedLabel,
        ) use (&$capturedFailedLabel): void {
            $capturedFailedLabel = $failedLabel;
        };

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(failedLabel: 'kanine: failed'),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            httpServerFactory: $httpServerFactory,
        );

        (new CommandTester($command))->execute([]);

        $this->assertSame('kanine: failed', $capturedFailedLabel);
    }

    public function testArchitectLabelPassedToHttpServer(): void
    {
        $capturedArchitectLabel = null;

        $httpServerFactory = function (
            UsageTracker $tracker,
            string $doneLabel,
            string $failedLabel,
            string $architectLabel,
        ) use (&$capturedArchitectLabel): void {
            $capturedArchitectLabel = $architectLabel;
        };

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(architectLabel: 'architect'),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            httpServerFactory: $httpServerFactory,
        );

        (new CommandTester($command))->execute([]);

        $this->assertSame('architect', $capturedArchitectLabel);
    }

    public function testHumanFeedbackLabelPassedToHttpServer(): void
    {
        $capturedHumanFeedbackLabel = null;

        $httpServerFactory = function (
            UsageTracker $tracker,
            string $doneLabel,
            string $failedLabel,
            string $architectLabel,
            string $humanFeedbackLabel,
        ) use (&$capturedHumanFeedbackLabel): void {
            $capturedHumanFeedbackLabel = $humanFeedbackLabel;
        };

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(humanFeedbackLabel: 'human feedback needed'),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            httpServerFactory: $httpServerFactory,
        );

        (new CommandTester($command))->execute([]);

        $this->assertSame('human feedback needed', $capturedHumanFeedbackLabel);
    }

    // -------------------------------------------------------------------------
    // T021/T022: BoardRenderer wiring
    // -------------------------------------------------------------------------

    public function testServeCommandCanBeConstructedWithBoardRenderer(): void
    {
        $boardRenderer = new \ScottKeckWarren\Kanine\Board\BoardRenderer([]);

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            boardRenderer: $boardRenderer,
        );

        $this->assertInstanceOf(ServeCommand::class, $command);
    }

    public function testServeCommandStillBootsSupervisorWithBoardRenderer(): void
    {
        $boardRenderer = new \ScottKeckWarren\Kanine\Board\BoardRenderer([]);

        $supervisor = $this->createMock(SupervisorInterface::class);
        $supervisor->expects($this->once())->method('boot');

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $supervisor,
            logger: $this->createMock(LoggerInterface::class),
            boardRenderer: $boardRenderer,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
    }

    // -------------------------------------------------------------------------
    // T031: Dispatcher wiring
    // -------------------------------------------------------------------------

    public function testServeCommandCanBeConstructedWithDispatcher(): void
    {
        $dispatcher = new \ScottKeckWarren\Kanine\Supervisor\Dispatcher(
            new \ScottKeckWarren\Kanine\Supervisor\IssueStore(),
            new \ScottKeckWarren\Kanine\Supervisor\PupRegistry(),
        );

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            dispatcher: $dispatcher,
        );

        $this->assertInstanceOf(ServeCommand::class, $command);
    }

    public function testServeCommandGetDispatcherReturnsInjectedDispatcher(): void
    {
        $dispatcher = new \ScottKeckWarren\Kanine\Supervisor\Dispatcher(
            new \ScottKeckWarren\Kanine\Supervisor\IssueStore(),
            new \ScottKeckWarren\Kanine\Supervisor\PupRegistry(),
        );

        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            dispatcher: $dispatcher,
        );

        $this->assertSame($dispatcher, $command->getDispatcher());
    }

    public function testServeCommandGetDispatcherReturnsNullByDefault(): void
    {
        $command = new ServeCommand(
            configInitializer: $this->makeInitializer(configExists: true),
            configLoader: $this->makeConfigLoader(),
            supervisor: $this->createMock(SupervisorInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertNull($command->getDispatcher());
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

    private function makeConfigLoader(
        string $doneLabel = 'kanine: done',
        string $failedLabel = 'kanine: failed',
        float $usageThrottlePct = 90.0,
        string $architectLabel = 'architect',
        string $humanFeedbackLabel = 'human feedback needed',
    ): ConfigLoaderInterface {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'gh-token',
            repositories: [],
            readyLabel: 'kanine-ready',
            logFile: null,
            usageThrottlePct: $usageThrottlePct,
            doneLabel: $doneLabel,
            failedLabel: $failedLabel,
            architectLabel: $architectLabel,
            humanFeedbackLabel: $humanFeedbackLabel,
        );

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn($config);

        return $loader;
    }
}
