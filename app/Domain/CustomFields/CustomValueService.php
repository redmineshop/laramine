<?php

namespace App\Domain\CustomFields;

use App\Domain\Attachments\AttachmentService;
use App\Domain\DomainException;
use App\Domain\Workflow\WorkflowService;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads and replaces `custom_values` rows for one customized record.
 *
 * A write deletes and inserts the rows for each changed field. Blank values
 * leave no row. Workflow rules for an issue use the status already stored
 * on the issue, so callers should run this before changing status.
 */
final class CustomValueService
{
    public function __construct(
        private readonly FieldFormatRegistry $formats,
        private readonly CustomFieldScope $scope,
        private readonly CustomFieldVisibility $visibility,
        private readonly CustomizedContext $context,
        private readonly WorkflowService $workflows,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * @param  array<int, mixed>  $inputs
     * @return list<CustomFieldValue>
     */
    public function sync(User $actor, Model $record, array $inputs, bool $applyDefaults): array
    {
        $type = $this->requireType($record);
        $recordId = $this->requireId($record);

        return DB::transaction(function () use ($actor, $record, $inputs, $applyDefaults, $type, $recordId): array {
            $applicable = $this->scope->applicable($record);
            $byId = [];
            foreach ($applicable as $field) {
                $byId[(int) $field->id] = $field;
            }

            foreach ($inputs as $id => $unused) {
                unset($unused);
                if (! isset($byId[$id])) {
                    $this->rejectUnknown((int) $id);
                }
            }

            $stored = $this->storedRows($type, $recordId);
            $errors = [];
            /** @var list<array{field: CustomField, rows: list<string>}> $writes */
            $writes = [];

            foreach ($applicable as $field) {
                $outcome = $this->plan($actor, $record, $field, $inputs, $stored, $applyDefaults);
                if ($outcome['messages'] !== []) {
                    $errors[] = [
                        'id' => (int) $field->id,
                        'name' => (string) $field->name,
                        'messages' => $outcome['messages'],
                    ];

                    continue;
                }
                $previous = $stored[(int) $field->id] ?? [];
                if ($outcome['rows'] !== $previous) {
                    $writes[] = ['field' => $field, 'rows' => $outcome['rows']];
                }
            }

            if ($errors !== []) {
                throw new CustomFieldValidationException($errors);
            }

            foreach ($writes as $write) {
                $this->replace($record, $type, $recordId, $write['field'], $write['rows']);
            }

            return $this->present($actor, $record, $type, $recordId);
        });
    }

    /**
     * @return list<CustomFieldValue>
     */
    public function read(?User $actor, Model $record): array
    {
        $type = $this->context->customizedType($record);
        $id = $record->getKey();
        if ($type === null || ! is_numeric($id)) {
            return [];
        }

        return $this->present($actor, $record, $type, (int) $id);
    }

    /**
     * @param  array<int, mixed>  $inputs
     * @param  array<int, list<string>>  $stored
     * @return array{rows: list<string>, messages: list<string>}
     */
    private function plan(User $actor, Model $record, CustomField $field, array $inputs, array $stored, bool $applyDefaults): array
    {
        $format = $this->formats->get((string) $field->field_format);
        $previous = $stored[(int) $field->id] ?? [];
        $submitted = array_key_exists((int) $field->id, $inputs);
        $project = $this->context->project($record);
        $visible = $this->visibility->canSee($actor, $field, $project);
        $messages = [];

        if (! $submitted) {
            if ($applyDefaults && $previous === []) {
                $raw = $format->defaultRaw($field);
                $messages = $format->validate($field, $raw, $record);
                $rows = $messages === [] ? $format->serialize($field, $raw) : [];
            } else {
                $rows = $previous;
            }
        } elseif (! $visible) {
            $rows = $previous;
            $probeMessages = $format->validate($field, $inputs[(int) $field->id], $record);
            $probe = $probeMessages === [] ? $format->serialize($field, $inputs[(int) $field->id]) : null;
            if ($probe === null || $probe !== $previous) {
                $messages[] = 'Custom field is not visible.';
            }
        } else {
            $raw = $inputs[(int) $field->id];
            $messages = $format->validate($field, $raw, $record);
            $rows = $messages === [] ? $format->serialize($field, $raw) : $previous;
            if ($messages === [] && ! $this->visibility->canEdit($actor, $field) && $rows !== $previous) {
                $messages[] = 'Custom field is not editable.';
                $rows = $previous;
            }
        }

        $rule = $record instanceof Issue ? $this->issueRule($actor, $record, $field) : null;
        if ($visible && $messages === [] && $rule === WorkflowService::RULE_READONLY && $submitted && $rows !== $previous) {
            $messages[] = 'Value is read-only in this status.';
        }
        if ($visible && $messages === [] && ($field->is_required || $rule === WorkflowService::RULE_REQUIRED) && $rows === []) {
            $messages[] = 'Value is required.';
        }

        return ['rows' => $rows, 'messages' => $messages];
    }

    private function issueRule(User $actor, Issue $issue, CustomField $field): ?string
    {
        if ($actor->admin && $actor->isActive()) {
            return null;
        }
        if ($field->visible) {
            return $this->workflows->fieldRule($actor, $issue, (string) $field->id);
        }

        $project = $issue->project;
        if (! $project instanceof Project) {
            return WorkflowService::RULE_READONLY;
        }

        $roles = $this->workflows->workflowRoles($actor, $project);
        if ($roles->isEmpty()) {
            return WorkflowService::RULE_READONLY;
        }

        $visibleRoleIds = [];
        foreach ($field->roles as $role) {
            $visibleRoleIds[] = (int) $role->id;
        }

        $collected = [];
        foreach ($roles as $role) {
            $roleId = (int) $role->id;
            if (! in_array($roleId, $visibleRoleIds, true)) {
                $collected[] = WorkflowService::RULE_READONLY;

                continue;
            }

            $rows = Workflow::query()
                ->where('type', WorkflowService::TYPE_FIELD_PERMISSION)
                ->where('tracker_id', $issue->tracker_id)
                ->where('role_id', $roleId)
                ->where('old_status_id', $issue->status_id)
                ->where('field_name', (string) $field->id)
                ->get();
            $picked = null;
            foreach ($rows as $row) {
                if ($row->rule === WorkflowService::RULE_REQUIRED) {
                    $picked = WorkflowService::RULE_REQUIRED;
                    break;
                }
                if ($row->rule === WorkflowService::RULE_READONLY) {
                    $picked = WorkflowService::RULE_READONLY;
                }
            }
            if ($picked === null) {
                return null;
            }
            $collected[] = $picked;
        }

        if (in_array(WorkflowService::RULE_REQUIRED, $collected, true)) {
            return WorkflowService::RULE_REQUIRED;
        }

        return $collected === [] ? null : WorkflowService::RULE_READONLY;
    }

    /**
     * @return list<CustomFieldValue>
     */
    private function present(?User $actor, Model $record, string $type, int $recordId): array
    {
        $stored = $this->storedRows($type, $recordId);
        $project = $this->context->project($record);
        $values = [];
        foreach ($this->scope->applicable($record) as $field) {
            if (! $this->visibility->canSee($actor, $field, $project)) {
                continue;
            }
            $format = $this->formats->get((string) $field->field_format);
            $raws = $stored[(int) $field->id] ?? [];
            $values[] = new CustomFieldValue(
                (int) $field->id,
                (string) $field->name,
                (string) $field->field_format,
                $this->castRows($field, $format, $raws),
                $raws,
            );
        }

        return $values;
    }

    /**
     * @param  list<string>  $raws
     */
    private function castRows(CustomField $field, FieldFormat $format, array $raws): mixed
    {
        if ($raws === []) {
            return null;
        }
        if ($field->multiple) {
            $cast = [];
            foreach ($raws as $raw) {
                $cast[] = $format->cast($field, $raw);
            }

            return $cast;
        }

        return $format->cast($field, $raws[0]);
    }

    /**
     * @return array<int, list<string>>
     */
    private function storedRows(string $type, int $recordId): array
    {
        $grouped = [];
        $rows = CustomValue::query()
            ->where('customized_type', $type)
            ->where('customized_id', $recordId)
            ->orderBy('id')
            ->get(['custom_field_id', 'value']);
        foreach ($rows as $row) {
            $value = $row->value;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $grouped[(int) $row->custom_field_id][] = $value;
        }

        return $grouped;
    }

    /**
     * @param  list<string>  $rows
     */
    private function replace(Model $record, string $type, int $recordId, CustomField $field, array $rows): void
    {
        CustomValue::query()
            ->where('customized_type', $type)
            ->where('customized_id', $recordId)
            ->where('custom_field_id', $field->id)
            ->delete();
        foreach ($rows as $value) {
            CustomValue::query()->create([
                'custom_field_id' => $field->id,
                'customized_type' => $type,
                'customized_id' => $recordId,
                'value' => $value,
            ]);
        }

        if ((string) $field->field_format === 'attachment') {
            $this->attachments->bindStoredIds($record, $rows);
        }
    }

    private function rejectUnknown(int $id): void
    {
        $field = CustomField::query()->find($id);
        $name = $field instanceof CustomField ? (string) $field->name : 'Custom field';
        $message = $field instanceof CustomField
            ? 'Custom field does not apply to this record.'
            : 'Custom field does not exist.';

        throw new CustomFieldValidationException([[
            'id' => $id,
            'name' => $name,
            'messages' => [$message],
        ]]);
    }

    private function requireType(Model $record): string
    {
        $type = $this->context->customizedType($record);
        if ($type === null) {
            throw new DomainException('This record does not support custom fields.');
        }

        return $type;
    }

    private function requireId(Model $record): int
    {
        $id = $record->getKey();
        if (! is_numeric($id)) {
            throw new DomainException('Save the record before writing custom values.');
        }

        return (int) $id;
    }
}
