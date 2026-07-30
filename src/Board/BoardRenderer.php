<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Board;

use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Model\Direction;
use PhpTui\Tui\Model\Layout\Constraint;
use PhpTui\Tui\Model\Text\Text;
use PhpTui\Tui\Model\Text\Title;
use PhpTui\Tui\Model\Widget\Borders;
use ScottKeckWarren\Kanine\Domain\Column;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;

final class BoardRenderer
{
    /**
     * @param list<Column> $columns
     */
    public function __construct(private readonly array $columns)
    {
    }

    /**
     * @param array<string, list<Issue>> $issuesByColumn
     */
    public function render(
        array $issuesByColumn,
        ?string $selectedColumn = null,
        ?int $selectedCard = null,
    ): GridWidget {
        $columnWidgets = [];
        $constraints   = [];
        $columnCount   = count($this->columns);
        $pct           = $columnCount > 0 ? (int) (100 / $columnCount) : 100;

        foreach ($this->columns as $column) {
            $issues = $issuesByColumn[$column->name] ?? [];
            $items  = array_map(
                fn (Issue $issue): ListItem => ListItem::new(Text::fromString(
                    "#{$issue->id} {$issue->title}"
                    . ($issue->assignedPupId !== null ? " [{$issue->assignedPupId}]" : '')
                    . ($issue->pinned ? ' 📌' : '')
                )),
                $issues,
            );

            $list  = ListWidget::default()->items(...$items);
            $block = BlockWidget::default()
                ->titles(Title::fromString($column->name))
                ->borders(Borders::ALL)
                ->widget($list);

            $columnWidgets[] = $block;
            $constraints[]   = Constraint::percentage($pct);
        }

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(...$constraints)
            ->widgets(...$columnWidgets);
    }

    /**
     * @return array<string, list<Issue>>
     */
    public function groupByColumn(IssueStore $store): array
    {
        $groups = [];

        foreach ($this->columns as $col) {
            $groups[$col->name] = [];
        }

        foreach ($store->getAll() as $issue) {
            $groups[$issue->column][] = $issue;
        }

        return $groups;
    }
}
