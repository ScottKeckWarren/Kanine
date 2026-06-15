<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\GitHub;

use GuzzleHttp\Client;

final class GitHubClient implements GitHubClientInterface
{
    private const BASE_URL = 'https://api.github.com';

    public function __construct(
        private readonly Client $guzzle,
        private readonly string $token,
    ) {
    }

    /**
     * @return list<array{number: int, title: string, body: string, labels: list<array{name: string}>}>
     */
    public function fetchOpenIssues(string $repository): array
    {
        $url    = self::BASE_URL . "/repos/{$repository}/issues";
        $issues = [];

        while ($url !== null) {
            $response = $this->guzzle->get($url, [
                'http_errors' => false,
                'query'       => ['state' => 'open', 'per_page' => 100],
                'headers'     => [
                    'Authorization'        => "Bearer {$this->token}",
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "GitHub API returned HTTP {$statusCode} for {$repository}"
                );
            }

            /** @var list<array{number: int, title: string, body: string, labels: list<array{name: string}>}> $page */
            $page    = json_decode((string) $response->getBody(), true);
            $issues  = array_merge($issues, $page);

            $url = $this->extractNextUrl($response->getHeaderLine('Link'));
        }

        return $issues;
    }

    private function extractNextUrl(string $linkHeader): ?string
    {
        if ($linkHeader === '') {
            return null;
        }

        foreach (explode(',', $linkHeader) as $part) {
            if (str_contains($part, 'rel="next"')) {
                if (preg_match('/<([^>]+)>/', $part, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }
}
