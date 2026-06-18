<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Console\Command\PupCommand;
use ScottKeckWarren\Kanine\Pup\ClaudeRunner;
use ScottKeckWarren\Kanine\Pup\GitHubLabelWriterInterface;
use ScottKeckWarren\Kanine\Pup\PromptResolver;
use ScottKeckWarren\Kanine\Pup\PromptResolverInterface;
use ScottKeckWarren\Kanine\Pup\PupClientInterface;
use ScottKeckWarren\Kanine\ValueObject\String\Prompt;
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
            'labels'       => [],
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')
            ->willReturn(['new_task' => $task, 'throttled' => false]);
        $client->method('postComplete')->willReturn([]);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 1,
            runnerFactory: $factory,
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
            'labels'       => [],
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')
            ->willReturn(['new_task' => $task, 'throttled' => false]);
        $client->method('postComplete')->willReturn([]);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $logger,
            maxPolls: 1,
            runnerFactory: $factory,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--pup-id' => 'pup-1']);

        $matched = array_filter(
            $infoMessages,
            fn (string $m): bool => str_contains($m, 'Fix the bug') && str_contains($m, 'org/repo'),
        );

        $this->assertNotEmpty($matched, 'Expected at least one info log containing task title and repo');
    }

    public function testPollLoopEntersTickLoopWhileRunnerIsRunning(): void
    {
        // In the new design, once a task is received and the runner starts,
        // the command enters a blocking tick loop until the runner exits.
        // Polling only resumes after the runner finishes (with idle status).
        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'details',
            'labels'       => [],
            'state'        => 'queued',
        ];

        // isRunning: true on first tick check, false on second — exits tick loop
        $isRunningCount = 0;
        $stubProcess    = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturnCallback(function () use (&$isRunningCount): bool {
            $isRunningCount++;
            return $isRunningCount <= 1;
        });
        $stubProcess->method('getExitCode')->willReturn(0);

        $pollCallCount = 0;
        $client        = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturnCallback(function () use ($task, &$pollCallCount): array {
            $pollCallCount++;
            return $pollCallCount === 1
                ? ['new_task' => $task, 'throttled' => false]
                : ['new_task' => null, 'throttled' => false];
        });
        $client->method('postComplete')->willReturn([]);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            runnerFactory: $factory,
            tickSleepFn: static function (): void {
            },
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        // tick loop ran at least once (isRunning was called)
        $this->assertGreaterThanOrEqual(1, $isRunningCount);
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
            'labels'       => [],
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')
            ->willReturn(['new_task' => $task, 'throttled' => false]);
        $client->method('postComplete')->willReturn([]);

        $factoryCalls = [];

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = function (
            Prompt $prompt,
            int $issueNumber,
            string $title,
            string $body,
        ) use (
            &$factoryCalls,
            $stubProcess,
        ): ClaudeRunner {
            $factoryCalls[] = ['title' => $title, 'body' => $body];
            return new ClaudeRunner(
                prompt: $prompt,
                issueNumber: $issueNumber,
                title: $title,
                body: $body,
                process: $stubProcess,
            );
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
            'labels'       => [],
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')
            ->willReturn(['new_task' => $task, 'throttled' => false]);
        $client->method('postComplete')->willReturn([]);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->expects($this->once())->method('start');
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $title, string $body): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $title, body: $body, process: $stubProcess);

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
            'labels'       => [],
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')
            ->willReturn(['new_task' => $task, 'throttled' => false]);
        $client->method('postComplete')->willReturn([]);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $title, string $body): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $title, body: $body, process: $stubProcess);

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
        // In the new design, once a task is received, the command enters a blocking
        // tick loop until the runner exits — no polling occurs while running.
        // After the runner exits and postComplete is called, the next poll is idle.
        $task = [
            'id'           => 'org/repo#42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix the bug',
            'body'         => 'details',
            'labels'       => [],
            'state'        => 'queued',
        ];

        $pollStatuses = [];
        $pollCount    = 0;

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')
            ->willReturnCallback(
                function (
                    string $pupId,
                    string $token,
                    string $status
                ) use (
                    $task,
                    &$pollCount,
                    &$pollStatuses,
                ): array {
                    $pollStatuses[] = $status;
                    $pollCount++;
                    return $pollCount === 1
                        ? ['new_task' => $task, 'throttled' => false]
                        : ['new_task' => null, 'throttled' => false];
                },
            );
        $client->method('postComplete')->willReturn([]);

        // Tick loop: true on first isRunning check, false on second → exits tick loop
        $isRunningCount = 0;
        $stubProcess    = $this->createMock(Process::class);
        $stubProcess->method('isRunning')
            ->willReturnCallback(function () use (&$isRunningCount): bool {
                $isRunningCount++;
                return $isRunningCount <= 1;
            });
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $title, string $body): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $title, body: $body, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            runnerFactory: $factory,
            tickSleepFn: static function (): void {
            },
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        // poll 1: idle (task received, tick loop runs, runner exits)
        // poll 2: idle (after runner cleanup)
        $this->assertSame('idle', $pollStatuses[0]);
        $this->assertSame('idle', $pollStatuses[1]);
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
            'labels'       => [],
            'state'        => 'queued',
        ];

        $pollCount = 0;

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')
            ->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')
            ->willReturnCallback(
                function () use ($task, &$pollCount): array {
                    $pollCount++;
                    return $pollCount === 1
                        ? ['new_task' => $task, 'throttled' => false]
                        : ['new_task' => null, 'throttled' => false];
                },
            );
        $client->method('postComplete')->willReturn([]);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $title, string $body): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $title, body: $body, process: $stubProcess);

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
            'title' => 'T', 'body' => 'B', 'labels' => [], 'state' => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturn(['new_task' => $task, 'throttled' => false]);
        $client->method('postComplete')->willReturn([]);

        $exitCalled   = false;
        $exitCallback = function () use (&$exitCalled): void {
            $exitCalled = true;
        };

        // isRunning: true on first tick-loop check; tickSleepFn fires the signal handler
        // which calls isRunning (check 2 → true → stop called) then exitCallback.
        // Then tick loop checks isRunning again (check 3 → false) → exits.
        $isRunningCount = 0;
        $stubProcess    = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturnCallback(
            function () use (&$isRunningCount): bool {
                $isRunningCount++;
                // checks 1 and 2: true; check 3+: false
                return $isRunningCount <= 2;
            },
        );
        $stubProcess->expects($this->once())->method('stop');

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        // tickSleepFn fires the signal handler mid-tick-loop
        $tickSleepFn = function () use (&$capturedHandler): void {
            if ($capturedHandler !== null) {
                ($capturedHandler)(SIGINT, null);
                $capturedHandler = null; // fire only once
            }
        };

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
            runnerFactory: $factory,
            signalInstaller: $signalInstaller,
            exitCallback: $exitCallback,
            tickSleepFn: $tickSleepFn,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

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
    // usage_pct
    // -------------------------------------------------------------------------

    public function testUsagePctNullSentOnPoll(): void
    {
        $pollUsagePcts = [];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturnCallback(
            function (string $pupId, string $token, string $status, ?float $usagePct) use (&$pollUsagePcts): array {
                $pollUsagePcts[] = $usagePct;
                return ['new_task' => null, 'throttled' => false];
            },
        );

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        $this->assertCount(2, $pollUsagePcts);
        $this->assertNull($pollUsagePcts[0]);
        $this->assertNull($pollUsagePcts[1]);
    }

    // -------------------------------------------------------------------------
    // Throttle handling
    // -------------------------------------------------------------------------

    public function testThrottledResponseDoublesPollDelay(): void
    {
        $pollSleepDelays = [];

        $pollCount = 0;
        $client    = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 500]);
        $client->method('poll')->willReturnCallback(function () use (&$pollCount): array {
            $pollCount++;
            // First poll returns throttled: true; second returns no task
            return $pollCount === 1
                ? ['new_task' => null, 'throttled' => true]
                : ['new_task' => null, 'throttled' => false];
        });

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            maxThrottlePollMs: 60_000,
            pollSleepFn: static function (int $ms) use (&$pollSleepDelays): void {
                $pollSleepDelays[] = $ms;
            },
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        // After throttled poll, delay should be doubled: 500 * 2 = 1000
        $this->assertSame(1000, $pollSleepDelays[0]);
    }

    public function testThrottleResetRestoresNormalPollDelay(): void
    {
        $pollSleepDelays = [];

        $pollCount = 0;
        $client    = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 500]);
        $client->method('poll')->willReturnCallback(function () use (&$pollCount): array {
            $pollCount++;
            return match ($pollCount) {
                1 => ['new_task' => null, 'throttled' => true],   // throttled
                2 => ['new_task' => null, 'throttled' => false],  // reset
                default => ['new_task' => null, 'throttled' => false],
            };
        });

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 3,
            maxThrottlePollMs: 60_000,
            pollSleepFn: static function (int $ms) use (&$pollSleepDelays): void {
                $pollSleepDelays[] = $ms;
            },
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        // Poll 1 throttled → delay doubled: 500 * 2 = 1000
        $this->assertSame(1000, $pollSleepDelays[0]);
        // Poll 2 not throttled → delay reset to poll_interval_ms: 500
        $this->assertSame(500, $pollSleepDelays[1]);
    }

    public function testThrottleDelayIsCappedAtMaxThrottlePollMs(): void
    {
        $pollSleepDelays = [];

        $pollCount = 0;
        $client    = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 500]);
        $client->method('poll')->willReturnCallback(function () use (&$pollCount): array {
            $pollCount++;
            // Throttled for first 3 polls so we can see the cap
            return $pollCount <= 3
                ? ['new_task' => null, 'throttled' => true]
                : ['new_task' => null, 'throttled' => false];
        });

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 4,
            maxThrottlePollMs: 1500,
            pollSleepFn: static function (int $ms) use (&$pollSleepDelays): void {
                $pollSleepDelays[] = $ms;
            },
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        // Poll 1: 500 * 2 = 1000
        $this->assertSame(1000, $pollSleepDelays[0]);
        // Poll 2: 1000 * 2 = 2000 → capped at 1500
        $this->assertSame(1500, $pollSleepDelays[1]);
        // Poll 3: 1500 * 2 = 3000 → capped at 1500
        $this->assertSame(1500, $pollSleepDelays[2]);
    }

    // -------------------------------------------------------------------------
    // Label writer integration
    // -------------------------------------------------------------------------

    public function testLabelWriterCalledAfterComplete(): void
    {
        $task = [
            'id'           => 'task-label-1',
            'issue_number' => 10,
            'repo'         => 'org/repo',
            'title'        => 'Label task',
            'body'         => 'body',
            'labels'       => [],
            'state'        => 'queued',
        ];

        $labelActions = [['add' => 'done'], ['remove' => 'in-progress']];

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        $pollCount = 0;
        $client    = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturnCallback(function () use ($task, &$pollCount): array {
            $pollCount++;
            return $pollCount === 1
                ? ['new_task' => $task, 'throttled' => false]
                : ['new_task' => null, 'throttled' => false];
        });
        $client->method('postComplete')->willReturn(['label_actions' => $labelActions]);

        $labelWriter = $this->createMock(GitHubLabelWriterInterface::class);
        $labelWriter->expects($this->once())
            ->method('applyActions')
            ->with(
                repo: 'org/repo',
                issueNumber: 10,
                labelActions: $labelActions,
            );

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            runnerFactory: $factory,
            labelWriter: $labelWriter,
            repo: 'org/repo',
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);
    }

    public function testLabelWriterFailureDoesNotBlockPoll(): void
    {
        $task = [
            'id'           => 'task-label-fail',
            'issue_number' => 11,
            'repo'         => 'org/repo',
            'title'        => 'Failing label task',
            'body'         => 'body',
            'labels'       => [],
            'state'        => 'queued',
        ];

        $labelActions = [['add' => 'done']];

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        $pollCount      = 0;
        $secondPollSeen = false;
        $client         = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturnCallback(
            function () use ($task, &$pollCount, &$secondPollSeen): array {
                $pollCount++;
                if ($pollCount === 1) {
                    return ['new_task' => $task, 'throttled' => false];
                }
                $secondPollSeen = true;
                return ['new_task' => null, 'throttled' => false];
            },
        );
        $client->method('postComplete')->willReturn(['label_actions' => $labelActions]);

        $labelWriter = $this->createMock(GitHubLabelWriterInterface::class);
        $labelWriter->method('applyActions')
            ->willThrowException(new \RuntimeException('GitHub API error'));

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            runnerFactory: $factory,
            labelWriter: $labelWriter,
            repo: 'org/repo',
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        $this->assertTrue($secondPollSeen, 'Pup should continue polling after label writer failure');
    }

    // -------------------------------------------------------------------------
    // PromptResolver integration
    // -------------------------------------------------------------------------

    public function testPromptResolverCalledOnTaskReceipt(): void
    {
        $task = [
            'id'           => 'org/repo#7',
            'issue_number' => 7,
            'repo'         => 'org/repo',
            'title'        => 'Some task',
            'body'         => 'body text',
            'labels'       => ['bug', 'backend'],
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturn(['new_task' => $task, 'throttled' => false]);

        $resolvedPrompt = new Prompt('Resolved prompt text');

        $resolver = $this->createMock(PromptResolverInterface::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with(['bug', 'backend'])
            ->willReturn($resolvedPrompt);

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
            runnerFactory: $factory,
            promptResolver: $resolver,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);
    }

    // -------------------------------------------------------------------------
    // Status posting while running
    // -------------------------------------------------------------------------

    public function testStatusPostedWhileRunning(): void
    {
        $task = [
            'id'           => 'task-id-99',
            'issue_number' => 99,
            'repo'         => 'org/repo',
            'title'        => 'Running task',
            'body'         => 'body',
            'labels'       => [],
            'state'        => 'queued',
        ];

        // isRunning: true on first tick, false on second (exits loop)
        $isRunningCount = 0;
        $stubProcess    = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturnCallback(function () use (&$isRunningCount): bool {
            $isRunningCount++;
            return $isRunningCount <= 1;
        });
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        // Clock: starts at 0, advances past statusIntervalMs on second call
        $clockCalls = 0;
        $clockFn    = function () use (&$clockCalls): float {
            $clockCalls++;
            return (float) ($clockCalls * 200); // 200ms increments
        };

        $postStatusCalled = false;
        $client           = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturn(['new_task' => $task, 'throttled' => false]);
        $client->expects($this->atLeastOnce())
            ->method('postStatus');
        $client->method('postComplete')->willReturn([]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            runnerFactory: $factory,
            statusIntervalMs: 100,
            clockFn: $clockFn,
            tickSleepFn: static function (): void {
            },
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);
    }

    // -------------------------------------------------------------------------
    // postComplete on exit
    // -------------------------------------------------------------------------

    public function testPostCompleteCalledWithSuccessOnExitCode0(): void
    {
        $task = [
            'id'           => 'task-id-42',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix it',
            'body'         => 'body',
            'labels'       => [],
            'state'        => 'queued',
        ];

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        $pollCount = 0;
        $client    = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturnCallback(function () use ($task, &$pollCount): array {
            $pollCount++;
            return $pollCount === 1
                ? ['new_task' => $task, 'throttled' => false]
                : ['new_task' => null, 'throttled' => false];
        });
        $client->expects($this->once())
            ->method('postComplete')
            ->with(
                pupId: 'pup-1',
                token: 'tok',
                taskId: 'task-id-42',
                outcome: 'success',
                summary: '',
                usagePct: null,
            )
            ->willReturn([]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            runnerFactory: $factory,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);
    }

    public function testPostCompleteCalledWithFailureOnNonZeroExitCode(): void
    {
        $task = [
            'id'           => 'task-id-77',
            'issue_number' => 77,
            'repo'         => 'org/repo',
            'title'        => 'Fix it',
            'body'         => 'body',
            'labels'       => [],
            'state'        => 'queued',
        ];

        $stubProcess = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(1);

        $factory = fn (Prompt $p, int $n, string $t, string $b): ClaudeRunner =>
            new ClaudeRunner(prompt: $p, issueNumber: $n, title: $t, body: $b, process: $stubProcess);

        $pollCount = 0;
        $client    = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturnCallback(function () use ($task, &$pollCount): array {
            $pollCount++;
            return $pollCount === 1
                ? ['new_task' => $task, 'throttled' => false]
                : ['new_task' => null, 'throttled' => false];
        });
        $client->expects($this->once())
            ->method('postComplete')
            ->with(
                pupId: 'pup-1',
                token: 'tok',
                taskId: 'task-id-77',
                outcome: 'failure',
                summary: '',
                usagePct: null,
            )
            ->willReturn([]);

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
            runnerFactory: $factory,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);
    }

    public function testClaudeRunnerReceivesResolvedPrompt(): void
    {
        $task = [
            'id'           => 'org/repo#8',
            'issue_number' => 8,
            'repo'         => 'org/repo',
            'title'        => 'Task title',
            'body'         => 'body',
            'labels'       => ['feature'],
            'state'        => 'queued',
        ];

        $client = $this->createMock(PupClientInterface::class);
        $client->method('register')->willReturn(['token' => 'tok', 'poll_interval_ms' => 0]);
        $client->method('poll')->willReturn(['new_task' => $task, 'throttled' => false]);

        $resolvedPrompt = new Prompt('Custom resolved prompt');

        $resolver = $this->createMock(PromptResolverInterface::class);
        $resolver->method('resolve')->willReturn($resolvedPrompt);

        $capturedPrompt = null;
        $stubProcess    = $this->createMock(Process::class);
        $stubProcess->method('isRunning')->willReturn(false);
        $stubProcess->method('getExitCode')->willReturn(0);

        $factory = function (
            Prompt $prompt,
            int $issueNumber,
            string $title,
            string $body,
        ) use (
            &$capturedPrompt,
            $stubProcess
): ClaudeRunner {
            $capturedPrompt = $prompt;
            return new ClaudeRunner(
                prompt: $prompt,
                issueNumber: $issueNumber,
                title: $title,
                body: $body,
                process: $stubProcess,
            );
        };

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 1,
            runnerFactory: $factory,
            promptResolver: $resolver,
        );

        (new CommandTester($command))->execute(['--pup-id' => 'pup-1']);

        $this->assertSame($resolvedPrompt, $capturedPrompt);
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
