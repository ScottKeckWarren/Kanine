<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console\Command;

use Closure;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Pup\ClaudeRunner;
use ScottKeckWarren\Kanine\Pup\GitHubLabelWriterInterface;
use ScottKeckWarren\Kanine\Pup\PromptResolver;
use ScottKeckWarren\Kanine\Pup\PromptResolverInterface;
use ScottKeckWarren\Kanine\Pup\PullRequestCreatorInterface;
use ScottKeckWarren\Kanine\Pup\PupClientInterface;
use ScottKeckWarren\Kanine\Pup\WorktreeManagerInterface;
use ScottKeckWarren\Kanine\ValueObject\String\Prompt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PupCommand extends Command
{
    /** @var Closure(Prompt $prompt, int $issueNumber, string $title, string $body, ?string $workingDirectory): ClaudeRunner */
    private readonly Closure $runnerFactory;

    /** @var callable(int, callable): void */
    private readonly mixed $signalInstaller;

    /** @var callable(): void */
    private readonly mixed $exitCallback;

    private readonly PromptResolverInterface $promptResolver;

    /** @var Closure(): float */
    private readonly Closure $clockFn;

    /** @var Closure(): void */
    private readonly Closure $tickSleepFn;

    /** @var Closure(list<string>): list<array{questionId: string, body: string}> */
    private readonly Closure $questionDetector;

    /** @var Closure(int): void */
    private readonly Closure $pollSleepFn;

    public function __construct(
        private readonly PupClientInterface $pupClient,
        private readonly LoggerInterface $logger,
        private readonly int $maxPolls = PHP_INT_MAX,
        ?Closure $runnerFactory = null,
        ?callable $signalInstaller = null,
        ?callable $exitCallback = null,
        private readonly Prompt $prompt = new Prompt('You are a helpful software engineer.'),
        ?PromptResolverInterface $promptResolver = null,
        private readonly int $statusIntervalMs = 30_000,
        private readonly int $maxThrottlePollMs = 60_000,
        private readonly ?GitHubLabelWriterInterface $labelWriter = null,
        private readonly string $repo = '',
        ?callable $clockFn = null,
        ?callable $tickSleepFn = null,
        ?callable $pollSleepFn = null,
        private readonly ?WorktreeManagerInterface $worktreeManager = null,
        private readonly ?PullRequestCreatorInterface $pullRequestCreator = null,
        private readonly string $prOwner = '',
        private readonly string $prRepo = '',
        ?callable $questionDetector = null,
    ) {
        $this->promptResolver = $promptResolver ?? new PromptResolver(basePath: (string) getcwd());
        $this->runnerFactory = $runnerFactory
            ?? static fn (
                Prompt $prompt,
                int $issueNumber,
                string $title,
                string $body,
                ?string $workingDirectory = null,
            ): ClaudeRunner => new ClaudeRunner(
                prompt: $prompt,
                issueNumber: $issueNumber,
                title: $title,
                body: $body,
                workingDirectory: $workingDirectory,
            );
        $this->signalInstaller = $signalInstaller ?? static function (int $signal, callable $handler): void {
            if (function_exists('pcntl_signal')) {
                pcntl_signal($signal, $handler);
            }
        };
        $this->exitCallback = $exitCallback ?? static function (): never {
            exit(0);
        };
        $this->clockFn = $clockFn !== null
            ? Closure::fromCallable($clockFn)
            : static fn (): float => microtime(true) * 1000.0;
        $this->tickSleepFn = $tickSleepFn !== null
            ? Closure::fromCallable($tickSleepFn)
            : static function (): void {
                usleep(100_000);
            };
        $this->pollSleepFn = $pollSleepFn !== null
            ? Closure::fromCallable($pollSleepFn)
            : static function (int $ms): void {
                usleep($ms * 1000);
            };
        $this->questionDetector = $questionDetector !== null
            ? Closure::fromCallable($questionDetector)
            : static function (array $lines): array {
                return [];
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
        $currentDelayMs = $pollIntervalMs;

        $this->logger->info("Registered pup {$pupId}; poll_interval_ms={$pollIntervalMs}");

        $status             = 'idle';
        $pollCount          = 0;
        $runner             = null;
        $currentIssueNumber = null;
        $currentTaskId      = null;
        $currentRepo        = $this->repo;
        $currentWorktree    = null;
        $currentTitle       = null;
        $currentBody        = null;

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
                $exitCode = $runner->getExitCode();
                $this->logger->info(
                    "Claude exited with code {$exitCode} for task #{$currentIssueNumber}",
                );

                if ($exitCode !== 0) {
                    $errorOutput = $runner->getErrorOutput();

                    if ($errorOutput !== []) {
                        $this->logger->error(
                            "Claude stderr for task #{$currentIssueNumber}: " . implode("\n", $errorOutput),
                        );
                    }
                }

                $outcome = ($exitCode === 0) ? 'success' : 'failure';

                if ($this->worktreeManager !== null && $currentWorktree !== null) {
                    // New worktree-based lifecycle
                    if ($outcome === 'success') {
                        try {
                            $branch = 'issue-' . $currentIssueNumber;
                            $this->logger->debug("Pushing branch {$branch} for issue #{$currentIssueNumber}");
                            $this->pullRequestCreator?->push($currentWorktree, $branch);
                            $this->logger->debug("Creating PR for branch {$branch}");
                            $prUrl = $this->pullRequestCreator?->createPR(
                                $this->prOwner,
                                $this->prRepo,
                                $branch,
                                (string) ($currentTitle ?? 'Fix issue #' . $currentIssueNumber),
                                (string) ($currentBody ?? ''),
                            ) ?? '';
                            $this->logger->debug("PR created for issue #{$currentIssueNumber}: {$prUrl}");
                            $this->pupClient->reportStatus($pupId, 'complete', $prUrl);
                        } catch (\Throwable $e) {
                            $this->logger->error("PR creation failed: {$e->getMessage()}");
                            $this->worktreeManager->remove($currentWorktree);
                            $this->pupClient->reportStatus($pupId, 'failed', $e->getMessage());
                        }
                    } else {
                        $this->worktreeManager->remove($currentWorktree);
                        $errorMsg = implode("\n", $runner->getErrorOutput());
                        $this->pupClient->reportStatus($pupId, 'failed', $errorMsg);
                    }
                } elseif ($currentTaskId !== null) {
                    // Legacy task-based lifecycle
                    $completeResult = $this->pupClient->postComplete(
                        pupId: $pupId,
                        token: $token,
                        taskId: $currentTaskId,
                        outcome: $outcome,
                        summary: '',
                        usagePct: null,
                    );

                    /** @var array<int, array{add?: string, remove?: string}> $labelActions */
                    $labelActions = $completeResult['label_actions'] ?? [];

                    if ($this->labelWriter !== null && $labelActions !== []) {
                        try {
                            $this->labelWriter->applyActions(
                                repo: $currentRepo,
                                issueNumber: (int) $currentIssueNumber,
                                labelActions: $labelActions,
                            );
                        } catch (\Throwable $e) {
                            $this->logger->error(
                                "Failed to apply label actions for task #{$currentIssueNumber}: {$e->getMessage()}",
                            );
                        }
                    }
                }

                $runner             = null;
                $currentIssueNumber = null;
                $currentTaskId      = null;
                $currentWorktree    = null;
                $currentTitle       = null;
                $currentBody        = null;
                $status             = 'idle';
            }

            $result     = $this->pupClient->poll(pupId: $pupId, token: $token, status: $status, usagePct: null);
            $assignment = $result['assignment'] ?? null;
            $newTask    = $result['new_task'] ?? null;
            $throttled  = $result['throttled'] ?? false;

            if ($throttled) {
                $currentDelayMs = min($currentDelayMs * 2, $this->maxThrottlePollMs);
                $this->logger->warning("Throttled by supervisor; next poll delay={$currentDelayMs}ms");
            } else {
                $currentDelayMs = $pollIntervalMs;
            }

            // Prefer the new assignment key; fall back to legacy new_task
            $activeAssignment = $assignment ?? $newTask;

            if ($activeAssignment !== null) {
                // Resolve issue number: new contract uses issueId, legacy uses issue_number
                $issueNumber = $activeAssignment['issueId'] ?? $activeAssignment['issue_number'];
                $title       = $activeAssignment['title'];
                $body        = $activeAssignment['body'] ?? '';
                $currentRepo = $activeAssignment['repo'];
                /** @var list<string> $labels */
                $labels      = $activeAssignment['labels'] ?? [];

                $this->logger->info("Starting claude for issue #{$issueNumber}: {$title}");
                $this->logger->info("Assigned task #{$issueNumber}: {$title} ({$currentRepo})");

                $worktreePath = null;
                if ($this->worktreeManager !== null) {
                    $this->logger->debug("Creating worktree for issue #{$issueNumber}");
                    $worktreePath    = $this->worktreeManager->create($issueNumber);
                    $currentWorktree = $worktreePath;
                    $this->logger->debug("Worktree ready at {$worktreePath}");
                }

                $resolvedPrompt     = $this->promptResolver->resolve($labels);
                $runner             = ($this->runnerFactory)(
                    $resolvedPrompt,
                    $issueNumber,
                    $title,
                    $body,
                    $worktreePath,
                );
                $currentIssueNumber = $issueNumber;
                $currentTaskId      = $activeAssignment['id'] ?? null;
                $currentTitle       = $title;
                $currentBody        = $body;
                $runner->start();
                $this->logger->info('Claude command: ' . implode(' ', $runner->getCommand()));
                $status = 'working';

                $lastStatusAt = ($this->clockFn)();

                while ($runner->isRunning()) {
                    ($this->tickSleepFn)();

                    $now = ($this->clockFn)();

                    $detectedQuestions = ($this->questionDetector)($runner->getLines());
                    foreach ($detectedQuestions as $detectedQuestion) {
                        $this->logger->debug(
                            "Detected question {$detectedQuestion['questionId']} for issue #{$currentIssueNumber}",
                        );
                        $this->pupClient->postQuestion(
                            pupId: $pupId,
                            questionId: $detectedQuestion['questionId'],
                            body: $detectedQuestion['body'],
                        );
                        $this->pupClient->reportStatus($pupId, 'blocked', 'Waiting for operator input');
                    }

                    if (($now - $lastStatusAt) >= $this->statusIntervalMs) {
                        $lastStatusAt = $now;
                        $latestLine   = $runner->getLatestLine() ?? '';
                        $this->logger->debug("Claude status tick for issue #{$currentIssueNumber}: {$latestLine}");

                        if ($currentTaskId !== null) {
                            $this->pupClient->postStatus(
                                pupId: $pupId,
                                token: $token,
                                taskId: $currentTaskId,
                                message: $latestLine,
                            );
                        }
                    }
                }
            } else {
                $this->logger->debug('No task assigned');
            }
            unset($activeAssignment);

            $pollCount++;

            if ($pollCount < $this->maxPolls) {
                ($this->pollSleepFn)($currentDelayMs);
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
