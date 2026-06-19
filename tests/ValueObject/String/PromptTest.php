<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\ValueObject\String;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\ValueObject\String\Prompt;

final class PromptTest extends TestCase
{
    public function testEmptyStringThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Prompt('');
    }

    public function testWhitespaceOnlyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Prompt('  ');
    }

    public function testValidStringConstructs(): void
    {
        $prompt = new Prompt('hello');

        $this->assertInstanceOf(Prompt::class, $prompt);
    }

    public function testToStringReturnsValue(): void
    {
        $prompt = new Prompt('hello');

        $this->assertSame('hello', (string) $prompt);
    }

    public function testValuePropertyMatchesInput(): void
    {
        $prompt = new Prompt('hello');

        $this->assertSame('hello', $prompt->value);
    }

    public function testEmptyStringThrowsRegression(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Prompt('');
    }
}
