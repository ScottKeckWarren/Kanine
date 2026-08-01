<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Logger;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Keeps a bounded in-memory buffer of recent log lines so a TUI can render
 * the tail of the log without writing to stdout/stderr and corrupting the
 * alternate-screen board display.
 */
final class TailBufferHandler extends AbstractProcessingHandler implements LogTailProvider
{
    private const MAX_BUFFER = 250;

    /** @var list<string> */
    private array $buffer = [];

    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    /**
     * @return list<string>
     */
    public function tail(int $lines): array
    {
        if ($lines <= 0) {
            return [];
        }

        return array_slice($this->buffer, -$lines);
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer[] = sprintf(
            '%s %s: %s',
            $record->datetime->format('H:i:s'),
            strtoupper($record->level->name),
            $record->message,
        );

        if (count($this->buffer) > self::MAX_BUFFER) {
            array_shift($this->buffer);
        }
    }
}
