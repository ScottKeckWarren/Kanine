<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use ScottKeckWarren\Kanine\ValueObject\String\Prompt;

interface PromptResolverInterface
{
    /**
     * @param list<string> $labels
     */
    public function resolve(array $labels): Prompt;
}
