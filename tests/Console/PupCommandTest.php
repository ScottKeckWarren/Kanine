<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Console\Command\PupCommand;
use ScottKeckWarren\Kanine\Pup\PupClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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

        $command = new PupCommand(
            pupClient: $client,
            logger: $this->createMock(LoggerInterface::class),
            maxPolls: 2,
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
    // Helpers
    // -------------------------------------------------------------------------

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
