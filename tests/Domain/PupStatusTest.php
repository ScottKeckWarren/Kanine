<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\PupStatus;

final class PupStatusTest extends TestCase
{
    public function testItIsAStringBackedEnum(): void
    {
        $this->assertSame('idle', PupStatus::Idle->value);
        $this->assertSame('working', PupStatus::Working->value);
    }

    public function testItCanBeCreatedFromAStringValue(): void
    {
        $this->assertSame(PupStatus::Idle, PupStatus::from('idle'));
        $this->assertSame(PupStatus::Working, PupStatus::from('working'));
    }

    public function testItHasExactlySixCases(): void
    {
        $this->assertCount(6, PupStatus::cases());
    }

    public function testInactiveCaseHasCorrectValue(): void
    {
        $this->assertSame('inactive', PupStatus::Inactive->value);
    }

    public function testCompletedCaseHasCorrectValue(): void
    {
        $this->assertSame('completed', PupStatus::Completed->value);
    }

    public function testFailedCaseHasCorrectValue(): void
    {
        $this->assertSame('failed', PupStatus::Failed->value);
    }

    public function testFromStringCompletedRoundTrips(): void
    {
        $this->assertSame(PupStatus::Completed, PupStatus::from('completed'));
    }

    public function testFromStringFailedRoundTrips(): void
    {
        $this->assertSame(PupStatus::Failed, PupStatus::from('failed'));
    }
}
