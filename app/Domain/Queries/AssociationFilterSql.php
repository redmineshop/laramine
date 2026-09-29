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
            'attachment' => $this->attachment($query, $filter, 'attachments.filename'),
            'attachment_description' => $this->attachment($query, $filter, 'attachments.description'),
            'watcher_id' => $this->watcher($query, $filter, $actor),
            'updated_by' => $this->updatedBy($query, $filter, $actor),
            'last_updated_by' => $this->lastUpdatedBy($query, $filter, $actor),
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
        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('groups_users')
                    ->whereColumn('groups_users.user_id', 'issues.author_id');
            });

            return;
        }

        if ($operator === '!*') {
            $query->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('groups_users')
                    ->whereColumn('groups_users.user_id', 'issues.author_id');
            });

            return;
        }

        $ids = FilterValues::ids($filter);
        if ($operator === '=') {
            $query->whereIn('issues.author_id', function (QueryBuilder $sub) use ($ids): void {
                $this->usersInGroups($sub, $ids);
            });

            return;
        }

        if ($operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->whereNotIn('issues.author_id', function (QueryBuilder $sub) use ($ids): void {
            $this->usersInGroups($sub, $ids);
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
    private function attachment(Builder $query, QueryFilter $filter, string $column): void
    {
        $this->textExists($query, $filter, $column, function (QueryBuilder $sub): void {
            $sub->selectRaw('1')
                ->from('attachments')
                ->where('attachments.container_type', 'Issue')
                ->whereColumn('attachments.container_id', 'issues.id');
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function watcher(Builder $query, QueryFilter $filter, ?User $actor): void
    {
        $this->linkedUser($query, $filter, $actor, function (QueryBuilder $sub, ?array $ids): void {
            $sub->selectRaw('1')
                ->from('watchers')
                ->where('watchers.watchable_type', 'Issue')
                ->whereColumn('watchers.watchable_id', 'issues.id');
            if ($ids !== null) {
                $sub->whereIn('watchers.user_id', $ids);
            }
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function updatedBy(Builder $query, QueryFilter $filter, ?User $actor): void
    {
        $this->linkedUser($query, $filter, $actor, function (QueryBuilder $sub, ?array $ids): void {
            $sub->selectRaw('1')
                ->from('journals')
                ->where('journals.journalized_type', 'Issue')
                ->whereColumn('journals.journalized_id', 'issues.id');
            if ($ids !== null) {
                $sub->whereIn('journals.user_id', $ids);
            }
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function lastUpdatedBy(Builder $query, QueryFilter $filter, ?User $actor): void
    {
        $ids = FilterValues::ids($filter, $actor, true);
        $latest = '(SELECT journals.user_id FROM journals WHERE journals.journalized_type = ? AND journals.journalized_id = issues.id ORDER BY journals.id DESC LIMIT 1)';
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
        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub): void {
                $this->timeRows($sub);
            });

            return;
        }

        if ($operator === '!*') {
            $query->whereNotExists(function (QueryBuilder $sub): void {
                $this->timeRows($sub);
            });

            return;
        }

        $numbers = FilterValues::decimals($filter);
        $sum = '(SELECT SUM(time_entries.hours) FROM time_entries WHERE time_entries.issue_id = issues.id)';
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
            $query->whereNot(function (Builder $inner) use ($tokens, $fieldIds): void {
                /** @var Builder<Issue> $inner */
                $this->eachTokenSomewhere($inner, $tokens, $fieldIds);
            });

            return;
        }

        if ($operator === '~') {
            $this->eachTokenSomewhere($query, $tokens, $fieldIds);

            return;
        }

        if ($operator !== '*~') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($tokens, $fieldIds): void {
            foreach ($tokens as $index => $token) {
                $apply = function (Builder $match) use ($token, $fieldIds): void {
                    /** @var Builder<Issue> $match */
                    $this->tokenSomewhere($match, $token, $fieldIds);
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

    private function timeRows(QueryBuilder $sub): void
    {
        $sub->selectRaw('1')
            ->from('time_entries')
            ->whereColumn('time_entries.issue_id', 'issues.id');
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

        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return;
        }

        if ($project !== null) {
            if (! $this->permissions->allowed($actor, 'view_private_notes', $project)) {
                $sub->where('journals.private_notes', false);
            }

            return;
        }

        $allowed = [];
        foreach (Project::query()->orderBy('id')->get() as $candidate) {
            if ($this->permissions->allowed($actor, 'view_private_notes', $candidate)) {
                $allowed[] = (int) $candidate->id;
            }
        }

        if ($allowed === []) {
            $sub->where('journals.private_notes', false);

            return;
        }

        $sub->where(function (QueryBuilder $visible) use ($allowed): void {
            $visible->where('journals.private_notes', false)
                ->orWhereIn('issues.project_id', $allowed);
        });
    }

    /**
     * @param  callable(QueryBuilder, list<int>|null): void  $base
     * @param  Builder<Issue>  $query
     */
    private function linkedUser(Builder $query, QueryFilter $filter, ?User $actor, callable $base): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub) use ($base): void {
                $base($sub, null);
            });

            return;
        }

        if ($operator === '!*') {
            $query->whereNotExists(function (QueryBuilder $sub) use ($base): void {
                $base($sub, null);
            });

            return;
        }

        $ids = FilterValues::ids($filter, $actor, true);
        if ($operator === '=') {
            $query->whereExists(function (QueryBuilder $sub) use ($base, $ids): void {
                $base($sub, $ids);
            });

            return;
        }

        if ($operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->whereNotExists(function (QueryBuilder $sub) use ($base, $ids): void {
            $base($sub, $ids);
        });
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
    private function eachTokenSomewhere(Builder $query, array $tokens, array $fieldIds): void
    {
        foreach ($tokens as $token) {
            $query->where(function (Builder $inner) use ($token, $fieldIds): void {
                /** @var Builder<Issue> $inner */
                $this->tokenSomewhere($inner, $token, $fieldIds);
            });
        }
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  list<int>  $fieldIds
     */
    private function tokenSomewhere(Builder $query, string $token, array $fieldIds): void
    {
        $like = FilterValues::like($token);
        $query->whereRaw('LOWER(issues.subject) LIKE ? ESCAPE ?', [$like, '\\'])
            ->orWhereRaw('LOWER(issues.description) LIKE ? ESCAPE ?', [$like, '\\']);
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
