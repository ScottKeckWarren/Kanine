<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Board;

use DateTimeImmutable;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\EventProvider;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Model\Display\Backend\DummyBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ScottKeckWarren\Kanine\Board\BoardLoop;
use ScottKeckWarren\Kanine\Board\BoardRenderer;
use ScottKeckWarren\Kanine\Board\FooterRenderer;
use ScottKeckWarren\Kanine\Board\KeyboardHandler;
use ScottKeckWarren\Kanine\Domain\Column;
use ScottKeckWarren\Kanine\Domain\Issue;
use ScottKeckWarren\Kanine\Logger\LogTailProvider;
use ScottKeckWarren\Kanine\Supervisor\Dispatcher;
use ScottKeckWarren\Kanine\Supervisor\IssueStore;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;

final class BoardLoopTest extends TestCase
{
    private DummyBackend $backend;
    private IssueStore $issueStore;
    private PupRegistry $pupRegistry;

    /** @var list<Column> */
    private array $columns;

    // -------------------------------------------------------------------------
    // render
    // -------------------------------------------------------------------------

    public function testRenderDrawsIssueTitleToBackend(): void
    {
        $this->issueStore->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Fix the widget',
            body: '',
            labels: [],
            column: 'Backlog',
        ));

        $loop = $this->makeLoop();
        $loop->render(new DateTimeImmutable('2024-01-15 10:30:00'));

        $this->assertStringContainsString('Fix the widget', $this->backend->toString());
    }

    public function testRenderShowsNoPupsWarningWhenNoPupsAndEligibleIssues(): void
    {
        $this->issueStore->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Needs a pup',
            body: '',
            labels: [],
            column: 'Backlog',
        ));

        $loop = $this->makeLoop();
        $loop->render(new DateTimeImmutable('2024-01-15 10:30:00'));

        $this->assertStringContainsString('No pups available', $this->backend->toString());
    }

    public function testRenderOmitsWarningWhenPupsAreAvailable(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->issueStore->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Needs a pup',
            body: '',
            labels: [],
            column: 'Backlog',
        ));

        $loop = $this->makeLoop();
        $loop->render(new DateTimeImmutable('2024-01-15 10:30:00'));

        $this->assertStringNotContainsString('No pups available', $this->backend->toString());
    }

    public function testRenderOmitsWarningWhenNoEligibleIssuesExist(): void
    {
        $loop = $this->makeLoop();
        $loop->render(new DateTimeImmutable('2024-01-15 10:30:00'));

        $this->assertStringNotContainsString('No pups available', $this->backend->toString());
    }

    public function testRenderShowsLivePupCount(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->pupRegistry->register(pupId: 'pup-2', hostname: 'host-2');

        $loop = $this->makeLoop();
        $loop->render(new DateTimeImmutable('2024-01-15 10:30:00'));

        $this->assertStringContainsString('pups: 2', $this->backend->toString());
    }

    // -------------------------------------------------------------------------
    // render — log tail panel
    // -------------------------------------------------------------------------

    public function testRenderShowsLogTailLinesWhenProviderInjected(): void
    {
        $logTail = new class implements LogTailProvider {
            /**
             * @return list<string>
             */
            public function tail(int $lines): array
            {
                return ['12:00:00 INFO: dispatched issue #1', '12:00:05 INFO: pup registered'];
            }
        };

        $loop = $this->makeLoop(logTail: $logTail);
        $loop->render(new DateTimeImmutable('2024-01-15 10:30:00'));

        $this->assertStringContainsString('dispatched issue #1', $this->backend->toString());
        $this->assertStringContainsString('pup registered', $this->backend->toString());
    }

    public function testRenderOmitsLogPanelWhenNoProviderInjected(): void
    {
        $loop = $this->makeLoop();
        $loop->render(new DateTimeImmutable('2024-01-15 10:30:00'));

        // Board still renders fine without a log tail provider — no crash, no stray content.
        $this->assertStringContainsString('pups: 0', $this->backend->toString());
    }

    // -------------------------------------------------------------------------
    // dispatch
    // -------------------------------------------------------------------------

    public function testDispatchAssignsEligibleIssueToIdlePup(): void
    {
        $this->pupRegistry->register(pupId: 'pup-1', hostname: 'host-1');
        $this->issueStore->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Do the thing',
            body: '',
            labels: [],
            column: 'Backlog',
        ));

        $loop     = $this->makeLoop();
        $assigned = $loop->dispatch();

        $this->assertSame(1, $assigned);
        $this->assertNotNull($this->issueStore->getByPupId('pup-1'));
    }

    public function testDispatchReleasesTimedOutPupsBeforeAssigning(): void
    {
        $this->pupRegistry->register(pupId: 'stale-pup', hostname: 'host-1');
        $this->pupRegistry->forceHeartbeatAt('stale-pup', new DateTimeImmutable('-1 hour'));

        $loop = $this->makeLoop();
        $loop->dispatch();

        $this->assertSame(0, $this->pupRegistry->activePupCount());
    }

    // -------------------------------------------------------------------------
    // keyboard handling
    // -------------------------------------------------------------------------

    public function testProcessKeyboardReturnsTrueOnQuit(): void
    {
        $loop = $this->makeLoop(new QueuedEventProvider([CharKeyEvent::new('q')]));

        $this->assertTrue($loop->processKeyboard());
    }

    public function testProcessKeyboardReturnsFalseWhenNoQuitRequested(): void
    {
        $loop = $this->makeLoop(new QueuedEventProvider([CharKeyEvent::new('r')]));

        $this->assertFalse($loop->processKeyboard());
    }

    public function testProcessKeyboardMovesSelectionRight(): void
    {
        $loop = $this->makeLoop(new QueuedEventProvider([CodedKeyEvent::new(KeyCode::Right)]));

        $loop->processKeyboard();

        $this->assertSame(1, $loop->state()->columnIndex);
    }

    public function testProcessKeyboardTogglesAutoRefresh(): void
    {
        $loop = $this->makeLoop(new QueuedEventProvider([CharKeyEvent::new('a')]));

        $loop->processKeyboard();

        $this->assertFalse($loop->state()->autoRefresh);
    }

    public function testProcessKeyboardPinsSelectedIssue(): void
    {
        $this->issueStore->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Pin me',
            body: '',
            labels: [],
            column: 'Backlog',
        ));

        $loop = $this->makeLoop(new QueuedEventProvider([CharKeyEvent::new('p')]));
        $loop->processKeyboard();

        $issue = $this->issueStore->getAll()[0];
        $this->assertTrue($issue->pinned);
    }

    public function testProcessKeyboardUnpinsAlreadyPinnedSelectedIssue(): void
    {
        $this->issueStore->add(new Issue(
            id: 1,
            repo: 'owner/repo',
            title: 'Pin me',
            body: '',
            labels: [],
            column: 'Backlog',
            pinned: true,
        ));

        $loop = $this->makeLoop(new QueuedEventProvider([CharKeyEvent::new('p')]));
        $loop->processKeyboard();

        $issue = $this->issueStore->getAll()[0];
        $this->assertFalse($issue->pinned);
    }

    public function testProcessKeyboardDrainsMultipleQueuedEvents(): void
    {
        $loop = $this->makeLoop(new QueuedEventProvider([
            CodedKeyEvent::new(KeyCode::Right),
            CodedKeyEvent::new(KeyCode::Right),
        ]));

        $loop->processKeyboard();

        // Only 2 columns configured (index 0, 1) — second Right must not exceed bounds.
        $this->assertSame(1, $loop->state()->columnIndex);
    }

    protected function setUp(): void
    {
        $this->backend     = DummyBackend::fromDimensions(80, 24);
        $this->issueStore  = new IssueStore();
        $this->pupRegistry = new PupRegistry();
        $this->columns     = [
            new Column(name: 'Backlog', label: 'status: backlog', position: 0),
            new Column(name: 'In Progress', label: 'status: in progress', position: 1),
        ];
    }

    private function makeLoop(?EventProvider $eventProvider = null, ?LogTailProvider $logTail = null): BoardLoop
    {
        $display = DisplayBuilder::default($this->backend)->build();

        return new BoardLoop(
            boardRenderer: new BoardRenderer($this->columns),
            footerRenderer: new FooterRenderer(),
            issueStore: $this->issueStore,
            pupRegistry: $this->pupRegistry,
            dispatcher: new Dispatcher($this->issueStore, $this->pupRegistry),
            keyboardHandler: new KeyboardHandler(),
            columns: $this->columns,
            logger: new NullLogger(),
            display: $display,
            eventProvider: $eventProvider ?? new QueuedEventProvider([]),
            pupTimeoutSeconds: 15,
            logTail: $logTail,
        );
    }
}
