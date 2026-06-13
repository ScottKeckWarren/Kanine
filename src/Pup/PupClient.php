<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use GuzzleHttp\Client;

final class PupClient implements PupClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly Client $guzzle,
    ) {
    }

    /**
     * Register this pup with the supervisor.
     *
     * @return array{token: string, poll_interval_ms: int}
     */
    public function register(string $pupId, string $hostname): array
    {
        $response = $this->guzzle->post("{$this->baseUrl}/pups/register", [
            'json' => [
                'pup_id'   => $pupId,
                'hostname' => $hostname,
            ],
        ]);

        /** @var array{token: string, poll_interval_ms: int} $data */
        $data = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Poll the supervisor for a new task assignment.
     *
     * @return array{new_task: array<string, mixed>|null}
     */
    public function poll(string $pupId, string $token, string $status): array
    {
        $response = $this->guzzle->post("{$this->baseUrl}/pups/{$pupId}/poll", [
            'headers' => [
                'Authorization' => "Bearer {$token}",
            ],
            'json' => [
                'status' => $status,
            ],
        ]);

        /** @var array{new_task: array<string, mixed>|null} $data */
        $data = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
