<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\PupStatus;

final class PupStatusTest extends TestCase
{
    public function testItIsAStringBackedEnum(): void
    {
        $this->assertSame('Idle', PupStatus::Idle->value);
        $this->assertSame('Working', PupStatus::Working->value);
    }

    public function testItCanBeCreatedFromAStringValue(): void
    {
        $this->assertSame(PupStatus::Idle, PupStatus::from('Idle'));
        $this->assertSame(PupStatus::Working, PupStatus::from('Working'));
    }

    public function testItHasExactlyTwoCases(): void
    {
        $this->assertCount(2, PupStatus::cases());
    }
}
