<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Console;

use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\Console\Command\PupCommand;
use ScottKeckWarren\Kanine\Pup\GitHubLabelWriter;
use ScottKeckWarren\Kanine\Pup\PromptResolver;
use ScottKeckWarren\Kanine\Pup\PupClientInterface;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct(
        private readonly LoggerInterface $logger,
        ?Configuration $config = null,
        ?PupClientInterface $pupClient = null,
        ?string $basePath = null,
    ) {
        parent::__construct('kanine');

        if ($config !== null && $pupClient !== null) {
            $resolvedBasePath = $basePath ?? (string) getcwd();
            $promptResolver   = new PromptResolver(basePath: $resolvedBasePath);
            $labelWriter      = new GitHubLabelWriter(token: $config->githubToken, logger: $logger);

            $this->add(new PupCommand(
                pupClient: $pupClient,
                logger: $logger,
                promptResolver: $promptResolver,
                statusIntervalMs: $config->statusIntervalMs,
                maxThrottlePollMs: $config->maxThrottlePollMs,
                labelWriter: $labelWriter,
            ));
        }
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}
