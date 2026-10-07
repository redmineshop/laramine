<?php

namespace App\Domain\Notifications;

use App\Domain\Acl\PermissionService;
use App\Domain\Auth\MailNotification;
use App\Domain\Auth\PreferenceCodec;
use App\Models\EmailAddress;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;

/**
 * Recipients for a project event that is not an issue.
 *
 * Project members with `all`, and `selected` members whose checkbox is on,
 * are included. A group membership with that checkbox, or a group whose own
 * preference is `all`, adds the group's active users. Watchers are included
 * unless their preference is `none` or blank. `only_owner` and `only_my_events`
 * match the owner ids. `only_assigned` does not match a wiki page or a message.
 * `no_self_notified` drops the actor. The user must be allowed `$viewPermission`.
 */
final class ModuleRecipientResolver
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly PreferenceCodec $preferences,
    ) {}

    /**
     * @param  list<int>  $ownerIds
     * @param  list<int>  $watcherIds
     * @return list<User>
     */
    public function recipients(
        User $actor,
        Project $project,
        string $viewPermission,
        array $ownerIds,
        array $watcherIds,
    ): array {
        $projectIds = $this->projectLevelIds($project);
        /** @var array<int, true> $ids */
        $ids = [];
        foreach ([$ownerIds, $watcherIds, $projectIds] as $bucket) {
            foreach ($bucket as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', array_keys($ids))
            ->where('type', User::TYPE_USER)
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();
        $preferenceRows = UserPreference::query()
            ->whereIn('user_id', array_keys($ids))
            ->get()
            ->keyBy(fn (UserPreference $row): int => (int) $row->user_id);

        $selected = [];
        foreach ($users as $user) {
            $userId = (int) $user->id;
            if (! $this->permissions->allowed($user, $viewPermission, $project)) {
                continue;
            }
            if (! $this->wantsMail($user, $userId, $ownerIds, $watcherIds, $projectIds)) {
                continue;
            }
            $preference = $preferenceRows->get($userId);
            $stored = $preference instanceof UserPreference && is_string($preference->others)
                ? $preference->others
                : null;
            $decoded = $this->preferences->decode($stored);
            if (($decoded['no_self_notified'] ?? false) === true && $userId === (int) $actor->id) {
                continue;
            }
            $selected[] = $user;
        }

        return $selected;
    }

    /**
     * @return list<string>
     */
    public function addresses(User $user): array
    {
        $rows = EmailAddress::query()
            ->where('user_id', $user->id)
            ->where('notify', true)
            ->orderBy('id')
            ->pluck('address');
        $addresses = [];
        foreach ($rows as $address) {
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param  list<int>  $ownerIds
     * @param  list<int>  $watcherIds
     * @param  list<int>  $projectIds
     */
    private function wantsMail(User $user, int $userId, array $ownerIds, array $watcherIds, array $projectIds): bool
    {
        $preference = (string) $user->mail_notification;
        if ($preference === '' || $preference === 'none' || ! MailNotification::valid($preference)) {
            return false;
        }
        if (in_array($userId, $watcherIds, true) || in_array($userId, $projectIds, true)) {
            return true;
        }

        $owner = in_array($userId, $ownerIds, true);

        return match ($preference) {
            'all', 'selected', 'only_my_events', 'only_owner' => $owner,
            'only_assigned' => false,
            default => false,
        };
    }

    /**
     * @return list<int>
     */
    private function projectLevelIds(Project $project): array
    {
        $members = Member::query()->where('project_id', $project->id)->orderBy('id')->get();
        $ids = [];
        foreach ($members as $member) {
            $principal = User::query()->find($member->user_id);
            if (! $principal instanceof User) {
                continue;
            }
            $checked = (bool) $member->mail_notification;
            if ($principal->type === User::TYPE_USER) {
                $preference = (string) $principal->mail_notification;
                if ($preference === 'all' || ($preference === 'selected' && $checked)) {
                    $ids[] = (int) $principal->id;
                }

                continue;
            }
            if ($principal->type === User::TYPE_GROUP && ($checked || (string) $principal->mail_notification === 'all')) {
                foreach ($this->groupUserIds((int) $principal->id) as $userId) {
                    $ids[] = $userId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function groupUserIds(int $groupId): array
    {
        $rows = DB::table('groups_users')->where('group_id', $groupId)->orderBy('user_id')->pluck('user_id');
        $ids = [];
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
