<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `custom_fields` row.
 *
 * `type` is the STI name. `field_format`, `format_store`, and `possible_values` are stored only.
 */
class CustomField extends Model
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
            'editable' => 'boolean',
            'is_filter' => 'boolean',
            'is_for_all' => 'boolean',
            'is_required' => 'boolean',
            'multiple' => 'boolean',
            'searchable' => 'boolean',
            'visible' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CustomValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomValue::class);
    }

    /**
     * @return HasMany<CustomFieldEnumeration, $this>
     */
    public function enumerations(): HasMany
    {
        return $this->hasMany(CustomFieldEnumeration::class);
    }

    /**
     * @return BelongsToMany<Tracker, $this>
     */
    public function trackers(): BelongsToMany
    {
        return $this->belongsToMany(Tracker::class, 'custom_fields_trackers', 'custom_field_id', 'tracker_id');
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'custom_fields_projects', 'custom_field_id', 'project_id');
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'custom_fields_roles', 'custom_field_id', 'role_id');
    }
}
