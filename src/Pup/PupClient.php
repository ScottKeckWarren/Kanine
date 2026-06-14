<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PupClient implements PupClientInterface
{
    private const MAX_BACKOFF_SECONDS = 30;

    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    private int $backoffSeconds = 1;

    private ?string $lastPupId    = null;
    private ?string $lastHostname = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly Client $guzzle,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper !== null
            ? Closure::fromCallable($sleeper)
            : static function (int $seconds): void {
                sleep($seconds);
            };
    }

    /**
     * Register this pup with the supervisor.
     *
     * @return array{token: string, poll_interval_ms: int}
     */
    public function register(string $pupId, string $hostname): array
    {
        $this->lastPupId    = $pupId;
        $this->lastHostname = $hostname;

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
     * Retries with exponential backoff on connection failures.
     * Re-registers and retries once on 401 Unauthorized.
     *
     * @return array{new_task: array<string, mixed>|null}
     */
    public function poll(string $pupId, string $token, string $status): array
    {
        while (true) {
            try {
                $result              = $this->doPoll($pupId, $token, $status);
                $this->backoffSeconds = 1;
                return $result;
            } catch (ConnectException) {
                $delay = min($this->backoffSeconds, self::MAX_BACKOFF_SECONDS);
                $this->logger->warning("Supervisor unreachable, retrying in {$delay}s");
                ($this->sleeper)($delay);
                $this->backoffSeconds = min($this->backoffSeconds * 2, self::MAX_BACKOFF_SECONDS);
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() !== 401) {
                    throw $e;
                }

                $this->logger->warning('Token rejected, re-registering');
                $registration = $this->register(
                    pupId: $this->lastPupId ?? $pupId,
                    hostname: $this->lastHostname ?? 'unknown',
                );
                $token = $registration['token'];
            }
        }
    }

    /**
     * @return array{new_task: array<string, mixed>|null}
     */
    private function doPoll(string $pupId, string $token, string $status): array
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
