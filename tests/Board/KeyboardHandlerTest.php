<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Board;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\CursorPositionEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Board\BoardState;
use ScottKeckWarren\Kanine\Board\KeyboardHandler;

final class KeyboardHandlerTest extends TestCase
{
    private KeyboardHandler $handler;

    // -------------------------------------------------------------------------
    // translateEvent
    // -------------------------------------------------------------------------

    public function testTranslatesLowercaseQToQuit(): void
    {
        $this->assertSame('quit', $this->handler->translateEvent(CharKeyEvent::new('q')));
    }

    public function testTranslatesRToRefresh(): void
    {
        $this->assertSame('refresh', $this->handler->translateEvent(CharKeyEvent::new('r')));
    }

    public function testTranslatesAToToggleAutoRefresh(): void
    {
        $this->assertSame('toggle-auto-refresh', $this->handler->translateEvent(CharKeyEvent::new('a')));
    }

    public function testTranslatesPToPin(): void
    {
        $this->assertSame('pin', $this->handler->translateEvent(CharKeyEvent::new('p')));
    }

    public function testTranslatesUpArrowToNavUp(): void
    {
        $this->assertSame('nav-up', $this->handler->translateEvent(CodedKeyEvent::new(KeyCode::Up)));
    }

    public function testTranslatesDownArrowToNavDown(): void
    {
        $this->assertSame('nav-down', $this->handler->translateEvent(CodedKeyEvent::new(KeyCode::Down)));
    }

    public function testTranslatesLeftArrowToNavLeft(): void
    {
        $this->assertSame('nav-left', $this->handler->translateEvent(CodedKeyEvent::new(KeyCode::Left)));
    }

    public function testTranslatesRightArrowToNavRight(): void
    {
        $this->assertSame('nav-right', $this->handler->translateEvent(CodedKeyEvent::new(KeyCode::Right)));
    }

    public function testTranslatesUnknownCharToNull(): void
    {
        $this->assertNull($this->handler->translateEvent(CharKeyEvent::new('z')));
    }

    public function testTranslatesUnknownEventToNull(): void
    {
        $this->assertNull($this->handler->translateEvent(new CursorPositionEvent(1, 1)));
    }

    // -------------------------------------------------------------------------
    // nextState — column navigation
    // -------------------------------------------------------------------------

    public function testNavRightAdvancesColumnAndResetsCardIndex(): void
    {
        $state = BoardState::initial()->withCardIndex(2);

        $next = $this->handler->nextState('nav-right', $state, columnCount: 3, cardCountsByColumn: [1, 1, 1]);

        $this->assertSame(1, $next->columnIndex);
        $this->assertSame(0, $next->cardIndex);
    }

    public function testNavRightDoesNotExceedLastColumn(): void
    {
        $state = BoardState::initial()->withColumnIndex(2);

        $next = $this->handler->nextState('nav-right', $state, columnCount: 3, cardCountsByColumn: [1, 1, 1]);

        $this->assertSame(2, $next->columnIndex);
    }

    public function testNavLeftRetreatsColumnAndResetsCardIndex(): void
    {
        $state = BoardState::initial()->withColumnIndex(2)->withCardIndex(2);

        $next = $this->handler->nextState('nav-left', $state, columnCount: 3, cardCountsByColumn: [1, 1, 1]);

        $this->assertSame(1, $next->columnIndex);
        $this->assertSame(0, $next->cardIndex);
    }

    public function testNavLeftDoesNotGoBelowZero(): void
    {
        $state = BoardState::initial();

        $next = $this->handler->nextState('nav-left', $state, columnCount: 3, cardCountsByColumn: [1, 1, 1]);

        $this->assertSame(0, $next->columnIndex);
    }

    // -------------------------------------------------------------------------
    // nextState — card navigation
    // -------------------------------------------------------------------------

    public function testNavDownAdvancesCardIndexWithinColumnBounds(): void
    {
        $state = BoardState::initial();

        $next = $this->handler->nextState('nav-down', $state, columnCount: 1, cardCountsByColumn: [3]);

        $this->assertSame(1, $next->cardIndex);
    }

    public function testNavDownDoesNotExceedLastCardInColumn(): void
    {
        $state = BoardState::initial()->withCardIndex(2);

        $next = $this->handler->nextState('nav-down', $state, columnCount: 1, cardCountsByColumn: [3]);

        $this->assertSame(2, $next->cardIndex);
    }

    public function testNavUpRetreatsCardIndex(): void
    {
        $state = BoardState::initial()->withCardIndex(2);

        $next = $this->handler->nextState('nav-up', $state, columnCount: 1, cardCountsByColumn: [3]);

        $this->assertSame(1, $next->cardIndex);
    }

    public function testNavUpDoesNotGoBelowZero(): void
    {
        $state = BoardState::initial();

        $next = $this->handler->nextState('nav-up', $state, columnCount: 1, cardCountsByColumn: [3]);

        $this->assertSame(0, $next->cardIndex);
    }

    // -------------------------------------------------------------------------
    // nextState — auto-refresh toggle
    // -------------------------------------------------------------------------

    public function testToggleAutoRefreshFlipsFlag(): void
    {
        $state = BoardState::initial();

        $next = $this->handler->nextState('toggle-auto-refresh', $state, columnCount: 1, cardCountsByColumn: [0]);

        $this->assertFalse($next->autoRefresh);
    }

    public function testToggleAutoRefreshTwiceRestoresFlag(): void
    {
        $state = BoardState::initial();

        $once  = $this->handler->nextState('toggle-auto-refresh', $state, columnCount: 1, cardCountsByColumn: [0]);
        $twice = $this->handler->nextState('toggle-auto-refresh', $once, columnCount: 1, cardCountsByColumn: [0]);

        $this->assertTrue($twice->autoRefresh);
    }

    // -------------------------------------------------------------------------
    // nextState — actions with side effects handled elsewhere leave state alone
    // -------------------------------------------------------------------------

    public function testQuitLeavesStateUnchanged(): void
    {
        $state = BoardState::initial()->withColumnIndex(1)->withCardIndex(1);

        $next = $this->handler->nextState('quit', $state, columnCount: 3, cardCountsByColumn: [1, 1, 1]);

        $this->assertSame($state->columnIndex, $next->columnIndex);
        $this->assertSame($state->cardIndex, $next->cardIndex);
        $this->assertSame($state->autoRefresh, $next->autoRefresh);
    }

    public function testPinLeavesStateUnchanged(): void
    {
        $state = BoardState::initial();

        $next = $this->handler->nextState('pin', $state, columnCount: 1, cardCountsByColumn: [1]);

        $this->assertSame($state->columnIndex, $next->columnIndex);
        $this->assertSame($state->cardIndex, $next->cardIndex);
    }

    protected function setUp(): void
    {
        $this->handler = new KeyboardHandler();
    }
}
