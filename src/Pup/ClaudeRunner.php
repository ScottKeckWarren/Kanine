<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use Symfony\Component\Process\Process;

final class ClaudeRunner
{
    private readonly Process $process;

    /** @var list<string> */
    private readonly array $command;

    public function __construct(
        string $title,
        string $body,
        ?Process $process = null,
    ) {
        $this->command = ['claude', '--headless', '--print', "Working on: {$title}\n\n{$body}"];
        $this->process = $process ?? new Process($this->command);
    }

    public function start(): void
    {
        $this->process->start();
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
}
