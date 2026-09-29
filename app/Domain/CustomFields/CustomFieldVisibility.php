<?php

namespace App\Domain\CustomFields;

use App\Domain\Acl\PermissionService;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;

/**
 * `visible = false` limits the field to roles in `custom_fields_roles`.
 * Active admins can see and edit every field.
 */
final class CustomFieldVisibility
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function canSee(?User $actor, CustomField $field, ?Project $project): bool
    {
        if ($field->visible) {
            return true;
        }

        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return true;
        }

        if ($actor === null || ! $this->permissions->isLoggedIn($actor)) {
            return false;
        }

        $allowed = [];
        foreach ($field->roles as $role) {
            $allowed[] = (int) $role->id;
        }
        if ($allowed === []) {
            return false;
        }

        foreach ($this->permissions->rolesFor($actor, $project) as $role) {
            if (in_array((int) $role->id, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    public function canEdit(User $actor, CustomField $field): bool
    {
        if ($this->markedEditable($field)) {
            return true;
        }

        return $actor->admin && $actor->isActive();
    }

    private function markedEditable(CustomField $field): bool
    {
        $stored = $field->getAttributes();
        if (! array_key_exists('editable', $stored)) {
            return true;
        }
        $raw = $stored['editable'];

        return $raw === null || $raw === true || $raw === 1 || $raw === '1';
    }
}
