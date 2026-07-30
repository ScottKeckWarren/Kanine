<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Board;

use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use ScottKeckWarren\Kanine\Domain\Issue;

final class CardRenderer
{
    public function render(Issue $issue, bool $hasOpenQuestion = false): ParagraphWidget
    {
        $pupPart      = $issue->assignedPupId !== null ? " [{$issue->assignedPupId}]" : '';
        $pinnedPart   = $issue->pinned ? ' 📌' : '';
        $questionPart = $hasOpenQuestion ? ' [?]' : '';
        $text         = "#{$issue->id} {$issue->title}{$pinnedPart}{$pupPart}{$questionPart}\n[{$issue->repo}]";

        return ParagraphWidget::fromString($text);
    }
}
