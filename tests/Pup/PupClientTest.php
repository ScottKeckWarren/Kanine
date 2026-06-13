<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Pup;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
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

    public function testPollThrowsOn401UnauthorizedResponse(): void
    {
        $mock = new MockHandler([
            new Response(
                status: 401,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR),
            ),
        ]);

        $client = $this->makeClient($mock);

        $this->expectException(ClientException::class);

        $client->poll(pupId: 'pup-1', token: 'bad-token', status: 'idle');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClient(MockHandler $mock): PupClient
    {
        $stack  = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $stack]);

        return new PupClient(baseUrl: 'http://supervisor:3737', guzzle: $guzzle);
    }
}
