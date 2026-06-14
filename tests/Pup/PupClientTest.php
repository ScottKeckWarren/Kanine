<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Pup;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Pup\PupClient;

final class PupClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // register()
    // -------------------------------------------------------------------------

    public function testRegisterReturnsTokenAndPollInterval(): void
    {
        $mock = new MockHandler([
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode([
                    'token'            => 'abc123',
                    'poll_interval_ms' => 5000,
                ], JSON_THROW_ON_ERROR),
            ),
        ]);

        $client = $this->makeClient($mock);

        $result = $client->register(pupId: 'pup-1', hostname: 'host-1');

        $this->assertSame('abc123', $result['token']);
        $this->assertSame(5000, $result['poll_interval_ms']);
    }

    public function testRegisterSendsPupIdAndHostnameInBody(): void
    {
        $capturedRequests = [];

        $mock = new MockHandler([
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode([
                    'token'            => 'tok-xyz',
                    'poll_interval_ms' => 3000,
                ], JSON_THROW_ON_ERROR),
            ),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) use (&$capturedRequests): callable {
            return function (Request $request, array $options) use ($handler, &$capturedRequests) {
                $capturedRequests[] = $request;
                return $handler($request, $options);
            };
        });

        $guzzle = new Client(['handler' => $stack]);
        $client = new PupClient(baseUrl: 'http://supervisor:3737', guzzle: $guzzle);

        $client->register(pupId: 'pup-abc', hostname: 'my-host');

        $this->assertCount(1, $capturedRequests);
        $body = json_decode((string) $capturedRequests[0]->getBody(), associative: true);
        $this->assertSame('pup-abc', $body['pup_id']);
        $this->assertSame('my-host', $body['hostname']);
    }

    // -------------------------------------------------------------------------
    // poll()
    // -------------------------------------------------------------------------

    public function testPollWithIdleStatusReturnsNewTaskWhenOneIsAvailable(): void
    {
        $taskPayload = [
            'id'           => 'task-1',
            'issue_number' => 42,
            'repo'         => 'org/repo',
            'title'        => 'Fix bug',
            'body'         => 'details',
            'state'        => 'queued',
        ];

        $mock = new MockHandler([
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => $taskPayload], JSON_THROW_ON_ERROR),
            ),
        ]);

        $client = $this->makeClient($mock);

        $result = $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');

        $this->assertNotNull($result['new_task']);
        $this->assertSame('task-1', $result['new_task']['id']);
        $this->assertSame(42, $result['new_task']['issue_number']);
    }

    public function testPollReturnsNullNewTaskWhenQueueIsEmpty(): void
    {
        $mock = new MockHandler([
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $client = $this->makeClient($mock);

        $result = $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');

        $this->assertNull($result['new_task']);
    }

    public function testPollSendsBearerTokenInAuthorizationHeader(): void
    {
        $capturedRequests = [];

        $mock = new MockHandler([
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) use (&$capturedRequests): callable {
            return function (Request $request, array $options) use ($handler, &$capturedRequests) {
                $capturedRequests[] = $request;
                return $handler($request, $options);
            };
        });

        $guzzle = new Client(['handler' => $stack]);
        $client = new PupClient(baseUrl: 'http://supervisor:3737', guzzle: $guzzle);

        $client->poll(pupId: 'pup-1', token: 'secret-token', status: 'idle');

        $this->assertCount(1, $capturedRequests);
        $this->assertSame('Bearer secret-token', $capturedRequests[0]->getHeaderLine('Authorization'));
    }

    public function testPollSendsPupIdInUrlAndStatusInBody(): void
    {
        $capturedRequests = [];

        $mock = new MockHandler([
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) use (&$capturedRequests): callable {
            return function (Request $request, array $options) use ($handler, &$capturedRequests) {
                $capturedRequests[] = $request;
                return $handler($request, $options);
            };
        });

        $guzzle = new Client(['handler' => $stack]);
        $client = new PupClient(baseUrl: 'http://supervisor:3737', guzzle: $guzzle);

        $client->poll(pupId: 'pup-xyz', token: 'tok', status: 'working');

        $this->assertCount(1, $capturedRequests);
        $this->assertStringContainsString('/pups/pup-xyz/poll', $capturedRequests[0]->getUri()->getPath());

        $body = json_decode((string) $capturedRequests[0]->getBody(), associative: true);
        $this->assertSame('working', $body['status']);
    }

    public function testPollDoesNotThrowOn401ButReRegistersInstead(): void
    {
        // When poll receives a 401, it re-registers (needing a prior register call)
        // and retries rather than propagating the exception.
        $mock = new MockHandler([
            // Initial register
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['token' => 'initial', 'poll_interval_ms' => 5000], JSON_THROW_ON_ERROR),
            ),
            // Poll returns 401
            new Response(
                status: 401,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR),
            ),
            // Re-register
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['token' => 'fresh', 'poll_interval_ms' => 5000], JSON_THROW_ON_ERROR),
            ),
            // Retry poll succeeds
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $client = $this->makeClient($mock);
        $client->register(pupId: 'pup-1', hostname: 'host-1');

        // Should not throw; should return the result from the retry
        $result = $client->poll(pupId: 'pup-1', token: 'initial', status: 'idle');

        $this->assertNull($result['new_task']);
    }

    // -------------------------------------------------------------------------
    // Backoff on connection failure
    // -------------------------------------------------------------------------

    public function testPollRetriesOnConnectException(): void
    {
        $request = new Request('POST', 'http://supervisor:3737/pups/pup-1/poll');

        $mock = new MockHandler([
            new ConnectException('Connection refused', $request),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $sleepDelays = [];
        $sleeper     = function (int $seconds) use (&$sleepDelays): void {
            $sleepDelays[] = $seconds;
        };

        $client = $this->makeClient($mock, sleeper: $sleeper);

        $result = $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');

        $this->assertNull($result['new_task']);
        $this->assertCount(1, $sleepDelays);
        $this->assertSame(1, $sleepDelays[0]);
    }

    public function testPollBackoffDoublesDelayOnSuccessiveFailures(): void
    {
        $request = new Request('POST', 'http://supervisor:3737/pups/pup-1/poll');

        $mock = new MockHandler([
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $sleepDelays = [];
        $sleeper     = function (int $seconds) use (&$sleepDelays): void {
            $sleepDelays[] = $seconds;
        };

        $client = $this->makeClient($mock, sleeper: $sleeper);

        $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');

        $this->assertSame([1, 2, 4], $sleepDelays);
    }

    public function testPollBackoffCapsDelayAt30Seconds(): void
    {
        $request = new Request('POST', 'http://supervisor:3737/pups/pup-1/poll');

        // Enough failures to push delay beyond 30s: 1, 2, 4, 8, 16, 32 → capped at 30
        $exceptions = [];
        for ($i = 0; $i < 6; $i++) {
            $exceptions[] = new ConnectException('Connection refused', $request);
        }
        $exceptions[] = new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
        );

        $mock = new MockHandler($exceptions);

        $sleepDelays = [];
        $sleeper     = function (int $seconds) use (&$sleepDelays): void {
            $sleepDelays[] = $seconds;
        };

        $client = $this->makeClient($mock, sleeper: $sleeper);

        $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');

        $this->assertSame(30, end($sleepDelays));
    }

    public function testPollLogsWarningOnConnectException(): void
    {
        $request = new Request('POST', 'http://supervisor:3737/pups/pup-1/poll');

        $mock = new MockHandler([
            new ConnectException('Connection refused', $request),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $warningMessages = [];
        $logger          = $this->createMock(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function (string $message) use (&$warningMessages): void {
                $warningMessages[] = $message;
            });

        $sleeper = function (int $seconds): void {
        };

        $client = $this->makeClientWithLogger($mock, logger: $logger, sleeper: $sleeper);

        $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');

        $matched = array_filter(
            $warningMessages,
            fn (string $m): bool => str_contains($m, 'Supervisor unreachable') && str_contains($m, 'retrying in'),
        );
        $this->assertNotEmpty($matched, 'Expected warning log containing "Supervisor unreachable" and "retrying in"');
    }

    public function testPollBackoffResetsToOneAfterSuccessfulPoll(): void
    {
        $request = new Request('POST', 'http://supervisor:3737/pups/pup-1/poll');

        $mock = new MockHandler([
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
            new ConnectException('Connection refused', $request),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $sleepDelays = [];
        $sleeper     = function (int $seconds) use (&$sleepDelays): void {
            $sleepDelays[] = $seconds;
        };

        $client = $this->makeClient($mock, sleeper: $sleeper);

        $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');
        $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');

        // First poll: retried after 1s, 2s; second poll: retried after 1s (reset)
        $this->assertSame([1, 2, 1], $sleepDelays);
    }

    // -------------------------------------------------------------------------
    // 401 re-registration
    // -------------------------------------------------------------------------

    public function testPollReRegistersAndRetryOnUnauthorized(): void
    {
        $mock = new MockHandler([
            // Initial register
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['token' => 'initial-token', 'poll_interval_ms' => 5000], JSON_THROW_ON_ERROR),
            ),
            // First poll returns 401
            new Response(
                status: 401,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR),
            ),
            // Re-register call
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['token' => 'fresh-token', 'poll_interval_ms' => 5000], JSON_THROW_ON_ERROR),
            ),
            // Retry poll succeeds
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $client = $this->makeClient($mock);

        $client->register(pupId: 'pup-1', hostname: 'host-1');
        $result = $client->poll(pupId: 'pup-1', token: 'initial-token', status: 'idle');

        $this->assertNull($result['new_task']);
    }

    public function testPollLogsWarningOnUnauthorizedBeforeReRegistering(): void
    {
        $mock = new MockHandler([
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['token' => 'tok', 'poll_interval_ms' => 5000], JSON_THROW_ON_ERROR),
            ),
            new Response(
                status: 401,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR),
            ),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['token' => 'new-tok', 'poll_interval_ms' => 5000], JSON_THROW_ON_ERROR),
            ),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $warningMessages = [];
        $logger          = $this->createMock(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function (string $message) use (&$warningMessages): void {
                $warningMessages[] = $message;
            });

        $client = $this->makeClientWithLogger($mock, logger: $logger);

        $client->register(pupId: 'pup-1', hostname: 'host-1');
        $client->poll(pupId: 'pup-1', token: 'tok', status: 'idle');

        $matched = array_filter(
            $warningMessages,
            fn (string $m): bool => str_contains($m, 'Token rejected') && str_contains($m, 're-registering'),
        );
        $this->assertNotEmpty($matched, 'Expected warning log containing "Token rejected" and "re-registering"');
    }

    public function testPollReturnsUpdatedTokenAfterReRegistration(): void
    {
        $capturedRequests = [];

        $mock = new MockHandler([
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['token' => 'old-token', 'poll_interval_ms' => 5000], JSON_THROW_ON_ERROR),
            ),
            new Response(
                status: 401,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR),
            ),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['token' => 'fresh-token', 'poll_interval_ms' => 5000], JSON_THROW_ON_ERROR),
            ),
            new Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['new_task' => null], JSON_THROW_ON_ERROR),
            ),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) use (&$capturedRequests): callable {
            return function (Request $request, array $options) use ($handler, &$capturedRequests) {
                $capturedRequests[] = $request;
                return $handler($request, $options);
            };
        });

        $guzzle = new Client(['handler' => $stack]);
        $client = new PupClient(baseUrl: 'http://supervisor:3737', guzzle: $guzzle);

        $client->register(pupId: 'pup-1', hostname: 'host-1');
        $client->poll(pupId: 'pup-1', token: 'old-token', status: 'idle');

        // 4th request is the retry poll; it should use the fresh token
        $this->assertCount(4, $capturedRequests);
        $this->assertSame('Bearer fresh-token', $capturedRequests[3]->getHeaderLine('Authorization'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param callable(int): void|null $sleeper
     */
    private function makeClient(MockHandler $mock, ?callable $sleeper = null): PupClient
    {
        $stack  = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $stack]);

        return new PupClient(
            baseUrl: 'http://supervisor:3737',
            guzzle: $guzzle,
            sleeper: $sleeper,
        );
    }

    /**
     * @param callable(int): void|null $sleeper
     */
    private function makeClientWithLogger(
        MockHandler $mock,
        LoggerInterface $logger,
        ?callable $sleeper = null,
    ): PupClient {
        $stack  = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $stack]);

        return new PupClient(
            baseUrl: 'http://supervisor:3737',
            guzzle: $guzzle,
            logger: $logger,
            sleeper: $sleeper,
        );
    }
}
