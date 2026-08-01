<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Board;

use PhpTui\Term\Event;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;

/**
 * Translates raw terminal key events into board actions, and applies pure
 * navigation/toggle transitions to a BoardState. Actions with side effects
 * (quit, refresh, pin) are reported but left for the caller to execute.
 */
final class KeyboardHandler
{
    public function translateEvent(Event $event): ?string
    {
        if ($event instanceof CharKeyEvent) {
            return match ($event->char) {
                'q'     => 'quit',
                'r'     => 'refresh',
                'a'     => 'toggle-auto-refresh',
                'p'     => 'pin',
                default => null,
            };
        }

        if ($event instanceof CodedKeyEvent) {
            return match ($event->code) {
                KeyCode::Up    => 'nav-up',
                KeyCode::Down  => 'nav-down',
                KeyCode::Left  => 'nav-left',
                KeyCode::Right => 'nav-right',
                default        => null,
            };
        }

        return null;
    }

    /**
     * @param list<int> $cardCountsByColumn number of cards in each column, indexed by column position
     */
    public function nextState(
        string $action,
        BoardState $state,
        int $columnCount,
        array $cardCountsByColumn,
    ): BoardState {
        return match ($action) {
            'nav-left'  => $this->navigateColumn($state, $state->columnIndex - 1),
            'nav-right' => $this->navigateColumn($state, min($state->columnIndex + 1, max(0, $columnCount - 1))),
            'nav-up'    => $state->withCardIndex(max(0, $state->cardIndex - 1)),
            'nav-down'  => $state->withCardIndex($this->clampCardIndex(
                $state->cardIndex + 1,
                $cardCountsByColumn[$state->columnIndex] ?? 0,
            )),
            'toggle-auto-refresh' => $state->withAutoRefresh(!$state->autoRefresh),
            default => $state,
        };
    }

    private function navigateColumn(BoardState $state, int $columnIndex): BoardState
    {
        return $state->withColumnIndex(max(0, $columnIndex))->withCardIndex(0);
    }

    private function clampCardIndex(int $desiredIndex, int $cardCount): int
    {
        if ($cardCount <= 0) {
            return 0;
        }

        return min($desiredIndex, $cardCount - 1);
    }
}
