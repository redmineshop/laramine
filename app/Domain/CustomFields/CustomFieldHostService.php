<?php

namespace App\Domain\CustomFields;

use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Project;
use App\Models\User;

/**
 * Custom values on enumeration and document hosts.
 *
 * Enumeration edits are limited to an active admin, matching the admin form.
 * The enumeration index lists shared rows and custom fields whose `visible`
 * column is true. Document edits use `view_documents`, `add_documents`, and
 * `edit_documents` on the project the caller supplies. There is no documents
 * table, so the host is an id plus that project.
 */
final class CustomFieldHostService
{
    /**
     * Enumeration index slugs and the `enumerations.type` they list.
     *
     * @var array<string, string>
     */
    public const INDEX = [
        'issue_priorities' => CustomFieldTypes::ISSUE_PRIORITY,
        'time_entry_activities' => CustomFieldTypes::TIME_ENTRY_ACTIVITY,
        'document_categories' => CustomFieldTypes::DOCUMENT_CATEGORY,
    ];

    public function __construct(
        private readonly CustomValueService $values,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @return array<string, list<array{id: int, name: string, is_default: bool, active: bool, custom_fields: list<array{id: int, name: string, value: string|list<string>|null}>}>>
     */
    public function index(?User $actor, string $slug): array
    {
        $this->assertAdmin($actor);
        $type = self::INDEX[$slug] ?? null;
        if ($type === null) {
            throw new DomainException('Enumeration type is not supported.');
        }

        $rows = [];
        $enumerations = Enumeration::query()
            ->where('type', $type)
            ->whereNull('project_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        foreach ($enumerations as $enumeration) {
            $rows[] = [
                'id' => (int) $enumeration->id,
                'name' => (string) $enumeration->name,
                'is_default' => (bool) $enumeration->is_default,
                'active' => (bool) $enumeration->active,
                'custom_fields' => $this->columnVisibleFields($enumeration),
            ];
        }

        return [$slug => $rows];
    }

    /**
     * @return list<array{id: int, name: string, field_format: string, value: mixed, raw: list<string>}>
     */
    public function readEnumeration(?User $actor, Enumeration $enumeration): array
    {
        $this->assertAdmin($actor);
        $this->assertHostType($enumeration);

        return $this->present($this->values->read($actor, $enumeration));
    }

    /**
     * @param  array<int, mixed>  $inputs
     * @return list<array{id: int, name: string, field_format: string, value: mixed, raw: list<string>}>
     */
    public function writeEnumeration(?User $actor, Enumeration $enumeration, array $inputs, bool $applyDefaults): array
    {
        $this->assertAdmin($actor);
        $this->assertHostType($enumeration);
        if (! $actor instanceof User) {
            throw new PermissionDeniedException('admin');
        }

        return $this->present($this->values->sync($actor, $enumeration, $inputs, $applyDefaults));
    }

    /**
     * @return list<array{id: int, name: string, field_format: string, value: mixed, raw: list<string>}>
     */
    public function readDocument(?User $actor, Project $project, int $documentId): array
    {
        $this->assertDocument($actor, $project, 'view_documents');

        return $this->present($this->values->read($actor, $this->document($documentId, $project)));
    }

    /**
     * @param  array<int, mixed>  $inputs
     * @return list<array{id: int, name: string, field_format: string, value: mixed, raw: list<string>}>
     */
    public function writeDocument(?User $actor, Project $project, int $documentId, array $inputs, bool $applyDefaults): array
    {
        $permission = $applyDefaults ? 'add_documents' : 'edit_documents';
        $this->assertDocument($actor, $project, $permission);
        if (! $actor instanceof User) {
            throw new PermissionDeniedException($permission);
        }

        return $this->present($this->values->sync($actor, $this->document($documentId, $project), $inputs, $applyDefaults));
    }

    /**
     * @return array<int, mixed>
     */
    public function inputs(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new DomainException('custom_fields must be a list.');
        }

        $mapped = [];
        foreach ($raw as $row) {
            if (! is_array($row) || ! array_key_exists('id', $row)) {
                throw new DomainException('Each custom field needs an id.');
            }
            if (! is_numeric($row['id'])) {
                throw new DomainException('Custom field id must be an integer.');
            }
            $id = (int) $row['id'];
            if (array_key_exists($id, $mapped)) {
                throw new DomainException('Custom field is repeated.');
            }
            $mapped[$id] = $row['value'] ?? null;
        }

        return $mapped;
    }

    /**
     * @param  list<CustomFieldValue>  $values
     * @return list<array{id: int, name: string, field_format: string, value: mixed, raw: list<string>}>
     */
    private function present(array $values): array
    {
        $rows = [];
        foreach ($values as $value) {
            $rows[] = $value->toArray();
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, name: string, value: string|list<string>|null}>
     */
    private function columnVisibleFields(Enumeration $enumeration): array
    {
        $sti = CustomFieldTypes::stiFor((string) $enumeration->type);
        if ($sti === null) {
            return [];
        }

        $stored = [];
        $rows = CustomValue::query()
            ->where('customized_type', (string) $enumeration->type)
            ->where('customized_id', (int) $enumeration->id)
            ->orderBy('id')
            ->get(['custom_field_id', 'value']);
        foreach ($rows as $row) {
            $value = $row->value;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $stored[(int) $row->custom_field_id][] = $value;
        }

        $fields = [];
        $definitions = CustomField::query()
            ->where('type', $sti)
            ->where('visible', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        foreach ($definitions as $field) {
            $raws = $stored[(int) $field->id] ?? [];
            $value = null;
            if ($field->multiple) {
                $value = $raws;
            } elseif ($raws !== []) {
                $value = $raws[0];
            }
            $fields[] = [
                'id' => (int) $field->id,
                'name' => (string) $field->name,
                'value' => $value,
            ];
        }

        return $fields;
    }

    private function document(int $documentId, Project $project): Document
    {
        if ($documentId <= 0) {
            throw new DomainException('Document id is invalid.');
        }

        $document = new Document;
        $document->id = $documentId;
        $document->exists = true;
        $document->setAttribute('project_id', $project->id);
        $document->setRelation('project', $project);

        return $document;
    }

    private function assertHostType(Enumeration $enumeration): void
    {
        if (! in_array((string) $enumeration->type, self::INDEX, true)) {
            throw new DomainException('This enumeration does not take custom fields.');
        }
    }

    private function assertAdmin(?User $actor): void
    {
        if (! $actor instanceof User || ! $actor->admin || ! $actor->isActive()) {
            throw new PermissionDeniedException('admin');
        }
    }

    private function assertDocument(?User $actor, Project $project, string $permission): void
    {
        if (! $this->permissions->allowed($actor, $permission, $project)) {
            throw new PermissionDeniedException($permission);
        }
    }
}
