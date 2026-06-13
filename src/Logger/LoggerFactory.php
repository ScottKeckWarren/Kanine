<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Logger;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    public function create(string $logFile, string $channel = 'kanine'): Logger
    {
        $logger = new Logger($channel);

        $logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));
        $logger->pushHandler(new RotatingFileHandler($logFile, maxFiles: 7, level: Level::Debug));

        return $logger;
    }
}
