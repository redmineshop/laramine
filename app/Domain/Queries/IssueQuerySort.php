<?php

namespace App\Domain\Queries;

use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatKey;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sort and group keys. Grouping orders rows; it does not collapse them.
 *
 * Priority, status, and tracker use position. Author and assignee use
 * firstname, then lastname. Project, category, and fixed version use the
 * related name. `cf_{id}` uses one minimum value per issue.
 * There is no `user_format` setting. Attachment custom fields are rejected.
 */
final class IssueQuerySort
{
    /**
     * Keys that order by the issue column itself.
     *
     * `project`, `category`, and `fixed_version` order by the related name.
     * The `_id` keys stay on the foreign key.
     *
     * @var array<string, string>
     */
    private const ISSUE_COLUMNS = [
        'id' => 'issues.id',
        'project_id' => 'issues.project_id',
        'subject' => 'issues.subject',
        'updated_on' => 'issues.updated_on',
        'created_on' => 'issues.created_on',
        'start_date' => 'issues.start_date',
        'due_date' => 'issues.due_date',
        'done_ratio' => 'issues.done_ratio',
        'estimated_hours' => 'issues.estimated_hours',
        'category_id' => 'issues.category_id',
        'fixed_version_id' => 'issues.fixed_version_id',
        'parent' => 'issues.parent_id',
        'parent_id' => 'issues.parent_id',
        'is_private' => 'issues.is_private',
        'closed_on' => 'issues.closed_on',
        'description' => 'issues.description',
    ];

    public function __construct(
        private readonly CustomFieldVisibility $visibility,
        private readonly IssueTreeHours $treeHours,
    ) {}

    /**
     * @param  Builder<Issue>  $query
     * @param  list<array{0: string, 1: string}>  $sort
     */
    public function apply(Builder $query, ?string $groupBy, array $sort, ?User $actor, ?Project $project): void
    {
        if ($groupBy !== null && $groupBy !== '') {
            $this->order($query, $groupBy, 'asc', $actor, $project);
        }

        if ($sort === []) {
            $query->orderBy('issues.id');

            return;
        }

        foreach ($sort as [$name, $direction]) {
            $this->order($query, $name, $direction, $actor, $project);
        }
    }

