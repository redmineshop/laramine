<?php

namespace App\Domain\Issues;

use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds an issue relation and a relation-add journal on the source issue.
 *
 * The other issue does not receive a journal in this slice.
 */
final class IssueRelationService
{
    /**
     * @var list<string>
     */
    public const TYPES = [
        'relates',
        'blocks',
        'duplicates',
        'precedes',
        'copied_to',
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly IssueJournalWriter $journals,
    ) {}

    public function add(User $actor, Issue $from, Issue $to, string $type = 'relates'): IssueRelation
    {
        $project = $from->project;
        if ($project === null) {
            throw new DomainException('Issue has no project.');
        }
        if (! $this->permissions->allowed($actor, 'manage_issue_relations', $project)) {
            throw new PermissionDeniedException('manage_issue_relations');
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('Relation type is not supported.');
        }
        if ($from->id === $to->id) {
            throw new DomainException('An issue cannot be related to itself.');
        }
        if ($this->pairExists($from, $to)) {
            throw new DomainException('Relation already exists.');
        }

        return DB::transaction(function () use ($actor, $from, $to, $type): IssueRelation {
            $relation = IssueRelation::query()->create([
                'issue_from_id' => $from->id,
                'issue_to_id' => $to->id,
                'relation_type' => $type,
            ]);
            $this->journals->recordRelationAdded($actor, $from, $to, $type);

            return $relation;
        });
    }

    private function pairExists(Issue $from, Issue $to): bool
    {
        $forward = IssueRelation::query()
            ->where('issue_from_id', $from->id)
            ->where('issue_to_id', $to->id)
            ->exists();
        if ($forward) {
            return true;
        }

        return IssueRelation::query()
            ->where('issue_from_id', $to->id)
            ->where('issue_to_id', $from->id)
            ->exists();
    }
}
