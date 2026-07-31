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
use ScottKeckWarren\Kanine\Supervisor\UsageTracker;

final class HttpServerTest extends TestCase
{
    private LoggerInterface $logger;
    private TaskQueue $queue;
    private PupRegistry $registry;
    private UsageTracker $usageTracker;
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

    public function testRegisterResponseIncludesStatusIntervalMs(): void
    {
        $request  = $this->makeRequest('POST', '/pups/register', '{"pup_id":"pup-1","hostname":"host-1"}');
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('status_interval_ms', $body);
        $this->assertSame(10000, $body['status_interval_ms']);
    }

    public function testRegisterResponseIncludesMaxThrottlePollMs(): void
    {
        $request  = $this->makeRequest('POST', '/pups/register', '{"pup_id":"pup-1","hostname":"host-1"}');
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('max_throttle_poll_ms', $body);
        $this->assertSame(60000, $body['max_throttle_poll_ms']);
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
        $request  = $this->makeRequest('GET', '/pups/unknown-pup/poll', '', 'Bearer sometoken');
        $response = $this->server->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testHandlePollInvalidTokenReturns401(): void
    {
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll', '', 'Bearer wrongtoken');
        $response = $this->server->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHandlePollMissingAuthReturns401(): void
    {
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll');
        $response = $this->server->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHandlePollEmptyQueueReturnsNullTask(): void
    {
        $token   = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $request = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");

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

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertNotNull($body['new_task']);
        $this->assertSame('task-1', $body['new_task']['id']);
    }

    public function testCompleteSucceedsForTaskAssignedThroughRealPollFlow(): void
    {
        // Reproduces the production bug: dequeue() removes the task from the
        // queue's internal map entirely, so a subsequent /complete lookup via
        // find() 404s even though the task was legitimately assigned via /poll.
        // Other Complete* tests bypass this by seeding the queue directly with
        // enqueue()+assignTo(), which never exercises the real dequeue() path.
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $task  = new Task(
            id: 'ScottKeckWarren/Kanine#129',
            issueNumber: 129,
            repo: 'ScottKeckWarren/Kanine',
            title: 'Fix bug',
            body: 'details',
            labels: [],
            state: TaskState::Queued,
        );
        $this->queue->enqueue($task);

        $pollRequest  = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $pollResponse = $this->server->handle($pollRequest);
        $pollBody     = json_decode((string) $pollResponse->getBody(), true);
        $this->assertSame('ScottKeckWarren/Kanine#129', $pollBody['new_task']['id']);

        $completeRequest = $this->makeRequest(
            'POST',
            '/tasks/' . rawurlencode('ScottKeckWarren/Kanine#129') . '/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $completeResponse = $this->server->handle($completeRequest);

        $this->assertSame(200, $completeResponse->getStatusCode());
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

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
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

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
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
        $request = $this->makeRequest('GET', '/pups/pup-1/poll', '{broken', "Bearer {$token}");

        $response = $this->server->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandlePollMalformedJsonBodyContainsInvalidJsonCode(): void
    {
        $token   = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $request = $this->makeRequest('GET', '/pups/pup-1/poll', '{broken', "Bearer {$token}");

        $response = $this->server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('INVALID_JSON', $body['code']);
    }

    // -------------------------------------------------------------------------
    // POST /tasks/{id}/status
    // -------------------------------------------------------------------------

    public function testStatusEndpointLogsMessage(): void
    {
        $messages = [];
        $logger   = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $registry = new PupRegistry();
        $token    = $registry->register(pupId: 'pup-1', hostname: 'host-1');
        $registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $server = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: new TaskQueue(),
            pupRegistry: $registry,
            logger: $logger,
        );

        $request = $this->makeRequest(
            'POST',
            '/tasks/task-1/status',
            '{"pup_id":"pup-1","message":"working on it"}',
            "Bearer {$token}",
        );
        $server->handle($request);

        $this->assertContains('Status from pup pup-1 on task task-1: working on it', $messages);
    }

    public function testStatusEndpointDefaultsMessageToEmptyStringWhenMissing(): void
    {
        $messages = [];
        $logger   = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $registry = new PupRegistry();
        $token    = $registry->register(pupId: 'pup-1', hostname: 'host-1');
        $registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $server = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: new TaskQueue(),
            pupRegistry: $registry,
            logger: $logger,
        );

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/status',
            '{"pup_id":"pup-1"}',
            "Bearer {$token}",
        );
        $response = $server->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertContains('Status from pup pup-1 on task task-1: ', $messages);
    }

    public function testStatusEndpointReturns422ForMissingPupId(): void
    {
        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/status',
            '{"message":"working on it"}',
        );
        $response = $this->server->handle($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testStatusEndpointReturns400ForInvalidJson(): void
    {
        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/status',
            '{not valid json',
        );
        $response = $this->server->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStatusEndpointReturns401ForMissingToken(): void
    {
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/status',
            '{"pup_id":"pup-1","message":"working on it"}',
        );
        $response = $this->server->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testStatusEndpointReturns403ForWrongPup(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->registry->register(pupId: 'pup-2', hostname: 'host-2');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/status',
            '{"pup_id":"pup-2","message":"working on it"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStatusEndpointReturns404ForUnknownTask(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/nonexistent-task/status',
            '{"pup_id":"pup-1","message":"working on it"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStatusEndpointReturns204ForValidRequest(): void
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
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/status',
            '{"pup_id":"pup-1","message":"working on it"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testStatusEndpointDecodesUrlEncodedTaskId(): void
    {
        $taskId = 'ScottKeckWarren/Kanine#129';
        $token  = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $task   = new Task(
            id: $taskId,
            issueNumber: 129,
            repo: 'ScottKeckWarren/Kanine',
            title: 'Fix bug',
            body: 'details',
            labels: [],
            state: TaskState::Queued,
        );
        $this->queue->enqueue($task);
        $this->registry->assign(pupId: 'pup-1', taskId: $taskId);

        $request  = $this->makeRequest(
            'POST',
            '/tasks/' . rawurlencode($taskId) . '/status',
            '{"pup_id":"pup-1","message":"working on it"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(204, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST /tasks/{id}/complete
    // -------------------------------------------------------------------------

    public function testCompleteReturns401ForMissingToken(): void
    {
        $request  = $this->makeRequest('POST', '/tasks/task-1/complete', '{"pup_id":"pup-1","outcome":"success"}');
        $response = $this->server->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCompleteReturns404ForUnknownTask(): void
    {
        $token    = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $request  = $this->makeRequest(
            'POST',
            '/tasks/unknown-task/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCompleteReturns403ForWrongPup(): void
    {
        $token1 = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');
        $token2 = $this->registry->register(pupId: 'pup-2', hostname: 'host-2');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-2","outcome":"success"}',
            "Bearer {$token2}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCompleteSuccessReturnsRemoveReadyAndAddDoneLabelActions(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(
            [['remove' => 'kanine: ready'], ['add' => 'kanine: done']],
            $body['label_actions'],
        );
    }

    public function testCompleteSuccessWithArchitectLabelReturnsHumanFeedbackNeededLabelAction(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: ['kanine: ready', 'architect'],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(
            [['remove' => 'kanine: ready'], ['add' => 'human feedback needed']],
            $body['label_actions'],
        );
    }

    public function testCompleteSuccessSetsTaskStateToComplete(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertSame(TaskState::Complete, $this->queue->find('task-1')->state);
    }

    public function testCompleteEndpointDecodesUrlEncodedTaskId(): void
    {
        $taskId = 'ScottKeckWarren/Kanine#129';
        $token  = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: $taskId,
            issueNumber: 129,
            repo: 'ScottKeckWarren/Kanine',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo($taskId, 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: $taskId);

        $request = $this->makeRequest(
            'POST',
            '/tasks/' . rawurlencode($taskId) . '/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(TaskState::Complete, $this->queue->find($taskId)->state);
    }

    public function testCompleteSuccessSetsPupToIdle(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');
        $this->registry->updateStatus(pupId: 'pup-1', status: \ScottKeckWarren\Kanine\Domain\PupStatus::Working);

        $request = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertSame(\ScottKeckWarren\Kanine\Domain\PupStatus::Idle, $this->registry->find('pup-1')->status);
    }

    public function testCompleteSuccessClearsPupAssignedTaskId(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertNull($this->registry->find('pup-1')->assignedTaskId);
    }

    public function testCompleteFailureReturnsRemoveReadyAndAddFailedLabelActions(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"failure"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(
            [['remove' => 'kanine: ready'], ['add' => 'kanine: failed']],
            $body['label_actions'],
        );
    }

    public function testCompleteFailureSetsTaskStateToFailed(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"failure"}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertSame(TaskState::Failed, $this->queue->find('task-1')->state);
    }

    public function testCompleteFailureSetsPupToIdle(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');
        $this->registry->updateStatus(pupId: 'pup-1', status: \ScottKeckWarren\Kanine\Domain\PupStatus::Working);

        $request = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"failure"}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertSame(\ScottKeckWarren\Kanine\Domain\PupStatus::Idle, $this->registry->find('pup-1')->status);
    }

    public function testCompleteInvalidOutcomeReturns422(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"invalid-outcome"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCompleteMissingOutcomeReturns422(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCompleteWithUsagePctAcceptedWithNoSideEffects(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success","usage_pct":42.5}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCompleteWithNullUsagePctAccepted(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success","usage_pct":null}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // UsageTracker throttle integration
    // -------------------------------------------------------------------------

    public function testPollRecordsUsagePct(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request = $this->makeRequest(
            'GET',
            '/pups/pup-1/poll',
            '{"usage_pct":95.0}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertSame(95.0, $this->usageTracker->usagePct());
    }

    public function testNullUsagePctIgnored(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $this->usageTracker->record(95.0);

        $request = $this->makeRequest(
            'GET',
            '/pups/pup-1/poll',
            '{"usage_pct":null}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertSame(95.0, $this->usageTracker->usagePct());
    }

    public function testThrottleResetLoggedOnce(): void
    {
        $this->usageTracker->record(95.0);
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $infoMessages = [];
        $this->logger
            ->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $throttledRequest = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $this->server->handle($throttledRequest);

        $this->usageTracker->record(50.0);

        $normalRequest = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $this->server->handle($normalRequest);
        $this->server->handle($normalRequest);

        $resetMessages = array_filter($infoMessages, fn(string $m) => str_contains($m, 'throttle'));
        $this->assertCount(1, $resetMessages);
    }

    public function testThrottleWarningLoggedOnce(): void
    {
        $this->usageTracker->record(95.0);
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $warningCount = 0;
        $this->logger
            ->method('warning')
            ->willReturnCallback(function () use (&$warningCount): void {
                $warningCount++;
            });

        $request = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $this->server->handle($request);
        $this->server->handle($request);
        $this->server->handle($request);

        $this->assertSame(1, $warningCount);
    }

    public function testPollNotThrottledAssignsTask(): void
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

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $response = $this->server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('task-1', $body['new_task']['id']);
        $this->assertFalse($body['throttled']);
    }

    public function testPollThrottledSkipsQueue(): void
    {
        $this->usageTracker->record(95.0);
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

        $request = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $this->server->handle($request);

        $this->assertNotNull($this->queue->dequeue());
    }

    public function testPollThrottledReturnsNullTask(): void
    {
        $this->usageTracker->record(95.0);
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertNull($body['new_task']);
        $this->assertTrue($body['throttled']);
    }

    public function testCompleteRecordsUsagePctInTracker(): void
    {
        $tracker = new UsageTracker(throttleThreshold: 90.0);
        $server  = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: $this->logger,
            readyLabel: 'kanine: ready',
            doneLabel: 'kanine: done',
            failedLabel: 'kanine: failed',
            usageTracker: $tracker,
        );

        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $request = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success","usage_pct":55.0}',
            "Bearer {$token}",
        );
        $server->handle($request);

        $this->assertSame(55.0, $tracker->usagePct());
    }

    public function testCompleteLogsIdleMessage(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $task = new Task(
            id: 'task-1',
            issueNumber: 10,
            repo: 'org/repo',
            title: 'A task',
            body: 'details',
            labels: [],
            state: TaskState::Assigned,
        );
        $this->queue->enqueue($task);
        $this->queue->assignTo('task-1', 'pup-1');
        $this->registry->assign(pupId: 'pup-1', taskId: 'task-1');

        $logged = [];
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->willReturnCallback(function (string $message) use (&$logged): void {
                $logged[] = $message;
            });

        $request = $this->makeRequest(
            'POST',
            '/tasks/task-1/complete',
            '{"pup_id":"pup-1","outcome":"success"}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertContains('Pup pup-1 now idle after completing task task-1', $logged);
    }

    // -------------------------------------------------------------------------
    // T029: Poll endpoint method change (POST → GET) and heartbeat
    // -------------------------------------------------------------------------

    public function testPollEndpointIsGetMethod(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $postRequest = $this->makeRequest('POST', '/pups/pup-1/poll', '', "Bearer {$token}");
        $postResponse = $this->server->handle($postRequest);

        $this->assertSame(404, $postResponse->getStatusCode());
    }

    public function testPollGetReturns200(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $getRequest = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $getResponse = $this->server->handle($getRequest);

        $this->assertSame(200, $getResponse->getStatusCode());
    }

    public function testPollUpdatesHeartbeat(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $this->server->handle($request);

        $pup = $this->registry->find('pup-1');
        $this->assertNotNull($pup);
        $this->assertNotNull($pup->lastHeartbeatAt);
    }

    public function testPollResponseIncludesPendingAnswers(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $response = $this->server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('pendingAnswers', $body);
        $this->assertSame([], $body['pendingAnswers']);
    }

    // -------------------------------------------------------------------------
    // T030: DELETE /pups/{pupId} deregister
    // -------------------------------------------------------------------------

    public function testDeregisterReturns200ForKnownPup(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest('DELETE', '/pups/pup-1', '', "Bearer {$token}");
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['ok']);
    }

    public function testDeregisterReturns404ForUnknownPup(): void
    {
        $request  = $this->makeRequest('DELETE', '/pups/unknown-id');
        $response = $this->server->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeregisterRemovesPupFromRegistry(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request = $this->makeRequest('DELETE', '/pups/pup-1', '', "Bearer {$token}");
        $this->server->handle($request);

        $this->assertNull($this->registry->find('pup-1'));
    }

    // -------------------------------------------------------------------------
    // T039: POST /pups/{pupId}/status
    // -------------------------------------------------------------------------

    public function testPupStatusEndpointReturns200WithOkForWorkingStatus(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest(
            'POST',
            '/pups/pup-1/status',
            '{"status":"working","message":"running"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['ok']);
    }

    public function testPupStatusEndpointUpdatesStatusToWorking(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request = $this->makeRequest(
            'POST',
            '/pups/pup-1/status',
            '{"status":"working","message":"running"}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertSame(\ScottKeckWarren\Kanine\Domain\PupStatus::Working, $this->registry->find('pup-1')->status);
    }

    public function testPupStatusEndpointUpdatesStatusToBlocked(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request = $this->makeRequest(
            'POST',
            '/pups/pup-1/status',
            '{"status":"blocked","message":"waiting"}',
            "Bearer {$token}",
        );
        $this->server->handle($request);

        $this->assertSame(\ScottKeckWarren\Kanine\Domain\PupStatus::Blocked, $this->registry->find('pup-1')->status);
    }

    public function testPupStatusEndpointWithCompleteReleasesAssignment(): void
    {
        $issueStore = new \ScottKeckWarren\Kanine\Supervisor\IssueStore();
        $issueStore->add(new \ScottKeckWarren\Kanine\Domain\Issue(
            id: 42,
            repo: 'owner/repo',
            title: 'Fix bug',
            body: 'body',
            labels: [],
            column: 'Backlog',
            assignedPupId: 'pup-1',
        ));

        $server = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: $this->logger,
            issueStore: $issueStore,
        );

        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request = $this->makeRequest(
            'POST',
            '/pups/pup-1/status',
            '{"status":"complete","message":"done"}',
            "Bearer {$token}",
        );
        $server->handle($request);

        $this->assertNull($issueStore->getByPupId('pup-1'));
    }

    public function testPupStatusEndpointWithFailedReleasesAssignment(): void
    {
        $issueStore = new \ScottKeckWarren\Kanine\Supervisor\IssueStore();
        $issueStore->add(new \ScottKeckWarren\Kanine\Domain\Issue(
            id: 42,
            repo: 'owner/repo',
            title: 'Fix bug',
            body: 'body',
            labels: [],
            column: 'Backlog',
            assignedPupId: 'pup-1',
        ));

        $server = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: $this->logger,
            issueStore: $issueStore,
        );

        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request = $this->makeRequest(
            'POST',
            '/pups/pup-1/status',
            '{"status":"failed","message":"oops"}',
            "Bearer {$token}",
        );
        $server->handle($request);

        $this->assertNull($issueStore->getByPupId('pup-1'));
    }

    public function testPupStatusEndpointReturns400ForInvalidJson(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest(
            'POST',
            '/pups/pup-1/status',
            '{not valid json',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPupStatusEndpointReturns400ForInvalidStatus(): void
    {
        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest(
            'POST',
            '/pups/pup-1/status',
            '{"status":"invalid_status"}',
            "Bearer {$token}",
        );
        $response = $this->server->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPupStatusEndpointReturns404ForUnknownPup(): void
    {
        $request  = $this->makeRequest(
            'POST',
            '/pups/unknown-pup/status',
            '{"status":"working"}',
        );
        $response = $this->server->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // T049+T051: POST /pups/{pupId}/questions
    // -------------------------------------------------------------------------

    public function testPostQuestionReturns200ForRegisteredPup(): void
    {
        $server = $this->makeServerWithQuestionStore();
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest(
            'POST',
            '/pups/pup-1/questions',
            '{"questionId":"q-uuid-1","body":"What should I do?"}',
        );
        $response = $server->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['ok']);
    }

    public function testPostQuestionReturns404ForUnknownPup(): void
    {
        $server  = $this->makeServerWithQuestionStore();
        $request = $this->makeRequest(
            'POST',
            '/pups/unknown/questions',
            '{"questionId":"q-1","body":"Hello?"}',
        );
        $response = $server->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPostQuestionReturns400ForMissingBody(): void
    {
        $server = $this->makeServerWithQuestionStore();
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest(
            'POST',
            '/pups/pup-1/questions',
            '{"questionId":"q-1"}',
        );
        $response = $server->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPostQuestionReturns400ForMissingQuestionId(): void
    {
        $server = $this->makeServerWithQuestionStore();
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $request  = $this->makeRequest(
            'POST',
            '/pups/pup-1/questions',
            '{"body":"What to do?"}',
        );
        $response = $server->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPostQuestionReturns409ForDuplicateQuestionId(): void
    {
        $server = $this->makeServerWithQuestionStore();
        $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $payload = '{"questionId":"q-dup","body":"Duplicate question"}';

        $request = $this->makeRequest('POST', '/pups/pup-1/questions', $payload);
        $server->handle($request);

        $response = $server->handle($request);

        $this->assertSame(409, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // T052: GET /pups/{pupId}/poll — wire QuestionStore::popAnswered
    // -------------------------------------------------------------------------

    public function testPollReturnsPendingAnswers(): void
    {
        $questionStore = new \ScottKeckWarren\Kanine\Supervisor\QuestionStore();
        $server = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: $this->logger,
            questionStore: $questionStore,
        );

        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $q = new \ScottKeckWarren\Kanine\Domain\Question(
            id: 'q-poll-1',
            taskId: 1,
            pupId: 'pup-1',
            body: 'Question body',
            postedAt: new \DateTimeImmutable(),
        );
        $questionStore->add($q);
        $questionStore->answer('q-poll-1', 'Answer text');

        $request  = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $response = $server->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $body['pendingAnswers']);
        $this->assertSame('q-poll-1', $body['pendingAnswers'][0]['questionId']);
        $this->assertSame('Answer text', $body['pendingAnswers'][0]['body']);
    }

    public function testPollClearsPendingAnswersAfterReturn(): void
    {
        $questionStore = new \ScottKeckWarren\Kanine\Supervisor\QuestionStore();
        $server = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: $this->logger,
            questionStore: $questionStore,
        );

        $token = $this->registry->register(pupId: 'pup-1', hostname: 'host-1');

        $q = new \ScottKeckWarren\Kanine\Domain\Question(
            id: 'q-clear-1',
            taskId: 1,
            pupId: 'pup-1',
            body: 'Question',
            postedAt: new \DateTimeImmutable(),
        );
        $questionStore->add($q);
        $questionStore->answer('q-clear-1', 'Answer');

        $request = $this->makeRequest('GET', '/pups/pup-1/poll', '', "Bearer {$token}");
        $server->handle($request);

        $response2 = $server->handle($request);
        $body2     = json_decode((string) $response2->getBody(), true);
        $this->assertSame([], $body2['pendingAnswers']);
    }

    protected function setUp(): void
    {
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->queue        = new TaskQueue();
        $this->registry     = new PupRegistry();
        $this->usageTracker = new UsageTracker();
        $this->server       = new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: $this->logger,
            usageTracker: $this->usageTracker,
            readyLabel: 'kanine: ready',
            doneLabel: 'kanine: done',
            failedLabel: 'kanine: failed',
        );
    }

    private function makeServerWithQuestionStore(): HttpServer
    {
        return new HttpServer(
            host: '127.0.0.1',
            port: 3737,
            taskQueue: $this->queue,
            pupRegistry: $this->registry,
            logger: $this->logger,
            questionStore: new \ScottKeckWarren\Kanine\Supervisor\QuestionStore(),
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
