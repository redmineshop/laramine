<?php

namespace App\Domain\Reactions;

use App\Models\Project;

/**
 * One reactable record, named by its Redmine STI type string and id.
 *
 * `project` is the project the record belongs to: the issue project for an
 * issue or an issue journal, the news project for news and its comments, and
 * the board project for a forum message. It is null when the chain is broken.
 */
final class ReactionTarget
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly ?Project $project,
    ) {}
}
