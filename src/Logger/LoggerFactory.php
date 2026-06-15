<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Logger;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    private const FORMAT = "[%datetime%] %channel%.%level_name%: %message%\n";
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function create(string $logFile, string $channel = 'kanine'): Logger
    {
        $formatter = new LineFormatter(
            format: self::FORMAT,
            dateFormat: self::DATE_FORMAT,
            allowInlineLineBreaks: false,
            ignoreEmptyContextAndExtra: true,
        );

        $streamHandler = new StreamHandler('php://stderr', Level::Debug);
        $streamHandler->setFormatter($formatter);

        $fileHandler = new RotatingFileHandler($logFile, maxFiles: 7, level: Level::Debug);
        $fileHandler->setFormatter($formatter);

        $logger = new Logger($channel);
        $logger->pushHandler($streamHandler);
        $logger->pushHandler($fileHandler);

        return $logger;
    }
}
