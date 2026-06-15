<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console\Command;

use Closure;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Pup\ClaudeRunner;
use ScottKeckWarren\Kanine\Pup\PupClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PupCommand extends Command
{
    /** @var Closure(string $title, string $body): ClaudeRunner */
    private readonly Closure $runnerFactory;

    /** @var callable(int, callable): void */
    private readonly mixed $signalInstaller;

    /** @var callable(): void */
    private readonly mixed $exitCallback;

    public function __construct(
        private readonly PupClientInterface $pupClient,
        private readonly LoggerInterface $logger,
        private readonly int $maxPolls = PHP_INT_MAX,
        ?Closure $runnerFactory = null,
        ?callable $signalInstaller = null,
        ?callable $exitCallback = null,
    ) {
        $this->runnerFactory = $runnerFactory
            ?? static fn (string $title, string $body): ClaudeRunner => new ClaudeRunner($title, $body);
        $this->signalInstaller = $signalInstaller ?? static function (int $signal, callable $handler): void {
            if (function_exists('pcntl_signal')) {
                pcntl_signal($signal, $handler);
            }
        };
        $this->exitCallback = $exitCallback ?? static function (): never {
            exit(0);
        };
        parent::__construct('pup');
    }

    protected function configure(): void
    {
        $this->setDescription('Start the Kanine pup process: register with supervisor and poll for tasks');
        $this->addOption(
            name: 'pup-id',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Unique pup identifier (defaults to KANINE_PUP_ID env var)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pupId    = $this->resolvePupId($input);
        $hostname = gethostname() ?: 'unknown';

        $this->logger->info("Registering pup {$pupId} from {$hostname}");

        $registration   = $this->pupClient->register(pupId: $pupId, hostname: $hostname);
        $token          = $registration['token'];
        $pollIntervalMs = $registration['poll_interval_ms'];

        $this->logger->info("Registered pup {$pupId}; poll_interval_ms={$pollIntervalMs}");

        $status             = 'idle';
        $pollCount          = 0;
        $runner             = null;
        $currentIssueNumber = null;

        $shutdownHandler = function () use (&$runner): void {
            if ($runner !== null && $runner->isRunning()) {
                $runner->stop();
            }
            $this->logger->info('Pup shutting down, claude process terminated');
            ($this->exitCallback)();
        };
        ($this->signalInstaller)(SIGINT, $shutdownHandler);
        ($this->signalInstaller)(SIGTERM, $shutdownHandler);

        while ($pollCount < $this->maxPolls) {
            if ($runner !== null && !$runner->isRunning()) {
                $this->logger->info(
                    "Claude exited with code {$runner->getExitCode()} for task #{$currentIssueNumber}",
                );
                $runner             = null;
                $currentIssueNumber = null;
                $status             = 'idle';
            }

            $result  = $this->pupClient->poll(pupId: $pupId, token: $token, status: $status);
            $newTask = $result['new_task'];

            if ($newTask !== null) {
                $issueNumber = $newTask['issue_number'];
                $title       = $newTask['title'];
                $body        = $newTask['body'] ?? '';
                $repo        = $newTask['repo'];

                $this->logger->info("Starting claude for issue #{$issueNumber}: {$title}");
                $this->logger->info("Assigned task #{$issueNumber}: {$title} ({$repo})");

                $runner             = ($this->runnerFactory)($title, $body);
                $currentIssueNumber = $issueNumber;
                $runner->start();
                $status = 'working';
            } else {
                $this->logger->debug('No task assigned');
            }

            $pollCount++;

            if ($pollCount < $this->maxPolls) {
                usleep($pollIntervalMs * 1000);
            }
        }

        return Command::SUCCESS;
    }

    private function resolvePupId(InputInterface $input): string
    {
        $optionValue = $input->getOption('pup-id');

        if (is_string($optionValue) && $optionValue !== '') {
            return $optionValue;
        }

        $envValue = getenv('KANINE_PUP_ID');

        if (is_string($envValue) && $envValue !== '') {
            return $envValue;
        }

        return uniqid('pup-', more_entropy: true);
    }
}
