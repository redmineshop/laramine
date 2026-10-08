<?php

namespace App\Domain\Queries;

use App\Domain\Acl\PermissionService;
use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatKey;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Model;

/**
 * Group counts and totals for an IssueQuery.
 *
 * Groupable columns are the 7.0.1 issue columns marked groupable, plus a
 * visible issue custom field whose format groups and that is not multiple.
 * Several stored values put the issue in each value's group. A multiple
 * field has no group statement, so it is rejected. Blank values are one group.
 * Timestamp columns group by the calendar date in the actor's time zone.
 */
final class IssueQueryGrouping
{
    /**
     * @var array<string, string>
     */
    private const COLUMNS = [
        'project' => 'project',
        'project_id' => 'project',
        'tracker' => 'tracker',
        'tracker_id' => 'tracker',
        'status' => 'status',
        'status_id' => 'status',
        'priority' => 'priority',
        'priority_id' => 'priority',
        'author' => 'author',
        'author_id' => 'author',
        'assigned_to' => 'assigned_to',
        'assigned_to_id' => 'assigned_to',
        'category' => 'category',
        'category_id' => 'category',
        'fixed_version' => 'fixed_version',
        'fixed_version_id' => 'fixed_version',
        'done_ratio' => 'done_ratio',
        'start_date' => 'start_date',
        'due_date' => 'due_date',
        'created_on' => 'created_on',
        'updated_on' => 'updated_on',
        'closed_on' => 'closed_on',
        'is_private' => 'is_private',
    ];

    /**
     * @var list<string>
     */
    private const TIMESTAMPS = ['created_on', 'updated_on', 'closed_on'];

    /**
     * @var list<FieldFormatKey>
     */
    private const GROUPABLE_FORMATS = [
        FieldFormatKey::List,
        FieldFormatKey::Enumeration,
        FieldFormatKey::Bool,
        FieldFormatKey::Date,
        FieldFormatKey::User,
        FieldFormatKey::Version,
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CustomFieldVisibility $visibility,
        private readonly IssueQueryTotals $totals,
    ) {}

    public function assertGroupable(string $name, ?User $actor, ?Project $project): void
    {
        $this->resolve($name, $actor, $project);
    }

    public function isGroupable(string $name, ?User $actor, ?Project $project): bool
    {
        try {
            $this->resolve($name, $actor, $project);
        } catch (QueryValidationException) {
            return false;
        }

        return true;
    }

    public function customFieldGroupable(CustomField $field): bool
    {
        if ($field->type !== 'IssueCustomField' || $field->multiple) {
            return false;
        }

        $format = FieldFormatKey::tryFrom((string) $field->field_format);

        return $format !== null && in_array($format, self::GROUPABLE_FORMATS, true);
    }

