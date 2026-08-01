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
use ScottKeckWarren\Kanine\Domain\Question;

final class HttpServer implements HttpServerInterface
{
    private readonly string $address;
    private bool $wasThrottled = false;

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
        private readonly string $architectLabel = 'architect',
        private readonly string $humanFeedbackLabel = 'human feedback needed',
        private readonly ?UsageTracker $usageTracker = null,
        private readonly ?IssueStore $issueStore = null,
        private readonly ?QuestionStore $questionStore = null,
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

        if ($method === 'GET' && preg_match('#^/pups/([^/]+)/poll$#', $path, $matches)) {
            return $this->handlePoll($request, $matches[1]);
        }

        if ($method === 'DELETE' && preg_match('#^/pups/([^/]+)$#', $path, $matches)) {
            return $this->handleDeregister($matches[1]);
        }

        if ($method === 'POST' && preg_match('#^/pups/([^/]+)/status$#', $path, $matches)) {
            return $this->handlePupStatus($request, $matches[1]);
        }

        if ($method === 'POST' && preg_match('#^/pups/([^/]+)/questions$#', $path, $matches)) {
            return $this->handlePostQuestion($request, $matches[1]);
        }

        if ($method === 'POST' && preg_match('#^/tasks/([^/]+)/complete$#', $path, $matches)) {
            return $this->handleTaskComplete($request, rawurldecode($matches[1]));
        }

        if ($method === 'POST' && preg_match('#^/tasks/([^/]+)/status$#', $path, $matches)) {
            return $this->handleTaskStatus($request, rawurldecode($matches[1]));
        }

