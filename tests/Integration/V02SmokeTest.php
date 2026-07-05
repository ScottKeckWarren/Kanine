<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Http\Message\ServerRequest;
use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;
use ScottKeckWarren\Kanine\Domain\PupStatus;
use ScottKeckWarren\Kanine\Supervisor\HttpServer;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;
use ScottKeckWarren\Kanine\Supervisor\TaskQueue;
use ScottKeckWarren\Kanine\Supervisor\UsageTracker;

/**
 * V0.2 integration smoke tests.
 *
 * Exercises the full HTTP flow in-process (no real network) using
 * HttpServer::handle() directly. No real Claude process or GitHub API is
 * invoked.
 */
final class V02SmokeTest extends TestCase
{
    private TaskQueue $queue;
    private PupRegistry $registry;
    private HttpServer $server;

    // -------------------------------------------------------------------------
    // Full task lifecycle
    // -------------------------------------------------------------------------

    public function testFullTaskLifecycleWithStatusAndComplete(): void
    {
        // Register a pup
        $registerResponse = $this->server->handle(
            $this->makeRequest('POST', '/pups/register', '{"pup_id":"v02-pup-1","hostname":"smoke-host"}'),
        );

        $this->assertSame(200, $registerResponse->getStatusCode());
        $registerBody = $this->decodeBody($registerResponse);
        $token = $registerBody['token'];
        $this->assertIsString($token);

        // Enqueue a task
        $task = new Task(
            id: 'v02-task-1',
            issueNumber: 100,
            repo: 'owner/smoke-repo',
            title: 'V02 smoke task',
            body: 'Smoke body',
            labels: ['kanine: ready'],
            state: TaskState::Queued,
        );
        $this->queue->enqueue($task);

        // Poll — pup should receive the task
        $pollResponse = $this->server->handle(
            $this->makeRequest('GET', '/pups/v02-pup-1/poll', '', "Bearer {$token}"),
        );

        $this->assertSame(200, $pollResponse->getStatusCode());
        $pollBody = $this->decodeBody($pollResponse);
        $this->assertNotNull($pollBody['new_task']);
        $this->assertSame('v02-task-1', $pollBody['new_task']['id']);
        $this->assertFalse($pollBody['throttled']);

        // After poll, the task is dequeued. Re-enqueue in Assigned state and
        // wire the assignment so the status and complete endpoints can find it.
        $assignedTask = new Task(
            id: 'v02-task-1',
            issueNumber: 100,
            repo: 'owner/smoke-repo',
            title: 'V02 smoke task',
            body: 'Smoke body',
            labels: ['kanine: ready'],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($assignedTask);
        $this->queue->assignTo('v02-task-1', 'v02-pup-1');

        // Post status — should return 204
        $statusResponse = $this->server->handle(
            $this->makeRequest(
                'POST',
                '/tasks/v02-task-1/status',
                '{"pup_id":"v02-pup-1","message":"working on it"}',
                "Bearer {$token}",
            ),
        );

        $this->assertSame(204, $statusResponse->getStatusCode());

        // Post complete — should return 200 with label_actions
        $completeResponse = $this->server->handle(
            $this->makeRequest(
                'POST',
                '/tasks/v02-task-1/complete',
                '{"pup_id":"v02-pup-1","outcome":"success"}',
                "Bearer {$token}",
            ),
        );

        $this->assertSame(200, $completeResponse->getStatusCode());
        $completeBody = $this->decodeBody($completeResponse);
        $this->assertSame(
            [['remove' => 'kanine: ready'], ['add' => 'kanine: done']],
            $completeBody['label_actions'],
        );

        // Task state must be Complete
        $finalTask = $this->queue->find('v02-task-1');
        $this->assertNotNull($finalTask);
        $this->assertSame(TaskState::Complete, $finalTask->state);

        // Pup must be Idle again
        $pup = $this->registry->find('v02-pup-1');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Idle, $pup->status);
    }

    // -------------------------------------------------------------------------
    // Throttled poll
    // -------------------------------------------------------------------------

    public function testThrottledPollReturnsNullTask(): void
    {
        $usageTracker = new UsageTracker(throttleThreshold: 90.0);
        $usageTracker->record(95.0);

        $server = $this->buildServer(usageTracker: $usageTracker);

        $queue    = $this->queue;
        $registry = $this->registry;

        $token = $registry->register(pupId: 'throttle-pup', hostname: 'host');

        $task = new Task(
            id: 'throttle-task',
            issueNumber: 5,
            repo: 'owner/repo',
            title: 'Should not be assigned',
            body: 'body',
            labels: [],
            state: TaskState::Queued,
        );
        $queue->enqueue($task);

        $response = $server->handle(
            $this->makeRequest('GET', '/pups/throttle-pup/poll', '', "Bearer {$token}"),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        $this->assertNull($body['new_task']);
        $this->assertTrue($body['throttled']);
    }

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    public function testStatusEndpointUnknownTaskReturns404(): void
    {
        $token = $this->registry->register(pupId: 'err-pup-1', hostname: 'host');

        $response = $this->server->handle(
            $this->makeRequest(
                'POST',
                '/tasks/nonexistent-task/status',
                '{"pup_id":"err-pup-1","message":"hello"}',
                "Bearer {$token}",
            ),
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCompleteEndpointWrongPupReturns403(): void
    {
        $token1 = $this->registry->register(pupId: 'owner-pup', hostname: 'host');
        $token2 = $this->registry->register(pupId: 'other-pup', hostname: 'host');

        $task = new Task(
            id: 'owned-task',
            issueNumber: 7,
            repo: 'owner/repo',
            title: 'Task owned by owner-pup',
            body: 'body',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('owned-task', 'owner-pup');
        $this->registry->assign(pupId: 'owner-pup', taskId: 'owned-task');

        $response = $this->server->handle(
            $this->makeRequest(
                'POST',
                '/tasks/owned-task/complete',
                '{"pup_id":"other-pup","outcome":"success"}',
                "Bearer {$token2}",
            ),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->queue    = new TaskQueue();
        $this->registry = new PupRegistry();
        $this->server   = $this->buildServer();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildServer(?UsageTracker $usageTracker = null): HttpServer
    {
        return new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: new NullLogger(),
            readyLabel: 'kanine: ready',
            doneLabel: 'kanine: done',
            failedLabel: 'kanine: failed',
            usageTracker: $usageTracker,
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

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(\React\Http\Message\Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
}
