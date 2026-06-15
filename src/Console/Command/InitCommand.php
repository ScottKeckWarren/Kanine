<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console\Command;

use ScottKeckWarren\Kanine\Config\ConfigInitializerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InitCommand extends Command
{
    public function __construct(private readonly ConfigInitializerInterface $configInitializer)
    {
        parent::__construct('init');
    }

    protected function configure(): void
    {
        $this->setDescription('Run the interactive Kanine setup wizard');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->configInitializer->run($input, $output);
        } catch (\RuntimeException) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