        return $this->jsonResponse(404, [
            'error' => "No route matches {$method} {$path}. "
                . 'Check the HTTP method and path against the pup API contract.',
        ]);
    }

    private function handleRegister(ServerRequestInterface $request): Response
    {
        $body = (string) $request->getBody();

        try {
            $data = $body !== ''
                ? json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException $e) {
            return $this->jsonResponse(400, [
                'error' => "Malformed JSON in POST /pups/register body: {$e->getMessage()}. "
                    . 'Resend with a valid JSON payload containing pup_id and hostname.',
                'code' => 'INVALID_JSON',
            ]);
        }

        $data   = is_array($data) ? $data : [];
        $pupId  = $data['pup_id'] ?? null;
        $hostname = $data['hostname'] ?? null;

        if (!is_string($pupId) || $pupId === '') {
            return $this->jsonResponse(422, [
                'error' => 'pup_id is required in the POST /pups/register body; '
                    . 'include a non-empty pup_id string.',
            ]);
        }

        if (!is_string($hostname) || $hostname === '') {
            return $this->jsonResponse(422, [
                'error' => 'hostname is required in the POST /pups/register body; '
                    . 'include a non-empty hostname string.',
            ]);
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
        $data = [];

        if ($body !== '') {
            try {
                $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
                $data    = is_array($decoded) ? $decoded : [];
            } catch (JsonException $e) {
                return $this->jsonResponse(400, [
                    'error' => "Malformed JSON in GET /pups/{$pupId}/poll body: {$e->getMessage()}. "
                        . 'Resend with a valid JSON payload or an empty body.',
                    'code' => 'INVALID_JSON',
                ]);
            }
        }

        $pup = $this->pupRegistry->find($pupId);

        if ($pup === null) {
            $this->logger->info("Poll from unknown pup {$pupId}");
            return $this->jsonResponse(404, [
                'error' => "Unknown pup: {$pupId}. Register the pup via POST /pups/register before polling.",
            ]);
        }

        $token = $this->extractBearerToken($request);

        if ($token === null || !$this->pupRegistry->validate(pupId: $pupId, token: $token)) {
            $this->logger->info("Unauthorized poll attempt for pup {$pupId}");
            return $this->jsonResponse(401, [
                'error' => "Unauthorized poll for pup {$pupId}: missing or invalid bearer token. "
                    . 'Re-register the pup to obtain a valid token.',
            ]);
        }

        $usagePct = isset($data['usage_pct']) && is_float($data['usage_pct']) ? $data['usage_pct'] : null;

        if ($usagePct !== null && $this->usageTracker !== null) {
            $this->usageTracker->record($usagePct);
        }

        if ($this->usageTracker !== null && $this->usageTracker->isThrottled()) {
            if (!$this->wasThrottled) {
                $this->logger->warning("UsageTracker throttle activated — usage at {$this->usageTracker->usagePct()}%");
                $this->wasThrottled = true;
            }

            return $this->jsonResponse(200, ['new_task' => null, 'throttled' => true, 'pendingAnswers' => []]);
        }

        if ($this->wasThrottled) {
            $pct = $this->usageTracker !== null ? $this->usageTracker->usagePct() : 0.0;
            $this->logger->info("UsageTracker throttle reset — usage at {$pct}%");
            $this->wasThrottled = false;
        }

        $this->pupRegistry->updateHeartbeat($pupId);
        $this->logger->debug("Heartbeat from pup {$pupId} (status={$pup->status->value})");

        $newTask = null;

        if ($pup->status === PupStatus::Idle) {
            $task = $this->taskQueue->dequeue();

            if ($task !== null) {
                $this->taskQueue->enqueue($task);
                $this->taskQueue->assign($task->id);
                $this->taskQueue->assignTo($task->id, $pupId);
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
            } else {
                $this->logger->debug("Pup {$pupId} idle, no task in queue");
            }
        }

        $pendingAnswers = $this->questionStore !== null
            ? $this->questionStore->popAnswered($pupId)
            : [];

        if ($pendingAnswers !== []) {
            $this->logger->debug('Delivering ' . count($pendingAnswers) . " pending answer(s) to pup {$pupId}");
        }

        $assignment = null;

        if ($this->issueStore !== null && $pup->status === PupStatus::Working) {
            $assignedIssue = $this->issueStore->getByPupId($pupId);

            if ($assignedIssue !== null) {
                $assignment = [
                    'issueId' => $assignedIssue->id,
                    'repo'    => $assignedIssue->repo,
                    'title'   => $assignedIssue->title,
                    'body'    => $assignedIssue->body,
                    'labels'  => $assignedIssue->labels,
                ];
            }
        }

        return $this->jsonResponse(200, [
            'new_task'       => $newTask,
            'assignment'     => $assignment,
            'throttled'      => false,
            'pendingAnswers' => $pendingAnswers,
        ]);
    }

    private function handleDeregister(string $pupId): Response
    {
        $pup = $this->pupRegistry->find($pupId);

        if ($pup === null) {
            return $this->jsonResponse(404, [
                'error' => "Unknown pup: {$pupId}. It may already be deregistered; no action needed.",
            ]);
        }

        $this->pupRegistry->remove($pupId);

        return $this->jsonResponse(200, ['ok' => true]);
    }

    private function handleTaskComplete(ServerRequestInterface $request, string $taskId): Response
    {
        $token = $this->extractBearerToken($request);

        if ($token === null) {
            return $this->jsonResponse(401, [
                'error' => "Unauthorized: missing bearer token for POST /tasks/{$taskId}/complete. "
                    . "Include 'Authorization: Bearer <token>' from registration.",
            ]);
        }

        $body = (string) $request->getBody();

        try {
            $data = $body !== ''
                ? json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException $e) {
            return $this->jsonResponse(400, [
                'error' => "Malformed JSON in POST /tasks/{$taskId}/complete body: {$e->getMessage()}. "
                    . 'Resend with a valid JSON payload containing pup_id and outcome.',
                'code' => 'INVALID_JSON',
            ]);
        }

        $data  = is_array($data) ? $data : [];
        $pupId = $data['pup_id'] ?? null;

        if (!is_string($pupId) || $pupId === '') {
            return $this->jsonResponse(422, [
                'error' => "pup_id is required in the POST /tasks/{$taskId}/complete body; "
                    . 'include a non-empty pup_id string.',
            ]);
        }

        if (!$this->pupRegistry->validate(pupId: $pupId, token: $token)) {
            return $this->jsonResponse(401, [
                'error' => "Unauthorized: invalid token for pup {$pupId}. "
                    . 'Re-register the pup to obtain a valid token.',
            ]);
        }

        $task = $this->taskQueue->find($taskId);

        if ($task === null) {
            return $this->jsonResponse(404, [
                'error' => "Unknown task: {$taskId}. Verify the task id returned from a previous poll.",
            ]);
        }

        $assignedPupId = $this->taskQueue->getAssignedPupId($taskId);

        if ($assignedPupId !== $pupId) {
            return $this->jsonResponse(403, [
                'error' => "Forbidden: task {$taskId} is assigned to a different pup. "
                    . 'Only the assigned pup can report completion.',
            ]);
        }

        $outcome = $data['outcome'] ?? null;

        if ($outcome !== 'success' && $outcome !== 'failure') {
            return $this->jsonResponse(422, [
                'error' => "Invalid outcome '{$outcome}' for task {$taskId}; use 'success' or 'failure'.",
            ]);
        }

        $usagePct = $data['usage_pct'] ?? null;
        if ($this->usageTracker !== null && is_numeric($usagePct)) {
            $this->usageTracker->record((float) $usagePct);
        }

        if ($outcome === 'success') {
            $this->taskQueue->complete($taskId);
            $isArchitectTask = in_array($this->architectLabel, $task->labels, strict: true);
            $completionLabel = $isArchitectTask ? $this->humanFeedbackLabel : $this->doneLabel;
            $labelActions    = [
                ['remove' => $this->readyLabel],
                ['add'    => $completionLabel],
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
            return $this->jsonResponse(400, [
                'error' => "Malformed JSON in POST /tasks/{$taskId}/status body: {$e->getMessage()}. "
                    . 'Resend with a valid JSON payload containing pup_id.',
                'code' => 'INVALID_JSON',
            ]);
        }

        $data  = is_array($data) ? $data : [];
        $pupId = $data['pup_id'] ?? null;

        if (!is_string($pupId) || $pupId === '') {
            return $this->jsonResponse(422, [
                'error' => "pup_id is required in the POST /tasks/{$taskId}/status body; "
                    . 'include a non-empty pup_id string.',
            ]);
        }

        $message = is_string($data['message'] ?? null) ? $data['message'] : '';

        $owningPup = $this->pupRegistry->findByAssignedTask($taskId);

        if ($owningPup === null) {
            return $this->jsonResponse(404, [
                'error' => "Unknown task: {$taskId}. Verify the task id returned from a previous poll.",
            ]);
        }

        if ($owningPup->id !== $pupId) {
            return $this->jsonResponse(403, [
                'error' => "Forbidden: task {$taskId} is assigned to pup {$owningPup->id}, not {$pupId}. "
                    . 'Only the assigned pup can report status.',
            ]);
        }

        $token = $this->extractBearerToken($request);

        if ($token === null || !$this->pupRegistry->validate(pupId: $pupId, token: $token)) {
            return $this->jsonResponse(401, [
                'error' => "Unauthorized status report for pup {$pupId}: missing or invalid bearer token. "
                    . 'Re-register the pup to obtain a valid token.',
            ]);
        }

        $this->logger->info("Status from pup {$pupId} on task {$taskId}: {$message}");

        return new Response(204);
    }

    private function handlePupStatus(ServerRequestInterface $request, string $pupId): Response
    {
        $body = (string) $request->getBody();

        try {
            $data = $body !== ''
                ? json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException $e) {
            return $this->jsonResponse(400, [
                'error' => "Malformed JSON in POST /pups/{$pupId}/status body: {$e->getMessage()}. "
                    . 'Resend with a valid JSON payload containing status.',
                'code' => 'INVALID_JSON',
            ]);
        }

        $data       = is_array($data) ? $data : [];
        $statusStr  = $data['status'] ?? null;

        $validStatuses = ['working', 'blocked', 'complete', 'failed'];

        if (!is_string($statusStr) || !in_array($statusStr, $validStatuses, strict: true)) {
            return $this->jsonResponse(400, [
                'error' => "Invalid status '{$statusStr}' for pup {$pupId}; "
                    . 'use one of: working, blocked, complete, failed.',
            ]);
        }

        $pup = $this->pupRegistry->find($pupId);

        if ($pup === null) {
            return $this->jsonResponse(404, [
                'error' => "Unknown pup: {$pupId}. Register via POST /pups/register before reporting status.",
            ]);
        }

        $mappedStatus = match ($statusStr) {
            'working'  => PupStatus::Working,
            'blocked'  => PupStatus::Blocked,
            'complete' => PupStatus::Completed,
            'failed'   => PupStatus::Failed,
        };

        $this->pupRegistry->updateStatus($pupId, $mappedStatus);
        $this->logger->debug("Pup {$pupId} reported status: {$statusStr}");

        if ($statusStr === 'complete' || $statusStr === 'failed') {
            if ($this->issueStore !== null) {
                $issue = $this->issueStore->getByPupId($pupId);

                if ($issue !== null) {
                    $this->issueStore->unassign($issue->id, $issue->repo);
                }
            }
        }

        return $this->jsonResponse(200, ['ok' => true]);
    }

    private function handlePostQuestion(ServerRequestInterface $request, string $pupId): Response
    {
        $pup = $this->pupRegistry->find($pupId);

        if ($pup === null) {
            return $this->jsonResponse(404, [
                'error' => "Unknown pup: {$pupId}. Register via POST /pups/register before posting questions.",
            ]);
        }

        $body = (string) $request->getBody();

        try {
            $data = $body !== ''
                ? json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
        } catch (JsonException $e) {
            return $this->jsonResponse(400, [
                'error' => "Malformed JSON in POST /pups/{$pupId}/questions body: {$e->getMessage()}. "
                    . 'Resend with a valid JSON payload containing questionId and body.',
                'code' => 'INVALID_JSON',
            ]);
        }

        $data       = is_array($data) ? $data : [];
        $questionId = $data['questionId'] ?? null;
        $questionBody = $data['body'] ?? null;

        if (!is_string($questionId) || $questionId === '') {
            return $this->jsonResponse(400, [
                'error' => "questionId is required in the POST /pups/{$pupId}/questions body; "
                    . 'include a non-empty questionId string.',
            ]);
        }

        if (!is_string($questionBody) || $questionBody === '') {
            return $this->jsonResponse(400, [
                'error' => "body is required in the POST /pups/{$pupId}/questions body; "
                    . 'include a non-empty body string.',
            ]);
        }

        if ($this->questionStore !== null) {
            try {
                $this->questionStore->add(new Question(
                    id: $questionId,
                    taskId: 0,
                    pupId: $pupId,
                    body: $questionBody,
                    postedAt: new \DateTimeImmutable(),
                ));
            } catch (\InvalidArgumentException $e) {
                return $this->jsonResponse(409, [
                    'error' => "{$e->getMessage()}. Use a unique questionId per question.",
                ]);
            }

            $this->logger->debug("Recorded question {$questionId} from pup {$pupId}");
        }

        return $this->jsonResponse(200, ['ok' => true]);
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
