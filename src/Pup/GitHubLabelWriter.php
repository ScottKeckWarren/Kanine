<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use Github\Api\Issue\Labels;
use Github\Client;
use Psr\Log\LoggerInterface;

final class GitHubLabelWriter
{
    private readonly Labels $labelsApi;

    public function __construct(
        private readonly string $token,
        private readonly LoggerInterface $logger,
        ?Labels $labelsApi = null,
    ) {
        if ($labelsApi !== null) {
            $this->labelsApi = $labelsApi;
        } else {
            $client = new Client();
            $client->authenticate($this->token, null, Client::AUTH_ACCESS_TOKEN);
            /** @var \Github\Api\Issue $issueApi */
            $issueApi = $client->issues();
            $this->labelsApi = $issueApi->labels();
        }
    }

    /**
     * @param array<int, array{add?: string, remove?: string}> $labelActions
     */
    public function applyActions(string $repo, int $issueNumber, array $labelActions): void
    {
        [$owner, $repoName] = explode('/', $repo, 2);

        foreach ($labelActions as $action) {
            try {
                if (isset($action['add'])) {
                    $this->labelsApi->add($owner, $repoName, $issueNumber, [$action['add']]);
                } elseif (isset($action['remove'])) {
                    $this->labelsApi->remove($owner, $repoName, $issueNumber, $action['remove']);
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    "GitHubLabelWriter: failed to apply label action on {$repo}#{$issueNumber}: {$e->getMessage()}",
                );
            }
        }
    }
}
