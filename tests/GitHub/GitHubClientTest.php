<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\GitHub;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\GitHub\GitHubClient;

final class GitHubClientTest extends TestCase
{
    public function testItReturnsParsedIssues(): void
    {
        $body = json_encode([
            ['number' => 1, 'title' => 'First', 'body' => 'Body one', 'labels' => [['name' => 'kanine: ready']]],
            ['number' => 2, 'title' => 'Second', 'body' => 'Body two', 'labels' => []],
        ]);

        $mock    = new MockHandler([new Response(200, [], $body)]);
        $guzzle  = new Client(['handler' => HandlerStack::create($mock)]);
        $client  = new GitHubClient($guzzle, 'ghp_token');

        $issues = $client->fetchOpenIssues('owner/repo');

        $this->assertCount(2, $issues);
        $this->assertSame(1, $issues[0]['number']);
        $this->assertSame('First', $issues[0]['title']);
        $this->assertSame('Body one', $issues[0]['body']);
        $this->assertSame([['name' => 'kanine: ready']], $issues[0]['labels']);
        $this->assertSame(2, $issues[1]['number']);
    }

    public function testItSendsAuthorizationHeader(): void
    {
        $body = json_encode([]);

        $mock    = new MockHandler([new Response(200, [], $body)]);
        $stack   = HandlerStack::create($mock);
        $guzzle  = new Client(['handler' => $stack]);
        $client  = new GitHubClient($guzzle, 'ghp_mytoken');

        $client->fetchOpenIssues('owner/repo');

        $lastRequest = $mock->getLastRequest();
        $this->assertSame('Bearer ghp_mytoken', $lastRequest->getHeaderLine('Authorization'));
    }

    public function testItRequestsOpenIssues(): void
    {
        $body = json_encode([]);

        $mock   = new MockHandler([new Response(200, [], $body)]);
        $stack  = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $stack]);
        $client = new GitHubClient($guzzle, 'ghp_token');

        $client->fetchOpenIssues('myorg/myrepo');

        $lastRequest = $mock->getLastRequest();
        $uri         = (string) $lastRequest->getUri();

        $this->assertStringContainsString('myorg/myrepo', $uri);
        $this->assertStringContainsString('state=open', $uri);
    }

    public function testItFollowsPaginationViaLinkHeader(): void
    {
        $page1 = json_encode([
            ['number' => 1, 'title' => 'Issue 1', 'body' => '', 'labels' => []],
        ]);
        $page2 = json_encode([
            ['number' => 2, 'title' => 'Issue 2', 'body' => '', 'labels' => []],
        ]);

        $nextUrl  = 'https://api.github.com/repos/owner/repo/issues?state=open&page=2';
        $linkHeader = "<{$nextUrl}>; rel=\"next\"";
        $mock = new MockHandler([
            new Response(200, ['Link' => $linkHeader], $page1),
            new Response(200, [], $page2),
        ]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new GitHubClient($guzzle, 'ghp_token');

        $issues = $client->fetchOpenIssues('owner/repo');

        $this->assertCount(2, $issues);
        $this->assertSame(1, $issues[0]['number']);
        $this->assertSame(2, $issues[1]['number']);
    }

    public function testItThrowsOnNon2xxResponse(): void
    {
        $mock   = new MockHandler([new Response(401, [], '{"message":"Bad credentials"}')]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $client = new GitHubClient($guzzle, 'bad_token');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/401/');

        $client->fetchOpenIssues('owner/repo');
    }
}
