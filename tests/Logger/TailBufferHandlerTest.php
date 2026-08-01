<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Logger;

use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Logger\LogTailProvider;
use ScottKeckWarren\Kanine\Logger\TailBufferHandler;

final class TailBufferHandlerTest extends TestCase
{
    public function testImplementsLogTailProvider(): void
    {
        $handler = new TailBufferHandler();

        $this->assertInstanceOf(LogTailProvider::class, $handler);
    }

    public function testTailReturnsEmptyArrayWhenNothingLogged(): void
    {
        $handler = new TailBufferHandler();

        $this->assertSame([], $handler->tail(5));
    }

    public function testTailReturnsLoggedMessageAfterOneEntry(): void
    {
        $handler = new TailBufferHandler();
        $logger  = new Logger('test', [$handler]);

        $logger->info('first message');

        $tail = $handler->tail(5);
        $this->assertCount(1, $tail);
        $this->assertStringContainsString('first message', $tail[0]);
    }

    public function testTailIncludesLevelName(): void
    {
        $handler = new TailBufferHandler();
        $logger  = new Logger('test', [$handler]);

        $logger->warning('careful now');

        $tail = $handler->tail(5);
        $this->assertStringContainsString('WARNING', $tail[0]);
    }

    public function testTailReturnsOnlyTheRequestedNumberOfMostRecentLines(): void
    {
        $handler = new TailBufferHandler();
        $logger  = new Logger('test', [$handler]);

        foreach (range(1, 10) as $i) {
            $logger->info("message {$i}");
        }

        $tail = $handler->tail(5);

        $this->assertCount(5, $tail);
        $this->assertStringContainsString('message 6', $tail[0]);
        $this->assertStringContainsString('message 10', $tail[4]);
    }

    public function testTailPreservesChronologicalOrder(): void
    {
        $handler = new TailBufferHandler();
        $logger  = new Logger('test', [$handler]);

        $logger->info('alpha');
        $logger->info('beta');
        $logger->info('gamma');

        $tail = $handler->tail(3);

        $this->assertStringContainsString('alpha', $tail[0]);
        $this->assertStringContainsString('beta', $tail[1]);
        $this->assertStringContainsString('gamma', $tail[2]);
    }

    public function testHandlerRespectsMinimumLevel(): void
    {
        $handler = new TailBufferHandler(level: Level::Warning);
        $logger  = new Logger('test', [$handler]);

        $logger->debug('should be ignored');
        $logger->warning('should be captured');

        $tail = $handler->tail(5);

        $this->assertCount(1, $tail);
        $this->assertStringContainsString('should be captured', $tail[0]);
    }

    public function testBufferDoesNotGrowUnboundedWithManyMessages(): void
    {
        $handler = new TailBufferHandler();
        $logger  = new Logger('test', [$handler]);

        foreach (range(1, 500) as $i) {
            $logger->info("msg {$i}");
        }

        $tail = $handler->tail(500);

        $this->assertLessThanOrEqual(250, count($tail));
    }
}
