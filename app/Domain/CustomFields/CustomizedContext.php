<?php

namespace App\Domain\CustomFields;

use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps a record to the Redmine customized type and, when it has one, its project.
 */
final class CustomizedContext
{
    public function customizedType(Model $record): ?string
    {
        if ($record instanceof Issue) {
            return CustomFieldTypes::ISSUE;
        }

        if ($record instanceof Project) {
            return CustomFieldTypes::PROJECT;
        }

        if ($record instanceof TimeEntry) {
            return CustomFieldTypes::TIME_ENTRY;
        }

        if ($record instanceof Version) {
            return CustomFieldTypes::VERSION;
        }

        if ($record instanceof User) {
            return match ($record->type) {
                User::TYPE_GROUP => CustomFieldTypes::GROUP,
                User::TYPE_USER => CustomFieldTypes::USER,
                default => null,
            };
        }

        return null;
    }

    public function project(Model $record): ?Project
    {
        if ($record instanceof Project) {
            return $record;
        }

        if ($record instanceof Issue || $record instanceof TimeEntry || $record instanceof Version) {
            $project = $record->project;

            return $project instanceof Project ? $project : null;
        }

        return null;
    }
}
