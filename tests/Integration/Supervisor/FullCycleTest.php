<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Integration\Supervisor;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Http\Message\ServerRequest;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Domain\PupStatus;
use ScottKeckWarren\Kanine\Supervisor\Dispatcher;
use ScottKeckWarren\Kanine\Supervisor\HttpServer;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;
use ScottKeckWarren\Kanine\Supervisor\QuestionStore;
use ScottKeckWarren\Kanine\Supervisor\TaskQueue;

/**
 * Full-cycle integration tests that exercise the complete dispatch → status →
 * complete lifecycle using real in-memory objects (no HTTP mocking).
 */
final class FullCycleTest extends TestCase
{
    private IssueStore $issueStore;
    private PupRegistry $pupRegistry;
    private Dispatcher $dispatcher;
    private QuestionStore $questionStore;
    private TaskQueue $taskQueue;
    private HttpServer $httpServer;

    public function testFullDispatchToPollCycle(): void
    {
        $token = $this->httpServer->handle(
            $this->makeRequest('POST', '/pups/register', '{"pup_id":"fc-pup-1","hostname":"host"}'),
        );
        $registerBody = $this->decodeBody($token);
        $authToken    = $registerBody['token'];

        $this->dispatcher->dispatch();

        $pollResponse = $this->httpServer->handle(
            $this->makeRequest('GET', '/pups/fc-pup-1/poll', '', "Bearer {$authToken}"),
        );

        $this->assertSame(200, $pollResponse->getStatusCode());
        $pollBody = $this->decodeBody($pollResponse);
        $this->assertNotNull($pollBody['assignment']);
        $this->assertSame(1, $pollBody['assignment']['issueId']);
    }

    public function testStatusCompleteReleasesIssue(): void
    {
        $registerResponse = $this->httpServer->handle(
            $this->makeRequest('POST', '/pups/register', '{"pup_id":"fc-pup-2","hostname":"host"}'),
        );
        $authToken = $this->decodeBody($registerResponse)['token'];

        $this->dispatcher->dispatch();

        $statusResponse = $this->httpServer->handle(
            $this->makeRequest('POST', '/pups/fc-pup-2/status', '{"status":"complete"}'),
        );

        $this->assertSame(200, $statusResponse->getStatusCode());
        $this->assertNull($this->issueStore->getByPupId('fc-pup-2'));
    }

    public function testStatusFailedReleasesIssueForRedispatch(): void
    {
        $registerResponse = $this->httpServer->handle(
            $this->makeRequest('POST', '/pups/register', '{"pup_id":"fc-pup-3","hostname":"host"}'),
        );
        $authToken = $this->decodeBody($registerResponse)['token'];

        $this->dispatcher->dispatch();

        $statusResponse = $this->httpServer->handle(
            $this->makeRequest('POST', '/pups/fc-pup-3/status', '{"status":"failed"}'),
        );

        $this->assertSame(200, $statusResponse->getStatusCode());
        $eligible = $this->issueStore->getEligible();
        $this->assertCount(1, $eligible);
        $this->assertSame(1, $eligible[0]->id);
    }

    public function testQuestionPostedAndReturnedOnPoll(): void
    {
        $registerResponse = $this->httpServer->handle(
            $this->makeRequest('POST', '/pups/register', '{"pup_id":"fc-pup-4","hostname":"host"}'),
        );
        $authToken = $this->decodeBody($registerResponse)['token'];

        $this->httpServer->handle(
            $this->makeRequest(
                'POST',
                '/pups/fc-pup-4/questions',
                '{"questionId":"q-1","body":"What should I do?"}',
            ),
        );

        $this->questionStore->answer('q-1', 'Do the thing.');

        $pollResponse = $this->httpServer->handle(
            $this->makeRequest('GET', '/pups/fc-pup-4/poll', '', "Bearer {$authToken}"),
        );

        $this->assertSame(200, $pollResponse->getStatusCode());
        $pollBody = $this->decodeBody($pollResponse);
        $this->assertCount(1, $pollBody['pendingAnswers']);
        $this->assertSame('q-1', $pollBody['pendingAnswers'][0]['questionId']);
        $this->assertSame('Do the thing.', $pollBody['pendingAnswers'][0]['body']);
    }

    public function testInactiveAfterMissedHeartbeat(): void
    {
        $registerResponse = $this->httpServer->handle(
            $this->makeRequest('POST', '/pups/register', '{"pup_id":"fc-pup-5","hostname":"host"}'),
        );
        $authToken = $this->decodeBody($registerResponse)['token'];

        $this->dispatcher->dispatch();

        $oldTime = new \DateTimeImmutable('-30 seconds');
        $this->pupRegistry->forceHeartbeatAt('fc-pup-5', $oldTime);

        $timedOut = $this->dispatcher->checkInactivity(15, new \DateTimeImmutable());

        $this->assertContains('fc-pup-5', $timedOut);

        $pup = $this->pupRegistry->find('fc-pup-5');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Inactive, $pup->status);

        $this->assertNull($this->issueStore->getByPupId('fc-pup-5'));
    }

    protected function setUp(): void
    {
        $this->issueStore    = new IssueStore();
        $this->pupRegistry   = new PupRegistry();
        $this->dispatcher    = new Dispatcher($this->issueStore, $this->pupRegistry);
        $this->questionStore = new QuestionStore();
        $this->taskQueue     = new TaskQueue();

        $this->httpServer = new HttpServer(
            host: '127.0.0.1',
            port: 7777,
            taskQueue: $this->taskQueue,
            pupRegistry: $this->pupRegistry,
            logger: new NullLogger(),
            issueStore: $this->issueStore,
            questionStore: $this->questionStore,
        );

        $issue = new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Test Issue',
            body: 'Body',
            labels: [],
            column: 'backlog',
        );
        $this->issueStore->add($issue);
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

        return new ServerRequest($method, "http://127.0.0.1:7777{$path}", $headers, $body);
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
