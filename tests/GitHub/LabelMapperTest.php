<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\GitHub;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Column;
use ScottKeckWarren\Kanine\GitHub\LabelMapper;

final class LabelMapperTest extends TestCase
{
    public function testReturnsColumnNameWhenLabelMatches(): void
    {
        $columns = [
            new Column(name: 'Todo', label: 'status: todo', position: 1),
            new Column(name: 'Backlog', label: 'status: backlog', position: 0),
        ];
        $mapper = new LabelMapper($columns);

        $result = $mapper->resolveColumn(['status: todo']);

        $this->assertSame('Todo', $result);
    }

    public function testReturnsFallbackColumnWhenNoLabelMatches(): void
    {
        $columns = [
            new Column(name: 'Backlog', label: 'status: backlog', position: 0),
            new Column(name: 'Todo', label: 'status: todo', position: 1),
        ];
        $mapper = new LabelMapper($columns);

        $result = $mapper->resolveColumn(['unrelated-label']);

        $this->assertSame('Backlog', $result);
    }

    public function testMatchesFirstColumnWhenMultipleLabelsMatch(): void
    {
        $columns = [
            new Column(name: 'Backlog', label: 'status: backlog', position: 0),
            new Column(name: 'Todo', label: 'status: todo', position: 1),
        ];
        $mapper = new LabelMapper($columns);

        $result = $mapper->resolveColumn(['status: todo', 'status: backlog']);

        $this->assertSame('Backlog', $result);
    }

    public function testEmptyLabelsReturnsFallback(): void
    {
        $columns = [
            new Column(name: 'Backlog', label: 'status: backlog', position: 0),
            new Column(name: 'Todo', label: 'status: todo', position: 1),
        ];
        $mapper = new LabelMapper($columns);

        $result = $mapper->resolveColumn([]);

        $this->assertSame('Backlog', $result);
    }
}
