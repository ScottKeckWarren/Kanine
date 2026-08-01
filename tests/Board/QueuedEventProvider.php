<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Board;

use PhpTui\Term\Event;
use PhpTui\Term\EventProvider;

final class QueuedEventProvider implements EventProvider
{
    /**
     * @param list<Event> $events
     */
    public function __construct(private array $events)
    {
    }

    public function next(): ?Event
    {
        return array_shift($this->events);
    }
}
