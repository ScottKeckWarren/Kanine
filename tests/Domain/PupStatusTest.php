<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\PupStatus;

final class PupStatusTest extends TestCase
{
    public function test_it_is_a_string_backed_enum(): void
    {
        $this->assertSame('Idle', PupStatus::Idle->value);
        $this->assertSame('Working', PupStatus::Working->value);
    }

    public function test_it_can_be_created_from_a_string_value(): void
    {
        $this->assertSame(PupStatus::Idle, PupStatus::from('Idle'));
        $this->assertSame(PupStatus::Working, PupStatus::from('Working'));
    }

    public function test_it_has_exactly_two_cases(): void
    {
        $this->assertCount(2, PupStatus::cases());
    }
}
