<?php

namespace App\Domain\Queries;

use App\Domain\Acl\PermissionService;
use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Filters that read a related row instead of one issue column.
 *
 * Group filters use `groups_users`. Role filters use `members` and `member_roles`
 * on the issue's project. Notes skip private journals unless the actor may view them.
 */
final class AssociationFilterSql
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly FieldFormatRegistry $formats,
        private readonly CustomFieldVisibility $visibility,
        private readonly JournalVisibility $journals,
    ) {}

    /**
     * @param  Builder<Issue>  $query
     */
    public function apply(Builder $query, IssueField $field, QueryFilter $filter, ?User $actor, ?Project $project, DateWindow $dates): void
    {
        match ($field->name) {
            'author.group' => $this->authorGroup($query, $filter),
            'author.role' => $this->role($query, $filter, 'issues.author_id', false),
            'member_of_group' => $this->memberOfGroup($query, $filter),
            'assigned_to_role' => $this->role($query, $filter, 'issues.assigned_to_id', true),
            'fixed_version.due_date' => $this->versionDueDate($query, $filter, $dates),
            'fixed_version.status' => $this->versionStatus($query, $filter),
            'project.status' => $this->projectStatus($query, $filter),
            'notes' => $this->notes($query, $filter, $actor, $project),
            'attachment' => $this->attachmentFile($query, $filter),
            'attachment_description' => $this->attachmentDescription($query, $filter),
            'watcher_id' => $this->watcher($query, $filter, $actor, $project),
            'updated_by' => $this->updatedBy($query, $filter, $actor, $project),
            'last_updated_by' => $this->lastUpdatedBy($query, $filter, $actor, $project),
            'spent_time' => $this->spentTime($query, $filter),
            'any_searchable' => $this->anySearchable($query, $filter, $actor, $project),
            default => throw new QueryValidationException('Unknown filter field: '.$field->name.'.'),
        };
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function authorGroup(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        $groupIds = FilterValues::ids($filter);
        if ($operator === '=') {
            $query->where(function (Builder $inner) use ($groupIds): void {
                /** @var Builder<Issue> $inner */
                $inner->whereIn('issues.author_id', $groupIds)
                    ->orWhereIn('issues.author_id', function (QueryBuilder $sub) use ($groupIds): void {
                        $this->usersInGroups($sub, $groupIds);
                    });
            });

            return;
        }

        if ($operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($groupIds): void {
            /** @var Builder<Issue> $inner */
            $inner->whereNotIn('issues.author_id', $groupIds)
                ->whereNotIn('issues.author_id', function (QueryBuilder $sub) use ($groupIds): void {
                    $this->usersInGroups($sub, $groupIds);
                });
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function memberOfGroup(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->where(function (Builder $inner): void {
                /** @var Builder<Issue> $inner */
                $inner->whereExists(function (QueryBuilder $sub): void {
                    $sub->selectRaw('1')
                        ->from('groups_users')
                        ->whereColumn('groups_users.user_id', 'issues.assigned_to_id');
                })->orWhereExists(function (QueryBuilder $sub): void {
                    $sub->selectRaw('1')
                        ->from('users')
                        ->whereColumn('users.id', 'issues.assigned_to_id')
                        ->where('users.type', User::TYPE_GROUP);
                });
            });

            return;
        }

        if ($operator === '!*') {
            $query->where(function (Builder $inner): void {
                /** @var Builder<Issue> $inner */
                $inner->whereNull('issues.assigned_to_id')
                    ->orWhere(function (Builder $plain): void {
                        /** @var Builder<Issue> $plain */
                        $plain->whereNotExists(function (QueryBuilder $sub): void {
                            $sub->selectRaw('1')
                                ->from('groups_users')
                                ->whereColumn('groups_users.user_id', 'issues.assigned_to_id');
                        })->whereNotExists(function (QueryBuilder $sub): void {
                            $sub->selectRaw('1')
                                ->from('users')
                                ->whereColumn('users.id', 'issues.assigned_to_id')
                                ->where('users.type', User::TYPE_GROUP);
                        });
                    });
            });

            return;
        }

        $ids = FilterValues::ids($filter);
        if ($operator === '=') {
            $query->where(function (Builder $inner) use ($ids): void {
                /** @var Builder<Issue> $inner */
                $inner->whereIn('issues.assigned_to_id', $ids)
                    ->orWhereIn('issues.assigned_to_id', function (QueryBuilder $sub) use ($ids): void {
                        $this->usersInGroups($sub, $ids);
                    });
            });

            return;
        }

        if ($operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($ids): void {
            /** @var Builder<Issue> $inner */
            $inner->whereNull('issues.assigned_to_id')
                ->orWhere(function (Builder $other) use ($ids): void {
                    /** @var Builder<Issue> $other */
                    $other->whereNotIn('issues.assigned_to_id', $ids)
                        ->whereNotIn('issues.assigned_to_id', function (QueryBuilder $sub) use ($ids): void {
                            $this->usersInGroups($sub, $ids);
                        });
                });
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function role(Builder $query, QueryFilter $filter, string $userColumn, bool $optional): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub) use ($userColumn): void {
                $this->roleMatch($sub, $userColumn, null);
            });

            return;
        }

        if ($operator === '!*') {
            $query->whereNotExists(function (QueryBuilder $sub) use ($userColumn): void {
                $this->roleMatch($sub, $userColumn, null);
            });

            return;
        }

        $ids = FilterValues::ids($filter);
        if ($operator === '=') {
            $query->whereExists(function (QueryBuilder $sub) use ($userColumn, $ids): void {
                $this->roleMatch($sub, $userColumn, $ids);
            });

            return;
        }

        if ($operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($userColumn, $ids, $optional): void {
            /** @var Builder<Issue> $inner */
            if ($optional) {
                $inner->whereNull($userColumn)->orWhereNotExists(function (QueryBuilder $sub) use ($userColumn, $ids): void {
                    $this->roleMatch($sub, $userColumn, $ids);
                });

                return;
            }

            $inner->whereNotExists(function (QueryBuilder $sub) use ($userColumn, $ids): void {
                $this->roleMatch($sub, $userColumn, $ids);
            });
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function versionDueDate(Builder $query, QueryFilter $filter, DateWindow $dates): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub): void {
                $this->versionRow($sub)->whereNotNull('versions.effective_date');
            });

            return;
        }

        if ($operator === '!*') {
            $query->where(function (Builder $inner): void {
                /** @var Builder<Issue> $inner */
                $inner->whereNull('issues.fixed_version_id')
                    ->orWhereExists(function (QueryBuilder $sub): void {
                        $this->versionRow($sub)->whereNull('versions.effective_date');
                    });
            });

            return;
        }

        $query->whereExists(function (QueryBuilder $sub) use ($filter, $operator, $dates): void {
            $this->versionRow($sub);
            $this->calendar($sub, 'versions.effective_date', $filter, $operator, $dates);
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function versionStatus(Builder $query, QueryFilter $filter): void
    {
        $values = $this->plainValues($filter);
        $callback = function (QueryBuilder $sub) use ($values): void {
            $this->versionRow($sub)->whereIn('versions.status', $values);
        };

        if ($filter->operator === '!') {
            $query->whereNotExists($callback);

            return;
        }

        if ($filter->operator !== '=') {
            throw new QueryValidationException('Operator '.$filter->operator.' is not valid for '.$filter->field.'.');
        }

        $query->whereExists($callback);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function projectStatus(Builder $query, QueryFilter $filter): void
    {
        $ids = FilterValues::ids($filter);
        $callback = function (QueryBuilder $sub) use ($ids): void {
            $sub->select('id')->from('projects')->whereIn('projects.status', $ids);
        };

        if ($filter->operator === '=') {
            $query->whereIn('issues.project_id', $callback);

            return;
        }

        if ($filter->operator !== '!') {
            throw new QueryValidationException('Operator '.$filter->operator.' is not valid for '.$filter->field.'.');
        }

        $query->whereNotIn('issues.project_id', $callback);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function notes(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project): void
    {
        $this->textExists($query, $filter, 'journals.notes', function (QueryBuilder $sub) use ($actor, $project): void {
            $sub->selectRaw('1')->from('journals');
            $this->visibleJournal($sub, $actor, $project);
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function attachmentFile(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub): void {
                $this->attachmentBase($sub);
            });

            return;
        }

        if ($operator === '!*') {
            $query->whereNotExists(function (QueryBuilder $sub): void {
                $this->attachmentBase($sub);
            });

            return;
        }

        $this->textExists($query, $filter, 'attachments.filename', function (QueryBuilder $sub): void {
            $this->attachmentBase($sub);
        });
    }

    /**
     * Redmine `attachment_description`: `!*` is an attachment whose description is blank.
     * `!~` is an attachment with a non-blank description that does not contain the tokens.
     *
     * @param  Builder<Issue>  $query
     */
    private function attachmentDescription(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        $column = 'attachments.description';
        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub) use ($column): void {
                $this->attachmentBase($sub);
                $sub->whereNotNull($column)->where($column, '!=', '');
            });

            return;
        }

        if ($operator === '!*') {
            $query->whereExists(function (QueryBuilder $sub) use ($column): void {
                $this->attachmentBase($sub);
                $sub->where(function (QueryBuilder $blank) use ($column): void {
                    $blank->whereNull($column)->orWhere($column, '=', '');
                });
            });

            return;
        }

        if ($operator === '!~') {
            $tokens = FilterValues::tokens($filter);
            $query->whereExists(function (QueryBuilder $sub) use ($column, $tokens): void {
                $this->attachmentBase($sub);
                $sub->whereNotNull($column)->where($column, '!=', '');
                foreach ($tokens as $token) {
                    $sub->whereRaw('LOWER('.$column.') NOT LIKE ? ESCAPE ?', [FilterValues::like($token), '\\']);
                }
            });

            return;
        }

        $query->whereExists(function (QueryBuilder $sub) use ($column, $filter, $operator): void {
            $this->attachmentBase($sub);
            if ($operator === '*~') {
                $sub->whereNotNull($column)->where($column, '!=', '');
            }
            $this->positiveText($sub, $column, $filter, $operator);
        });
    }

    private function attachmentBase(QueryBuilder $sub): void
    {
        $sub->selectRaw('1')
            ->from('attachments')
            ->where('attachments.container_type', 'Issue')
            ->whereColumn('attachments.container_id', 'issues.id');
    }

    /**
     * Redmine `watcher_id` is a list. `me` includes the actor's groups.
     * Other user ids require `view_issue_watchers` on the issue project.
     *
     * @param  Builder<Issue>  $query
     */
    private function watcher(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project): void
    {
        $operator = $filter->operator;
        if ($operator !== '=' && $operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $ids = FilterValues::ids($filter, $actor, true, true);
        $self = $this->selfPrincipalIds($actor);
        $mine = array_values(array_intersect($ids, $self));
        $others = array_values(array_diff($ids, $self));

        $positive = function (Builder $inner) use ($mine, $others, $actor, $project): void {
            /** @var Builder<Issue> $inner */
            $inner->where(function (Builder $match) use ($mine, $others, $actor, $project): void {
                /** @var Builder<Issue> $match */
                if ($mine !== []) {
                    $match->whereExists(function (QueryBuilder $sub) use ($mine): void {
                        $this->watcherRows($sub, $mine);
                    });
                } else {
                    $match->whereRaw('1 = 0');
                }
                if ($others !== []) {
                    $match->orWhere(function (Builder $other) use ($others, $actor, $project): void {
                        /** @var Builder<Issue> $other */
                        $this->watcherPermission($other, $actor, $project);
                        $other->whereExists(function (QueryBuilder $sub) use ($others): void {
                            $this->watcherRows($sub, $others);
                        });
                    });
                }
            });
        };

        if ($operator === '=') {
            $query->where($positive);

            return;
        }

        $query->whereNot($positive);
    }

    /**
     * @param  list<int>  $ids
     */
    private function watcherRows(QueryBuilder $sub, array $ids): void
    {
        $sub->selectRaw('1')
            ->from('watchers')
            ->where('watchers.watchable_type', 'Issue')
            ->whereColumn('watchers.watchable_id', 'issues.id')
            ->whereIn('watchers.user_id', $ids);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function watcherPermission(Builder $query, ?User $actor, ?Project $project): void
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return;
        }

        if ($project !== null) {
            if (! $this->permissions->allowed($actor, 'view_issue_watchers', $project)) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $allowed = $this->projectsAllowing($actor, 'view_issue_watchers');
        if ($allowed === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('issues.project_id', $allowed);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function updatedBy(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project): void
    {
        $operator = $filter->operator;
        if ($operator !== '=' && $operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $ids = FilterValues::ids($filter, $actor, true);
        $callback = function (QueryBuilder $sub) use ($ids, $actor, $project): void {
            $sub->selectRaw('1')->from('journals');
            $this->visibleJournal($sub, $actor, $project);
            $sub->whereIn('journals.user_id', $ids);
        };

        if ($operator === '=') {
            $query->whereExists($callback);

            return;
        }

        $query->whereNotExists($callback);
    }

    /**
     * Latest visible journal by id. A private journal is skipped when the actor cannot see it.
     *
     * @param  Builder<Issue>  $query
     */
    private function lastUpdatedBy(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project): void
    {
        $ids = FilterValues::ids($filter, $actor, true);
        $visible = $this->visibleJournalSql($actor, $project);
        $latest = '(SELECT journals.user_id FROM journals WHERE journals.id = (SELECT MAX(journals.id) FROM journals WHERE journals.journalized_type = ? AND journals.journalized_id = issues.id AND '.$visible.'))';
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $bindings = ['Issue', ...$ids];

        if ($filter->operator === '=') {
            $query->whereRaw($latest.' IN ('.$placeholders.')', $bindings);

            return;
        }

        if ($filter->operator !== '!') {
            throw new QueryValidationException('Operator '.$filter->operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($latest, $placeholders, $bindings): void {
            /** @var Builder<Issue> $inner */
            $inner->whereRaw($latest.' IS NULL', ['Issue'])
                ->orWhereRaw($latest.' NOT IN ('.$placeholders.')', $bindings);
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function spentTime(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        $sum = 'COALESCE((SELECT ROUND(CAST(SUM(time_entries.hours) AS DECIMAL(30,3)), 2) FROM time_entries WHERE time_entries.issue_id = issues.id), 0)';
        if ($operator === '*') {
            $query->whereRaw($sum.' > 0');

            return;
        }

        if ($operator === '!*') {
            $query->whereRaw($sum.' = 0');

            return;
        }

        $numbers = FilterValues::decimals($filter);
        if ($operator === '=') {
            $query->where(function (Builder $inner) use ($sum, $numbers): void {
                foreach ($numbers as $index => $number) {
                    if ($index === 0) {
                        $inner->whereRaw($sum.' = ?', [$number]);
                    } else {
                        $inner->orWhereRaw($sum.' = ?', [$number]);
                    }
                }
            });

            return;
        }

        if ($operator === '>=') {
            $query->whereRaw($sum.' >= ?', [$numbers[0]]);

            return;
        }

        if ($operator === '<=') {
            $query->whereRaw($sum.' <= ?', [$numbers[0]]);

            return;
        }

        if ($operator !== '><') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->whereRaw($sum.' BETWEEN ? AND ?', [$numbers[0], $numbers[1]]);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function anySearchable(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project): void
    {
        $operator = $filter->operator;
        $fieldIds = $this->searchableFieldIds($actor, $project);
        $tokens = $operator === '!~' || $operator === '~' || $operator === '*~'
            ? FilterValues::tokens($filter)
            : [];

        if ($operator === '!~') {
            $query->whereNot(function (Builder $inner) use ($tokens, $fieldIds, $actor, $project): void {
                /** @var Builder<Issue> $inner */
                $this->eachTokenSomewhere($inner, $tokens, $fieldIds, $actor, $project);
            });

            return;
        }

        if ($operator === '~') {
            $this->eachTokenSomewhere($query, $tokens, $fieldIds, $actor, $project);

            return;
        }

        if ($operator !== '*~') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($tokens, $fieldIds, $actor, $project): void {
            foreach ($tokens as $index => $token) {
                $apply = function (Builder $match) use ($token, $fieldIds, $actor, $project): void {
                    /** @var Builder<Issue> $match */
                    $this->tokenSomewhere($match, $token, $fieldIds, $actor, $project);
                };
                if ($index === 0) {
                    $inner->where($apply);
                } else {
                    $inner->orWhere($apply);
                }
            }
        });
    }

    /**
     * @param  list<int>  $groupIds
     */
    private function usersInGroups(QueryBuilder $sub, array $groupIds): void
    {
        $sub->select('groups_users.user_id')
            ->from('groups_users')
            ->whereIn('groups_users.group_id', $groupIds);
    }

    /**
     * @param  list<int>|null  $roleIds
     */
    private function roleMatch(QueryBuilder $sub, string $userColumn, ?array $roleIds): void
    {
        $sub->selectRaw('1')
            ->from('members')
            ->join('member_roles', 'member_roles.member_id', '=', 'members.id')
            ->whereColumn('members.project_id', 'issues.project_id')
            ->whereColumn('members.user_id', $userColumn);
        if ($roleIds !== null) {
            $sub->whereIn('member_roles.role_id', $roleIds);
        }
    }

    private function versionRow(QueryBuilder $sub): QueryBuilder
    {
        return $sub->selectRaw('1')
            ->from('versions')
            ->whereColumn('versions.id', 'issues.fixed_version_id');
    }

    /**
     * @param  callable(QueryBuilder): void  $base
     * @param  Builder<Issue>  $query
     */
    private function textExists(Builder $query, QueryFilter $filter, string $column, callable $base): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub) use ($base, $column): void {
                $base($sub);
                $sub->whereNotNull($column)->where($column, '!=', '');
            });

            return;
        }

        if ($operator === '!*') {
            $query->whereNotExists(function (QueryBuilder $sub) use ($base, $column): void {
                $base($sub);
                $sub->whereNotNull($column)->where($column, '!=', '');
            });

            return;
        }

        if ($operator === '!~') {
            $tokens = FilterValues::tokens($filter);
            $query->whereNotExists(function (QueryBuilder $sub) use ($base, $column, $tokens): void {
                $base($sub);
                foreach ($tokens as $token) {
                    $sub->whereRaw('LOWER('.$column.') LIKE ? ESCAPE ?', [FilterValues::like($token), '\\']);
                }
            });

            return;
        }

        $query->whereExists(function (QueryBuilder $sub) use ($base, $column, $filter, $operator): void {
            $base($sub);
            $this->positiveText($sub, $column, $filter, $operator);
        });
    }

    private function positiveText(QueryBuilder $sub, string $column, QueryFilter $filter, string $operator): void
    {
        if ($operator === '~') {
            foreach (FilterValues::tokens($filter) as $token) {
                $sub->whereRaw('LOWER('.$column.') LIKE ? ESCAPE ?', [FilterValues::like($token), '\\']);
            }

            return;
        }

        if ($operator === '*~') {
            $tokens = FilterValues::tokens($filter);
            $sub->where(function (QueryBuilder $inner) use ($column, $tokens): void {
                foreach ($tokens as $index => $token) {
                    $sql = 'LOWER('.$column.') LIKE ? ESCAPE ?';
                    $bindings = [FilterValues::like($token), '\\'];
                    if ($index === 0) {
                        $inner->whereRaw($sql, $bindings);
                    } else {
                        $inner->orWhereRaw($sql, $bindings);
                    }
                }
            });

            return;
        }

        if ($operator !== '^' && $operator !== '$') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $values = FilterValues::present($filter);
        $sub->where(function (QueryBuilder $inner) use ($column, $values, $operator): void {
            foreach ($values as $index => $value) {
                $pattern = $operator === '^' ? FilterValues::likePrefix($value) : FilterValues::likeSuffix($value);
                $sql = 'LOWER('.$column.') LIKE ? ESCAPE ?';
                $bindings = [$pattern, '\\'];
                if ($index === 0) {
                    $inner->whereRaw($sql, $bindings);
                } else {
                    $inner->orWhereRaw($sql, $bindings);
                }
            }
        });
    }

    private function visibleJournal(QueryBuilder $sub, ?User $actor, ?Project $project): void
    {
        $sub->where('journals.journalized_type', 'Issue')
            ->whereColumn('journals.journalized_id', 'issues.id');
        $this->journals->constrain($sub, $actor, $project);
    }

    /**
     * SQL fragment for the same private-note rule as {@see visibleJournal()}.
     */
    private function visibleJournalSql(?User $actor, ?Project $project): string
    {
        return $this->journals->sql($actor, $project);
    }

    /**
     * @return list<int>
     */
    private function projectsAllowing(?User $actor, string $permission): array
    {
        $allowed = [];
        foreach (Project::query()->orderBy('id')->get() as $candidate) {
            if ($this->permissions->allowed($actor, $permission, $candidate)) {
                $allowed[] = (int) $candidate->id;
            }
        }

        return $allowed;
    }

    /**
     * Actor id plus group ids. Redmine treats these watcher values as "me" and skips `view_issue_watchers`.
     *
     * @return list<int>
     */
    private function selfPrincipalIds(?User $actor): array
    {
        if ($actor === null || $actor->type !== User::TYPE_USER) {
            return [0];
        }

        $ids = [0, (int) $actor->id];
        foreach (DB::table('groups_users')->where('user_id', $actor->id)->pluck('group_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private function calendar(QueryBuilder $sub, string $column, QueryFilter $filter, string $operator, DateWindow $dates): void
    {
        if (in_array($operator, DateWindow::CLOSED_RELATIVE, true) || in_array($operator, DateWindow::OFFSET_OPERATORS, true)) {
            $days = in_array($operator, DateWindow::OFFSET_OPERATORS, true) ? FilterValues::dayOffset($filter) : 0;
            $dates->calendarBound($operator, $days)->apply($sub, $column);

            return;
        }

        $values = FilterValues::dates($filter);
        if ($operator === '=') {
            $sub->whereIn($column, $values);

            return;
        }

        if ($operator === '>=') {
            $sub->where($column, '>=', $values[0]);

            return;
        }

        if ($operator === '<=') {
            $sub->where($column, '<=', $values[0]);

            return;
        }

        if ($operator !== '><') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $sub->whereBetween($column, [$values[0], $values[1]]);
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  list<string>  $tokens
     * @param  list<int>  $fieldIds
     */
    private function eachTokenSomewhere(Builder $query, array $tokens, array $fieldIds, ?User $actor, ?Project $project): void
    {
        foreach ($tokens as $token) {
            $query->where(function (Builder $inner) use ($token, $fieldIds, $actor, $project): void {
                /** @var Builder<Issue> $inner */
                $this->tokenSomewhere($inner, $token, $fieldIds, $actor, $project);
            });
        }
    }

    /**
     * Subject, description, visible journal notes, and visible searchable custom values.
     * Redmine's issue search does the same and leaves attachments to their own filter.
     *
     * @param  Builder<Issue>  $query
     * @param  list<int>  $fieldIds
     */
    private function tokenSomewhere(Builder $query, string $token, array $fieldIds, ?User $actor, ?Project $project): void
    {
        $like = FilterValues::like($token);
        $query->whereRaw('LOWER(issues.subject) LIKE ? ESCAPE ?', [$like, '\\'])
            ->orWhereRaw('LOWER(issues.description) LIKE ? ESCAPE ?', [$like, '\\'])
            ->orWhereExists(function (QueryBuilder $sub) use ($like, $actor, $project): void {
                $sub->selectRaw('1')->from('journals');
                $this->visibleJournal($sub, $actor, $project);
                $sub->whereRaw('LOWER(journals.notes) LIKE ? ESCAPE ?', [$like, '\\']);
            });
        if ($fieldIds === []) {
            return;
        }

        $query->orWhereExists(function (QueryBuilder $sub) use ($fieldIds, $like): void {
            $sub->selectRaw('1')
                ->from('custom_values')
                ->where('custom_values.customized_type', 'Issue')
                ->whereColumn('custom_values.customized_id', 'issues.id')
                ->whereIn('custom_values.custom_field_id', $fieldIds)
                ->whereRaw('LOWER(custom_values.value) LIKE ? ESCAPE ?', [$like, '\\']);
        });
    }

    /**
     * @return list<int>
     */
    private function searchableFieldIds(?User $actor, ?Project $project): array
    {
        $ids = [];
        $fields = CustomField::query()
            ->with('roles')
            ->where('type', 'IssueCustomField')
            ->where('searchable', true)
            ->orderBy('id')
            ->get();
        foreach ($fields as $field) {
            $format = $this->formats->get((string) $field->field_format);
            if (! $format->isImplemented()) {
                continue;
            }
            if (! $this->visibility->canSee($actor, $field, $project)) {
                continue;
            }
            $ids[] = (int) $field->id;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function plainValues(QueryFilter $filter): array
    {
        $values = FilterValues::present($filter);
        if (in_array('me', $values, true)) {
            throw new QueryValidationException('Filter value me is not valid for '.$filter->field.'.');
        }

        return $values;
    }
}
