<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Redmine 7.0.1 `issues` row.
 *
 * Nested set columns are `parent_id`, `root_id`, `lft`, and `rgt`.
 * `lock_version` is stored only; optimistic locking is not enforced here.
 */
class Issue extends Model
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
            'is_private' => 'boolean',
            'estimated_hours' => 'float',
            'start_date' => 'date',
            'due_date' => 'date',
            'closed_on' => 'datetime',
            'created_on' => 'datetime',
            'updated_on' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Tracker, $this>
     */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(Tracker::class);
    }

    /**
     * @return BelongsTo<IssueStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'status_id');
    }

    /**
     * @return BelongsTo<Enumeration, $this>
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Enumeration::class, 'priority_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * @return BelongsTo<IssueCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(IssueCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function fixedVersion(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'fixed_version_id');
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_id');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<IssueRelation, $this>
     */
    public function relationsFrom(): HasMany
    {
        return $this->hasMany(IssueRelation::class, 'issue_from_id');
    }

    /**
     * @return HasMany<IssueRelation, $this>
     */
    public function relationsTo(): HasMany
    {
        return $this->hasMany(IssueRelation::class, 'issue_to_id');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return MorphMany<Journal, $this>
     */
    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'journalized');
    }

    /**
     * @return MorphMany<Watcher, $this>
     */
    public function watchers(): MorphMany
    {
        return $this->morphMany(Watcher::class, 'watchable');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'container');
    }

    /**
     * Values stored with Redmine's customized type name, not the Eloquent class name.
     *
     * @return HasMany<CustomValue, $this>
     */
    public function customValues(): HasMany
    {
        return $this->hasMany(CustomValue::class, 'customized_id')
            ->where('custom_values.customized_type', 'Issue');
    }

    /**
     * @return MorphMany<Reaction, $this>
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }
}
