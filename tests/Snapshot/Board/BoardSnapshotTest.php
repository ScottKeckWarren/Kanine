<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Snapshot\Board;

use DateTimeImmutable;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Model\Direction;
use PhpTui\Tui\Model\Display\Backend\DummyBackend;
use PhpTui\Tui\Model\Display\Display;
use PhpTui\Tui\Model\Layout\Constraint;
use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Board\BoardRenderer;
use ScottKeckWarren\Kanine\Board\CardRenderer;
use ScottKeckWarren\Kanine\Board\FooterRenderer;
use ScottKeckWarren\Kanine\Domain\Column;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;

/**
 * Pure rendering snapshot tests: BoardRenderer + FooterRenderer are composed
 * into a single screen the same way BoardLoop::render() does, but without
 * going through BoardLoop itself, and the resulting terminal buffer is
 * asserted against a hand-written expected fixture. CardRenderer is
 * exercised directly to confirm the pin indicator it draws matches what the
 * board's own card text shows.
 */
final class BoardSnapshotTest extends TestCase
{
    private const string EXPECTED_EMPTY = '┌Backlog─────────────────────┐┌In Progress─────────────────┐
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
└────────────────────────────┘└────────────────────────────┘
Board  last-sync: 10:30:00  pups: 0                         ';

    private const string EXPECTED_PINNED = '┌Backlog─────────────────────┐┌In Progress─────────────────┐
│#42 Pin me 📌                ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
└────────────────────────────┘└────────────────────────────┘
Board  last-sync: 10:30:00  pups: 0  ⚠ No pups available    ';

    private const string EXPECTED_PINNED_CARD = '#42 Pin me 📌                  
[owner/repo]                  
                              ';

    private const string EXPECTED_MIXED = '┌Backlog─────────────────────┐┌In Progress─────────────────┐
│#1 Idle issue               ││#2 Assigned issue [pup-1]   │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
│                            ││                            │
└────────────────────────────┘└────────────────────────────┘
Board  last-sync: 10:30:00  pups: 2                         ';


    private BoardRenderer $boardRenderer;
    private FooterRenderer $footerRenderer;
    private CardRenderer $cardRenderer;

    /** @var list<Column> */
    private array $columns;

    public function testEmptyBoardWithConfiguredColumnsAndNoIssues(): void
    {
        $backend = DummyBackend::fromDimensions(60, 10);
        $display = DisplayBuilder::default($backend)->build();

        $issuesByColumn = $this->boardRenderer->groupByColumn(new IssueStore());

        $this->draw(
            $display,
            $this->boardRenderer->render($issuesByColumn),
            $this->footerRenderer->render('Board', $this->now(), pupCount: 0, warning: null),
        );

        $this->assertSame(self::EXPECTED_EMPTY, $backend->toString());
    }

    public function testBoardWithOnePinnedIssueShowsPinIndicator(): void
    {
        $backend = DummyBackend::fromDimensions(60, 10);
        $display = DisplayBuilder::default($backend)->build();

        $issueStore = new IssueStore();
        $issueStore->add(new Issue(
            id: 42,
            repo: 'owner/repo',
            title: 'Pin me',
            body: '',
            labels: [],
            column: 'Backlog',
            pinned: true,
        ));

        $issuesByColumn = $this->boardRenderer->groupByColumn($issueStore);

        $this->draw(
            $display,
            $this->boardRenderer->render($issuesByColumn),
            $this->footerRenderer->render('Board', $this->now(), pupCount: 0, warning: 'No pups available'),
        );

        $this->assertSame(self::EXPECTED_PINNED, $backend->toString());

        $cardBackend = DummyBackend::fromDimensions(30, 3);
        $cardDisplay = DisplayBuilder::default($cardBackend)->build();
        $cardDisplay->draw($this->cardRenderer->render($issueStore->getAll()[0]));

        $this->assertSame(self::EXPECTED_PINNED_CARD, $cardBackend->toString());
    }

    public function testBoardWithAssignedAndIdleIssuesShowsLivePupCountInFooter(): void
    {
        $backend = DummyBackend::fromDimensions(60, 10);
        $display = DisplayBuilder::default($backend)->build();

        $issueStore = new IssueStore();
        $issueStore->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Idle issue',
            body: '',
            labels: [],
            column: 'Backlog',
        ));
        $issueStore->add(new Issue(
            id: 2,
            repo: 'owner/repo',
            title: 'Assigned issue',
            body: '',
            labels: [],
            column: 'In Progress',
            assignedPupId: 'pup-1',
        ));

        $pupRegistry = new PupRegistry();
        $pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $pupRegistry->register(pupId: 'pup-2', hostname: 'host-2');

        $issuesByColumn = $this->boardRenderer->groupByColumn($issueStore);

        $this->draw(
            $display,
            $this->boardRenderer->render($issuesByColumn),
            $this->footerRenderer->render(
                'Board',
                $this->now(),
                pupCount: $pupRegistry->activePupCount(),
                warning: null,
            ),
        );

        $this->assertSame(self::EXPECTED_MIXED, $backend->toString());
    }

    protected function setUp(): void
    {
        $this->columns = [
            new Column(name: 'Backlog', label: 'status: backlog', position: 0),
            new Column(name: 'In Progress', label: 'status: in progress', position: 1),
        ];
        $this->boardRenderer  = new BoardRenderer($this->columns);
        $this->footerRenderer = new FooterRenderer();
        $this->cardRenderer   = new CardRenderer();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2024-01-15 10:30:00');
    }

    private function draw(Display $display, GridWidget $board, ParagraphWidget $footer): void
    {
        $screen = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(1))
            ->widgets($board, $footer);

        $display->draw($screen);
    }
}
