<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console\Command;

use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Config\ConfigInitializerInterface;
use ScottKeckWarren\Kanine\Config\ConfigLoaderInterface;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\GitHub\IssueLoaderInterface;
use ScottKeckWarren\Kanine\Supervisor\SupervisorInterface;
use ScottKeckWarren\Kanine\Supervisor\UsageTracker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ServeCommand extends Command
{
    /** @var callable(int, callable): void */
    private readonly mixed $signalInstaller;

    /** @var (callable(Configuration, LoggerInterface): IssueLoaderInterface)|null */
    private readonly mixed $issueLoaderFactory;

    /** @var (callable(UsageTracker, string, string, string, string): void)|null */
    private readonly mixed $httpServerFactory;

    /**
     * @param (callable(int, callable): void)|null $signalInstaller
     * @param (callable(Configuration, LoggerInterface): IssueLoaderInterface)|null $issueLoaderFactory
     * @param (callable(UsageTracker, string, string, string, string): void)|null $httpServerFactory
     */
    public function __construct(
        private readonly ConfigInitializerInterface $configInitializer,
        private readonly ConfigLoaderInterface $configLoader,
        private readonly SupervisorInterface $supervisor,
        private readonly LoggerInterface $logger,
        ?callable $signalInstaller = null,
        ?callable $issueLoaderFactory = null,
        ?callable $httpServerFactory = null,
    ) {
        parent::__construct('serve');
        $this->signalInstaller    = $signalInstaller ?? static function (int $signal, callable $handler): void {
            if (function_exists('pcntl_signal')) {
                pcntl_signal($signal, $handler);
            }
        };
        $this->issueLoaderFactory = $issueLoaderFactory;
        $this->httpServerFactory  = $httpServerFactory;
    }

    protected function configure(): void
    {
        $this->setDescription('Start the Kanine supervisor HTTP server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->configInitializer->configExists()) {
            $output->writeln('<info>No config found. Running setup wizard...</info>');
            $this->configInitializer->run($input, $output);
        }

        try {
            $config = $this->configLoader->load();
        } catch (\InvalidArgumentException $e) {
            $this->logger->error($e->getMessage());
            $output->writeln('<error>' . $e->getMessage() . '</error>');

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

        $shutdownHandler = function (): void {
            $this->logger->info('Supervisor shutting down');
            $this->supervisor->stop();
        };
        ($this->signalInstaller)(SIGINT, $shutdownHandler);
        ($this->signalInstaller)(SIGTERM, $shutdownHandler);

        $this->logger->info(sprintf(
            'Starting kanine serve on %s:%d',
            $config->host,
            $config->port,
        ));

        $this->supervisor->boot();

        return Command::SUCCESS;
    }
}
