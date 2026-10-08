<?php

namespace App\Domain\Queries;

use App\Domain\Acl\PermissionService;
use App\Domain\CustomFields\CustomFieldTypes;
use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * Custom fields of an associated record, and `cf_N.cf_M` through a user or version field.
 *
 * `project.cf_N` reads a project field. `author.cf_N` and `assigned_to.cf_N` read a
 * user field stored on a user or a group. `fixed_version.cf_N` reads a version field.
 * `cf_N.cf_M` follows an issue custom field whose format is user or version.
 * Operators are the target field's filter operators. A hidden field is an error.
 */
final class AssociationCustomFieldSql
{
    public function __construct(
        private readonly FieldFormatRegistry $formats,
        private readonly CustomFieldVisibility $visibility,
        private readonly CustomFieldFilterSql $values,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @param  Builder<Issue>  $query
     */
    public function apply(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project, DateWindow $dates): void
    {
        if (preg_match('/^(project|author|assigned_to|fixed_version)\.cf_(\d+)$/', $filter->field, $matches) === 1) {
            $this->association($query, $filter, $matches[1], (int) $matches[2], $actor, $project, $dates);

            return;
        }

        if (preg_match('/^cf_(\d+)\.cf_(\d+)$/', $filter->field, $matches) === 1) {
            $this->chained($query, $filter, (int) $matches[1], (int) $matches[2], $actor, $project, $dates);

            return;
        }

        throw new QueryValidationException('Chained custom field filter is not supported: '.$filter->field.'.');
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function association(Builder $query, QueryFilter $filter, string $assoc, int $fieldId, ?User $actor, ?Project $project, DateWindow $dates): void
    {
        [$column, $sti, $types] = $this->associationTarget($assoc);
        $field = $this->field($sti, $fieldId, $filter->field, $actor, $project);
        $this->compile($query, $filter, $field, $actor, $project, $dates, function (QueryBuilder $sub) use ($field, $column, $types): void {
            $sub->whereColumn('custom_values.customized_id', $column)
                ->whereIn('custom_values.customized_type', $types)
                ->where('custom_values.custom_field_id', $field->id);
        }, function (QueryBuilder $sub) use ($column, $types): void {
            $sub->whereColumn('journals.journalized_id', $column)
                ->whereIn('journals.journalized_type', $types);
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function chained(Builder $query, QueryFilter $filter, int $throughId, int $targetId, ?User $actor, ?Project $project, DateWindow $dates): void
    {
        $through = $this->field('IssueCustomField', $throughId, $filter->field, $actor, $project);
        $format = (string) $through->field_format;
        if ($format !== 'user' && $format !== 'version') {
            throw new QueryValidationException('Chained custom field filters require a user or version field: '.$filter->field.'.');
        }

        $sti = $format === 'user' ? 'UserCustomField' : 'VersionCustomField';
        $types = $format === 'user' ? [CustomFieldTypes::USER, CustomFieldTypes::GROUP] : [CustomFieldTypes::VERSION];
        $target = $this->field($sti, $targetId, $filter->field, $actor, $project);
        $this->compile($query, $filter, $target, $actor, $project, $dates, function (QueryBuilder $sub) use ($through, $target, $types): void {
            $sub->join('custom_values as issue_link', function (JoinClause $join) use ($through): void {
                $join->where('issue_link.customized_type', '=', CustomFieldTypes::ISSUE)
                    ->whereColumn('issue_link.customized_id', 'issues.id')
                    ->where('issue_link.custom_field_id', '=', $through->id)
                    ->whereRaw("issue_link.value REGEXP '^[0-9]+$'")
                    ->whereRaw('custom_values.customized_id = CAST(issue_link.value AS UNSIGNED)');
            })->whereIn('custom_values.customized_type', $types)
                ->where('custom_values.custom_field_id', $target->id);
        }, function (QueryBuilder $sub) use ($through, $types): void {
            $sub->join('custom_values as issue_link', function (JoinClause $join) use ($through): void {
                $join->where('issue_link.customized_type', '=', CustomFieldTypes::ISSUE)
                    ->whereColumn('issue_link.customized_id', 'issues.id')
                    ->where('issue_link.custom_field_id', '=', $through->id)
                    ->whereRaw("issue_link.value REGEXP '^[0-9]+$'")
                    ->whereRaw('journals.journalized_id = CAST(issue_link.value AS UNSIGNED)');
            })->whereIn('journals.journalized_type', $types);
        });
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  callable(QueryBuilder): void  $valueScope
     * @param  callable(QueryBuilder): void  $journalScope
     */
    private function compile(
        Builder $query,
        QueryFilter $filter,
        CustomField $field,
        ?User $actor,
        ?Project $project,
        DateWindow $dates,
        callable $valueScope,
        callable $journalScope,
    ): void {
        $format = $this->formats->get((string) $field->field_format);
        OperatorMatrix::assert($format->queryFilterType(), $filter->operator, $filter->field);
        FilterValues::assertCount($filter);

        if (in_array($filter->operator, ['ev', '!ev', 'cf'], true)) {
            $this->history($query, $filter, $field, $actor, $project, $dates, $valueScope, $journalScope);

            return;
        }

        $this->values->applyScoped($query, $filter, $field, $valueScope, $actor, $dates);
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  callable(QueryBuilder): void  $valueScope
     * @param  callable(QueryBuilder): void  $journalScope
     */
    private function history(
        Builder $query,
        QueryFilter $filter,
        CustomField $field,
        ?User $actor,
        ?Project $project,
        DateWindow $dates,
        callable $valueScope,
        callable $journalScope,
    ): void {
        $values = $this->historyValues($field, $filter, $actor);
        $current = new QueryFilter($filter->field, '=', $values);
        $journal = function (QueryBuilder $sub) use ($journalScope, $field, $filter, $values, $actor, $project): void {
            $sub->selectRaw('1')
                ->from('journals')
                ->join('journal_details', 'journal_details.journal_id', '=', 'journals.id');
            $journalScope($sub);
            $sub->where('journal_details.property', 'cf')
                ->where('journal_details.prop_key', (string) $field->id);
            $this->hidePrivateJournals($sub, $actor, $project);
            if ($filter->operator === 'cf') {
                $sub->whereIn('journal_details.old_value', $values);

                return;
            }
            $sub->where(function (QueryBuilder $either) use ($values): void {
                $either->whereIn('journal_details.old_value', $values)
                    ->orWhereIn('journal_details.value', $values);
            });
        };

        if ($filter->operator === 'cf') {
            $query->whereExists($journal);

            return;
        }

        if ($filter->operator === '!ev') {
            $query->whereNotExists(function (QueryBuilder $sub) use ($field, $actor, $dates, $valueScope, $current): void {
                $sub->selectRaw('1')->from('custom_values');
                $valueScope($sub);
                $this->values->applyScopedValue($sub, $current, $field, $actor, $dates);
            });
            $query->whereNotExists($journal);

            return;
        }

        $query->where(function (Builder $either) use ($field, $actor, $dates, $valueScope, $current, $journal): void {
            $either->whereExists(function (QueryBuilder $sub) use ($field, $actor, $dates, $valueScope, $current): void {
                $sub->selectRaw('1')->from('custom_values');
                $valueScope($sub);
                $this->values->applyScopedValue($sub, $current, $field, $actor, $dates);
            })->orWhereExists($journal);
        });
    }

    private function hidePrivateJournals(QueryBuilder $sub, ?User $actor, ?Project $project): void
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return;
        }

        if ($project !== null && $this->permissions->allowed($actor, 'view_private_notes', $project)) {
            return;
        }

        $sub->where('journals.private_notes', false);
    }

    /**
     * @return list<string>
     */
    private function historyValues(CustomField $field, QueryFilter $filter, ?User $actor): array
    {
        if ((string) $field->field_format === 'user') {
            $values = [];
            foreach (FilterValues::ids($filter, $actor, true) as $id) {
                $values[] = (string) $id;
            }

            return $values;
        }

        $values = FilterValues::present($filter);
        if (in_array('me', $values, true)) {
            throw new QueryValidationException('Filter value me is not valid for '.$filter->field.'.');
        }

        return $values;
    }

    /**
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function associationTarget(string $assoc): array
    {
        return match ($assoc) {
            'project' => ['issues.project_id', 'ProjectCustomField', [CustomFieldTypes::PROJECT]],
            'author', 'assigned_to' => [
                $assoc === 'author' ? 'issues.author_id' : 'issues.assigned_to_id',
                'UserCustomField',
                [CustomFieldTypes::USER, CustomFieldTypes::GROUP],
            ],
            'fixed_version' => ['issues.fixed_version_id', 'VersionCustomField', [CustomFieldTypes::VERSION]],
            default => throw new QueryValidationException('Unknown filter field: '.$assoc.'.'),
        };
    }

    private function field(string $sti, int $fieldId, string $label, ?User $actor, ?Project $project): CustomField
    {
        $customField = CustomField::query()->with('roles')->find($fieldId);
        if (! $customField instanceof CustomField || $customField->type !== $sti) {
            throw new QueryValidationException('Custom field filter is unknown: '.$label.'.');
        }
        if (! $customField->is_filter) {
            throw new QueryValidationException('Custom field is not a filter: '.$label.'.');
        }

        $format = $this->formats->get((string) $customField->field_format);
        if (! $format->isImplemented()) {
            throw new QueryValidationException('Custom field format cannot be filtered yet: '.$customField->field_format.'.');
        }
        if (! $this->visibility->canSee($actor, $customField, $project)) {
            throw new QueryValidationException('Custom field is not visible: '.$label.'.');
        }

        return $customField;
    }
}
