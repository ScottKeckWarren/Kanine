<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console\Command;

use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\Supervisor\SupervisorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ServeCommand extends Command
{
    public function __construct(
        private readonly Configuration $config,
        private readonly SupervisorInterface $supervisor,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct('serve');
    }

    protected function configure(): void
    {
        $this->setDescription('Start the Kanine supervisor HTTP server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info(sprintf(
            'Starting kanine serve on %s:%d',
            $this->config->host,
            $this->config->port,
        ));

        $this->supervisor->boot();

        return Command::SUCCESS;
    }
}
