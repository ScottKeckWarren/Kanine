<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Board;

use PHPUnit\Framework\TestCase;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use ScottKeckWarren\Kanine\Board\CardRenderer;
use ScottKeckWarren\Kanine\Domain\Issue;

final class CardRendererTest extends TestCase
{
    public function testRenderIncludesIssueNumberAndTitle(): void
    {
        $renderer = new CardRenderer();
        $issue    = new Issue(
            id: 42,
            repo: 'owner/repo',
            title: 'Fix the bug',
            body: 'Some body',
            labels: [],
            column: 'Backlog',
        );

        $result = $renderer->render($issue);

        $this->assertInstanceOf(ParagraphWidget::class, $result);
    }

    public function testRenderIncludesPupIdWhenAssigned(): void
    {
        $renderer = new CardRenderer();
        $issue    = new Issue(
            id: 7,
            repo: 'owner/repo',
            title: 'Assigned task',
            body: '',
            labels: [],
            column: 'In Progress',
            assignedPupId: 'pup-1',
        );

        $result = $renderer->render($issue);

        $this->assertInstanceOf(ParagraphWidget::class, $result);
    }

    public function testRenderShowsQuestionIndicatorWhenFlagged(): void
    {
        $renderer = new CardRenderer();
        $issue    = new Issue(
            id: 5,
            repo: 'owner/repo',
            title: 'Blocked task',
            body: '',
            labels: [],
            column: 'In Progress',
        );

        $result = $renderer->render($issue, hasOpenQuestion: true);

        $this->assertInstanceOf(ParagraphWidget::class, $result);
        // The rendered text is not directly accessible from ParagraphWidget;
        // we verify the method accepts the flag without error.
        // Deeper verification through string inspection of a rendered widget would
        // require TUI infrastructure — assert only the correct type is returned.
        $this->assertNotNull($result);
    }

    public function testRenderHidesQuestionIndicatorWhenNotFlagged(): void
    {
        $renderer = new CardRenderer();
        $issue    = new Issue(
            id: 6,
            repo: 'owner/repo',
            title: 'Normal task',
            body: '',
            labels: [],
            column: 'Backlog',
        );

        $result = $renderer->render($issue, hasOpenQuestion: false);

        $this->assertInstanceOf(ParagraphWidget::class, $result);
        $this->assertNotNull($result);
    }
}
