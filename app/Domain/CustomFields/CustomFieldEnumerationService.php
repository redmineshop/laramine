<?php

namespace App\Domain\CustomFields;

use App\Domain\DomainException;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use Illuminate\Support\Facades\DB;

/**
 * Inserts, renames, reorders, and activates enumeration options for one field.
 *
 * Stored `custom_values` keep the option id. Renaming or reordering does not
 * rewrite them. Deleting an option is not implemented.
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
            if (! $active && (string) $field->default_value === (string) $option->id) {
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
}
