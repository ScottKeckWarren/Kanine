<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Logger;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    private const FORMAT = "[%datetime%] %channel%.%level_name%: %message%\n";
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param bool $logToStderr set false to suppress the stderr handler — required when a
     *                          full-screen TUI owns the terminal, since interleaved writes
     *                          to stderr corrupt the alternate-screen display
     * @param string $rotationDateFormat date segment appended to the rotated log filename;
     *                                   must match RotatingFileHandler's accepted formats
     *                                   (e.g. 'Y-m-d' or 'Ymd')
     */
    public function create(
        string $logFile,
        string $channel = 'kanine',
        bool $logToStderr = true,
        ?HandlerInterface $extraHandler = null,
        string $rotationDateFormat = RotatingFileHandler::FILE_PER_DAY,
    ): Logger {
        $formatter = new LineFormatter(
            format: self::FORMAT,
            dateFormat: self::DATE_FORMAT,
            allowInlineLineBreaks: false,
            ignoreEmptyContextAndExtra: true,
        );

        $logger = new Logger($channel);

        if ($logToStderr) {
            $streamHandler = new StreamHandler('php://stderr', Level::Debug);
            $streamHandler->setFormatter($formatter);
            $logger->pushHandler($streamHandler);
        }

        $fileHandler = new RotatingFileHandler(
            $logFile,
            maxFiles: 7,
            level: Level::Debug,
            dateFormat: $rotationDateFormat,
        );
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);

        if ($extraHandler !== null) {
            $logger->pushHandler($extraHandler);
        }

        return $logger;
    }
}
