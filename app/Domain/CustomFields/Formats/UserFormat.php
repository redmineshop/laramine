<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\Acl\MembershipService;
use App\Domain\CustomFields\CustomizedContext;
use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a user id string. With a project, the user must be a member.
 * `format_store.user_role` limits which of that project's roles qualify.
 */
final class UserFormat extends AbstractFormat
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly CustomizedContext $context,
    ) {}

    public function key(): string
    {
        return 'user';
    }

    public function supportsMultiple(): bool
    {
        return true;
    }

    public function supportsSearchable(): bool
    {
        return false;
    }

    public function queryFilterType(): string
    {
        return 'list_optional_with_history';
    }

    public function validateDefinition(CustomField $field): array
    {
        $roleIds = $this->roleIds($field);
        if ($roleIds === null) {
            return ['user_role must be a list of role ids.'];
        }

        if ($roleIds !== []) {
            $found = Role::query()->whereIn('id', $roleIds)->count();
            if ($found !== count($roleIds)) {
                return ['user_role references a role that does not exist.'];
            }
        }

        $default = FieldValues::defaultString($field);
        if ($default === null) {
            return [];
        }

        if ($this->canonicalId($default) === null) {
            return ['Default value must be a user id.'];
        }

        return [];
    }

    public function validate(CustomField $field, mixed $raw, ?Model $customized): array
    {
        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $parsed = $this->parseIds($raw, (bool) $field->multiple);
        if ($parsed['error'] !== null) {
            return [$parsed['error']];
        }
        $ids = $parsed['ids'];

        $roleIds = $this->roleIds($field) ?? [];
        $project = $customized !== null ? $this->context->project($customized) : null;
        $errors = [];
        $seen = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                $errors[] = 'Value is repeated.';
                break;
            }
            $seen[$id] = true;
            $user = User::query()->find($id);
            if (! $user instanceof User || $user->type !== User::TYPE_USER) {
                $errors[] = 'User does not exist.';
                break;
            }
            if (! $user->isActive()) {
                $errors[] = 'User is not active.';
                break;
            }
            if ($project !== null && ! $this->memberships->isMember($user, $project)) {
                $errors[] = 'User is not a member of the project.';
                break;
            }
            if ($roleIds !== [] && ! $this->hasRole($user, $roleIds, $project)) {
                $errors[] = 'User does not have an allowed role.';
                break;
            }
        }

        return $errors;
    }

    public function cast(CustomField $field, ?string $stored): mixed
    {
        unset($field);

        if ($stored === null || $stored === '' || ! ctype_digit($stored)) {
            return null;
        }

        return (int) $stored;
    }

    public function serialize(CustomField $field, mixed $raw): array
    {
        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $parsed = $this->parseIds($raw, (bool) $field->multiple);
        $ids = $parsed['ids'];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = (string) $id;
        }

        return $rows;
    }

    /**
     * @return list<int>|null
     */
    private function roleIds(CustomField $field): ?array
    {
        $store = $field->formatStoreData();
        if (! is_array($store) || ! array_key_exists('user_role', $store)) {
            return [];
        }

        $raw = $store['user_role'];
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            return null;
        }

        $ids = [];
        foreach ($raw as $id) {
            if (! is_numeric($id) || (int) $id <= 0) {
                return null;
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function hasRole(User $user, array $roleIds, ?Project $project): bool
    {
        $roles = $project === null
            ? $this->memberships->rolesAcrossProjects($user)
            : $this->memberships->rolesOnProject($user, $project);

        foreach ($roles as $role) {
            if (in_array((int) $role->id, $roleIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ids: list<int>, error: ?string}
     */
    private function parseIds(mixed $raw, bool $multiple): array
    {
        $source = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($source as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $id = $this->canonicalId($item);
            if ($id === null) {
                return ['ids' => [], 'error' => 'Value must be a user id.'];
            }
            $ids[] = $id;
        }

        if (! $multiple && count($ids) > 1) {
            return ['ids' => [], 'error' => 'Multiple values are not supported for this format.'];
        }

        return ['ids' => $ids, 'error' => null];
    }

    private function canonicalId(mixed $raw): ?int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^[1-9]\d*$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        return null;
    }
}
