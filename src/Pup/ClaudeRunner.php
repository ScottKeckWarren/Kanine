<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use ScottKeckWarren\Kanine\ValueObject\String\Prompt;
use Symfony\Component\Process\Process;

final class ClaudeRunner
{
    private readonly Process $process;

    /** @var list<string> */
    private readonly array $command;

    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $errorLines = [];

    private ?string $latestLine = null;

    public function __construct(
        private readonly Prompt $prompt,
        private readonly int $issueNumber,
        private readonly string $title,
        private readonly string $body,
        ?Process $process = null,
        private readonly ?string $workingDirectory = null,
    ) {
        $input = "{$this->prompt}\n\n## Issue #{$this->issueNumber}: {$this->title}\n\n{$this->body}";
        $this->command = ['claude', '--print', $input];
        $this->process = $process ?? new Process($this->command, $this->workingDirectory);
    }

    public function start(): void
    {
        $this->process->start(function (string $type, string $data): void {
            $line = rtrim($data, "\n");

            if ($line === '') {
                return;
            }

            if ($type === Process::ERR) {
                $this->errorLines[] = $line;

                return;
            }

            $this->latestLine = $line;

            $this->lines[] = $line;

            if (count($this->lines) > 10) {
                $this->lines = array_values(array_slice($this->lines, -10));
            }
        });
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function stop(): void
    {
        $this->process->stop();
    }

    public function getExitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    /**
     * @return list<string>
     */
    public function getCommand(): array
    {
        return $this->command;
    }

    public function getLatestLine(): ?string
    {
        return $this->latestLine;
    }

    /**
     * @return list<string>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<string>
     */
    public function getErrorOutput(): array
    {
        return $this->errorLines;
    }

    public function getUsagePct(): ?float
    {
        return null;
    }
}
