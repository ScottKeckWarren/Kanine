<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Board;

use DateTimeImmutable;
use PhpTui\Term\EventProvider;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Model\Direction;
use PhpTui\Tui\Model\Display\Display;
use PhpTui\Tui\Model\Layout\Constraint;
use PhpTui\Tui\Model\Text\Title;
use PhpTui\Tui\Model\Widget\Borders;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Domain\Column;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Logger\LogTailProvider;
use ScottKeckWarren\Kanine\Supervisor\Dispatcher;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;

/**
 * Composes render, dispatch, and keyboard-handling ticks for the supervisor
 * board. All public methods here are pure/side-effect-scoped and unit
 * testable; the blocking ReactPHP wiring lives outside this class.
 */
final class BoardLoop
{
    private const string NO_PUPS_WARNING = 'No pups available';
    private const int LOG_TAIL_LINES = 5;

    private BoardState $state;

    /**
     * @param list<Column> $columns
     */
    public function __construct(
        private readonly BoardRenderer $boardRenderer,
        private readonly FooterRenderer $footerRenderer,
        private readonly IssueStore $issueStore,
        private readonly PupRegistry $pupRegistry,
        private readonly Dispatcher $dispatcher,
        private readonly KeyboardHandler $keyboardHandler,
        private readonly array $columns,
        private readonly LoggerInterface $logger,
        private readonly Display $display,
        private readonly EventProvider $eventProvider,
        private readonly int $pupTimeoutSeconds = 15,
        private readonly ?LogTailProvider $logTail = null,
    ) {
        $this->state = BoardState::initial();
    }

    public function state(): BoardState
    {
        return $this->state;
    }

    public function render(?DateTimeImmutable $now = null): void
    {
        $now = $now ?? new DateTimeImmutable();

        $issuesByColumn = $this->boardRenderer->groupByColumn($this->issueStore);
        $selectedColumn = $this->columns[$this->state->columnIndex]->name ?? null;

        $board = $this->boardRenderer->render(
            issuesByColumn: $issuesByColumn,
            selectedColumn: $selectedColumn,
            selectedCard: $this->state->cardIndex,
        );

        $pupCount = $this->pupRegistry->activePupCount();
        $warning  = ($pupCount === 0 && $this->issueStore->getEligible() !== [])
            ? self::NO_PUPS_WARNING
            : null;

        $footer = $this->footerRenderer->render(
            viewName: 'Board',
            lastSync: $now,
            pupCount: $pupCount,
            warning: $warning,
        );

        $rows        = [$board];
        $constraints = [Constraint::min(0)];

        if ($this->logTail !== null) {
            $rows[]        = $this->renderLogPanel();
            $constraints[] = Constraint::length(self::LOG_TAIL_LINES + 2);
        }

        $rows[]        = $footer;
        $constraints[] = Constraint::length(1);

        $screen = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(...$rows);

        $this->display->draw($screen);
    }

    /**
     * Releases assignments held by timed-out pups, then assigns eligible
     * issues to idle pups. Returns the number of new assignments made.
     */
    public function dispatch(): int
    {
        $timedOut = $this->dispatcher->checkInactivity($this->pupTimeoutSeconds);

        foreach ($timedOut as $pupId) {
            $this->logger->info("Pup {$pupId} timed out — assignment released");
        }

        $assigned = $this->dispatcher->dispatch();

        if ($assigned > 0) {
            $this->logger->info("Dispatched {$assigned} issue(s) to idle pup(s)");
        }

        return $assigned;
    }

    /**
     * Drains all pending keyboard events and applies them. Returns true if
     * the operator requested quit.
     */
    public function processKeyboard(): bool
    {
        while (($event = $this->eventProvider->next()) !== null) {
            $action = $this->keyboardHandler->translateEvent($event);

            if ($action === null) {
                continue;
            }

            if ($action === 'quit') {
                return true;
            }

            if ($action === 'pin') {
                $this->togglePinOnSelectedIssue();
                continue;
            }

            $this->state = $this->keyboardHandler->nextState(
                $action,
                $this->state,
                columnCount: count($this->columns),
                cardCountsByColumn: $this->cardCountsByColumn(),
            );
        }

        return false;
    }

    public function selectedIssue(): ?Issue
    {
        $column = $this->columns[$this->state->columnIndex] ?? null;

        if ($column === null) {
            return null;
        }

        $issues = $this->boardRenderer->groupByColumn($this->issueStore)[$column->name] ?? [];

        return $issues[$this->state->cardIndex] ?? null;
    }

    private function togglePinOnSelectedIssue(): void
    {
        $issue = $this->selectedIssue();

        if ($issue === null) {
            return;
        }

        if ($issue->pinned) {
            $this->issueStore->unpin($issue->id, $issue->repo);
        } else {
            $this->issueStore->pin($issue->id, $issue->repo);
        }
    }

    /**
     * @return list<int>
     */
    private function cardCountsByColumn(): array
    {
        $issuesByColumn = $this->boardRenderer->groupByColumn($this->issueStore);

        return array_map(
            fn (Column $column): int => count($issuesByColumn[$column->name] ?? []),
            $this->columns,
        );
    }

    private function renderLogPanel(): BlockWidget
    {
        $lines = $this->logTail?->tail(self::LOG_TAIL_LINES) ?? [];
        $text  = $lines !== [] ? implode("\n", $lines) : '';

        return BlockWidget::default()
            ->titles(Title::fromString('Log'))
            ->borders(Borders::ALL)
            ->widget(ParagraphWidget::fromString($text));
    }
}
