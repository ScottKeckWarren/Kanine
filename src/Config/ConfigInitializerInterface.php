<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Config;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface ConfigInitializerInterface
{
    public function configExists(): bool;

    /**
     * @param array{token_env: string, repositories: list<string>} $data
     */
    public function write(array $data): void;

    public function run(InputInterface $input, OutputInterface $output): void;
}
