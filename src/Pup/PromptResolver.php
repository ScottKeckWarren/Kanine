<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ScottKeckWarren\Kanine\ValueObject\String\Prompt;

final class PromptResolver
{
    public const DEFAULT_PROMPT = 'You are a software developer. Work the following GitHub issue to completion.';

    public function __construct(
        private readonly string $basePath,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<string> $labels
     */
    public function resolve(array $labels): Prompt
    {
        $resolvedBase = realpath($this->basePath);

        foreach ($labels as $label) {
            if (str_contains($label, '/')) {
                continue;
            }

            if (str_contains($label, "\0")) {
                continue;
            }

            $candidatePath = $this->basePath . '/.kanine/labels/' . $label . '/prompt.md';

            $resolvedCandidate = realpath($candidatePath);

            if ($resolvedCandidate === false) {
                continue;
            }

            if ($resolvedBase === false || !str_starts_with($resolvedCandidate, $resolvedBase . '/')) {
                $this->logger->warning('Skipping path traversal attempt for label: ' . $label);
                continue;
            }

            $content = file_get_contents($resolvedCandidate);

            if ($content === false) {
                continue;
            }

            $this->logger->info('Resolved prompt from file: ' . $resolvedCandidate);

            return new Prompt($content);
        }

        $this->logger->info('No label matched; using default prompt.');

        return new Prompt(self::DEFAULT_PROMPT);
    }
}
