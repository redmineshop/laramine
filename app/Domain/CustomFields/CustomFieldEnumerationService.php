<?php

namespace App\Domain\CustomFields;

use App\Domain\DomainException;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inserts, renames, reorders, activates, and deletes enumeration options.
 *
 * Stored `custom_values` keep the option id. Renaming or reordering does not
 * rewrite them. Deleting an option that those rows still store requires
 * another option of the same field; the stored ids are then rewritten.
 */
final class CustomFieldEnumerationService
{
    public function create(CustomField $field, string $name, bool $active = true): CustomFieldEnumeration
    {
        return DB::transaction(function () use ($field, $name, $active): CustomFieldEnumeration {
            $this->assertEnumerationField($field);
            $storedName = $this->name($name);
            $this->assertUniqueName($field, $storedName, null);

            $max = CustomFieldEnumeration::query()
                ->where('custom_field_id', $field->id)
                ->lockForUpdate()
                ->max('position');
            $position = is_numeric($max) ? ((int) $max + 1) : 1;

            $option = new CustomFieldEnumeration([
                'custom_field_id' => $field->id,
                'name' => $storedName,
                'active' => $active,
                'position' => $position,
            ]);
            $option->save();

            return $this->requireSaved($option);
        });
    }

    public function rename(CustomFieldEnumeration $option, string $name): CustomFieldEnumeration
    {
        return DB::transaction(function () use ($option, $name): CustomFieldEnumeration {
            $field = $this->fieldFor($option);
            $storedName = $this->name($name);
            $this->assertUniqueName($field, $storedName, (int) $option->id);
            $option->name = $storedName;
            $option->save();

            return $this->requireSaved($option);
        });
    }

    public function setActive(CustomFieldEnumeration $option, bool $active): CustomFieldEnumeration
    {
        return DB::transaction(function () use ($option, $active): CustomFieldEnumeration {
            $field = $this->fieldFor($option);
            if ((bool) $option->active === $active) {
                return $option;
            }
            if (! $active && $this->isCurrentDefault($field, $option)) {
                throw new DomainException('Enumeration is the default value.');
            }
            $option->active = $active;
            $option->save();

            return $this->requireSaved($option);
        });
    }

    /**
     * Rewrite `position` to 1..n in `$orderedIds`. Every option of the field is required.
     *
     * @param  list<int>  $orderedIds
     * @return list<CustomFieldEnumeration>
     */
    public function reorder(CustomField $field, array $orderedIds): array
    {
        return DB::transaction(function () use ($field, $orderedIds): array {
            $this->assertEnumerationField($field);
            $rows = CustomFieldEnumeration::query()
                ->where('custom_field_id', $field->id)
                ->lockForUpdate()
                ->get();

            $byId = [];
            foreach ($rows as $row) {
                $byId[(int) $row->id] = $row;
            }
            if (count($orderedIds) !== count($byId)) {
                throw new DomainException('Reorder must list every enumeration.');
            }

            $seen = [];
            $ordered = [];
            $position = 1;
            foreach ($orderedIds as $id) {
                if (isset($seen[$id]) || ! isset($byId[$id])) {
                    throw new DomainException('Reorder must list every enumeration.');
                }
                $seen[$id] = true;
                $row = $byId[$id];
                $row->position = $position;
                $row->save();
                $ordered[] = $row;
                $position++;
            }

            return $ordered;
        });
    }