    public function assertAvailable(string $name, string $type, ?User $actor, ?Project $project): void
    {
        $this->expressions($name, $type, $actor, $project);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function order(Builder $query, string $name, string $direction, ?User $actor, ?Project $project): void
    {
        $direction = $this->direction($direction);
        foreach ($this->expressions($name, QueryType::ISSUE, $actor, $project) as [$sql, $bindings]) {
            $query->orderByRaw($sql.' '.$direction, $bindings);
        }
    }

    /**
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function expressions(string $name, string $type, ?User $actor, ?Project $project): array
    {
        $named = $this->nameExpression($name);
        if ($named !== null) {
            return [$named];
        }

        if (isset(self::ISSUE_COLUMNS[$name])) {
            return [[self::ISSUE_COLUMNS[$name], []]];
        }

        $position = $this->positionExpression($name);
        if ($position !== null) {
            return [$position];
        }

        $users = $this->userExpressions($name);
        if ($users !== null) {
            return $users;
        }

        if ($name === 'estimated_remaining_hours') {
            if ($type !== QueryType::ISSUE) {
                throw new QueryValidationException('Sort column is not available: '.$name.'.');
            }

            return [[IssueQueryColumns::REMAINING_SQL, []]];
        }

        if ($name === 'total_estimated_hours' || $name === 'total_spent_hours') {
            if ($type !== QueryType::ISSUE) {
                throw new QueryValidationException('Sort column is not available: '.$name.'.');
            }
            if ($name === 'total_spent_hours' && ! $this->treeHours->spentAvailable($actor, $project)) {
                throw new QueryValidationException('Sort column is not available: '.$name.'.');
            }

            return [$name === 'total_estimated_hours'
                ? $this->treeHours->estimatedOrder($actor)
                : $this->treeHours->spentOrder($actor)];
        }

        if (preg_match('/^cf_(\d+)$/', $name, $matches) !== 1) {
            throw new QueryValidationException('Sort column is not available: '.$name.'.');
        }

        if ($type !== QueryType::ISSUE) {
            throw new QueryValidationException('Sort column is not available: '.$name.'.');
        }

        return $this->customFieldExpressions($name, (int) $matches[1], $actor, $project);
    }

    /**
     * @return array{0: string, 1: list<int|string>}|null
     */
    private function nameExpression(string $name): ?array
    {
        $sql = match ($name) {
            'project' => '(SELECT projects.name FROM projects WHERE projects.id = issues.project_id)',
            'category' => '(SELECT issue_categories.name FROM issue_categories WHERE issue_categories.id = issues.category_id)',
            'fixed_version' => '(SELECT versions.name FROM versions WHERE versions.id = issues.fixed_version_id)',
            default => null,
        };
        if ($sql === null) {
            return null;
        }

        return [$sql, []];
    }

    /**
     * @return array{0: string, 1: list<int|string>}|null
     */
    private function positionExpression(string $name): ?array
    {
        return match ($name) {
            'priority', 'priority_id' => [
                '(SELECT enumerations.position FROM enumerations WHERE enumerations.id = issues.priority_id AND enumerations.type = ?)',
                ['IssuePriority'],
            ],
            'status', 'status_id' => [
                '(SELECT issue_statuses.position FROM issue_statuses WHERE issue_statuses.id = issues.status_id)',
                [],
            ],
            'tracker', 'tracker_id' => [
                '(SELECT trackers.position FROM trackers WHERE trackers.id = issues.tracker_id)',
                [],
            ],
            default => null,
        };
    }

    /**
     * @return list<array{0: string, 1: list<int|string>}>|null
     */
    private function userExpressions(string $name): ?array
    {
        $column = match ($name) {
            'author', 'author_id' => 'issues.author_id',
            'assigned_to', 'assigned_to_id' => 'issues.assigned_to_id',
            default => null,
        };
        if ($column === null) {
            return null;
        }

        return [
            ['(SELECT users.firstname FROM users WHERE users.id = '.$column.')', []],
            ['(SELECT users.lastname FROM users WHERE users.id = '.$column.')', []],
        ];
    }

    /**
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function customFieldExpressions(string $name, int $id, ?User $actor, ?Project $project): array
    {
        $customField = CustomField::query()->with('roles')->find($id);
        if (! $customField instanceof CustomField || $customField->type !== 'IssueCustomField') {
            throw new QueryValidationException('Custom field sort is unknown: '.$name.'.');
        }
        if (! $this->visibility->canSee($actor, $customField, $project)) {
            throw new QueryValidationException('Custom field is not visible: '.$name.'.');
        }

        $key = FieldFormatKey::tryFrom((string) $customField->field_format);
        if ($key === null) {
            throw new QueryValidationException('Custom field is not sortable: '.$name.'.');
        }

        return match ($key) {
            FieldFormatKey::String, FieldFormatKey::Text, FieldFormatKey::Link, FieldFormatKey::Date, FieldFormatKey::List, FieldFormatKey::Bool => $this->textExpressions($id),
            FieldFormatKey::Int, FieldFormatKey::Progressbar => $this->numericExpressions($id, true),
            FieldFormatKey::Float => $this->numericExpressions($id, false),
            FieldFormatKey::Enumeration => $this->enumerationExpressions($id),
            FieldFormatKey::User => $this->customUserExpressions($id),
            FieldFormatKey::Version => $this->versionExpressions($id),
            FieldFormatKey::Attachment => throw new QueryValidationException('Custom field is not sortable: '.$name.'.'),
        };
    }

    /**
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function textExpressions(int $fieldId): array
    {
        [$where, $bindings] = $this->valueWhere($fieldId);

        return [[
            '(SELECT MIN(custom_values.value) FROM custom_values WHERE '.$where.' AND custom_values.value IS NOT NULL)',
            $bindings,
        ]];
    }

    /**
     * Whole numbers and decimals use the same token as query totals, so junk text
     * is skipped instead of being cast to zero.
     *
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function numericExpressions(int $fieldId, bool $integer): array
    {
        [$where, $bindings] = $this->valueWhere($fieldId);
        $pattern = $integer
            ? '^[+-]?[0-9]+$'
            : '^[+-]?([0-9]+(\\.[0-9]+)?|\\.[0-9]+)$';
        $scale = $integer ? '0' : '10';
        $bindings[] = $pattern;

        return [[
            '(SELECT MIN(CAST(custom_values.value AS DECIMAL(30,'.$scale.'))) FROM custom_values WHERE '.$where.' AND custom_values.value REGEXP ?)',
            $bindings,
        ]];
    }

    /**
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function enumerationExpressions(int $fieldId): array
    {
        [$where, $bindings] = $this->valueWhere($fieldId);
        $bindings[] = '^[0-9]+$';

        return [[
            '(SELECT MIN(custom_field_enumerations.position) FROM custom_values INNER JOIN custom_field_enumerations ON custom_field_enumerations.id = custom_values.value AND custom_field_enumerations.custom_field_id = custom_values.custom_field_id WHERE '.$where.' AND custom_values.value REGEXP ?)',
            $bindings,
        ]];
    }

    /**
     * The inner order picks one user. The outer direction then orders issues by that name.
     *
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function customUserExpressions(int $fieldId): array
    {
        [$where, $bindings] = $this->valueWhere($fieldId);
        $bindings[] = '^[0-9]+$';
        $filter = $where.' AND custom_values.value REGEXP ?';
        $order = 'ORDER BY users.firstname ASC, users.lastname ASC, users.id ASC LIMIT 1';
        $base = 'SELECT users.%s FROM custom_values INNER JOIN users ON users.id = custom_values.value WHERE '.$filter.' '.$order;

        return [
            ['('.sprintf($base, 'firstname').')', $bindings],
            ['('.sprintf($base, 'lastname').')', $bindings],
        ];
    }

    /**
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function versionExpressions(int $fieldId): array
    {
        [$where, $bindings] = $this->valueWhere($fieldId);
        $bindings[] = '^[0-9]+$';

        return [[
            '(SELECT MIN(versions.name) FROM custom_values INNER JOIN versions ON versions.id = custom_values.value WHERE '.$where.' AND custom_values.value REGEXP ?)',
            $bindings,
        ]];
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function valueWhere(int $fieldId): array
    {
        return [
            'custom_values.customized_type = ? AND custom_values.customized_id = issues.id AND custom_values.custom_field_id = ?',
            ['Issue', $fieldId],
        ];
    }

    private function direction(string $direction): string
    {
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new QueryValidationException('Saved query sort could not be read.');
        }

        return $direction;
    }
}
