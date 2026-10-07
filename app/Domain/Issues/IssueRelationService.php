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
 * Adds and removes issue relations.
 *
 * An added relation journals the source issue only. A removed relation
 * journals both issues. The other issue stores the reverse relation type.
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

    /**
     * @var array<string, string>
     */
    private const REVERSE = [
        'relates' => 'relates',
        'blocks' => 'blocked',
        'duplicates' => 'duplicated',
        'precedes' => 'follows',
        'copied_to' => 'copied_from',
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

    public function remove(User $actor, IssueRelation $relation): void
    {
        $from = Issue::query()->find($relation->issue_from_id);
        $to = Issue::query()->find($relation->issue_to_id);
        if (! $from instanceof Issue || ! $to instanceof Issue) {
            throw new DomainException('Relation issue does not exist.');
        }
        $project = $from->project;
        if ($project === null) {
            throw new DomainException('Issue has no project.');
        }
        if (! $this->permissions->allowed($actor, 'manage_issue_relations', $project)) {
            throw new PermissionDeniedException('manage_issue_relations');
        }
        $type = (string) $relation->relation_type;
        if (! isset(self::REVERSE[$type])) {
            throw new DomainException('Relation type is not supported.');
        }

        DB::transaction(function () use ($actor, $relation, $from, $to): void {
            $locked = IssueRelation::query()->whereKey($relation->id)->lockForUpdate()->first();
            if (! $locked instanceof IssueRelation) {
                throw new DomainException('Relation does not exist.');
            }
            $lockedType = (string) $locked->relation_type;
            $reverse = self::REVERSE[$lockedType] ?? null;
            if ($reverse === null) {
                throw new DomainException('Relation type is not supported.');
            }
            $locked->delete();
            $this->journals->recordRelationRemoved($actor, $from, $lockedType, (int) $to->id);
            $this->journals->recordRelationRemoved($actor, $to, $reverse, (int) $from->id);
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
