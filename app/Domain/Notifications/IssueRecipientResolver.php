<?php

namespace App\Domain\Notifications;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\Auth\MailNotification;
use App\Domain\Auth\PreferenceCodec;
use App\Models\EmailAddress;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\Watcher;
use Illuminate\Support\Facades\DB;

/**
 * Chooses who receives one issue notification.
 *
 * Author, assignee, and previous assignee are kept when `notify_about` matches
 * their `mail_notification`. Project members with `all`, and `selected` members
 * whose membership checkbox is on, are added for every visible issue event.
 * A group membership with that checkbox adds the group's active users the same way.
 * Watchers are added unless their preference is `none` or blank.
 * `no_self_notified` drops the actor. A private note with no details is limited
 * to `view_private_notes`.
 */
final class IssueRecipientResolver
{
    public function __construct(
        private readonly IssueVisibility $issues,
        private readonly PermissionService $permissions,
        private readonly PreferenceCodec $preferences,
    ) {}

    /**
     * @return list<User>
     */
    public function recipients(User $actor, Issue $issue, ?int $previousAssigneeId, ?Journal $journal): array
    {
        $project = $issue->project;
        if (! $project instanceof Project) {
            return [];
        }

        $authorIds = $this->principalIds($issue->author_id);
        $assigneeIds = $this->principalIds($issue->assigned_to_id);
        $previousIds = $previousAssigneeId === null ? [] : $this->principalIds($previousAssigneeId);
        $watcherIds = $this->watcherIds($issue);
        $projectIds = $this->projectLevelIds($project);

        /** @var array<int, true> $ids */
        $ids = [];
        foreach ([$authorIds, $assigneeIds, $previousIds, $watcherIds, $projectIds] as $bucket) {
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

        $privateOnly = $journal instanceof Journal
            && (bool) $journal->private_notes
            && ! $this->journalHasDetails($journal);

        $selected = [];
        foreach ($users as $user) {
            $userId = (int) $user->id;
            if (! $this->issues->canSee($user, $issue)) {
                continue;
            }
            if ($privateOnly && ! $this->permissions->allowed($user, 'view_private_notes', $project)) {
                continue;
            }
            if (! $this->wantsMail(
                $user,
                $userId,
                $authorIds,
                $assigneeIds,
                $previousIds,
                $watcherIds,
                $projectIds,
            )) {
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
     * Notify addresses for one user, in id order.
     *
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

    public function seesPrivateNote(User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'view_private_notes', $project);
    }

    /**
     * @param  list<int>  $authorIds
     * @param  list<int>  $assigneeIds
     * @param  list<int>  $previousIds
     * @param  list<int>  $watcherIds
     * @param  list<int>  $projectIds
     */
    private function wantsMail(
        User $user,
        int $userId,
        array $authorIds,
        array $assigneeIds,
        array $previousIds,
        array $watcherIds,
        array $projectIds,
    ): bool {
        $preference = (string) $user->mail_notification;
        if ($preference === '' || $preference === 'none' || ! MailNotification::valid($preference)) {
            return false;
        }

        $author = in_array($userId, $authorIds, true);
        $assignee = in_array($userId, $assigneeIds, true) || in_array($userId, $previousIds, true);
        $watcher = in_array($userId, $watcherIds, true);
        $projectLevel = in_array($userId, $projectIds, true);

        if ($watcher || $projectLevel) {
            return true;
        }

        return match ($preference) {
            'all', 'selected', 'only_my_events' => $author || $assignee,
            'only_assigned' => $assignee,
            'only_owner' => $author,
            default => false,
        };
    }

    /**
     * @return list<int>
     */
    private function principalIds(mixed $id): array
    {
        if (! is_numeric($id)) {
            return [];
        }

        $principal = User::query()->find((int) $id);
        if (! $principal instanceof User) {
            return [];
        }
        if ($principal->type === User::TYPE_GROUP) {
            return $this->groupUserIds((int) $principal->id);
        }
        if ($principal->type === User::TYPE_USER) {
            return [(int) $principal->id];
        }

        return [];
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

    /**
     * @return list<int>
     */
    private function watcherIds(Issue $issue): array
    {
        $rows = Watcher::query()
            ->where('watchable_type', 'Issue')
            ->where('watchable_id', $issue->id)
            ->orderBy('id')
            ->pluck('user_id');
        $ids = [];
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
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

    private function journalHasDetails(Journal $journal): bool
    {
        if ($journal->relationLoaded('details')) {
            return $journal->details->isNotEmpty();
        }

        return $journal->details()->exists();
    }
}
