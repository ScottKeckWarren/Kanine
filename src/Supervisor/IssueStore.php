<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use ScottKeckWarren\Kanine\Domain\Issue;

final class IssueStore
{
    /** @var array<string, Issue> */
    private array $issues = [];

    public function add(Issue $issue): void
    {
        $this->issues[$this->key($issue->id, $issue->repo)] = $issue;
    }

    /**
     * @return list<Issue>
     */
    public function getAll(): array
    {
        return array_values($this->issues);
    }

    /**
     * @return list<Issue>
     */
    public function getEligible(): array
    {
        return array_values(
            array_filter(
                $this->issues,
                fn (Issue $issue): bool => !$issue->pinned && $issue->assignedPupId === null,
            )
        );
    }

    public function pin(int $id, string $repo): void
    {
        $key = $this->key($id, $repo);
        $issue = $this->issues[$key];

        $this->issues[$key] = new Issue(
            id: $issue->id,
            repo: $issue->repo,
            title: $issue->title,
            body: $issue->body,
            labels: $issue->labels,
            column: $issue->column,
            pinned: true,
            assignedPupId: $issue->assignedPupId,
            fetchedAt: $issue->fetchedAt,
        );
    }

    public function unpin(int $id, string $repo): void
    {
        $key = $this->key($id, $repo);
        $issue = $this->issues[$key];

        $this->issues[$key] = new Issue(
            id: $issue->id,
            repo: $issue->repo,
            title: $issue->title,
            body: $issue->body,
            labels: $issue->labels,
            column: $issue->column,
            pinned: false,
            assignedPupId: $issue->assignedPupId,
            fetchedAt: $issue->fetchedAt,
        );
    }

    public function assign(int $id, string $repo, string $pupId): void
    {
        $key = $this->key($id, $repo);
        $issue = $this->issues[$key];

        $this->issues[$key] = new Issue(
            id: $issue->id,
            repo: $issue->repo,
            title: $issue->title,
            body: $issue->body,
            labels: $issue->labels,
            column: $issue->column,
            pinned: $issue->pinned,
            assignedPupId: $pupId,
            fetchedAt: $issue->fetchedAt,
        );
    }

    public function unassign(int $id, string $repo): void
    {
        $key = $this->key($id, $repo);
        $issue = $this->issues[$key];

        $this->issues[$key] = new Issue(
            id: $issue->id,
            repo: $issue->repo,
            title: $issue->title,
            body: $issue->body,
            labels: $issue->labels,
            column: $issue->column,
            pinned: $issue->pinned,
            assignedPupId: null,
            fetchedAt: $issue->fetchedAt,
        );
    }

    public function getByPupId(string $pupId): ?Issue
    {
        foreach ($this->issues as $issue) {
            if ($issue->assignedPupId === $pupId) {
                return $issue;
            }
        }

        return null;
    }

    private function key(int $id, string $repo): string
    {
        return "{$repo}#{$id}";
    }
}
