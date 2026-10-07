<?php

namespace App\Domain\TimeEntries;

use App\Domain\Acl\PermissionService;
use App\Domain\CustomFields\CustomValueService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Settings\SettingValue;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates, updates, and deletes `time_entries` rows.
 *
 * Create requires `log_time`. Update and delete require `edit_time_entries`,
 * or `edit_own_time_entries` when `user_id` is the actor. Assigning another
 * user requires `log_time_for_other_users`. Required custom fields are written
 * with the row. This is not an HTTP time log and it does not authenticate
 * the actor.
 */
final class TimeEntryService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CustomValueService $customValues,
        private readonly SettingValue $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, Project $project, array $attributes): TimeEntry
    {
        if (! $this->permissions->allowed($actor, 'log_time', $project)) {
            throw new PermissionDeniedException('log_time');
        }

        $custom = $this->customInputs($attributes);
        $payload = $this->payload($actor, $project, $attributes, null);

        return DB::transaction(function () use ($actor, $project, $payload, $custom): TimeEntry {
            $entry = TimeEntry::query()->create([
                'project_id' => $project->id,
                'author_id' => $actor->id,
                ...$payload,
            ]);
            $this->customValues->sync($actor, $entry, $custom, true);

            return $entry->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, TimeEntry $entry, array $attributes): TimeEntry
    {
        $project = $this->projectOf($entry);
        $this->assertCanEdit($actor, $project, $entry);
        $custom = $this->customInputs($attributes);
        $payload = $this->payload($actor, $project, $attributes, $entry);

        return DB::transaction(function () use ($actor, $entry, $payload, $custom): TimeEntry {
            $locked = $this->lock($entry);
            $this->assertCanEdit($actor, $this->projectOf($locked), $locked);
            $locked->fill($payload);
            $locked->save();
            $this->customValues->sync($actor, $locked, $custom, false);

            return $locked->refresh();
        });
    }

    public function delete(User $actor, TimeEntry $entry): void
    {
        $project = $this->projectOf($entry);
        $this->assertCanEdit($actor, $project, $entry);

        DB::transaction(function () use ($actor, $entry): void {
            $locked = $this->lock($entry);
            $this->assertCanEdit($actor, $this->projectOf($locked), $locked);
            CustomValue::query()
                ->where('customized_type', 'TimeEntry')
                ->where('customized_id', $locked->id)
                ->delete();
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{activity_id: int, hours: float, comments: ?string, issue_id: ?int, spent_on: string, user_id: int, tyear: int, tmonth: int, tweek: int}
     */
    private function payload(User $actor, Project $project, array $attributes, ?TimeEntry $current): array
    {
        if ($current === null || array_key_exists('activity_id', $attributes)) {
            $activityId = (int) $this->activity($project, $attributes['activity_id'] ?? null)->id;
        } else {
            $activityId = (int) $current->activity_id;
        }
        if ($current === null || array_key_exists('hours', $attributes)) {
            $hours = $this->hours($attributes['hours'] ?? null);
        } else {
            $hours = (float) $current->hours;
        }
        if ($current === null || array_key_exists('comments', $attributes)) {
            $comments = $this->comments($attributes['comments'] ?? null);
        } else {
            $comments = $this->storedComments($current);
        }
        if ($current === null || array_key_exists('spent_on', $attributes)) {
            $spentOn = $this->spentOn($attributes['spent_on'] ?? null);
        } else {
            $spentOn = $this->storedDate($current);
        }
        if ($current === null || array_key_exists('issue_id', $attributes)) {
            $issueId = $this->issueOnProject(
                $project,
                $this->idOrNull($attributes['issue_id'] ?? null, 'Issue is not in this project.'),
            );
        } else {
            $issueId = $current->issue_id === null ? null : (int) $current->issue_id;
        }
        $calendar = $this->calendar($spentOn);
        $this->assertRequired($issueId, $comments);

        return [
            'activity_id' => $activityId,
            'hours' => $hours,
            'comments' => $comments,
            'issue_id' => $issueId,
            'spent_on' => $spentOn,
            'user_id' => $this->userId($actor, $project, $attributes, $current),
            'tyear' => $calendar['tyear'],
            'tmonth' => $calendar['tmonth'],
            'tweek' => $calendar['tweek'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userId(User $actor, Project $project, array $attributes, ?TimeEntry $current): int
    {
        if (! array_key_exists('user_id', $attributes)) {
            if ($current instanceof TimeEntry) {
                return (int) $current->user_id;
            }

            return (int) $actor->id;
        }

        $requested = $this->idOrNull($attributes['user_id'], 'Time entry user must be an active user.');
        if ($requested === null || $requested === (int) $actor->id) {
            return (int) $actor->id;
        }
        if ($current instanceof TimeEntry && $requested === (int) $current->user_id) {
            return $requested;
        }
        if (! $this->permissions->allowed($actor, 'log_time_for_other_users', $project)) {
            throw new PermissionDeniedException('log_time_for_other_users');
        }

        $user = User::query()->find($requested);
        if (! $user instanceof User || $user->type !== User::TYPE_USER || ! $user->isActive()) {
            throw new DomainException('Time entry user must be an active user.');
        }

        return (int) $user->id;
    }

    private function activity(Project $project, mixed $value): Enumeration
    {
        $id = $this->idOrNull($value, 'Activity is not a time entry activity.');
        $activity = $id === null ? null : Enumeration::query()->find($id);
        if (! $activity instanceof Enumeration || (string) $activity->type !== 'TimeEntryActivity') {
            throw new DomainException('Activity is not a time entry activity.');
        }
        if (! $activity->active || ! $this->activityOnProject($project, $activity)) {
            throw new DomainException('Activity is not available on this project.');
        }

        return $activity;
    }

    private function activityOnProject(Project $project, Enumeration $activity): bool
    {
        if ($activity->project_id !== null) {
            return (int) $activity->project_id === (int) $project->id;
        }

        return ! Enumeration::query()
            ->where('type', 'TimeEntryActivity')
            ->where('parent_id', $activity->id)
            ->where('project_id', $project->id)
            ->exists();
    }

    private function hours(mixed $value): float
    {
        return HourValue::parse($value);
    }

    private function assertRequired(?int $issueId, ?string $comments): void
    {
        $required = $this->settings->timelogRequiredFields();
        if (in_array('issue_id', $required, true) && $issueId === null) {
            throw new DomainException('Issue is required.');
        }
        if (in_array('comments', $required, true) && ($comments === null || $comments === '')) {
            throw new DomainException('Comments are required.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, mixed>
     */
    private function customInputs(array $attributes): array
    {
        if (! array_key_exists('custom_field_values', $attributes)) {
            return [];
        }
        $raw = $attributes['custom_field_values'];
        if (! is_array($raw)) {
            throw new DomainException('Custom field values must be a map.');
        }
        $inputs = [];
        foreach ($raw as $id => $value) {
            if (! is_int($id) && preg_match('/^\d+$/', $id) !== 1) {
                throw new DomainException('Custom field id is invalid.');
            }
            $inputs[(int) $id] = $value;
        }

        return $inputs;
    }

    private function comments(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new DomainException('Comments must be text.');
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 1024) {
            throw new DomainException('Comments cannot be longer than 1024 characters.');
        }

        return $text;
    }

    private function spentOn(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new DomainException('Spent on must be a date.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
            throw new DomainException('Spent on must be a date.');
        }
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new DomainException('Spent on must be a date.');
        }

        return $value;
    }

    /**
     * @return array{tyear: int, tmonth: int, tweek: int}
     */
    private function calendar(string $spentOn): array
    {
        $date = new DateTimeImmutable($spentOn);

        return [
            'tyear' => (int) $date->format('o'),
            'tmonth' => (int) $date->format('n'),
            'tweek' => (int) $date->format('W'),
        ];
    }

    private function issueOnProject(Project $project, ?int $issueId): ?int
    {
        if ($issueId === null) {
            return null;
        }
        $issue = Issue::query()->find($issueId);
        if (! $issue instanceof Issue || (int) $issue->project_id !== (int) $project->id) {
            throw new DomainException('Issue is not in this project.');
        }

        return (int) $issue->id;
    }

    private function idOrNull(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new DomainException($message);
    }

    private function assertCanEdit(User $actor, Project $project, TimeEntry $entry): void
    {
        if ($this->permissions->allowed($actor, 'edit_time_entries', $project)) {
            return;
        }
        if ((int) $entry->user_id === (int) $actor->id
            && $this->permissions->allowed($actor, 'edit_own_time_entries', $project)) {
            return;
        }

        throw new PermissionDeniedException('edit_time_entries');
    }

    private function projectOf(TimeEntry $entry): Project
    {
        $project = $entry->relationLoaded('project') ? $entry->project : null;
        if (! $project instanceof Project) {
            $found = Project::query()->find($entry->project_id);
            $project = $found instanceof Project ? $found : null;
        }
        if (! $project instanceof Project) {
            throw new DomainException('Time entry has no project.');
        }

        return $project;
    }

    private function lock(TimeEntry $entry): TimeEntry
    {
        $locked = TimeEntry::query()->whereKey($entry->id)->lockForUpdate()->first();
        if (! $locked instanceof TimeEntry) {
            throw new DomainException('Time entry does not exist.');
        }

        return $locked;
    }

    private function storedDate(TimeEntry $entry): string
    {
        $raw = $entry->getRawOriginal('spent_on');
        if (! is_string($raw) || $raw === '') {
            throw new DomainException('Spent on must be a date.');
        }

        return substr($raw, 0, 10);
    }

    private function storedComments(TimeEntry $entry): ?string
    {
        $comments = $entry->comments;

        return is_string($comments) && $comments !== '' ? $comments : null;
    }
}
