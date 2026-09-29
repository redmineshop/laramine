<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `projects` row.
 *
 * Nested set columns are `parent_id`, `lft`, and `rgt` (there is no `root_id`).
 */
class Project extends Model
{
    public const CREATED_AT = 'created_on';

    public const UPDATED_AT = 'updated_on';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inherit_members' => 'boolean',
            'is_public' => 'boolean',
            'created_on' => 'datetime',
            'updated_on' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assigned_to_id');
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function defaultVersion(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'default_version_id');
    }

    /**
     * @return BelongsTo<Query, $this>
     */
    public function defaultIssueQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'default_issue_query_id');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return HasMany<Version, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
    }

    /**
     * @return HasMany<IssueCategory, $this>
     */
    public function issueCategories(): HasMany
    {
        return $this->hasMany(IssueCategory::class);
    }

    /**
     * @return HasMany<EnabledModule, $this>
     */
    public function enabledModules(): HasMany
    {
        return $this->hasMany(EnabledModule::class);
    }

    /**
     * @return HasMany<Query, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<Enumeration, $this>
     */
    public function enumerations(): HasMany
    {
        return $this->hasMany(Enumeration::class);
    }

    /**
     * @return BelongsToMany<Tracker, $this>
     */
    public function trackers(): BelongsToMany
    {
        return $this->belongsToMany(Tracker::class, 'projects_trackers', 'project_id', 'tracker_id');
    }
}
