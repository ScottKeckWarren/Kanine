<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;

final class IssueStoreTest extends TestCase
{
    public function testAddAndGetAll(): void
    {
        $store = new IssueStore();
        $issue = $this->makeIssue(id: 1);

        $store->add($issue);

        $this->assertCount(1, $store->getAll());
        $this->assertSame($issue, $store->getAll()[0]);
    }

    public function testGetEligibleExcludesPinnedIssues(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1, pinned: true));

        $this->assertSame([], $store->getEligible());
    }

    public function testGetEligibleExcludesAssignedIssues(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1));
        $store->assign(id: 1, repo: 'owner/repo', pupId: 'pup-1');

        $this->assertSame([], $store->getEligible());
    }

    public function testGetEligibleReturnsUnpinnedUnassignedIssues(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1));

        $eligible = $store->getEligible();

        $this->assertCount(1, $eligible);
        $this->assertSame(1, $eligible[0]->id);
    }

    public function testPinRemovesIssueFromEligible(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1));
        $store->pin(id: 1, repo: 'owner/repo');

        $this->assertSame([], $store->getEligible());
    }

    public function testUnpinRestoresIssueToEligible(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1));
        $store->pin(id: 1, repo: 'owner/repo');
        $store->unpin(id: 1, repo: 'owner/repo');

        $this->assertCount(1, $store->getEligible());
    }

    public function testAssignSetsAssignedPupId(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1));
        $store->assign(id: 1, repo: 'owner/repo', pupId: 'pup-7');

        $this->assertSame('pup-7', $store->getAll()[0]->assignedPupId);
    }

    public function testUnassignClearsAssignedPupId(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1));
        $store->assign(id: 1, repo: 'owner/repo', pupId: 'pup-7');
        $store->unassign(id: 1, repo: 'owner/repo');

        $this->assertNull($store->getAll()[0]->assignedPupId);
    }

    public function testGetByPupIdReturnsCorrectIssue(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1));
        $store->add($this->makeIssue(id: 2));
        $store->assign(id: 1, repo: 'owner/repo', pupId: 'pup-7');

        $found = $store->getByPupId('pup-7');

        $this->assertNotNull($found);
        $this->assertSame(1, $found->id);
    }

    public function testGetByPupIdReturnsNullWhenNoIssueAssigned(): void
    {
        $store = new IssueStore();
        $store->add($this->makeIssue(id: 1));

        $this->assertNull($store->getByPupId('pup-7'));
    }

    private function makeIssue(
        int $id = 1,
        string $repo = 'owner/repo',
        bool $pinned = false,
        ?string $assignedPupId = null,
    ): Issue {
        return new Issue(
            id: $id,
            repo: $repo,
            title: 'Test issue',
            body: 'Body',
            labels: [],
            column: 'Backlog',
            pinned: $pinned,
            assignedPupId: $assignedPupId,
        );
    }
}
