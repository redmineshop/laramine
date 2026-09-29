<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `trackers` row.
 *
 * `fields_bits` is a core-field bitmask. `private_by_default` was added in 7.0.x.
 */
class Tracker extends Model
{
    public $timestamps = false;

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
            'is_in_roadmap' => 'boolean',
            'private_by_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<IssueStatus, $this>
     */
    public function defaultStatus(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'default_status_id');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'projects_trackers', 'tracker_id', 'project_id');
    }

    /**
     * @return BelongsToMany<CustomField, $this>
     */
    public function customFields(): BelongsToMany
    {
        return $this->belongsToMany(CustomField::class, 'custom_fields_trackers', 'tracker_id', 'custom_field_id');
    }
}
