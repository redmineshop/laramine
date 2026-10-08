<?php

namespace App\Domain\Reactions;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\Acl\UserVisibility;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Settings\SettingValue;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Thumbs-up reactions on issues, issue journals, news, news comments, and
 * forum messages (`reactions` table, Redmine 7.0.1 semantics).
 *
 * Seeing reactions needs the `reactions_enabled` setting (a missing row is on)
 * and a visible record. A journal follows its issue, so a private note on a
 * visible issue can still be reacted to. Adding or removing a reaction also
 * needs a signed-in user and an active project; a closed or archived project
 * is read-only. Counts and names only include users the viewer can see.
 */
final class ReactionService
{
    public const MISSING = 'Reaction target does not exist.';

    /**
     * Redmine STI names that accept a reaction.
     */
    public const TYPES = ['Journal', 'Issue', 'Message', 'News', 'Comment'];

    /**
     * Names listed in the tooltip before the remaining count.
     */
    public const TOOLTIP_USER_LIMIT = 10;

    public function __construct(
        private readonly SettingValue $settings,
        private readonly PermissionService $permissions,
        private readonly IssueVisibility $issues,
        private readonly UserVisibility $users,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->reactionsEnabled();
    }

    /**
     * Finds the record a reaction request names.
     *
     * An unknown type is a permission failure, as on the pin. A known type
     * with no row is a missing record.
     */
    public function target(string $type, int $id): ReactionTarget
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new PermissionDeniedException('reactions');
        }

        $project = match ($type) {
            'Issue' => $this->issueProject($id),
            'Journal' => $this->journalProject($id),
            'News' => $this->newsProject($id),
            'Comment' => $this->commentProject($id),
            default => $this->messageProject($id),
        };

        return new ReactionTarget($type, $id, $project);
    }

    public function visible(?User $viewer, ReactionTarget $target): bool
    {
        return $this->enabled() && $this->recordVisible($viewer, $target);
    }

    public function editable(?User $viewer, ReactionTarget $target): bool
    {
        return $viewer instanceof User
            && $this->permissions->isLoggedIn($viewer)
            && $this->visible($viewer, $target)
            && $target->project instanceof Project
            && (int) $target->project->status === Project::STATUS_ACTIVE;
    }

    /**
     * Adds the actor's reaction once. A second call keeps the first row.
     */
    public function react(User $actor, ReactionTarget $target): Reaction
    {
        $this->assertEditable($actor, $target);

        $existing = $this->own($actor, $target);
        if ($existing instanceof Reaction) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($actor, $target): Reaction {
                $reaction = new Reaction([
                    'reactable_type' => $target->type,
                    'reactable_id' => $target->id,
                    'user_id' => (int) $actor->id,
                ]);
                $reaction->save();

                return $reaction;
            });
        } catch (QueryException $exception) {
            // A concurrent request stored the same row first (unique index on
            // type, id, and user). Return that row.
            $existing = $this->own($actor, $target);
            if ($existing instanceof Reaction) {
                return $existing;
            }
            throw $exception;
        }
    }

    /**
     * Removes the actor's own reaction with this id on this record. Any other
     * id, or someone else's reaction, is ignored without an error.
     */
    public function unreact(User $actor, ReactionTarget $target, int $reactionId): void
    {
        $this->assertEditable($actor, $target);

        Reaction::query()
            ->where('reactable_type', $target->type)
            ->where('reactable_id', $target->id)
            ->where('user_id', (int) $actor->id)
            ->whereKey($reactionId)
            ->delete();
    }

    public function detail(?User $viewer, ReactionTarget $target): ReactionDetail
    {
        return $this->details($viewer, $target->type, [$target->id])[$target->id] ?? new ReactionDetail;
    }

    /**
     * Reaction details for several records of one type, keyed by record id.
     * Records with no visible reaction get an empty detail. Returns an empty
     * map when reactions are disabled.
     *
     * @param  list<int>  $ids
     * @return array<int, ReactionDetail>
     */
    public function details(?User $viewer, string $type, array $ids): array
    {
        if (! $this->enabled() || $ids === []) {
            return [];
        }

        $visibleUsers = $this->users->apply(User::query(), $viewer)->select('users.id');
        $rows = Reaction::query()
            ->where('reactable_type', $type)
            ->whereIn('reactable_id', $ids)
            ->whereIn('user_id', $visibleUsers)
            ->orderByDesc('id')
            ->get(['id', 'reactable_id', 'user_id']);

        $userIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int) $row->user_id;
        }
        $people = User::query()->whereIn('id', array_values(array_unique($userIds)))->get()->keyBy('id');

        /** @var array<int, list<User>> $users */
        $users = [];
        /** @var array<int, int> $own */
        $own = [];
        foreach ($rows as $row) {
            $recordId = (int) $row->reactable_id;
            $person = $people->get((int) $row->user_id);
            if (! $person instanceof User) {
                continue;
            }
            $users[$recordId][] = $person;
            if ($viewer instanceof User && (int) $person->id === (int) $viewer->id) {
                $own[$recordId] = (int) $row->id;
            }
        }

        $details = [];
        foreach ($ids as $id) {
            $details[$id] = new ReactionDetail($users[$id] ?? [], $own[$id] ?? null);
        }

        return $details;
    }

    /**
     * The control for one record, or null when the viewer cannot see
     * reactions on it.
     */
    public function button(?User $viewer, ReactionTarget $target): ?ReactionButton
    {
        if (! $this->visible($viewer, $target)) {
            return null;
        }

        $detail = $this->detail($viewer, $target);
        $count = $detail->count();
        $tooltip = $this->tooltip($detail);

        if (! $this->editable($viewer, $target)) {
            return new ReactionButton($target->type, $target->id, ReactionButton::READONLY, $count, $tooltip, null);
        }
        if ($detail->ownReactionId !== null) {
            return new ReactionButton($target->type, $target->id, ReactionButton::REACTED, $count, $tooltip, $detail->ownReactionId);
        }

        return new ReactionButton($target->type, $target->id, ReactionButton::NOT_REACTED, $count, $tooltip, null);
    }

    /**
     * Up to ten names, newest reaction first, then `1 other` or `N others`,
     * joined as an English sentence. Null when nobody visible reacted.
     */
    public function tooltip(ReactionDetail $detail): ?string
    {
        $count = $detail->count();
        if ($count === 0) {
            return null;
        }

        $names = [];
        foreach (array_slice($detail->users, 0, self::TOOLTIP_USER_LIMIT) as $user) {
            $names[] = self::name($user);
        }
        $others = $count - count($names);
        if ($others > 0) {
            $names[] = $others === 1 ? '1 other' : $others.' others';
        }

        return self::sentence($names);
    }

    /**
     * Deletes every reaction on these records.
     *
     * @param  list<int>  $ids
     */
    public static function forget(string $type, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        Reaction::query()->where('reactable_type', $type)->whereIn('reactable_id', $ids)->delete();
    }

    /**
     * Default `user_format` (`firstname_lastname`): first name, a space, then
     * last name, without trimming.
     */
    public static function name(User $user): string
    {
        return (string) $user->firstname.' '.(string) $user->lastname;
    }

    /**
     * English `to_sentence`: `a`, `a and b`, `a, b, and c`.
     *
     * @param  list<string>  $words
     */
    public static function sentence(array $words): string
    {
        $count = count($words);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $words[0];
        }
        if ($count === 2) {
            return $words[0].' and '.$words[1];
        }

        return implode(', ', array_slice($words, 0, -1)).', and '.$words[$count - 1];
    }

    private function assertEditable(User $actor, ReactionTarget $target): void
    {
        if (! $this->enabled()) {
            throw new PermissionDeniedException('reactions_enabled');
        }
        if (! $this->editable($actor, $target)) {
            throw new PermissionDeniedException('reactions');
        }
    }

    private function own(User $actor, ReactionTarget $target): ?Reaction
    {
        return Reaction::query()
            ->where('reactable_type', $target->type)
            ->where('reactable_id', $target->id)
            ->where('user_id', (int) $actor->id)
            ->first();
    }

    private function recordVisible(?User $viewer, ReactionTarget $target): bool
    {
        $project = $target->project;
        if (! $project instanceof Project) {
            return false;
        }

        return match ($target->type) {
            'Issue' => $this->issueVisible($viewer, $target->id),
            'Journal' => $this->issueVisible($viewer, $this->journalIssueId($target->id)),
            'News', 'Comment' => $this->permissions->allowed($viewer, 'view_news', $project),
            'Message' => $this->permissions->allowed($viewer, 'view_messages', $project),
            default => false,
        };
    }

    private function issueVisible(?User $viewer, ?int $issueId): bool
    {
        if ($issueId === null) {
            return false;
        }
        $issue = Issue::query()->find($issueId);

        return $issue instanceof Issue && $this->issues->canSee($viewer, $issue);
    }

    private function journalIssueId(int $journalId): ?int
    {
        $journal = Journal::query()->find($journalId);
        if (! $journal instanceof Journal || (string) $journal->journalized_type !== 'Issue') {
            return null;
        }

        return (int) $journal->journalized_id;
    }

    private function issueProject(int $id): ?Project
    {
        $issue = Issue::query()->find($id);
        if (! $issue instanceof Issue) {
            throw new DomainException(self::MISSING);
        }

        return $issue->project;
    }

    private function journalProject(int $id): ?Project
    {
        $journal = Journal::query()->find($id);
        if (! $journal instanceof Journal) {
            throw new DomainException(self::MISSING);
        }
        if ((string) $journal->journalized_type !== 'Issue') {
            return null;
        }
        $issue = Issue::query()->find((int) $journal->journalized_id);

        return $issue instanceof Issue ? $issue->project : null;
    }

    private function newsProject(int $id): ?Project
    {
        $news = News::query()->find($id);
        if (! $news instanceof News) {
            throw new DomainException(self::MISSING);
        }

        return $news->project;
    }

    private function commentProject(int $id): ?Project
    {
        $comment = Comment::query()->find($id);
        if (! $comment instanceof Comment) {
            throw new DomainException(self::MISSING);
        }
        if ((string) $comment->commented_type !== 'News') {
            return null;
        }
        $news = News::query()->find((int) $comment->commented_id);

        return $news instanceof News ? $news->project : null;
    }

    private function messageProject(int $id): ?Project
    {
        $message = Message::query()->find($id);
        if (! $message instanceof Message) {
            throw new DomainException(self::MISSING);
        }
        $board = Board::query()->find((int) $message->board_id);

        return $board instanceof Board ? $board->project : null;
    }
}
