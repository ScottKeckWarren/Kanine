<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use ScottKeckWarren\Kanine\Domain\PupStatus;

final class HttpServer implements HttpServerInterface
{
    private readonly string $address;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly TaskQueue $taskQueue,
        private readonly PupRegistry $pupRegistry,
        private readonly LoggerInterface $logger,
        private readonly int $statusIntervalMs = 10000,
        private readonly int $maxThrottlePollMs = 60000,
        private readonly string $readyLabel = 'kanine: ready',
        private readonly string $doneLabel = 'kanine: done',
        private readonly string $failedLabel = 'kanine: failed',
    ) {
        $this->address = "{$host}:{$port}";
    }

    public function boundAddress(): string
    {
        return $this->address;
    }

    /**
     * @codeCoverageIgnore blocks on Loop::run(), cannot be unit-tested
     */
    public function start(): void
    {
        $loop   = Loop::get();
        $server = new ReactHttpServer([$this, 'handle']);
        $socket = new SocketServer($this->address, [], $loop);

        $server->listen($socket);

        $this->logger->info("HttpServer listening on {$this->address}");

        $loop->run();
    }

    /**
     * @codeCoverageIgnore interacts with ReactPHP global loop, cannot be unit-tested
     */
    public function stop(): void
    {
        Loop::get()->stop();
    }

    public function handle(ServerRequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path   = $request->getUri()->getPath();

        $this->logger->info("HTTP {$method} {$path}");

        if ($method === 'POST' && $path === '/pups/register') {
            return $this->handleRegister($request);
        }

        if ($method === 'POST' && preg_match('#^/pups/([^/]+)/poll$#', $path, $matches)) {
            return $this->handlePoll($request, $matches[1]);
        }

        if ($method === 'POST' && preg_match('#^/tasks/([^/]+)/complete$#', $path, $matches)) {
            return $this->handleTaskComplete($request, $matches[1]);
        }

        if ($method === 'POST' && preg_match('#^/tasks/([^/]+)/status$#', $path, $matches)) {
            return $this->handleTaskStatus($request, $matches[1]);
        }

        return $this->jsonResponse(404, ['error' => 'Not found']);
    }

    private function handleRegister(ServerRequestInterface $request): Response
    {
        $body = (string) $request->getBody();

        try {
            $data = $body !== ''
                ? json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException $e) {
            return $this->jsonResponse(400, ['error' => $e->getMessage(), 'code' => 'INVALID_JSON']);
        }

        $data   = is_array($data) ? $data : [];
        $pupId  = $data['pup_id'] ?? null;
        $hostname = $data['hostname'] ?? null;

        if (!is_string($pupId) || $pupId === '') {
            return $this->jsonResponse(422, ['error' => 'pup_id is required']);
        }

        if (!is_string($hostname) || $hostname === '') {
            return $this->jsonResponse(422, ['error' => 'hostname is required']);
        }

        $token = $this->pupRegistry->register(pupId: $pupId, hostname: $hostname);

        $this->logger->info("Registered pup {$pupId} from {$hostname}");

        return $this->jsonResponse(200, [
            'token'               => $token,
            'poll_interval_ms'    => 5000,
            'status_interval_ms'  => $this->statusIntervalMs,
            'max_throttle_poll_ms' => $this->maxThrottlePollMs,
        ]);
    }

    private function handlePoll(ServerRequestInterface $request, string $pupId): Response
    {
        $body = (string) $request->getBody();

        if ($body !== '') {
            try {
                json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return $this->jsonResponse(400, ['error' => $e->getMessage(), 'code' => 'INVALID_JSON']);
            }
        }

        $pup = $this->pupRegistry->find($pupId);

        if ($pup === null) {
            $this->logger->info("Poll from unknown pup {$pupId}");
            return $this->jsonResponse(404, ['error' => "Unknown pup: {$pupId}"]);
        }

        $token = $this->extractBearerToken($request);

        if ($token === null || !$this->pupRegistry->validate(pupId: $pupId, token: $token)) {
            $this->logger->info("Unauthorized poll attempt for pup {$pupId}");
            return $this->jsonResponse(401, ['error' => 'Unauthorized']);
        }

        $newTask = null;

        if ($pup->status === PupStatus::Idle) {
            $task = $this->taskQueue->dequeue();

            if ($task !== null) {
                $this->pupRegistry->assign(pupId: $pupId, taskId: $task->id);
                $this->pupRegistry->updateStatus(pupId: $pupId, status: PupStatus::Working);
                $this->logger->info("Assigned task {$task->id} to pup {$pupId}");
                $newTask = [
                    'id'           => $task->id,
                    'issue_number' => $task->issueNumber,
                    'repo'         => $task->repo,
                    'title'        => $task->title,
                    'body'         => $task->body,
                    'labels'       => $task->labels,
                    'state'        => $task->state->value,
                ];
            }
        }

        return $this->jsonResponse(200, ['new_task' => $newTask]);
    }

    private function handleTaskComplete(ServerRequestInterface $request, string $taskId): Response
    {
        $token = $this->extractBearerToken($request);

        if ($token === null) {
            return $this->jsonResponse(401, ['error' => 'Unauthorized']);
        }

        $body = (string) $request->getBody();

        try {
            $data = $body !== ''
                ? json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException $e) {
            return $this->jsonResponse(400, ['error' => $e->getMessage(), 'code' => 'INVALID_JSON']);
        }

        $data  = is_array($data) ? $data : [];
        $pupId = $data['pup_id'] ?? null;

        if (!is_string($pupId) || $pupId === '') {
            return $this->jsonResponse(422, ['error' => 'pup_id is required']);
        }

        if (!$this->pupRegistry->validate(pupId: $pupId, token: $token)) {
            return $this->jsonResponse(401, ['error' => 'Unauthorized']);
        }

        $task = $this->taskQueue->find($taskId);

        if ($task === null) {
            return $this->jsonResponse(404, ['error' => "Unknown task: {$taskId}"]);
        }

        $assignedPupId = $this->taskQueue->getAssignedPupId($taskId);

        if ($assignedPupId !== $pupId) {
            return $this->jsonResponse(403, ['error' => 'Forbidden']);
        }

        $outcome = $data['outcome'] ?? null;

        if ($outcome !== 'success' && $outcome !== 'failure') {
            return $this->jsonResponse(422, ['error' => "Invalid outcome: {$outcome}"]);
        }

        if ($outcome === 'success') {
            $this->taskQueue->complete($taskId);
            $labelActions = [
                ['remove' => $this->readyLabel],
                ['add'    => $this->doneLabel],
            ];
        } else {
            $this->taskQueue->fail($taskId);
            $labelActions = [
                ['remove' => $this->readyLabel],
                ['add'    => $this->failedLabel],
            ];
        }

        $this->taskQueue->unassignTask($taskId);
        $this->pupRegistry->unassign(pupId: $pupId);
        $this->pupRegistry->updateStatus(pupId: $pupId, status: PupStatus::Idle);

        $this->logger->info("Pup {$pupId} now idle after completing task {$taskId}");

        return $this->jsonResponse(200, ['label_actions' => $labelActions]);
    }

    private function handleTaskStatus(ServerRequestInterface $request, string $taskId): Response
    {
        $body = (string) $request->getBody();

        try {
            $data = $body !== ''
                ? json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException $e) {
            return $this->jsonResponse(400, ['error' => $e->getMessage(), 'code' => 'INVALID_JSON']);
        }

        $data  = is_array($data) ? $data : [];
        $pupId = $data['pup_id'] ?? null;

        if (!is_string($pupId) || $pupId === '') {
            return $this->jsonResponse(422, ['error' => 'pup_id is required']);
        }

        $message = $data['message'] ?? null;

        if (!is_string($message) || $message === '') {
            return $this->jsonResponse(422, ['error' => 'message is required']);
        }

        $owningPup = $this->pupRegistry->findByAssignedTask($taskId);

        if ($owningPup === null) {
            return $this->jsonResponse(404, ['error' => "Unknown task: {$taskId}"]);
        }

        if ($owningPup->id !== $pupId) {
            return $this->jsonResponse(403, ['error' => 'Forbidden']);
        }

        $token = $this->extractBearerToken($request);

        if ($token === null || !$this->pupRegistry->validate(pupId: $pupId, token: $token)) {
            return $this->jsonResponse(401, ['error' => 'Unauthorized']);
        }

        $this->logger->info("Status from pup {$pupId} on task {$taskId}: {$message}");

        return new Response(204);
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }

    private function jsonResponse(int $status, mixed $data): Response
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