    /**
     * Remove one option.
     *
     * An unused option can be removed with no replacement. When `custom_values`
     * for this field still store the id, `$reassignTo` must be a different
     * option of the same field. Those rows are rewritten to that id. If the
     * same record already stores the replacement, the old row is removed so
     * the id is not stored twice. The option named by `default_value` cannot
     * be removed. Positions of the options that remain are left as stored.
     * The caller authorizes this change.
     */
    public function delete(CustomFieldEnumeration $option, ?CustomFieldEnumeration $reassignTo = null): void
    {
        DB::transaction(function () use ($option, $reassignTo): void {
            $field = $this->lockedField($option);
            /** @var Collection<int, CustomFieldEnumeration> $options */
            $options = CustomFieldEnumeration::query()
                ->where('custom_field_id', $field->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $locked = $this->optionIn($options, (int) $option->id);
            if (! $locked instanceof CustomFieldEnumeration) {
                throw new DomainException('Enumeration does not exist.');
            }
            if ($this->isCurrentDefault($field, $locked)) {
                throw new DomainException('Enumeration is the default value.');
            }

            $targetId = $this->replacementId($options, $locked, $reassignTo);
            /** @var Collection<int, CustomValue> $values */
            $values = CustomValue::query()
                ->where('custom_field_id', $field->id)
                ->where('value', (string) $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($values->isNotEmpty() && $targetId === null) {
                throw new DomainException('Enumeration is in use.');
            }
            if ($targetId !== null) {
                $this->rewriteValues($values, (int) $field->id, $targetId);
            }

            $locked->delete();
        });
    }

    private function assertEnumerationField(CustomField $field): void
    {
        if (! $field->exists || (string) $field->field_format !== 'enumeration') {
            throw new DomainException('Enumerations belong to an enumeration custom field.');
        }
    }

    private function fieldFor(CustomFieldEnumeration $option): CustomField
    {
        $field = $option->customField;
        if (! $field instanceof CustomField) {
            throw new DomainException('Enumeration does not belong to a custom field.');
        }
        $this->assertEnumerationField($field);

        return $field;
    }

    private function lockedField(CustomFieldEnumeration $option): CustomField
    {
        $field = $this->fieldFor($option);
        $locked = CustomField::query()->whereKey($field->id)->lockForUpdate()->first();
        if (! $locked instanceof CustomField) {
            throw new DomainException('Enumeration does not belong to a custom field.');
        }
        $this->assertEnumerationField($locked);

        return $locked;
    }

    private function name(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new DomainException('Enumeration name is required.');
        }
        if (mb_strlen($trimmed) > 255) {
            throw new DomainException('Enumeration name is too long.');
        }

        return $trimmed;
    }

    private function assertUniqueName(CustomField $field, string $name, ?int $exceptId): void
    {
        $query = CustomFieldEnumeration::query()
            ->where('custom_field_id', $field->id)
            ->where('name', $name);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw new DomainException('Enumeration name is already used.');
        }
    }

    private function requireSaved(CustomFieldEnumeration $option): CustomFieldEnumeration
    {
        return $option->refresh();
    }

    private function isCurrentDefault(CustomField $field, CustomFieldEnumeration $option): bool
    {
        $default = $field->default_value;

        return is_string($default) && $default !== '' && $default === (string) $option->id;
    }

    /**
     * @param  Collection<int, CustomFieldEnumeration>  $options
     */
    private function optionIn(Collection $options, int $id): ?CustomFieldEnumeration
    {
        foreach ($options as $row) {
            if ((int) $row->id === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, CustomFieldEnumeration>  $options
     */
    private function replacementId(Collection $options, CustomFieldEnumeration $option, ?CustomFieldEnumeration $reassignTo): ?int
    {
        if ($reassignTo === null) {
            return null;
        }
        if ((int) $reassignTo->id === (int) $option->id) {
            throw new DomainException('Replacement enumeration must be a different option.');
        }

        $target = $this->optionIn($options, (int) $reassignTo->id);
        if (! $target instanceof CustomFieldEnumeration) {
            throw new DomainException('Replacement enumeration does not belong to this field.');
        }

        return (int) $target->id;
    }

    /**
     * @param  Collection<int, CustomValue>  $rows
     */
    private function rewriteValues(Collection $rows, int $fieldId, int $toId): void
    {
        $target = (string) $toId;
        /** @var array<string, true> $kept */
        $kept = [];
        foreach ($rows as $row) {
            $type = (string) $row->customized_type;
            $recordId = (int) $row->customized_id;
            $key = $type."\0".$recordId;
            $already = isset($kept[$key]) || CustomValue::query()
                ->where('custom_field_id', $fieldId)
                ->where('customized_type', $type)
                ->where('customized_id', $recordId)
                ->where('value', $target)
                ->lockForUpdate()
                ->exists();
            if ($already) {
                $row->delete();

                continue;
            }
            $row->value = $target;
            $row->save();
            $kept[$key] = true;
        }
    }
}
