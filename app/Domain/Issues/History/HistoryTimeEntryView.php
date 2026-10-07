<?php

namespace App\Domain\Issues\History;

/**
 * One spent-time row on the issue history tab.
 */
final readonly class HistoryTimeEntryView
{
    public function __construct(
        public int $id,
        public string $spentOn,
        public float $hours,
        public ?string $comments,
        public string $userName,
        public string $activityName,
    ) {}
}
