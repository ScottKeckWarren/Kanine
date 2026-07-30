<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Column;

final class ColumnTest extends TestCase
{
    public function testNameIsAccessible(): void
    {
        $column = new Column(name: 'Backlog', label: 'status: backlog', position: 0);

        $this->assertSame('Backlog', $column->name);
    }

    public function testLabelIsAccessible(): void
    {
        $column = new Column(name: 'Backlog', label: 'status: backlog', position: 0);

        $this->assertSame('status: backlog', $column->label);
    }

    public function testPositionIsAccessible(): void
    {
        $column = new Column(name: 'In Progress', label: 'status: in-progress', position: 2);

        $this->assertSame(2, $column->position);
    }
}
