<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Board;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Board\BoardState;

final class BoardStateTest extends TestCase
{
    public function testDefaultStateStartsAtOriginWithAutoRefreshEnabled(): void
    {
        $state = BoardState::initial();

        $this->assertSame(0, $state->columnIndex);
        $this->assertSame(0, $state->cardIndex);
        $this->assertTrue($state->autoRefresh);
    }

    public function testWithColumnIndexReturnsNewInstanceWithUpdatedColumnIndex(): void
    {
        $state    = BoardState::initial();
        $newState = $state->withColumnIndex(2);

        $this->assertSame(2, $newState->columnIndex);
        $this->assertSame(0, $state->columnIndex, 'original state must remain unchanged');
    }

    public function testWithCardIndexReturnsNewInstanceWithUpdatedCardIndex(): void
    {
        $state    = BoardState::initial();
        $newState = $state->withCardIndex(3);

        $this->assertSame(3, $newState->cardIndex);
        $this->assertSame(0, $state->cardIndex);
    }

    public function testWithAutoRefreshReturnsNewInstanceWithUpdatedFlag(): void
    {
        $state    = BoardState::initial();
        $newState = $state->withAutoRefresh(false);

        $this->assertFalse($newState->autoRefresh);
        $this->assertTrue($state->autoRefresh);
    }
}
