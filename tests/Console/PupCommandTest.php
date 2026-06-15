<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Console\Command\PupCommand;
use ScottKeckWarren\Kanine\Pup\ClaudeRunner;
use ScottKeckWarren\Kanine\Pup\PupClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class PupCommandTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function testCommandNameIsPup(): void
    {
        $command = new PupCommand(
            pupClient: $this->createMock(PupClientInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertSame('pup', $command->getName());
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function testExecuteCallsRegisterWithPupIdAndHostname(): void
    {
        $pupId    = 'pup-abc';
        $hostname = gethostname() ?: 'unknown';

        $client = $this->createMock(PupClientInterface::class);
        $client->expects($this->once())
            ->method('register')
            ->with(
                pupId: $pupId,
                hostname: $hostname,
            )
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);

        $client->method('poll')
            ->willReturn(['new_task' => null]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => $pupId]);
    }

    public function testExecuteLogsRegistrationStart(): void
    {
        $infoMessages = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturn(['new_task' => null]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 1,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertTrue(
            $this->anyMessageContains($infoMessages, 'Registering'),
            'Expected at least one info log containing "Registering"',
        );
    }

    // -------------------------------------------------------------------------
    // Poll loop — no task
    // -------------------------------------------------------------------------

    public function testPollLoopLogsDebugWhenNoTaskAssigned(): void
    {
        $debugMessages = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('debug')
            ->willReturnCallback(function (string $message) use (&$debugMessages): void {
                $debugMessages[] = $message;
            });

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturn(['new_task' => null]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 1,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertTrue(
            $this->anyMessageContains($debugMessages, 'No task assigned'),
            'Expected at least one debug log containing "No task assigned"',
        );
    }

    // -------------------------------------------------------------------------
    // Poll loop — task received
    // -------------------------------------------------------------------------

    public function testPollLoopLogsInfoWhenTaskIsAssigned(): void
    {
        $infoMessages = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'details',
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturn(['new_task' => $task]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 1,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertTrue(
            $this->anyMessageMatches($infoMessages, '/Assigned task #\d+/'),
            'Expected at least one info log matching "Assigned task #<number>"',
        );
    }

    public function testPollLoopLogsTaskTitleAndRepoWhenTaskAssigned(): void
    {
        $infoMessages = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'details',
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturn(['new_task' => $task]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 1,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $matched = array_filter(
            $infoMessages,
            fn (string $m): bool => str_contains($m, 'Fix the bug') && str_contains($m, 'org/repo'),
        );

        $this->assertNotEmpty($matched, 'Expected at least one info log containing task title and repo');
    }

    public function testPollLoopContinuesWithWorkingStatusAfterTaskAssigned(): void
    {
        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'details',
            'state'        => 'queued',
        ];

        $pollCalls = [];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturnCallback(
                function (string $pupId, string $token, string $status) use ($task, &$pollCalls): array {
                    $pollCalls[] = $status;
                    return ['new_task' => $task];
                },
            );

        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);

        $runner = new ClaudeRunner(title: 'Fix the bug', body: 'details', process: $process);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            runnerFactory: static fn (string $t, string $b) => $runner,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertSame('idle', $pollCalls[0]);
        $this->assertSame('working', $pollCalls[1]);
    }

    // -------------------------------------------------------------------------
    // Exit code
    // -------------------------------------------------------------------------

    public function testExecuteReturnsSuccessExitCode(): void
    {
        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturn(['new_task' => null]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Runner integration
    // -------------------------------------------------------------------------

    public function testFactoryIsCalledWithTitleAndBodyWhenTaskReceived(): void
    {
        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'Some details here',
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturn(['new_task' => $task]);

        $factoryCalls = [];

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(true);

        $factory = function (string $title, string $body) use (&$factoryCalls, $stubProcess): ClaudeRunner {
            $factoryCalls[] = ['title' => $title, 'body' => $body];
            return new ClaudeRunner(title: $title, body: $body, process: $stubProcess);
        };

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
            runnerFactory: $factory,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertCount(1, $factoryCalls);
        $this->assertSame('Fix the bug', $factoryCalls[0]['title']);
        $this->assertSame('Some details here', $factoryCalls[0]['body']);
    }

    public function testRunnerStartIsCalledWhenTaskReceived(): void
    {
        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'Some details here',
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturn(['new_task' => $task]);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->expects($this->once())->method('start');
        $stubProcess->method('isRunning')->willReturn(true);

        $factory = fn (string $title, string $body): ClaudeRunner =>
            new ClaudeRunner(title: $title, body: $body, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
            runnerFactory: $factory,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);
    }

    public function testStartingClaudeIsLoggedWhenTaskReceived(): void
    {
        $infoMessages = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'Some details here',
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturn(['new_task' => $task]);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(true);

        $factory = fn (string $title, string $body): ClaudeRunner =>
            new ClaudeRunner(title: $title, body: $body, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 1,
            runnerFactory: $factory,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertTrue(
            $this->anyMessageMatches($infoMessages, '/Starting claude for issue #42/'),
            'Expected info log containing "Starting claude for issue #42"',
        );
    }

    public function testStatusResetsToIdleAfterRunnerFinishes(): void
    {
        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'details',
            'state'        => 'queued',
        ];

        $loopTick     = 0;
        $pollStatuses = [];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturnCallback(
                function (string $pupId, string $token, string $status) use ($task, &$loopTick, &$pollStatuses): array {
                    $pollStatuses[] = $status;
                    $loopTick++;
                    // First poll returns a task; subsequent polls return no task
                    return $loopTick === 1 ? ['new_task' => $task] : ['new_task' => null];
                },
            );

        // isRunning returns true on first check (tick 2 pre-poll check), false on second (tick 3 pre-poll check)
        $isRunningCallCount = 0;
        $stubProcess        = $this->createMock(Process::class);
        $stubProcess->method('isRunning')
            ->willReturnCallback(function () use (&$isRunningCallCount): bool {
                $isRunningCallCount++;
                // Still running on first check, finished on second
                return $isRunningCallCount === 1;
            });
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (string $title, string $body): ClaudeRunner =>
            new ClaudeRunner(title: $title, body: $body, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 3,
            runnerFactory: $factory,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertSame('idle', $pollStatuses[0]);
        $this->assertSame('working', $pollStatuses[1]);
        // After runner exits (isRunning false on second check), next poll should be idle again
        $this->assertSame('idle', $pollStatuses[2]);
    }

    public function testExitCodeIsLoggedWhenRunnerFinishes(): void
    {
        $infoMessages = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'details',
            'state'        => 'queued',
        ];

        $pollCount = 0;

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 100]);
        $client->method('poll')
            ->willReturnCallback(
                function () use ($task, &$pollCount): array {
                    $pollCount++;
                    return $pollCount === 1 ? ['new_task' => $task] : ['new_task' => null];
                },
            );

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (string $title, string $body): ClaudeRunner =>
            new ClaudeRunner(title: $title, body: $body, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 2,
            runnerFactory: $factory,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $this->assertTrue(
            $this->anyMessageMatches($infoMessages, '/Claude exited with code 0 for task #42/'),
            'Expected info log containing exit code for task #42',
        );
    }

    // -------------------------------------------------------------------------
    // Graceful shutdown — signal handling
    // -------------------------------------------------------------------------

    public function testPupRegistersSigintAndSigtermHandlers(): void
    {
        $registeredSignals = [];

        $signalInstaller = function (int $signal, callable $handler) use (&$registeredSignals): void {
            $registeredSignals[] = $signal;
        };

        $client = $this->makeSinglePollClient();

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
            signalInstaller: $signalInstaller,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        $this->assertContains(SIGINT, $registeredSignals);
        $this->assertContains(SIGTERM, $registeredSignals);
    }

    public function testSignalHandlerStopsRunnerIfRunning(): void
    {
        $capturedHandler = null;

        $signalInstaller = function (int $signal, callable $handler) use (&$capturedHandler): void {
            if ($capturedHandler === null) {
                $capturedHandler = $handler;
            }
        };

        $task = [
            'id' => 'org/repo#1', 'issue_number' => 1, 'repo' => 'org/repo',
            'title' => 'T', 'body' => 'B', 'state' => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturn(['new_task' => $task]);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(true);
        $stubProcess->expects($this->once())->method('stop');

        $factory = fn (string $t, string $b): ClaudeRunner => new ClaudeRunner($t, $b, $stubProcess);

        $exitCalled   = false;
        $exitCallback = function () use (&$exitCalled): void {
            $exitCalled = true;
        };

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
            runnerFactory: $factory,
            signalInstaller: $signalInstaller,
            exitCallback: $exitCallback,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        $this->assertNotNull($capturedHandler);
        ($capturedHandler)(SIGINT, null);

        $this->assertTrue($exitCalled);
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

        $client       = $this->makeSinglePollClient();
        $exitCallback = static function (): void {
        };

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 1,
            signalInstaller: $signalInstaller,
            exitCallback: $exitCallback,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        $this->assertNotNull($capturedHandler);
        ($capturedHandler)(SIGINT, null);

        $found = array_filter(
            $infoMessages,
            fn (string $m): bool => str_contains($m, 'Pup shutting down'),
        );
        $this->assertNotEmpty($found, 'Expected log message containing "Pup shutting down"');
    }

    public function testSignalHandlerInvokesExitCallback(): void
    {
        $capturedHandler = null;

        $signalInstaller = function (int $signal, callable $handler) use (&$capturedHandler): void {
            if ($capturedHandler === null) {
                $capturedHandler = $handler;
            }
        };

        $exitCalled   = false;
        $exitCallback = function () use (&$exitCalled): void {
            $exitCalled = true;
        };

        $command = new PupCommand(
            pupClient: $this->makeSinglePollClient(),
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
            signalInstaller: $signalInstaller,
            exitCallback: $exitCallback,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        $this->assertNotNull($capturedHandler);
        ($capturedHandler)(SIGINT, null);

        $this->assertTrue($exitCalled);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSinglePollClient(): PupClientInterface
    {
        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturn(['new_task' => null]);
        return $client;
    }

    /**
     * @param list<string> $messages
     */
    private function anyMessageContains(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $messages
     */
    private function anyMessageMatches(array $messages, string $pattern): bool
    {
        foreach ($messages as $message) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
