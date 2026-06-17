<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Supervisor\UsageTracker;

final class UsageTrackerTest extends TestCase
{
    public function testInitialStateNotThrottled(): void
    {
        $tracker = new UsageTracker();

        $this->assertFalse($tracker->isThrottled());
    }

    public function testInitialUsagePctIsZero(): void
    {
        $tracker = new UsageTracker();

        $this->assertSame(0.0, $tracker->usagePct());
    }

    public function testAtThresholdIsThrottled(): void
    {
        $tracker = new UsageTracker();

        $tracker->record(90.0);

        $this->assertTrue($tracker->isThrottled());
    }

    public function testBelowThresholdNotThrottled(): void
    {
        $tracker = new UsageTracker();

        $tracker->record(89.9);

        $this->assertFalse($tracker->isThrottled());
    }

    public function testAboveThresholdIsThrottled(): void
    {
        $tracker = new UsageTracker();

        $tracker->record(95.0);

        $this->assertTrue($tracker->isThrottled());
    }

    public function testClampsBelowZero(): void
    {
        $tracker = new UsageTracker();

        $tracker->record(-5.0);

        $this->assertSame(0.0, $tracker->usagePct());
    }

    public function testClampsAbove100(): void
    {
        $tracker = new UsageTracker();

        $tracker->record(105.0);

        $this->assertSame(100.0, $tracker->usagePct());
    }

    public function testCustomThreshold(): void
    {
        $tracker = new UsageTracker(75.0);

        $tracker->record(75.0);

        $this->assertTrue($tracker->isThrottled());
    }

    public function testResetDetected(): void
    {
        $tracker = new UsageTracker();
        $tracker->record(95.0);

        $tracker->record(50.0);

        $this->assertFalse($tracker->isThrottled());
    }
}
