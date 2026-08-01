<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console\Command;

use PhpTui\Term\EventProvider;
use PhpTui\Tui\Model\Display\Display;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use ScottKeckWarren\Kanine\Board\BoardLoop;
use ScottKeckWarren\Kanine\Board\BoardRenderer;
use ScottKeckWarren\Kanine\Board\FooterRenderer;
use ScottKeckWarren\Kanine\Board\KeyboardHandler;
use ScottKeckWarren\Kanine\Config\ConfigInitializerInterface;
use ScottKeckWarren\Kanine\Config\ConfigLoaderInterface;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\GitHub\IssueLoaderInterface;
use ScottKeckWarren\Kanine\Logger\LogTailProvider;
use ScottKeckWarren\Kanine\Supervisor\Dispatcher;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;
use ScottKeckWarren\Kanine\Supervisor\SupervisorInterface;
use ScottKeckWarren\Kanine\Supervisor\UsageTracker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ServeCommand extends Command
{
    private const float KEYBOARD_POLL_INTERVAL_SECONDS = 0.1;

    /** @var callable(int, callable): void */
    private readonly mixed $signalInstaller;

    /** @var (callable(Configuration, LoggerInterface): IssueLoaderInterface)|null */
    private readonly mixed $issueLoaderFactory;

    /** @var (callable(UsageTracker, string, string, string, string): void)|null */
    private readonly mixed $httpServerFactory;

    /** @var (callable(): Display)|null */
    private readonly mixed $displayFactory;

    /** @var (callable(): EventProvider)|null */
    private readonly mixed $eventProviderFactory;

    /** @var callable(): void */
    private readonly mixed $terminalSetup;

    /** @var callable(): void */
    private readonly mixed $terminalTeardown;

    /**
     * @param (callable(int, callable): void)|null $signalInstaller
     * @param (callable(Configuration, LoggerInterface): IssueLoaderInterface)|null $issueLoaderFactory
     * @param (callable(UsageTracker, string, string, string, string): void)|null $httpServerFactory
     * @param (callable(): Display)|null $displayFactory
     * @param (callable(): EventProvider)|null $eventProviderFactory
     * @param (callable(): void)|null $terminalSetup
     * @param (callable(): void)|null $terminalTeardown
     */
    public function __construct(
        private readonly ConfigInitializerInterface $configInitializer,
        private readonly ConfigLoaderInterface $configLoader,
        private readonly SupervisorInterface $supervisor,
        private readonly LoggerInterface $logger,
        ?callable $signalInstaller = null,
        ?callable $issueLoaderFactory = null,
        ?callable $httpServerFactory = null,
        private readonly ?BoardRenderer $boardRenderer = null,
        private readonly ?Dispatcher $dispatcher = null,
        private readonly ?IssueStore $issueStore = null,
        private readonly ?PupRegistry $pupRegistry = null,
        private readonly ?FooterRenderer $footerRenderer = null,
        private readonly ?KeyboardHandler $keyboardHandler = null,
        private readonly ?LogTailProvider $logTail = null,
        ?callable $displayFactory = null,
        ?callable $eventProviderFactory = null,
        ?callable $terminalSetup = null,
        ?callable $terminalTeardown = null,
    ) {
        parent::__construct('serve');
        $this->signalInstaller    = $signalInstaller ?? static function (int $signal, callable $handler): void {
            if (function_exists('pcntl_signal')) {
                pcntl_signal($signal, $handler);
            }
        };
        $this->issueLoaderFactory  = $issueLoaderFactory;
        $this->httpServerFactory   = $httpServerFactory;
        $this->displayFactory      = $displayFactory;
        $this->eventProviderFactory = $eventProviderFactory;
        $this->terminalSetup       = $terminalSetup ?? static function (): void {
        };
        $this->terminalTeardown    = $terminalTeardown ?? static function (): void {
        };
    }

    public function getDispatcher(): ?Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * Handle a pup status transition — releases assignment when status is complete or failed.
     */
    public function handleStatusTransition(string $pupId, string $status): void
    {
        if ($status !== 'complete' && $status !== 'failed') {
            return;
        }

        if ($this->issueStore === null) {
            return;
        }

        $issue = $this->issueStore->getByPupId($pupId);

        if ($issue !== null) {
            $this->issueStore->unassign($issue->id, $issue->repo);
        }
    }

    /**
     * Composes a BoardLoop from injected dependencies. Returns null when any
     * required collaborator (board renderer, dispatcher, issue store, pup
     * registry, display factory, or event provider factory) is missing —
     * this keeps `serve` usable in tests and non-TUI contexts.
     */
    public function buildBoardLoop(Configuration $config): ?BoardLoop
    {
        if (
            $this->boardRenderer === null
            || $this->dispatcher === null
            || $this->issueStore === null
            || $this->pupRegistry === null
            || $this->displayFactory === null
            || $this->eventProviderFactory === null
        ) {
            return null;
        }

        return new BoardLoop(
            boardRenderer: $this->boardRenderer,
            footerRenderer: $this->footerRenderer ?? new FooterRenderer(),
            issueStore: $this->issueStore,
            pupRegistry: $this->pupRegistry,
            dispatcher: $this->dispatcher,
            keyboardHandler: $this->keyboardHandler ?? new KeyboardHandler(),
            columns: $config->columns,
            logger: $this->logger,
            display: ($this->displayFactory)(),
            eventProvider: ($this->eventProviderFactory)(),
            pupTimeoutSeconds: $config->pupTimeoutSeconds,
            logTail: $this->logTail,
        );
    }

    protected function configure(): void
    {
        $this->setDescription('Start the Kanine supervisor HTTP server');
        $this->addOption(
            'token',
            null,
            InputOption::VALUE_REQUIRED,
            'GitHub token; overrides .kanine/kanine.local.yaml and the configured token/env var',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->configInitializer->configExists()) {
            $output->writeln('<info>No config found. Running setup wizard...</info>');
            $this->configInitializer->run($input, $output);
        }

        /** @var string|null $tokenOption */
        $tokenOption = $input->getOption('token');

        try {
            $config = $this->configLoader->load(tokenOverride: $tokenOption);
        } catch (\InvalidArgumentException $e) {
            $message = "Configuration error: {$e->getMessage()} "
                . "Fix .kanine/kanine.yaml (or rerun 'kanine init') and run 'kanine serve' again.";
            $this->logger->error($message);
            $output->writeln('<error>' . $message . '</error>');

            return Command::FAILURE;
        }

        if ($this->httpServerFactory !== null) {
            $tracker = new UsageTracker($config->usageThrottlePct);
            ($this->httpServerFactory)(
                $tracker,
                $config->doneLabel,
                $config->failedLabel,
                $config->architectLabel,
                $config->humanFeedbackLabel,
            );
        }

        if ($this->issueLoaderFactory !== null) {
            $this->supervisor->setIssueLoader(($this->issueLoaderFactory)($config, $this->logger));
        }

        $boardLoop = $this->buildBoardLoop($config);

        $shutdownHandler = function () use ($boardLoop): void {
            $this->logger->info('Supervisor shutting down');

            if ($boardLoop !== null) {
                ($this->terminalTeardown)();
            }

            $this->supervisor->stop();
        };
        ($this->signalInstaller)(SIGINT, $shutdownHandler);
        ($this->signalInstaller)(SIGTERM, $shutdownHandler);

        $this->logger->info(sprintf(
            'Starting kanine serve on %s:%d',
            $config->host,
            $config->port,
        ));

        if ($boardLoop !== null) {
            $this->runBoardLoop($boardLoop, $config, $shutdownHandler);
        }

        $this->supervisor->boot();

        return Command::SUCCESS;
    }

    /**
     * Registers ReactPHP periodic timers driving the board render, dispatch,
     * and keyboard-polling ticks. Relies on the global ReactPHP event loop
     * (shared with HttpServer::start()) and never returns while the loop is
     * running, so it cannot be meaningfully unit tested.
     *
     * @codeCoverageIgnore
     */
    private function runBoardLoop(BoardLoop $boardLoop, Configuration $config, callable $onQuit): void
    {
        ($this->terminalSetup)();
        $boardLoop->render();

        $loop = Loop::get();

        $loop->addPeriodicTimer(
            $config->dispatchIntervalSeconds,
            static fn (): int => $boardLoop->dispatch(),
        );

        $loop->addPeriodicTimer(
            $config->refreshIntervalSeconds,
            static function () use ($boardLoop): void {
                if ($boardLoop->state()->autoRefresh) {
                    $boardLoop->render();
                }
            },
        );

        $loop->addPeriodicTimer(
            self::KEYBOARD_POLL_INTERVAL_SECONDS,
            static function () use ($boardLoop, $onQuit): void {
                if ($boardLoop->processKeyboard()) {
                    $onQuit();

                    return;
                }

                $boardLoop->render();
            },
        );
    }
}
