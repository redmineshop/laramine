<?php

namespace App\Domain\Watchers;

use App\Models\User;
use App\Models\Watcher;

/**
 * Rows in `watchers` for a Redmine watchable type string.
 */
final class WatcherLedger
{
    public const WIKI_PAGE = 'WikiPage';

    public const MESSAGE = 'Message';

    /**
     * @return list<int>
     */
    public function userIds(string $type, int $id): array
    {
        $rows = Watcher::query()
            ->where('watchable_type', $type)
            ->where('watchable_id', $id)
            ->orderBy('id')
            ->pluck('user_id');
        $ids = [];
        foreach ($rows as $userId) {
            if (is_numeric($userId)) {
                $ids[] = (int) $userId;
            }
        }

        return $ids;
    }

    public function add(User $user, string $type, int $id): void
    {
        $exists = Watcher::query()
            ->where('watchable_type', $type)
            ->where('watchable_id', $id)
            ->where('user_id', $user->id)
            ->exists();
        if ($exists) {
            return;
        }
        Watcher::query()->create([
            'user_id' => $user->id,
            'watchable_id' => $id,
            'watchable_type' => $type,
        ]);
    }

    public function remove(User $user, string $type, int $id): void
    {
        Watcher::query()
            ->where('watchable_type', $type)
            ->where('watchable_id', $id)
            ->where('user_id', $user->id)
            ->delete();
    }

    public function forget(string $type, int $id): void
    {
        Watcher::query()
            ->where('watchable_type', $type)
            ->where('watchable_id', $id)
            ->delete();
    }
}
