<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

final class UsageTracker
{
    private float $pct = 0.0;

    public function __construct(private readonly float $throttleThreshold = 90.0)
    {
    }

    public function record(float $pct): void
    {
        $this->pct = max(0.0, min(100.0, $pct));
    }

    public function isThrottled(): bool
    {
        return $this->pct >= $this->throttleThreshold;
    }

    public function usagePct(): float
    {
        return $this->pct;
    }
}
