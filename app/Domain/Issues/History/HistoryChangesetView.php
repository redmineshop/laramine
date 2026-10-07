<?php

namespace App\Domain\Issues\History;

/**
 * One associated revision on the issue history tab.
 */
final readonly class HistoryChangesetView
{
    public function __construct(
        public int $id,
        public string $revision,
        public ?string $comments,
        public string $committedOn,
        public ?string $committer,
        public ?string $authorName,
        public ?string $repositoryIdentifier,
    ) {}
}
