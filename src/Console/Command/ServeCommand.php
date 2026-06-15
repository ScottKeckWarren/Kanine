<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console\Command;

use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Config\ConfigInitializerInterface;
use ScottKeckWarren\Kanine\Config\ConfigLoaderInterface;
use ScottKeckWarren\Kanine\Supervisor\SupervisorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ServeCommand extends Command
{
    /** @var callable(int, callable): void */
    private readonly mixed $signalInstaller;

    /**
     * @param (callable(int, callable): void)|null $signalInstaller
     */
    public function __construct(
        private readonly ConfigInitializerInterface $configInitializer,
        private readonly ConfigLoaderInterface $configLoader,
        private readonly SupervisorInterface $supervisor,
        private readonly LoggerInterface $logger,
        ?callable $signalInstaller = null,
    ) {
        parent::__construct('serve');
        $this->signalInstaller = $signalInstaller ?? static function (int $signal, callable $handler): void {
            if (function_exists('pcntl_signal')) {
                pcntl_signal($signal, $handler);
            }
        };
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

        $config = $this->configLoader->load();

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
