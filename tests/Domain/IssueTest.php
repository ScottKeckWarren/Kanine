<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Issue;

final class IssueTest extends TestCase
{
    public function testIdIsAccessible(): void
    {
        $issue = $this->makeIssue(id: 42);

        $this->assertSame(42, $issue->id);
    }

    public function testRepoIsAccessible(): void
    {
        $issue = $this->makeIssue(repo: 'owner/repo');

        $this->assertSame('owner/repo', $issue->repo);
    }

    public function testTitleIsAccessible(): void
    {
        $issue = $this->makeIssue(title: 'Fix the bug');

        $this->assertSame('Fix the bug', $issue->title);
    }

    public function testBodyIsAccessible(): void
    {
        $issue = $this->makeIssue(body: 'Detailed description');

        $this->assertSame('Detailed description', $issue->body);
    }

    public function testLabelsIsAccessible(): void
    {
        $issue = $this->makeIssue(labels: ['kanine: ready', 'bug']);

        $this->assertSame(['kanine: ready', 'bug'], $issue->labels);
    }

    public function testColumnIsAccessible(): void
    {
        $issue = $this->makeIssue(column: 'Backlog');

        $this->assertSame('Backlog', $issue->column);
    }

    public function testPinnedDefaultsToFalse(): void
    {
        $issue = $this->makeIssue();

        $this->assertFalse($issue->pinned);
    }

    public function testPinnedCanBeSetToTrue(): void
    {
        $issue = $this->makeIssue(pinned: true);

        $this->assertTrue($issue->pinned);
    }

    public function testAssignedPupIdIsNullByDefault(): void
    {
        $issue = $this->makeIssue();

        $this->assertNull($issue->assignedPupId);
    }

    public function testAssignedPupIdCanBeSet(): void
    {
        $issue = $this->makeIssue(assignedPupId: 'pup-1');

        $this->assertSame('pup-1', $issue->assignedPupId);
    }

    public function testFetchedAtIsNullByDefault(): void
    {
        $issue = $this->makeIssue();

        $this->assertNull($issue->fetchedAt);
    }

    public function testFetchedAtCanBeSet(): void
    {
        $dt = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $issue = $this->makeIssue(fetchedAt: $dt);

        $this->assertSame($dt, $issue->fetchedAt);
    }

    /**
     * @param list<string> $labels
     */
    private function makeIssue(
        int $id = 1,
        string $repo = 'owner/repo',
        string $title = 'Test issue',
        string $body = 'Body text',
        array $labels = [],
        string $column = 'Backlog',
        bool $pinned = false,
        ?string $assignedPupId = null,
        ?\DateTimeImmutable $fetchedAt = null,
    ): Issue {
        return new Issue(
            id: $id,
            repo: $repo,
            title: $title,
            body: $body,
            labels: $labels,
            column: $column,
            pinned: $pinned,
            assignedPupId: $assignedPupId,
            fetchedAt: $fetchedAt,
        );
    }
}
