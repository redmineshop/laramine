<?php

namespace Database\Seeders;

use App\Domain\Acl\BuiltinRole;
use App\Domain\Acl\PermissionCatalog;
use App\Models\Role;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * Minimal roles for a fresh database and for tests.
 *
 * Non member and Anonymous store an empty permission list. Public permissions
 * are still implied. Manager receives every project, issue-tracking, and
 * time-tracking permission. Developer and Reporter are smaller custom roles.
 * Per-tracker masks and managed-role limits are not seeded.
 */
class DefaultAccessSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(PermissionCatalog::class);

        $this->store($catalog, 'Non member', BuiltinRole::NON_MEMBER, [], 'default', false, 1);
        $this->store($catalog, 'Anonymous', BuiltinRole::ANONYMOUS, [], 'default', false, 2);
        $this->store($catalog, 'Manager', BuiltinRole::CUSTOM, array_merge(
            $catalog->namesForModule('project'),
            $catalog->namesForModule('issue_tracking'),
            $catalog->namesForModule('time_tracking'),
        ), 'all', true, 3);
        $this->store($catalog, 'Developer', BuiltinRole::CUSTOM, [
            'view_issues',
            'add_issues',
            'edit_issues',
            'edit_own_issues',
            'manage_subtasks',
            'add_issue_notes',
            'view_time_entries',
            'log_time',
            'save_queries',
        ], 'default', true, 4);
        $this->store($catalog, 'Reporter', BuiltinRole::CUSTOM, [
            'view_issues',
            'add_issues',
            'add_issue_notes',
        ], 'default', true, 5);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function store(
        PermissionCatalog $catalog,
        string $name,
        int $builtin,
        array $permissions,
        string $issuesVisibility,
        bool $assignable,
        int $position,
    ): void {
        foreach ($permissions as $permission) {
            if (! $catalog->has($permission)) {
                throw new InvalidArgumentException('Unknown seeded permission: '.$permission);
            }
        }

        $lookup = $builtin === BuiltinRole::CUSTOM
            ? ['name' => $name, 'builtin' => BuiltinRole::CUSTOM]
            : ['builtin' => $builtin];

        Role::query()->updateOrCreate($lookup, [
            'name' => $name,
            'builtin' => $builtin,
            'position' => $position,
            'assignable' => $assignable,
            'permissions' => $permissions,
            'issues_visibility' => $issuesVisibility,
            'all_roles_managed' => true,
        ]);
    }
}
