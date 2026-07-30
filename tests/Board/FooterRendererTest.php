<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Board;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use ScottKeckWarren\Kanine\Board\FooterRenderer;

final class FooterRendererTest extends TestCase
{
    public function testRenderReturnsParagraphWidget(): void
    {
        $renderer = new FooterRenderer();

        $result = $renderer->render(
            viewName: 'Board',
            lastSync: new DateTimeImmutable('2024-01-15 10:30:00'),
            pupCount: 3,
        );

        $this->assertInstanceOf(ParagraphWidget::class, $result);
    }

    public function testRenderWithWarningReturnsParagraphWidget(): void
    {
        $renderer = new FooterRenderer();

        $result = $renderer->render(
            viewName: 'Board',
            lastSync: new DateTimeImmutable('2024-01-15 10:30:00'),
            pupCount: 2,
            warning: 'Rate limit approaching',
        );

        $this->assertInstanceOf(ParagraphWidget::class, $result);
    }
}
