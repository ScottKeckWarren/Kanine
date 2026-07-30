<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Board;

use DateTimeImmutable;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;

final class FooterRenderer
{
    public function render(
        string $viewName,
        DateTimeImmutable $lastSync,
        int $pupCount,
        ?string $warning = null,
    ): ParagraphWidget {
        $syncStr = $lastSync->format('H:i:s');
        $text    = "{$viewName}  last-sync: {$syncStr}  pups: {$pupCount}";

        if ($warning !== null) {
            $text .= "  ⚠ {$warning}";
        }

        return ParagraphWidget::fromString($text);
    }
}
