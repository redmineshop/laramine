<?php

namespace App\Domain\Acl;

use App\Models\Role;

/**
 * Per-tracker permission limits stored on `roles.settings`.
 *
 * The five issue permissions that carry a tracker mask are view, add, edit,
 * notes, and delete. A missing setting, or any flag other than `0`, means
 * every tracker. `0` keeps only the integer ids listed for that permission.
 */
final class TrackerPermissionMask
{
    /**
     * @var list<string>
     */
    public const SCOPED = [
        'view_issues',
        'add_issues',
        'edit_issues',
        'add_issue_notes',
        'delete_issues',
    ];

    public function applies(string $permission): bool
    {
        return in_array($permission, self::SCOPED, true);
    }

    public function allows(Role $role, string $permission, int $trackerId): bool
    {
        $ids = $this->ids($role, $permission);
        if ($ids === null) {
            return true;
        }

        return in_array($trackerId, $ids, true);
    }

    /**
     * Tracker ids the role may use for this permission.
     *
     * Null means every tracker. An empty list means none.
     *
     * @return list<int>|null
     */
    public function ids(Role $role, string $permission): ?array
    {
        if (! $this->applies($permission)) {
            return null;
        }

        $settings = $role->settings;
        if ($settings === null) {
            return null;
        }

        $flags = $settings['permissions_all_trackers'] ?? null;
        if (! is_array($flags) || ! array_key_exists($permission, $flags)) {
            return null;
        }

        $flag = $flags[$permission];
        if (! is_scalar($flag) || (string) $flag !== '0') {
            return null;
        }

        $map = $settings['permissions_tracker_ids'] ?? null;
        if (! is_array($map)) {
            return [];
        }

        $listed = $map[$permission] ?? null;
        if (! is_array($listed)) {
            return [];
        }

        $ids = [];
        foreach ($listed as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
