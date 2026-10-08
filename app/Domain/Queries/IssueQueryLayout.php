<?php

namespace App\Domain\Queries;

use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatKey;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Domain\Settings\SettingValue;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;

/**
 * Available, default, inline, block, and totalable issue columns.
 *
 * A missing `issue_list_default_columns` setting keeps the built-in default.
 * A global query prepends `project` to that default. `description`, `last_notes`,
 * and text custom fields are block columns. `id` is frozen. Int and float custom
 * fields are totalable, along with `estimated_hours`, `estimated_remaining_hours`,
 * and `spent_hours` when time entries are visible.
 */
final class IssueQueryLayout
{
    /**
     * @var list<string>
     */
    private const BLOCK = ['description', 'last_notes'];

    /**
     * @var list<string>
     */
    private const GROUPABLE = [
        'project',
        'tracker',
        'status',
        'priority',
        'author',
        'assigned_to',
        'updated_on',
        'category',
        'fixed_version',
        'start_date',
        'due_date',
        'done_ratio',
        'created_on',
        'closed_on',
        'is_private',
    ];

    /**
     * @var list<string>
     */
    private const TOTALABLE = [
        'estimated_hours',
        'estimated_remaining_hours',
        'spent_hours',
    ];

    public function __construct(
        private readonly SettingValue $settings,
        private readonly CustomFieldVisibility $visibility,
        private readonly FieldFormatRegistry $formats,
        private readonly IssueTreeHours $treeHours,
        private readonly IssueQueryGrouping $grouping,
    ) {}

    /**
     * @param  list<string>|null  $stored
     * @return list<string>
     */
    public function defaultNames(?Project $project, ?array $stored = null): array
    {
        $names = $stored ?? $this->settings->issueListDefaultColumns() ?? IssueQueryColumns::DEFAULT;
        if ($project !== null) {
            return $names;
        }
        if ($names !== [] && $names[0] === 'project') {
            return $names;
        }

        $merged = ['project'];
        foreach ($names as $name) {
            if ($name !== 'project') {
                $merged[] = $name;
            }
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    public function defaultTotals(): array
    {
        return $this->settings->issueListDefaultTotals();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function defaultSort(): array
    {
        return [['id', 'desc']];
    }

    /**
     * @param  list<string>  $names
     * @return array{0: list<string>, 1: list<string>}
     */
    public function split(?User $actor, ?Project $project, array $names): array
    {
        $inline = [];
        $block = [];
        foreach ($names as $name) {
            if ($this->block($name, $actor, $project)) {
                $block[] = $name;
            } else {
                $inline[] = $name;
            }
        }

        return [$inline, $block];
    }

    /**
     * @return list<array{name: string, inline: bool, groupable: bool, totalable: bool, frozen: bool}>
     */
    public function available(?User $actor, ?Project $project): array
    {
        $rows = [];
        foreach (IssueQueryColumns::AVAILABLE as $name) {
            if (! $this->offered($name, $actor, $project)) {
                continue;
            }
            $rows[] = $this->describeBuiltin($name, $actor, $project);
        }

        $fields = CustomField::query()
            ->with('roles')
            ->where('type', 'IssueCustomField')
            ->orderBy('id')
            ->get();
        foreach ($fields as $field) {
            if (! $this->visibility->canSee($actor, $field, $project)) {
                continue;
            }
            $name = 'cf_'.$field->id;
            $format = FieldFormatKey::tryFrom((string) $field->field_format);
            $rows[] = [
                'name' => $name,
                'inline' => $format !== FieldFormatKey::Text,
                'groupable' => $this->grouping->customFieldGroupable($field),
                'totalable' => $this->formats->get((string) $field->field_format)->supportsTotal(),
                'frozen' => false,
            ];
        }

        return $rows;
    }

    /**
     * @return array{name: string, inline: bool, groupable: bool, totalable: bool, frozen: bool}
     */
    private function describeBuiltin(string $name, ?User $actor, ?Project $project): array
    {
        $totalable = in_array($name, self::TOTALABLE, true);
        if ($name === 'spent_hours' && ! $this->treeHours->spentAvailable($actor, $project)) {
            $totalable = false;
        }

        return [
            'name' => $name,
            'inline' => ! in_array($name, self::BLOCK, true),
            'groupable' => in_array($name, self::GROUPABLE, true) && ($name !== 'is_private' || $this->grouping->privateGroupable($actor)),
            'totalable' => $totalable,
            'frozen' => $name === 'id',
        ];
    }

    private function offered(string $name, ?User $actor, ?Project $project): bool
    {
        if (($name === 'spent_hours' || $name === 'total_spent_hours') && ! $this->treeHours->spentAvailable($actor, $project)) {
            return false;
        }

        if ($name === 'is_private' && ! $this->grouping->privateGroupable($actor)) {
            return false;
        }

        return true;
    }

    private function block(string $name, ?User $actor, ?Project $project): bool
    {
        if (in_array($name, self::BLOCK, true)) {
            return true;
        }

        if (preg_match('/^cf_(\d+)$/', $name, $matches) !== 1) {
            return false;
        }

        $field = CustomField::query()->with('roles')->find((int) $matches[1]);
        if (! $field instanceof CustomField || ! $this->visibility->canSee($actor, $field, $project)) {
            return false;
        }

        return (string) $field->field_format === FieldFormatKey::Text->value;
    }
}
