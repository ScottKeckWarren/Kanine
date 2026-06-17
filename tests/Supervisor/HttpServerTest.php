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

    public function testStatusEndpointReturns422ForMissingMessage(): void
    {
        $request  = $this->makeRequest(
            'POST',
            '/tasks/task-1/status',
            '{"pup_id":"pup-1"}',
        );
        $response = $this->server->handle($request);

        $this->assertSame(422, $response->getStatusCode());
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
            readyLabel: 'kanine: ready',
            doneLabel: 'kanine: done',
            failedLabel: 'kanine: failed',
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
