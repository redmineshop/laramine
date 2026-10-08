<?php

namespace App\Domain\Reactions;

use App\Models\User;

/**
 * Reactions on one record as one viewer sees them.
 *
 * `users` are the reacting users the viewer can see, newest reaction first.
 * `count` is the size of that list, so a reaction from a user the viewer
 * cannot see is not counted. `ownReactionId` is the viewer's own reaction.
 */
final class ReactionDetail
{
    /**
     * @param  list<User>  $users
     */
    public function __construct(
        public readonly array $users = [],
        public readonly ?int $ownReactionId = null,
    ) {}

    public function count(): int
    {
        return count($this->users);
    }
}
