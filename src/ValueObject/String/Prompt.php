<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\ValueObject\String;

final readonly class Prompt
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(
                sprintf('Prompt value must not be blank, got: "%s"', $value)
            );
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
