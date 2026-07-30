<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Board;

use PHPUnit\Framework\TestCase;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use ScottKeckWarren\Kanine\Board\BoardRenderer;
use ScottKeckWarren\Kanine\Domain\Column;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;

final class BoardRendererTest extends TestCase
{
    public function testRenderReturnsGridWidget(): void
    {
        $columns  = [
            new Column(name: 'Backlog', label: 'backlog', position: 0),
            new Column(name: 'In Progress', label: 'in-progress', position: 1),
        ];
        $renderer = new BoardRenderer($columns);

        $result = $renderer->render(issuesByColumn: []);

        $this->assertInstanceOf(GridWidget::class, $result);
    }

    public function testRenderCreatesCorrectNumberOfColumnWidgets(): void
    {
        $columns  = [
            new Column(name: 'Backlog', label: 'backlog', position: 0),
            new Column(name: 'In Progress', label: 'in-progress', position: 1),
            new Column(name: 'Done', label: 'done', position: 2),
        ];
        $renderer = new BoardRenderer($columns);

        $result = $renderer->render(issuesByColumn: []);

        $this->assertCount(3, $result->constraints);
        $this->assertCount(3, $result->widgets);
    }

    public function testGroupByColumnGroupsIssuesByColumn(): void
    {
        $columns  = [
            new Column(name: 'Backlog', label: 'backlog', position: 0),
            new Column(name: 'In Progress', label: 'in-progress', position: 1),
        ];
        $renderer = new BoardRenderer($columns);

        $store = new IssueStore();
        $store->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Backlog issue',
            body: '',
            labels: [],
            column: 'Backlog',
        ));
        $store->add(new Issue(
            id: 2,
            repo: 'owner/repo',
            title: 'In-progress issue',
            body: '',
            labels: [],
            column: 'In Progress',
        ));

        $groups = $renderer->groupByColumn($store);

        $this->assertCount(1, $groups['Backlog']);
        $this->assertSame(1, $groups['Backlog'][0]->id);
        $this->assertCount(1, $groups['In Progress']);
        $this->assertSame(2, $groups['In Progress'][0]->id);
    }

    public function testGroupByColumnIncludesEmptyColumnsWithNoIssues(): void
    {
        $columns  = [
            new Column(name: 'Backlog', label: 'backlog', position: 0),
            new Column(name: 'In Progress', label: 'in-progress', position: 1),
        ];
        $renderer = new BoardRenderer($columns);

        $store = new IssueStore();
        $store->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Backlog only',
            body: '',
            labels: [],
            column: 'Backlog',
        ));

        $groups = $renderer->groupByColumn($store);

        $this->assertArrayHasKey('In Progress', $groups);
        $this->assertSame([], $groups['In Progress']);
    }
}
