<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Domain\PupStatus;
use ScottKeckWarren\Kanine\Supervisor\Dispatcher;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;

final class DispatcherTest extends TestCase
{
    private IssueStore $issueStore;
    private PupRegistry $pupRegistry;
    private Dispatcher $dispatcher;

    public function testDispatchAssignsEligibleIssuesToIdlePups(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->issueStore->add($this->makeIssue(id: 1, repo: 'org/repo'));

        $assigned = $this->dispatcher->dispatch();

        $this->assertSame(1, $assigned);
        $issues = $this->issueStore->getAll();
        $this->assertSame('pup-1', $issues[0]->assignedPupId);
    }

    public function testDispatchSkipsWhenNoIdlePups(): void
    {
        $this->issueStore->add($this->makeIssue(id: 1, repo: 'org/repo'));

        $assigned = $this->dispatcher->dispatch();

        $this->assertSame(0, $assigned);
    }

    public function testDispatchSkipsWhenNoEligibleIssues(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');

        $assigned = $this->dispatcher->dispatch();

        $this->assertSame(0, $assigned);
    }

    public function testDispatchAssignsMultiplePupsToMultipleIssues(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->pupRegistry->register(pupId: 'pup-2', hostname: 'host-2');
        $this->issueStore->add($this->makeIssue(id: 1, repo: 'org/repo'));
        $this->issueStore->add($this->makeIssue(id: 2, repo: 'org/repo'));

        $assigned = $this->dispatcher->dispatch();

        $this->assertSame(2, $assigned);
        $allIssues = $this->issueStore->getAll();
        $assignedPups = array_filter(
            $allIssues,
            fn (Issue $i): bool => $i->assignedPupId !== null,
        );
        $this->assertCount(2, $assignedPups);
    }

    public function testDispatchDoesNotAssignMoreThanAvailableIssues(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->pupRegistry->register(pupId: 'pup-2', hostname: 'host-2');
        $this->pupRegistry->register(pupId: 'pup-3', hostname: 'host-3');
        $this->issueStore->add($this->makeIssue(id: 1, repo: 'org/repo'));

        $assigned = $this->dispatcher->dispatch();

        $this->assertSame(1, $assigned);
    }

    public function testDispatchSetsAssignedPupToWorking(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->issueStore->add($this->makeIssue(id: 1, repo: 'org/repo'));

        $this->dispatcher->dispatch();

        $pup = $this->pupRegistry->find('pup-1');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Working, $pup->status);
    }

    public function testCheckInactivityMarksTimedOutPupsInactive(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $thirtySecondsAgo = new \DateTimeImmutable('-30 seconds');
        $this->pupRegistry->forceHeartbeatAt('pup-1', $thirtySecondsAgo);
        $now = new \DateTimeImmutable();

        $this->dispatcher->checkInactivity(15, $now);

        $pup = $this->pupRegistry->find('pup-1');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Inactive, $pup->status);
    }

    public function testCheckInactivitySkipsRecentlyHeartbeatedPups(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $fiveSecondsAgo = new \DateTimeImmutable('-5 seconds');
        $this->pupRegistry->forceHeartbeatAt('pup-1', $fiveSecondsAgo);
        $now = new \DateTimeImmutable();

        $this->dispatcher->checkInactivity(15, $now);

        $pup = $this->pupRegistry->find('pup-1');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Idle, $pup->status);
    }

    public function testCheckInactivityReleasesAssignedIssue(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->issueStore->add($this->makeIssue(id: 1, repo: 'org/repo'));
        $this->issueStore->assign(1, 'org/repo', 'pup-1');
        $thirtySecondsAgo = new \DateTimeImmutable('-30 seconds');
        $this->pupRegistry->forceHeartbeatAt('pup-1', $thirtySecondsAgo);
        $now = new \DateTimeImmutable();

        $this->dispatcher->checkInactivity(15, $now);

        $issue = $this->issueStore->getByPupId('pup-1');
        $this->assertNull($issue);
    }

    public function testCheckInactivityReturnsTimedOutPupIds(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->pupRegistry->register(pupId: 'pup-2', hostname: 'host-2');
        $thirtySecondsAgo = new \DateTimeImmutable('-30 seconds');
        $recentTime       = new \DateTimeImmutable('-5 seconds');
        $this->pupRegistry->forceHeartbeatAt('pup-1', $thirtySecondsAgo);
        $this->pupRegistry->forceHeartbeatAt('pup-2', $recentTime);
        $now = new \DateTimeImmutable();

        $timedOut = $this->dispatcher->checkInactivity(15, $now);

        $this->assertSame(['pup-1'], $timedOut);
    }

    protected function setUp(): void
    {
        $this->issueStore  = new IssueStore();
        $this->pupRegistry = new PupRegistry();
        $this->dispatcher  = new Dispatcher($this->issueStore, $this->pupRegistry);
    }

    private function makeIssue(int $id, string $repo): Issue
    {
        return new Issue(
            id: $id,
            repo: $repo,
            title: "Issue {$id}",
            body: 'body',
            labels: [],
            column: 'todo',
        );
    }
}
