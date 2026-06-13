<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct('kanine');
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}