    public function privateGroupable(?User $actor): bool
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return true;
        }

        foreach (Project::query()->orderBy('id')->get() as $project) {
            if ($this->permissions->allowed($actor, 'set_issues_private', $project)
                || $this->permissions->allowed($actor, 'set_own_issues_private', $project)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<Issue>  $issues
     * @param  list<QueryTotalColumn>  $totalColumns
     * @return list<array{key: string|null, value: string|null, count: int, ids: list<int>, totals: array<string, string>}>
     */
    public function summarize(?User $actor, ?Project $project, iterable $issues, string $groupBy, array $totalColumns): array
    {
        $column = $this->resolve($groupBy, $actor, $project);
        $list = [];
        foreach ($issues as $issue) {
            $list[] = $issue;
        }

        $memberships = $column['field'] instanceof CustomField
            ? $this->customMemberships($list, $column['field'])
            : $this->builtinMemberships($actor, $list, $column['name']);

        $headers = [];
        $members = [];
        foreach ($list as $issue) {
            $id = (int) $issue->id;
            foreach ($memberships[$id] ?? [['key' => null, 'value' => null]] as $membership) {
                $token = $membership['value'] === null ? 'n' : 'v:'.$membership['value'];
                if (! isset($headers[$token])) {
                    $headers[$token] = [
                        'key' => $membership['key'],
                        'value' => $membership['value'],
                    ];
                    $members[$token] = [];
                }
                $members[$token][$id] = true;
            }
        }

        $groups = [];
        foreach ($headers as $token => $header) {
            $ids = array_keys($members[$token]);
            $totals = [];
            if ($totalColumns !== []) {
                $scope = Issue::query()->whereIn('issues.id', $ids === [] ? [0] : $ids);
                $totals = $this->totals->sum($scope, $totalColumns, $actor);
            }
            $groups[] = $this->groupRow($header['key'], $header['value'], $ids, $totals);
        }

        return $groups;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, string>  $totals
     * @return array{key: string|null, value: string|null, count: int, ids: list<int>, totals: array<string, string>}
     */
    private function groupRow(?string $key, ?string $value, array $ids, array $totals): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'count' => count($ids),
            'ids' => $ids,
            'totals' => $totals,
        ];
    }

    /**
     * @return array{name: string, field: CustomField|null}
     */
    private function resolve(string $name, ?User $actor, ?Project $project): array
    {
        if (isset(self::COLUMNS[$name])) {
            $column = self::COLUMNS[$name];
            if ($column === 'is_private' && ! $this->privateGroupable($actor)) {
                throw new QueryValidationException('Query group is not available: '.$name.'.');
            }

            return ['name' => $column, 'field' => null];
        }

        if (preg_match('/^cf_(\d+)$/', $name, $matches) !== 1) {
            throw new QueryValidationException('Query group is not available: '.$name.'.');
        }

        $field = CustomField::query()->with('roles')->find((int) $matches[1]);
        if (! $field instanceof CustomField || $field->type !== 'IssueCustomField') {
            throw new QueryValidationException('Custom field group is unknown: '.$name.'.');
        }
        if (! $this->visibility->canSee($actor, $field, $project)) {
            throw new QueryValidationException('Custom field is not visible: '.$name.'.');
        }
        if (! $this->customFieldGroupable($field)) {
            throw new QueryValidationException('Custom field cannot be grouped: '.$name.'.');
        }

        return ['name' => $name, 'field' => $field];
    }

    /**
     * @param  list<Issue>  $issues
     * @return array<int, list<array{key: string|null, value: string|null}>>
     */
    private function builtinMemberships(?User $actor, array $issues, string $column): array
    {
        $labels = $this->labels($issues, $column);
        $rows = [];
        foreach ($issues as $issue) {
            $rows[(int) $issue->id] = [$this->builtin($actor, $issue, $column, $labels)];
        }

        return $rows;
    }

    /**
     * @param  list<Issue>  $issues
     * @return array<int, list<array{key: string|null, value: string|null}>>
     */
    private function customMemberships(array $issues, CustomField $field): array
    {
        $ids = [];
        foreach ($issues as $issue) {
            $ids[] = (int) $issue->id;
        }
        $format = FieldFormatKey::tryFrom((string) $field->field_format);
        $stored = [];
        $userIds = [];
        $versionIds = [];
        $enumIds = [];
        if ($ids !== []) {
            $values = CustomValue::query()
                ->where('customized_type', 'Issue')
                ->where('custom_field_id', $field->id)
                ->whereIn('customized_id', $ids)
                ->orderBy('id')
                ->get();
            foreach ($values as $value) {
                $text = $value->getAttribute('value');
                if (! is_string($text) || $text === '') {
                    continue;
                }
                $stored[(int) $value->customized_id][] = $text;
                if (preg_match('/^[0-9]+$/', $text) !== 1) {
                    continue;
                }
                $id = (int) $text;
                if ($format === FieldFormatKey::User) {
                    $userIds[] = $id;
                } elseif ($format === FieldFormatKey::Version) {
                    $versionIds[] = $id;
                } elseif ($format === FieldFormatKey::Enumeration) {
                    $enumIds[] = $id;
                }
            }
        }

        $users = $this->userNames($userIds);
        $versions = $this->names(Version::query()->whereIn('id', $versionIds === [] ? [0] : $versionIds)->get());
        $enums = [];
        if ($enumIds !== []) {
            foreach (CustomFieldEnumeration::query()->whereIn('id', $enumIds)->get() as $row) {
                if ((int) $row->custom_field_id === (int) $field->id) {
                    $enums[(int) $row->id] = (string) $row->name;
                }
            }
        }

        $rows = [];
        foreach ($issues as $issue) {
            $parts = $stored[(int) $issue->id] ?? [];
            if ($parts === []) {
                $rows[(int) $issue->id] = [['key' => null, 'value' => null]];

                continue;
            }
            $seen = [];
            $memberships = [];
            foreach ($parts as $part) {
                if (isset($seen[$part])) {
                    continue;
                }
                $seen[$part] = true;
                $memberships[] = [
                    'key' => $this->customLabel($format, $part, $users, $versions, $enums),
                    'value' => $part,
                ];
            }
            $rows[(int) $issue->id] = $memberships;
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $users
     * @param  array<int, string>  $versions
     * @param  array<int, string>  $enums
     */
    private function customLabel(?FieldFormatKey $format, string $stored, array $users, array $versions, array $enums): string
    {
        if ($format === null || preg_match('/^[0-9]+$/', $stored) !== 1) {
            return $stored;
        }

        $id = (int) $stored;

        return match ($format) {
            FieldFormatKey::User => $users[$id] ?? $stored,
            FieldFormatKey::Version => $versions[$id] ?? $stored,
            FieldFormatKey::Enumeration => $enums[$id] ?? $stored,
            FieldFormatKey::String, FieldFormatKey::Text, FieldFormatKey::Link, FieldFormatKey::Int, FieldFormatKey::Float, FieldFormatKey::Date, FieldFormatKey::List, FieldFormatKey::Bool, FieldFormatKey::Attachment, FieldFormatKey::Progressbar => $stored,
        };
    }

    /**
     * @param  list<Issue>  $issues
     * @return array<string, array<int, string>>
     */
    private function labels(array $issues, string $column): array
    {
        $ids = function (string $attribute) use ($issues): array {
            $found = [];
            foreach ($issues as $issue) {
                $raw = $issue->getAttribute($attribute);
                if (is_numeric($raw)) {
                    $found[] = (int) $raw;
                }
            }

            return $found === [] ? [0] : $found;
        };

        return match ($column) {
            'project' => ['project' => $this->names(Project::query()->whereIn('id', $ids('project_id'))->get())],
            'tracker' => ['tracker' => $this->names(Tracker::query()->whereIn('id', $ids('tracker_id'))->get())],
            'status' => ['status' => $this->names(IssueStatus::query()->whereIn('id', $ids('status_id'))->get())],
            'priority' => ['priority' => $this->names(Enumeration::query()->whereIn('id', $ids('priority_id'))->get())],
            'author', 'assigned_to' => ['user' => $this->userNames([...$ids('author_id'), ...$ids('assigned_to_id')])],
            'category' => ['category' => $this->names(IssueCategory::query()->whereIn('id', $ids('category_id'))->get())],
            'fixed_version' => ['version' => $this->names(Version::query()->whereIn('id', $ids('fixed_version_id'))->get())],
            default => [],
        };
    }

    /**
     * @param  array<string, array<int, string>>  $labels
     * @return array{key: string|null, value: string|null}
     */
    private function builtin(?User $actor, Issue $issue, string $column, array $labels): array
    {
        if (in_array($column, self::TIMESTAMPS, true)) {
            $date = $this->timestamp($actor, $issue->getRawOriginal($column));

            return ['key' => $date, 'value' => $date];
        }

        if ($column === 'start_date' || $column === 'due_date') {
            $date = $this->day($issue->getRawOriginal($column));

            return ['key' => $date, 'value' => $date];
        }

        if ($column === 'done_ratio') {
            $raw = $issue->getRawOriginal('done_ratio');
            if (! is_numeric($raw)) {
                return ['key' => null, 'value' => null];
            }
            $text = (string) (int) $raw;

            return ['key' => $text, 'value' => $text];
        }

        if ($column === 'is_private') {
            $flag = $issue->getRawOriginal('is_private');
            $text = $flag === true || $flag === 1 || $flag === '1' ? '1' : '0';

            return ['key' => $text, 'value' => $text];
        }

        $resolved = match ($column) {
            'project' => ['project_id', $labels['project'] ?? []],
            'tracker' => ['tracker_id', $labels['tracker'] ?? []],
            'status' => ['status_id', $labels['status'] ?? []],
            'priority' => ['priority_id', $labels['priority'] ?? []],
            'author' => ['author_id', $labels['user'] ?? []],
            'assigned_to' => ['assigned_to_id', $labels['user'] ?? []],
            'category' => ['category_id', $labels['category'] ?? []],
            'fixed_version' => ['fixed_version_id', $labels['version'] ?? []],
            default => null,
        };
        if ($resolved === null) {
            return ['key' => null, 'value' => null];
        }
        [$attribute, $map] = $resolved;
        $raw = $issue->getAttribute($attribute);
        if (! is_numeric($raw)) {
            return ['key' => null, 'value' => null];
        }
        $id = (int) $raw;

        return ['key' => $map[$id] ?? null, 'value' => (string) $id];
    }

    private function timestamp(?User $actor, mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $instant = CarbonImmutable::parse($raw, DateWindow::userZone(null));
        } catch (Exception) {
            return null;
        }

        return $instant->timezone(DateWindow::userZone($actor))->toDateString();
    }

    private function day(mixed $raw): ?string
    {
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) !== 1) {
            return null;
        }

        return substr($raw, 0, 10);
    }

    /**
     * @param  iterable<Model>  $rows
     * @return array<int, string>
     */
    private function names(iterable $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->getAttribute('id')] = (string) $row->getAttribute('name');
        }

        return $map;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (User::query()->whereIn('id', $ids)->get() as $user) {
            $name = trim((string) $user->firstname.' '.(string) $user->lastname);
            if ($name === '') {
                $name = (string) $user->login;
            }
            if ($name === '') {
                $name = (string) $user->id;
            }
            $map[(int) $user->id] = $name;
        }

        return $map;
    }
}
