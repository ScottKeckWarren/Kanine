<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Pup;

use Github\Api\Issue\Labels;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Pup\GitHubLabelWriter;

final class GitHubLabelWriterTest extends TestCase
{
    public function testAddLabelActionCallsApi(): void
    {
        $labelsMock = $this->makeLabelsMock();

        $labelsMock
            ->expects($this->once())
            ->method('add')
            ->with('myorg', 'myrepo', 42, ['bug']);

        $writer = new GitHubLabelWriter(
            token: 'ghp_token',
            logger: $this->createStub(LoggerInterface::class),
            labelsApi: $labelsMock,
        );

        $writer->applyActions('myorg/myrepo', 42, [['add' => 'bug']]);
    }

    public function testRemoveLabelActionCallsApi(): void
    {
        $labelsMock = $this->makeLabelsMock();

        $labelsMock
            ->expects($this->once())
            ->method('remove')
            ->with('myorg', 'myrepo', 42, 'bug');

        $writer = new GitHubLabelWriter(
            token: 'ghp_token',
            logger: $this->createStub(LoggerInterface::class),
            labelsApi: $labelsMock,
        );

        $writer->applyActions('myorg/myrepo', 42, [['remove' => 'bug']]);
    }

    public function testApiFailureOnOneActionIsLogged(): void
    {
        $labelsMock = $this->makeLabelsMock();

        $labelsMock
            ->method('add')
            ->willThrowException(new \RuntimeException('API error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error');

        $writer = new GitHubLabelWriter(
            token: 'ghp_token',
            logger: $logger,
            labelsApi: $labelsMock,
        );

        $writer->applyActions('myorg/myrepo', 42, [['add' => 'bug']]);
    }

    public function testApiFailureDoesNotStopSubsequentActions(): void
    {
        $labelsMock = $this->makeLabelsMock();

        $labelsMock
            ->method('add')
            ->willThrowException(new \RuntimeException('API error'));

        $labelsMock
            ->expects($this->once())
            ->method('remove')
            ->with('myorg', 'myrepo', 42, 'wontfix');

        $writer = new GitHubLabelWriter(
            token: 'ghp_token',
            logger: $this->createStub(LoggerInterface::class),
            labelsApi: $labelsMock,
        );

        $writer->applyActions('myorg/myrepo', 42, [
            ['add' => 'bug'],
            ['remove' => 'wontfix'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeLabelsMock(): Labels&MockObject
    {
        return $this->createMock(Labels::class);
    }
}
