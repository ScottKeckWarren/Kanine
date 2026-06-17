<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use React\Http\Message\ServerRequest;
use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;
use ScottKeckWarren\Kanine\Supervisor\HttpServer;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;
use ScottKeckWarren\Kanine\Supervisor\TaskQueue;

final class HttpServerTest extends TestCase
{
    private LoggerInterface $logger;
    private TaskQueue $queue;
    private PupRegistry $registry;
    private HttpServer $server;

    public function testHttpServerIsInstantiable(): void
    {
        $this->assertInstanceOf(HttpServer::class, $this->server);
    }

    public function testHttpServerExposesBoundAddress(): void
    {
        $this->assertSame('127.0.0.1:3737', $this->server->boundAddress());
    }

    public function testHandleRegisterReturnsTokenAndPollInterval(): void
    {
        $request  = $this->makeRequest('POST', '/pups/register', '{"pup_id":"pup-1","hostname":"host-1"}');
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('token', $body);
        $this->assertSame(5000, $body['poll_interval_ms']);
    }

    public function testHandleRegisterMissingPupIdReturns422(): void
    {
        $request  = $this->makeRequest('POST', '/pups/register', '{"hostname":"host-1"}');
        $response = $this->server->handle($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testHandleRegisterMissingHostnameReturns422(): void
    {
        $request  = $this->makeRequest('POST', '/pups/register', '{"pup_id":"pup-1"}');
        $response = $this->server->handle($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testHandlePollUnknownPupReturns404(): void
    {
        $request  = $this->makeRequest('POST', '/pups/unknown-pup/poll', '', 'Bearer sometoken');
        $response = $this->server->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testHandlePollInvalidTokenReturns401(): void
    {
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest('POST', '/pups/pup-1/poll', '', 'Bearer wrongtoken');
        $response = $this->server->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHandlePollMissingAuthReturns401(): void
    {
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest('POST', '/pups/pup-1/poll');
        $response = $this->server->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHandlePollEmptyQueueReturnsNullTask(): void
    {
        $token   = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $request = $this->makeRequest('POST', '/pups/pup-1/poll', '', "Bearer {$token}");

        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertNull($body['new_task']);
    }

    public function testHandlePollWithTaskInQueueAssignsTask(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $task  = new Task(
            id: 'task-1',
            issueNumber: 42,
            repo: 'org/repo',
            title: 'Fix bug',
            body: 'details',
            labels: [],
            state: TaskState::Queued,
        );
        $this->queue->enqueue($task);

        $request  = $this->makeRequest('POST', '/pups/pup-1/poll', '', "Bearer {$token}");
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertNotNull($body['new_task']);
        $this->assertSame('task-1', $body['new_task']['id']);
    }

    public function testPollResponseIncludesLabels(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $task  = new Task(
            id: 'task-1',
            issueNumber: 42,
            repo: 'org/repo',
            title: 'Fix bug',
            body: 'details',
            labels: ['bug', 'kanine: ready'],
            state: TaskState::Queued,
        );
        $this->queue->enqueue($task);

        $request  = $this->makeRequest('POST', '/pups/pup-1/poll', '', "Bearer {$token}");
        $response = $this->server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body['new_task']['labels']);
        $this->assertSame(['bug', 'kanine: ready'], $body['new_task']['labels']);
    }

    public function testPollResponseDoesNotIncludePrompt(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $task  = new Task(
            id: 'task-1',
            issueNumber: 42,
            repo: 'org/repo',
            title: 'Fix bug',
            body: 'details',
            labels: [],
            state: TaskState::Queued,
        );
        $this->queue->enqueue($task);

        $request  = $this->makeRequest('POST', '/pups/pup-1/poll', '', "Bearer {$token}");
        $response = $this->server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('prompt', $body['new_task']);
    }

    public function testHandleUnknownRouteReturns404(): void
    {
        $request  = $this->makeRequest('GET', '/unknown');
        $response = $this->server->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Error handling: malformed JSON
    // -------------------------------------------------------------------------

    public function testHandleRegisterMalformedJsonReturns400(): void
    {
        $request  = $this->makeRequest('POST', '/pups/register', 'not-valid-json{');
        $response = $this->server->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandleRegisterMalformedJsonBodyContainsInvalidJsonCode(): void
    {
        $request  = $this->makeRequest('POST', '/pups/register', 'not-valid-json{');
        $response = $this->server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('INVALID_JSON', $body['code']);
    }

    public function testHandleRegisterMalformedJsonBodyContainsErrorMessage(): void
    {
        $request  = $this->makeRequest('POST', '/pups/register', '{bad json');
        $response = $this->server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertIsString($body['error']);
        $this->assertNotEmpty($body['error']);
    }

    public function testHandlePollMalformedJsonReturns400(): void
    {
        $token   = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $request = $this->makeRequest('POST', '/pups/pup-1/poll', '{broken', "Bearer {$token}");

        $response = $this->server->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandlePollMalformedJsonBodyContainsInvalidJsonCode(): void
    {
        $token   = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $request = $this->makeRequest('POST', '/pups/pup-1/poll', '{broken', "Bearer {$token}");

        $response = $this->server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('INVALID_JSON', $body['code']);
    }

    protected function setUp(): void
    {
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->queue    = new TaskQueue();
        $this->registry = new PupRegistry();
        $this->server   = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: $this->logger,
        );
    }

    private function makeRequest(
        string $method,
        string $path,
        string $body = '',
        string $authorization = '',
    ): ServerRequest {
        $headers = ['Content-Type' => 'application/json'];

        if ($authorization !== '') {
            $headers['Authorization'] = $authorization;
        }

        return new ServerRequest($method, "http://127.0.0.1:3737{$path}", $headers, $body);
    }
}
