<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\TaskState;

final class TaskStateTest extends TestCase
{
    public function testItIsAStringBackedEnum(): void
    {
        $this->assertSame('Queued', TaskState::Queued->value);
        $this->assertSame('Assigned', TaskState::Assigned->value);
    }

    public function testItCanBeCreatedFromAStringValue(): void
    {
        $this->assertSame(TaskState::Queued, TaskState::from('Queued'));
        $this->assertSame(TaskState::Assigned, TaskState::from('Assigned'));
    }

    public function testItHasExactlyFourCases(): void
    {
        $this->assertCount(4, TaskState::cases());
    }

    public function testCompleteCaseHasCorrectValue(): void
    {
        $this->assertSame('complete', TaskState::Complete->value);
    }

    public function testFailedCaseHasCorrectValue(): void
    {
        $this->assertSame('failed', TaskState::Failed->value);
    }

    public function testFromStringCompleteRoundTrips(): void
    {
        $this->assertSame(TaskState::Complete, TaskState::from('complete'));
    }

    public function testFromStringFailedRoundTrips(): void
    {
        $this->assertSame(TaskState::Failed, TaskState::from('failed'));
    }
}
